<?php

namespace App\Http\Controllers;

use App\Models\Complaint;
use App\Services\ComplaintResolutionService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class ComplaintController extends Controller
{
    public function index(Request $request)
    {
        $validated = $this->validateFilterParams($request);
        $stores = $this->getStores();
        $assigneeOptions = $this->getAssigneeOptions($stores);

        return view('admin.complaints.index', compact('stores', 'assigneeOptions'));
    }

    public function datatable(Request $request)
    {
        $validated = $this->validateFilterParams($request);
        $stores = $this->getStores();
        $assigneeOptions = $this->getAssigneeOptions($stores);
        $severityLabels = [
            'low' => 'Thấp',
            'normal' => 'Bình thường',
            'high' => 'Cao',
            'critical' => 'Khẩn cấp',
        ];
        $statusLabels = [
            'new' => 'Mới tiếp nhận',
            'pending' => 'Mới tiếp nhận',
            'processing' => 'Đang xử lý',
            'awaiting_store_action' => 'Chờ cửa hàng',
            'refund_processing' => 'Đang hoàn tiền',
            'resolved' => 'Đã giải quyết',
            'closed' => 'Đã đóng',
            'cancelled' => 'Khách đã hủy',
        ];
        $columns = [
            0 => 'complaint_code',
            4 => 'created_at',
        ];

        $orderColumn = (int) $request->input('order.0.column', 4);
        $orderField = $columns[$orderColumn] ?? 'created_at';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $start = (int) $request->input('start', 0);
        $length = (int) $request->input('length', 20);

        $query = $this->buildComplaintQuery($validated);
        $recordsTotal = (clone $query)->count();

        $searchValue = (string) $request->input('search.value', '');
        if ($searchValue !== '') {
            $query->where(function ($sub) use ($searchValue) {
                $sub->where('complaint_code', 'like', '%' . $searchValue . '%')
                    ->orWhere('title', 'like', '%' . $searchValue . '%')
                    ->orWhere('description', 'like', '%' . $searchValue . '%')
                    ->orWhere('user_id', 'like', '%' . $searchValue . '%')
                    ->orWhere('order_id', 'like', '%' . $searchValue . '%');
            });
        }

        $recordsFiltered = $query->count();

        $complaints = $query
            ->orderBy($orderField, $orderDir)
            ->skip($start)
            ->take($length)
            ->get();

        $data = $complaints->map(function (Complaint $complaint) {
            return $complaint;
        })->map(function (Complaint $complaint) use ($assigneeOptions, $severityLabels, $statusLabels) {
            return [
                'complaint' => view('admin.complaints.partials.datatable-complaint', compact('complaint'))->render(),
                'related' => view('admin.complaints.partials.datatable-related', compact('complaint'))->render(),
                'severity_status' => view('admin.complaints.partials.datatable-severity-status', compact('complaint', 'severityLabels', 'statusLabels'))->render(),
                'assignment' => view('admin.complaints.partials.datatable-assignment', compact('complaint', 'assigneeOptions', 'statusLabels'))->render(),
                'created_at' => view('admin.complaints.partials.datatable-created-at', compact('complaint'))->render(),
                'actions' => view('admin.complaints.partials.datatable-actions', compact('complaint'))->render(),
            ];
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function show(Complaint $complaint)
    {
        $complaint->loadMissing('store:id,sale_id');

        if ($complaint->store) {
            AdminCmsAccess::abortUnlessCanViewStore($complaint->store);
        } elseif (AdminCmsAccess::isSale()) {
            abort(403, 'Ban khong co quyen xem ticket nay.');
        }

        $complaint->load([
            'store:id,store_code,name,owner_id,wallet_balance,ticket_debt,credit_limit,is_debt_locked,debt_lock_reason',
            'user:id,name,full_name,phone',
            'order:id,order_code,delivery_phone,delivery_address,phone,address,final_amount,total_amount',
            'orderReturn.items',
            'refund',
            'assignee:id,name,full_name,email',
            'resolver:id,name,full_name,email',
            'messages' => function ($query) {
                $query->orderBy('created_at');
            },
        ]);

        $storesQuery = DB::table('stores')
            ->select('id', 'store_code', 'name', 'owner_id')
            ->orderBy('name')
            ->limit(1000);

        if (AdminCmsAccess::isSale()) {
            $storesQuery->where('sale_id', auth()->id());
        }

        $stores = $storesQuery->get();

        $assigneeOptions = $this->getAssigneeOptions($stores);
        $actionLogs = Schema::hasTable('complaint_action_logs')
            ? DB::table('complaint_action_logs')->where('complaint_id', (string) $complaint->id)->orderBy('created_at')->get()
            : collect();
        $fundingPreview = $this->fundingPreview($complaint);

        return view('admin.complaints.show', compact('complaint', 'assigneeOptions', 'actionLogs', 'fundingPreview'));
    }

    public function assign(Request $request, Complaint $complaint)
    {
        abort_unless(AdminCmsAccess::isSuperAdmin(), 403, 'Chỉ Super Admin được phân công khiếu nại.');

        $validated = $request->validate([
            'assigned_to' => [
                'nullable',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', 'sale')
                    ->where('status', 'active')),
            ],
            'status' => ['nullable', 'string', 'in:new,pending,processing'],
            'resolution_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $payload = [
            'assigned_to' => $validated['assigned_to'] ?? null,
        ];

        if (isset($validated['status']) && $validated['status'] !== '') {
            $payload['status'] = $validated['status'];
        } elseif (!empty($validated['assigned_to']) && in_array($complaint->status, ['new', 'pending'], true)) {
            $payload['status'] = 'processing';
        }

        if (array_key_exists('resolution_note', $validated)) {
            $payload['resolution_note'] = $validated['resolution_note'];
        }

        $targetStatus = $payload['status'] ?? $complaint->status;
        if (in_array($targetStatus, ['resolved', 'closed'], true)) {
            $payload['resolved_by'] = (string) auth()->id();
            $payload['resolved_at'] = now();
        }

        $complaint->update($payload);

        try {
            event(new \App\Events\ComplaintStatusUpdated((string) $complaint->id, [
                'event' => 'complaint.status.updated',
                'id' => (string) $complaint->id,
                'code' => $complaint->complaint_code ?? $complaint->code ?? '',
                'status' => $complaint->status,
                'resolution_type' => $complaint->resolution_type,
                'resolution_note' => $complaint->resolution_note,
                'updated_at' => now()->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to broadcast complaint status update in assign.', [
                'id' => $complaint->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Cập nhật phân công/trạng thái khiếu nại thành công.');
    }

    public function createReturnOrderForStore(Complaint $complaint)
    {
        $this->ensureSuperAdminCanResolve($complaint);
        if (!$this->hasRejectedReturn($complaint)) {
            abort(403, 'Thao tác chỉ khả dụng khi yêu cầu trả hàng đã bị Cửa hàng từ chối.');
        }

        try {
            $return = app(ComplaintResolutionService::class)
                ->createReturnForStore($complaint, auth()->user());

            return back()->with('success', 'Đã tạo đơn hoàn ' . $return->return_code . ' và gửi Store xác nhận.');
        } catch (RuntimeException $e) {
            return back()->withErrors(['complaint_action' => $this->resolutionErrorMessage($e)]);
        }
    }

    public function rejectClientComplaint(Request $request, Complaint $complaint)
    {
        $this->ensureSuperAdminCanResolve($complaint);
        if (!$this->hasRejectedReturn($complaint)) {
            abort(403, 'Thao tác chỉ khả dụng khi yêu cầu trả hàng đã bị Cửa hàng từ chối.');
        }
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            $resolved = app(ComplaintResolutionService::class)
                ->reject($complaint, auth()->user(), trim((string) $validated['reason']));
            $this->notifyCustomer($resolved, 'complaint_rejected', 'Khiếu nại chưa được chấp thuận', (string) $resolved->resolution_note);
            $this->notifyStoreAdmin($resolved, 'complaint_resolved', 'Quyết định xử lý khiếu nại', 'Admin đã từ chối khiếu nại của khách hàng.');

            return back()->with('success', 'Đã từ chối khiếu nại và giải phóng các vé hợp lệ.');
        } catch (RuntimeException $e) {
            return back()->withErrors(['complaint_action' => $this->resolutionErrorMessage($e)]);
        }
    }

    public function forceRefundOrLockStore(Request $request, Complaint $complaint)
    {
        $this->ensureSuperAdminCanResolve($complaint);
        if (!$this->hasRejectedReturn($complaint)) {
            abort(403, 'Thao tác chỉ khả dụng khi yêu cầu trả hàng đã bị Cửa hàng từ chối.');
        }
        $validated = $request->validate([
            'action' => ['required', 'in:force_refund,lock_store'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        try {
            $service = app(ComplaintResolutionService::class);
            if ($validated['action'] === 'force_refund') {
                $return = $service->forceRefund($complaint, auth()->user(), trim((string) $validated['reason']));
                $this->notifyCustomer($complaint->fresh(), 'complaint_refunded', 'Khiếu nại đã được hoàn tiền', 'Số tiền hoàn: ' . number_format((float) $return->refund_amount_final) . ' đ.');
                $this->notifyStoreAdmin($complaint->fresh(), 'complaint_resolved', 'Quyết định xử lý khiếu nại', 'Admin đã quyết định: Hoàn tiền cho khách hàng.');

                return back()->with('success', 'Đã buộc hoàn tiền thành công. Mã đơn hoàn: ' . $return->return_code . '.');
            }

            $service->lockStore($complaint, auth()->user(), trim((string) $validated['reason']));
            $this->notifyCustomer($complaint->fresh(), 'complaint_action', 'Cập nhật khiếu nại', 'Cửa hàng đã bị khóa để xử lý khiếu nại.');
            $this->notifyStoreAdmin($complaint->fresh(), 'complaint_action', 'Cửa hàng bị khóa', 'Cửa hàng của bạn đã bị khóa do vi phạm khiếu nại.');

            return back()->with('success', 'Đã khóa Store và gửi yêu cầu chấp nhận hoàn tiền.');
        } catch (RuntimeException $e) {
            return back()->withErrors(['complaint_action' => $this->resolutionErrorMessage($e)]);
        }
    }

    public function resolveManualComplaint(Request $request, Complaint $complaint)
    {
        $this->ensureSuperAdminCanResolve($complaint);
        if (!$this->hasRejectedReturn($complaint)) {
            abort(403, 'Thao tác chỉ khả dụng khi yêu cầu trả hàng đã bị Cửa hàng từ chối.');
        }
        $validated = $request->validate([
            'resolution_note' => ['required', 'string', 'min:5', 'max:5000'],
        ]);

        try {
            $resolved = app(ComplaintResolutionService::class)
                ->resolveManual($complaint, auth()->user(), trim((string) $validated['resolution_note']));
            $this->notifyCustomer($resolved, 'complaint_resolved', 'Khiếu nại đã được giải quyết', (string) $resolved->resolution_note);
            $this->notifyStoreAdmin($resolved, 'complaint_resolved', 'Quyết định xử lý khiếu nại', 'Admin đã đưa ra phương pháp xử lý: ' . $resolved->resolution_note);

            return back()->with('success', 'Đã ghi nhận kết quả giải quyết thủ công.');
        } catch (RuntimeException $e) {
            return back()->withErrors(['complaint_action' => $this->resolutionErrorMessage($e)]);
        }
    }

    public function acknowledge(Request $request, Complaint $complaint)
    {
        $this->ensureSuperAdminCanResolve($complaint);
        if ($this->hasRejectedReturn($complaint)) {
            abort(403, 'Thao tác này không áp dụng cho trường hợp có đơn hoàn trả bị từ chối.');
        }

        try {
            $resolved = app(ComplaintResolutionService::class)
                ->acknowledge($complaint, auth()->user());
            $this->notifyCustomer($resolved, 'complaint_acknowledged', 'Khiếu nại đã được tiếp nhận', 'Khiếu nại của bạn đã được tiếp nhận.');
            $this->notifyStoreAdmin($resolved, 'complaint_action', 'Khiếu nại đã được tiếp nhận', 'Admin đã tiếp nhận xử lý khiếu nại này.');

            return back()->with('success', 'Đã tiếp nhận khiếu nại thành công.');
        } catch (RuntimeException $e) {
            return back()->withErrors(['complaint_action' => $this->resolutionErrorMessage($e)]);
        }
    }

    private function hasRejectedReturn(Complaint $complaint): bool
    {
        return \App\Models\OrderReturn::where('order_id', $complaint->order_id)
            ->where('status', 'rejected')
            ->exists();
    }

    private function validateFilterParams(Request $request): array
    {
        return $request->validate([
            'severity' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:50'],
            'store_id' => ['nullable', 'string', 'max:36'],
            'assigned_to' => ['nullable', 'string', 'max:36'],
            'refund_requested' => ['nullable', 'in:0,1'],
            'keyword' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function buildComplaintQuery(array $validated)
    {
        $query = Complaint::query()
            ->with(['store:id,store_code,name', 'assignee:id,name,full_name,email']);

        AdminCmsAccess::applyComplaintScope($query);

        if (!empty($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['store_id'])) {
            $query->where('store_id', $validated['store_id']);
        }

        if (!empty($validated['assigned_to'])) {
            $query->where('assigned_to', $validated['assigned_to']);
        }

        if (array_key_exists('refund_requested', $validated) && $validated['refund_requested'] !== null && $validated['refund_requested'] !== '') {
            $query->where('refund_requested', (int) $validated['refund_requested']);
        }

        if (!empty($validated['keyword'])) {
            $keyword = trim((string) $validated['keyword']);
            $query->where(function ($sub) use ($keyword) {
                $sub->where('complaint_code', 'like', '%' . $keyword . '%')
                    ->orWhere('title', 'like', '%' . $keyword . '%')
                    ->orWhere('description', 'like', '%' . $keyword . '%')
                    ->orWhere('user_id', 'like', '%' . $keyword . '%')
                    ->orWhere('order_id', 'like', '%' . $keyword . '%');
            });
        }

        return $query;
    }

    private function getStores()
    {
        $query = DB::table('stores')
            ->select('id', 'store_code', 'name', 'owner_id')
            ->orderBy('name')
            ->limit(1000);

        if (AdminCmsAccess::isSale()) {
            $query->where('sale_id', auth()->id());
        }

        return $query->get();
    }

    private function getAssigneeOptions($stores)
    {
        return DB::table('users')
            ->where('role', 'sale')
            ->where('status', 'active')
            ->orderByRaw("COALESCE(NULLIF(full_name, ''), name, email)")
            ->get(['id', 'name', 'full_name', 'email'])
            ->map(fn ($user) => [
                'value' => (string) $user->id,
                'label' => trim((string) ($user->full_name ?: $user->name ?: $user->email ?: $user->id)),
            ]);
    }

    private function ensureSuperAdminCanResolve(Complaint $complaint): void
    {
        abort_unless(AdminCmsAccess::isSuperAdmin(), 403, 'Chỉ Super Admin được xử lý quyết định khiếu nại.');
        $complaint->loadMissing('store:id,sale_id');
        if ($complaint->store) {
            AdminCmsAccess::abortUnlessCanViewStore($complaint->store);
        }
    }

    private function fundingPreview(Complaint $complaint): array
    {
        $orderAmount = max(0, (float) ($complaint->order?->final_amount ?? $complaint->order?->total_amount ?? 0));
        $alreadyRefunded = Schema::hasTable('order_returns')
            ? (float) DB::table('order_returns')->where('order_id', (string) $complaint->order_id)->where('status', 'refunded')->sum('refund_amount_final')
            : 0.0;
        $amount = max(0, $orderAmount - $alreadyRefunded);
        $wallet = max(0, (float) ($complaint->store?->wallet_balance ?? 0));
        $walletDebit = min($wallet, $amount);
        $remaining = max(0, $amount - $walletDebit);
        $pendingSettlement = Schema::hasTable('financial_reconciliations')
            ? max(0, (float) DB::table('financial_reconciliations')
                ->where('store_id', (string) $complaint->store_id)
                ->whereIn(DB::raw("LOWER(COALESCE(status, ''))"), ['pending_shop', 'waiting_payment', 'pending', 'pending_settlement', 'draft', 'confirmed'])
                ->sum('net_amount'))
            : 0.0;
        $settlementDebit = min($pendingSettlement, $remaining);

        return [
            'refund_amount' => $amount,
            'wallet_balance' => $wallet,
            'wallet_debit' => $walletDebit,
            'pending_settlement_offset' => $settlementDebit,
            'platform_advance' => max(0, $remaining - $settlementDebit),
            'credit_limit' => max(0, (float) ($complaint->store?->credit_limit ?? 0)),
            'ticket_debt' => max(0, (float) ($complaint->store?->ticket_debt ?? 0)),
        ];
    }

    private function notifyCustomer(Complaint $complaint, string $type, string $title, string $message): void
    {
        if (! Schema::hasTable('notifications') || empty($complaint->user_id)) {
            return;
        }

        $payload = [
            'user_id' => (string) $complaint->user_id,
            'type' => $type,
            'title' => $title,
            'body' => $message,
            'message' => $message,
            'action_url' => '/profile/complaints',
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $payload = collect($payload)
            ->filter(fn ($value, string $column): bool => Schema::hasColumn('notifications', $column))
            ->all();

        $id = null;
        if ($payload !== []) {
            try {
                $id = DB::table('notifications')->insertGetId($payload);
            } catch (\Throwable $e) {
                // Fallback to basic insert if auto-increment is not standard
                DB::table('notifications')->insert($payload);
            }
        }

        if ($id) {
            try {
                event(new \App\Events\Customer\NotificationCreatedEvent((string) $complaint->user_id, [
                    'notification_id' => (string) $id,
                    'id' => (string) $id,
                    'user_id' => (string) $complaint->user_id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $message,
                    'message' => $message,
                    'display' => [
                        'title' => $title,
                        'body' => $message,
                    ],
                    'deep_link' => '/profile/complaints',
                    'action_url' => '/profile/complaints',
                    'created_at' => now()->toIso8601String(),
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Failed to broadcast complaint notification to customer: ' . $e->getMessage());
            }
        }
    }

    private function notifyStoreAdmin(Complaint $complaint, string $type, string $title, string $message): void
    {
        if (! Schema::hasTable('store_admin_notifications') || empty($complaint->store_id)) {
            return;
        }

        $notification = \App\Models\StoreAdminNotification::query()->create([
            'id' => (string) Str::uuid(),
            'store_id' => (string) $complaint->store_id,
            'order_id' => $complaint->order_id,
            'order_code' => $complaint->order?->order_code ?? '',
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => [
                'complaint_id' => (string) $complaint->id,
                'complaint_code' => $complaint->complaint_code,
            ],
            'is_read' => false,
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolutionErrorMessage(RuntimeException $e): string
    {
        return match ($e->getMessage()) {
            'COMPLAINT_STATE_CONFLICT' => 'Khiếu nại đã được xử lý bởi thao tác khác. Vui lòng tải lại trang.',
            'COMPLAINT_ORDER_NOT_REFUNDABLE' => 'Đơn hàng không còn số lượng có thể hoàn.',
            'COMPLAINT_RETURN_STATE_CONFLICT' => 'Đơn hoàn đang ở trạng thái không thể cưỡng chế.',
            default => 'Không thể xử lý khiếu nại ở trạng thái hiện tại.',
        };
    }
}
