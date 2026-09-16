<?php

namespace App\Http\Controllers\Api;

use App\Events\ComplaintMessageBroadcasted;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Models\Order;
use App\Models\StoreAdminNotification;
use App\Services\ComplaintTicketLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientComplaintController extends ClientController
{
    public function my(Request $request): JsonResponse
    {
        if (!Schema::hasTable('complaints')) {
            return response()->json([
                'data' => [],
                'message' => 'Chức năng khiếu nại chưa sẵn sàng.',
            ], 503);
        }

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để xem khiếu nại của mình.',
            ], 401);
        }

        $search = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $type = trim((string) $request->query('type', ''));
        $sort = strtolower(trim((string) $request->query('sort', 'newest')));
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = min(50, max(1, (int) $request->query('pageSize', $request->query('page_size', 20))));

        $query = Complaint::query()
            ->with(['order:id,order_code', 'store:id,sale_zalo_phone,name'])
            ->where('user_id', $user->id);

        if ($status !== '' && strtolower($status) !== 'all' && Schema::hasColumn('complaints', 'status')) {
            $query->where('status', $status);
        }

        if ($type !== '' && strtolower($type) !== 'all' && Schema::hasColumn('complaints', 'complaint_type')) {
            $query->where('complaint_type', $type);
        }

        if ($search !== '') {
            $query->where(function ($innerQuery) use ($search) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $innerQuery
                    ->where('complaint_code', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('order', function ($orderQuery) use ($like) {
                        $orderQuery->where('order_code', 'like', $like);
                    });
            });
        }

        if (Schema::hasColumn('complaints', 'created_at')) {
            $sort === 'oldest' ? $query->orderBy('created_at') : $query->orderByDesc('created_at');
        } else {
            $sort === 'oldest' ? $query->orderBy('id') : $query->orderByDesc('id');
        }

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        // Batch message count + last message for the whole page in 2 queries
        // instead of 2 queries per complaint (fixes N+1).
        $complaintItems = collect($paginator->items());
        $messageCounts = [];
        $lastMessages = [];

        if (Schema::hasTable('complaint_messages') && Schema::hasColumn('complaint_messages', 'complaint_id')) {
            $complaintIds = $complaintItems->pluck('id')->filter()->values()->all();

            if (!empty($complaintIds)) {
                $messageCounts = ComplaintMessage::query()
                    ->whereIn('complaint_id', $complaintIds)
                    ->groupBy('complaint_id')
                    ->selectRaw('complaint_id, COUNT(*) as aggregate_count')
                    ->pluck('aggregate_count', 'complaint_id')
                    ->map(fn ($count) => (int) $count)
                    ->all();

                // Fetch messages newest-first and keep the first occurrence per complaint
                // (matches the original orderByDesc created_at, id "last message" logic).
                ComplaintMessage::query()
                    ->whereIn('complaint_id', $complaintIds)
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->get(['complaint_id', 'message'])
                    ->each(function (ComplaintMessage $row) use (&$lastMessages) {
                        if (!array_key_exists($row->complaint_id, $lastMessages)) {
                            $lastMessages[$row->complaint_id] = $row->message;
                        }
                    });
            }
        }

        $complaints = $complaintItems->map(function (Complaint $complaint) use ($messageCounts, $lastMessages) {
            $messageCount = $messageCounts[$complaint->id] ?? 0;
            $lastMessage = $lastMessages[$complaint->id] ?? null;

            return [
                'id' => $complaint->id,
                'code' => $complaint->complaint_code,
                'complaintCode' => $complaint->complaint_code,
                'complaint_type' => $complaint->complaint_type,
                'title' => $complaint->title,
                'description' => $complaint->description,
                'orderCode' => $complaint->order?->order_code,
                'order_code' => $complaint->order?->order_code,
                'status' => $complaint->status,
                'refund_bank_info' => $complaint->refund_bank_info,
                'is_escalated' => (bool) $complaint->is_escalated,
                'storeId' => $complaint->store_id,
                'store_id' => $complaint->store_id,
                'saleZaloPhone' => $complaint->store?->sale_zalo_phone,
                'sale_zalo_phone' => $complaint->store?->sale_zalo_phone,
                'messageCount' => $messageCount,
                'message_count' => $messageCount,
                'lastMessage' => $lastMessage,
                'last_message' => $lastMessage,
                'createdAt' => optional($complaint->created_at)->toIso8601String(),
                'created_at' => optional($complaint->created_at)->toIso8601String(),
                'updatedAt' => optional($complaint->updated_at)->toIso8601String(),
                'updated_at' => optional($complaint->updated_at)->toIso8601String(),
                'images' => $complaint->images,
                'videos' => $complaint->videos,
                'storeName' => $complaint->store?->name,
                'store_name' => $complaint->store?->name,
            ];
        })->values();

        return response()->json([
            'data' => $complaints,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
                'page_size' => $paginator->perPage(),
                'totalItems' => $paginator->total(),
                'total_items' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'total_pages' => $paginator->lastPage(),
            ],
            'message' => 'Lấy danh sách khiếu nại thành công.',
        ]);
    }

    public function messages(Request $request, string $id): JsonResponse
    {
        if (!Schema::hasTable('complaints')) {
            return response()->json([
                'data' => [],
                'message' => 'Chức năng khiếu nại chưa sẵn sàng.',
            ], 503);
        }

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để xem hội thoại.',
            ], 401);
        }

        $complaint = Complaint::query()->where('id', $id)->where('user_id', $user->id)->first();
        if (!$complaint) {
            return response()->json([
                'message' => 'Không tìm thấy khiếu nại.',
            ], 404);
        }

        if (!Schema::hasTable('complaint_messages')) {
            return response()->json([
                'data' => [],
                'message' => 'Chức năng hội thoại chưa sẵn sàng.',
            ], 503);
        }

        $messages = ComplaintMessage::query()
            ->where('complaint_id', $complaint->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (ComplaintMessage $message) {
                return [
                    'id' => $message->id,
                    'complaintId' => $message->complaint_id,
                    'complaint_id' => $message->complaint_id,
                    'senderId' => $message->sender_id,
                    'sender_id' => $message->sender_id,
                    'senderType' => $message->sender_type,
                    'sender_type' => $message->sender_type,
                    'sender' => $message->is_from_admin ? 'admin' : 'user',
                    'is_from_admin' => (bool) $message->is_from_admin,
                    'message' => $message->message,
                    'createdAt' => optional($message->created_at)->toIso8601String(),
                    'created_at' => optional($message->created_at)->toIso8601String(),
                ];
            })->values();

        return response()->json([
            'data' => $messages,
            'message' => 'Lấy hội thoại thành công.',
        ]);
    }

    public function storeMessage(Request $request, string $id): JsonResponse
    {
        if (!Schema::hasTable('complaints')) {
            return response()->json([
                'message' => 'Chức năng khiếu nại chưa sẵn sàng.',
            ], 503);
        }

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để gửi tin nhắn.',
            ], 401);
        }

        $complaint = Complaint::query()->where('id', $id)->where('user_id', $user->id)->first();
        if (!$complaint) {
            return response()->json([
                'message' => 'Không tìm thấy khiếu nại.',
            ], 404);
        }

        if (!Schema::hasTable('complaint_messages')) {
            return response()->json([
                'message' => 'Chức năng hội thoại chưa sẵn sàng.',
            ], 503);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:3000'],
        ]);

        $message = new ComplaintMessage();
        $message->complaint_id = $complaint->id;
        $message->sender_id = $user->id;
        $message->sender_type = 'customer';
        $message->message = trim((string) $validated['message']);
        $message->attachments = null;
        $message->is_from_admin = false;
        $message->is_read = false;
        $message->created_at = now();
        $message->save();

        if (Schema::hasColumn('complaints', 'updated_at')) {
            $complaint->touch();
        }

        event(new ComplaintMessageBroadcasted((string) $complaint->id, [
            'id' => (string) $message->id,
            'complaint_id' => (string) $message->complaint_id,
            'sender_id' => (string) $message->sender_id,
            'sender_type' => (string) $message->sender_type,
            'sender' => 'user',
            'is_from_admin' => false,
            'sender_name' => trim((string) ($user->full_name ?? $user->name ?? 'Khach hang')),
            'message' => (string) $message->message,
            'created_at' => optional($message->created_at)->toIso8601String(),
        ]));

        $this->createStoreAdminComplaintNotification(
            $complaint,
            'complaint_message',
            'Tin nhan khieu nai moi',
            'Khach hang vua gui tin nhan trong khieu nai ' . ($complaint->complaint_code ?? $complaint->id) . '.'
        );

        return response()->json([
            'data' => [
                'id' => $message->id,
                'complaintId' => $message->complaint_id,
                'complaint_id' => $message->complaint_id,
                'senderId' => $message->sender_id,
                'sender_id' => $message->sender_id,
                'senderType' => $message->sender_type,
                'sender_type' => $message->sender_type,
                'sender' => 'user',
                'is_from_admin' => false,
                'message' => $message->message,
                'createdAt' => optional($message->created_at)->toIso8601String(),
                'created_at' => optional($message->created_at)->toIso8601String(),
            ],
            'message' => 'Đã gửi tin nhắn thành công.',
        ], 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để tải lên hình ảnh.',
            ], 401);
        }

        $fileField = $request->hasFile('file') ? 'file' : ($request->hasFile('image') ? 'image' : null);
        if (!$fileField) {
            return response()->json([
                'message' => 'Không tìm thấy file hình ảnh hoặc video để tải lên.',
            ], 422);
        }

        $request->validate([
            $fileField => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif,mp4,mov,webm', 'max:10240'],
        ]);

        $file = $request->file($fileField);
        $userId = (string) $user->id;
        $directory = 'uploads/complaints/' . $userId;
        $path = $file->store($directory, 'public');

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => url(\Illuminate\Support\Facades\Storage::url($path)),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ],
            'errors' => [],
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        if (!Schema::hasTable('complaints')) {
            return response()->json([
                'message' => 'Chức năng khiếu nại chưa sẵn sàng.',
            ], 503);
        }

        $validated = $request->validate([
            'complaint_type' => ['nullable', 'string', 'max:50'],
            'complaintType' => ['nullable', 'string', 'max:50'],
            'order_code' => ['nullable', 'string', 'max:100'],
            'orderCode' => ['required_without:order_code', 'string', 'max:100'],
            'product_ids' => ['prohibited'],
            'productIds' => ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:3000'],
            'message' => ['nullable', 'string', 'max:3000'],
            'severity' => ['nullable', 'string', 'max:20'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string', 'max:255'],
            'refund_bank_info' => ['nullable', 'array'],
            'refund_bank_info.bank_name' => ['required_with:refund_bank_info', 'string', 'max:255'],
            'refund_bank_info.account_name' => ['required_with:refund_bank_info', 'string', 'max:255'],
            'refund_bank_info.account_number' => ['required_with:refund_bank_info', 'string', 'max:255'],
        ]);

        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để gửi khiếu nại.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $complaintType = trim((string) ($validated['complaint_type'] ?? $validated['complaintType'] ?? 'other'));
        $orderCode = trim((string) ($validated['order_code'] ?? $validated['orderCode'] ?? ''));
        if (strtolower($complaintType) === 'refund_request' && ! is_array($validated['refund_bank_info'] ?? null)) {
            return response()->json([
                'message' => 'Vui lòng thêm tài khoản ngân hàng để nhận hoàn tiền.',
                'error_code' => 'REFUND_BANK_INFO_REQUIRED',
            ], 422);
        }

        $order = Order::query()
            ->where('order_code', $orderCode)
            ->orWhere('id', $orderCode)
            ->first();

        if (!$order) {
            return response()->json([
                'message' => 'Không tìm thấy đơn hàng theo mã đã nhập.',
            ], 404);
        }

        if (!empty($order->user_id) && (string) $order->user_id !== (string) $user->id) {
            return response()->json([
                'message' => 'Bạn không có quyền gửi khiếu nại cho đơn hàng này.',
            ], 403);
        }

        $orderStatus = strtolower(trim((string) $order->status));
        if (!in_array($orderStatus, ['completed', 'delivered'], true)) {
            return response()->json([
                'message' => 'Chỉ có thể khiếu nại các đơn hàng đã hoàn thành.',
            ], 422);
        }

        $deliveredAt = $order->delivered_at ?: $order->updated_at ?: $order->created_at;
        if ($deliveredAt) {
            $deliveredTime = \Carbon\Carbon::parse($deliveredAt);
            if ($deliveredTime->copy()->addDays(3)->isPast()) {
                return response()->json([
                    'message' => 'Đơn hàng này đã quá thời hạn khiếu nại (chỉ hỗ trợ khiếu nại trong vòng 3 ngày kể từ khi hoàn thành).',
                ], 422);
            }
        }

        if (
            strtolower($complaintType) === 'refund_request'
            && Schema::hasTable('order_returns')
            && Schema::hasColumn('order_returns', 'order_id')
            && Schema::hasColumn('order_returns', 'status')
        ) {
            $hasRejectedReturn = \App\Models\OrderReturn::where('order_id', $order->id)
                ->where('status', 'rejected')
                ->exists();
            if (!$hasRejectedReturn) {
                return response()->json([
                    'message' => 'Vui lòng thực hiện hoàn hàng với cửa hàng trước.',
                ], 422);
            }
        }

        if (!Schema::hasTable('complaint_ticket_locks') || !Schema::hasTable('tickets')) {
            return response()->json([
                'message' => 'Chức năng khóa vé khiếu nại chưa sẵn sàng.',
            ], 503);
        }

        $lockedTicketIds = [];
        $complaint = null;

        try {
            DB::beginTransaction();

            $order = Order::query()->whereKey((string) $order->id)->lockForUpdate()->firstOrFail();
            $existingComplaint = Complaint::query()
                ->where('order_id', $order->id)
                ->whereNotIn('status', ['closed', 'cancelled'])
                ->lockForUpdate()
                ->first();

            if ($existingComplaint) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Đơn hàng này đã có khiếu nại đang xử lý.',
                ], 422);
            }

            $complaint = new Complaint();
            $complaint->complaint_code = $this->generateComplaintCode();
            $complaint->user_id = $user?->id;
            $complaint->store_id = $order?->store_id;
            $complaint->order_id = $order?->id;
            $complaint->complaint_type = $complaintType;
            $complaint->severity = trim((string) ($validated['severity'] ?? 'normal')) ?: 'normal';
            $complaint->title = trim((string) $validated['title']);
            $complaint->description = trim((string) $validated['description']);
            $complaint->status = 'new';
            $complaint->images = $validated['images'] ?? null;
            $complaint->refund_bank_info = $validated['refund_bank_info'] ?? null;
            $complaint->save();

            if (Schema::hasTable('complaint_messages')) {
                $message = new ComplaintMessage();
                $message->complaint_id = $complaint->id;
                $message->sender_id = $user?->id;
                $message->sender_type = 'customer';
                $message->message = trim((string) ($validated['message'] ?? $validated['description']));
                $message->is_from_admin = false;
                $message->is_read = false;
                $message->created_at = now();
                $message->save();
            }

            $lockedTicketIds = app(ComplaintTicketLockService::class)
                ->lockAvailableOrderRewardTickets($complaint, $order, (string) $user->id);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Failed to store complaint: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            if (config('app.debug')) {
                throw $e;
            }
            return response()->json([
                'message' => 'Không thể gửi khiếu nại vào lúc này. Vui lòng thử lại sau.',
            ], 500);
        }

        $this->createStoreAdminComplaintNotification(
            $complaint,
            'complaint_new',
            'Khieu nai moi',
            'Khach hang vua gui khieu nai ' . ($complaint->complaint_code ?? $complaint->id) . '.'
        );

        return response()->json([
            'complaint_id' => $complaint->id,
            'complaint_code' => $complaint->complaint_code,
            'scope' => 'order',
            'locked_ticket_count' => count($lockedTicketIds),
            'locked_ticket_ids' => $lockedTicketIds,
            'status' => $complaint->status,
            'message' => 'Đã gửi khiếu nại thành công.',
        ], 201);
    }

    private function generateComplaintCode(): string
    {
        $datePart = now()->format('ymd');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'CMP-' . $datePart . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
            if (!Complaint::query()->where('complaint_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'CMP-' . now()->format('ymdHis');
    }

    public function cancelComplaint(Request $request, string $id): JsonResponse
    {
        $user = $this->resolveApiUser($request);
        if (! $user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để hủy khiếu nại.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $reason = trim((string) ($validated['reason'] ?? 'Khách hàng chủ động hủy khiếu nại.'));

        try {
            DB::beginTransaction();

            $complaint = Complaint::query()
                ->whereKey($id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $complaint) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Không tìm thấy khiếu nại.',
                    'error_code' => 'COMPLAINT_NOT_FOUND',
                ], 404);
            }

            $currentStatus = strtolower(trim((string) $complaint->status));
            if ($currentStatus === 'cancelled') {
                DB::commit();
                return response()->json([
                    'complaint_id' => (string) $complaint->id,
                    'status' => 'cancelled',
                    'idempotent' => true,
                    'message' => 'Khiếu nại đã được hủy trước đó.',
                ]);
            }

            if (! in_array($currentStatus, ['new', 'pending', 'processing'], true)) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Khiếu nại đã bước vào giai đoạn xử lý không thể hủy.',
                    'error_code' => 'COMPLAINT_STATE_CONFLICT',
                    'current_status' => $currentStatus,
                ], 409);
            }

            if (Schema::hasTable('order_returns') && Schema::hasColumn('order_returns', 'complaint_id')) {
                $return = DB::table('order_returns')
                    ->where('complaint_id', $complaint->id)
                    ->lockForUpdate()
                    ->first();

                if ($return && ! in_array(strtolower((string) $return->status), ['pending', 'rejected', 'closed'], true)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Yêu cầu hoàn hàng đã được xử lý nên không thể hủy khiếu nại.',
                        'error_code' => 'COMPLAINT_STATE_CONFLICT',
                    ], 409);
                }

                if ($return && strtolower((string) $return->status) === 'pending') {
                    $returnPayload = ['status' => 'closed', 'updated_at' => now()];
                    if (Schema::hasColumn('order_returns', 'admin_resolution')) {
                        $returnPayload['admin_resolution'] = 'customer_cancelled';
                    }
                    if (Schema::hasColumn('order_returns', 'admin_resolution_note')) {
                        $returnPayload['admin_resolution_note'] = $reason;
                    }
                    DB::table('order_returns')->where('id', $return->id)->update($returnPayload);
                }
            }

            $release = app(ComplaintTicketLockService::class)
                ->release($complaint, 'customer_cancelled', $user->id);

            $fromStatus = $currentStatus;
            $payload = ['status' => 'cancelled', 'updated_at' => now()];
            if (Schema::hasColumn('complaints', 'resolution_type')) {
                $payload['resolution_type'] = 'customer_cancelled';
            }
            if (Schema::hasColumn('complaints', 'cancelled_at')) {
                $payload['cancelled_at'] = now();
            }
            if (Schema::hasColumn('complaints', 'cancelled_by')) {
                $payload['cancelled_by'] = (string) $user->id;
            }
            if (Schema::hasColumn('complaints', 'cancel_reason')) {
                $payload['cancel_reason'] = $reason;
            }
            $complaint->forceFill($payload)->save();

            if (Schema::hasTable('complaint_action_logs')) {
                DB::table('complaint_action_logs')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'complaint_id' => (string) $complaint->id,
                    'actor_id' => (string) $user->id,
                    'actor_role' => (string) ($user->role ?? 'user'),
                    'action' => 'customer_cancelled',
                    'from_status' => $fromStatus,
                    'to_status' => 'cancelled',
                    'metadata' => json_encode(['reason' => $reason, 'released_ticket_count' => $release['released_count']], JSON_UNESCAPED_UNICODE),
                    'idempotency_key' => 'complaint:' . $complaint->id . ':customer_cancelled',
                    'created_at' => now(),
                ]);
            }

            DB::commit();

            $this->createStoreAdminComplaintNotification(
                $complaint->fresh(),
                'complaint_cancelled',
                'Khiếu nại đã được hủy',
                'Khách hàng đã hủy khiếu nại ' . ($complaint->complaint_code ?? $complaint->id) . '.'
            );

            return response()->json([
                'complaint_id' => (string) $complaint->id,
                'status' => 'cancelled',
                'idempotent' => false,
                'released_ticket_count' => $release['released_count'],
                'message' => 'Đã hủy khiếu nại thành công.',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'message' => 'Không thể hủy khiếu nại vào lúc này.',
                'error_code' => 'COMPLAINT_CANCEL_FAILED',
            ], 500);
        }
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        if (!Schema::hasTable('complaints')) {
            return response()->json([
                'message' => 'Chức năng khiếu nại chưa sẵn sàng.',
            ], 503);
        }

        $user = $this->resolveApiUser($request);
        if (!$user) {
            return response()->json([
                'message' => 'Bạn cần đăng nhập để cập nhật trạng thái khiếu nại.',
            ], 401);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:closed'],
        ]);

        $newStatus = mb_strtolower(trim((string) $validated['status']));

        try {
            DB::beginTransaction();

            // Only allow customer to confirm (set to 'closed') if complaint is 'resolved'
            $complaint = DB::table('complaints')
                ->where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$complaint) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Không tìm thấy khiếu nại.',
                ], 404);
            }

            $currentStatus = mb_strtolower(trim((string) ($complaint->status ?? '')));

            // Customer can only close a complaint if it's already resolved by admin/store
            if ($newStatus === 'closed' && $currentStatus !== 'resolved') {
                DB::rollBack();
                return response()->json([
                    'message' => 'Chỉ có thể xác nhận (đóng) khiếu nại khi cửa hàng đã xử lý xong.',
                    'current_status' => $currentStatus,
                ], 422);
            }

            // Update complaint status to 'closed'
            $updatePayload = ['status' => 'closed'];
            if (Schema::hasColumn('complaints', 'updated_at')) {
                $updatePayload['updated_at'] = now();
            }

            if (!empty($updatePayload)) {
                DB::table('complaints')->where('id', $id)->update($updatePayload);
            }

            app(ComplaintTicketLockService::class)
                ->release($id, 'customer_confirmed', $user->id);

            // Add system message to complaint thread
            if (Schema::hasTable('complaint_messages')) {
                $messagePayload = [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'complaint_id' => $id,
                    'sender_id' => $user->id,
                    'sender_type' => 'customer',
                    'message' => 'Tôi xác nhận rằng vấn đề đã được giải quyết thành công.',
                    'is_from_admin' => false,
                    'is_read' => false,
                    'created_at' => now(),
                ];

                DB::table('complaint_messages')->insert($messagePayload);
            }

            DB::commit();

            return response()->json([
                'complaint_id' => $id,
                'status' => 'closed',
                'message' => 'Đã xác nhận khiếu nại. Vé sẽ được mở khóa ngay.',
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json([
                'message' => 'Không thể cập nhật trạng thái khiếu nại vào lúc này. Vui lòng thử lại sau.',
            ], 500);
        }
    }

    private function createStoreAdminComplaintNotification(
        Complaint $complaint,
        string $type,
        string $title,
        string $message
    ): void {
        if (!Schema::hasTable('store_admin_notifications') || empty($complaint->store_id)) {
            return;
        }

        $orderCode = $complaint->relationLoaded('order')
            ? ($complaint->order?->order_code ?? null)
            : Order::query()->where('id', $complaint->order_id)->value('order_code');
        $order = $complaint->relationLoaded('order')
            ? $complaint->order
            : Order::query()->where('id', $complaint->order_id)->first();
        $customer = $complaint->relationLoaded('user')
            ? $complaint->user
            : $complaint->user()->first();
        $customerPhone = trim((string) ($order?->delivery_phone ?? $order?->phone ?? $customer?->phone ?? ''));
        $customerName = trim((string) ($order?->name ?? $customer?->full_name ?? $customer?->name ?? ''));
        $deliveryAddress = trim((string) ($order?->delivery_address ?? $order?->address ?? ''));

        try {
            StoreAdminNotification::query()->create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'store_id' => (string) $complaint->store_id,
                'order_id' => null,
                'order_code' => null,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => [
                    'complaint_id' => (string) $complaint->id,
                    'complaint_code' => (string) ($complaint->complaint_code ?? ''),
                    'order_id' => $complaint->order_id ? (string) $complaint->order_id : null,
                    'order_code' => $orderCode ? (string) $orderCode : null,
                    'complaint_type' => (string) ($complaint->complaint_type ?? ''),
                    'complaint_reason' => (string) ($complaint->description ?? ''),
                    'customer_name' => $customerName !== '' ? $customerName : null,
                    'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                    'delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
                    'action_url' => '/admin_store/complaints?focusComplaint=' . rawurlencode((string) $complaint->id),
                ],
                'is_read' => false,
                'read_at' => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
