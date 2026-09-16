<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\ComplaintMessageBroadcasted;
use App\Models\Complaint;
use App\Models\Store;
use App\Services\ComplaintResolutionService;
use App\Services\ComplaintStoreLockService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class StoreAdminComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('complaints')) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'page_size' => 20,
                    'total' => 0,
                    'total_pages' => 0,
                    'has_next' => false,
                    'filters' => [
                        'status' => null,
                        'q' => null,
                        'from' => null,
                        'to' => null,
                        'sort' => '-created_at',
                    ],
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        }

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string', 'in:created_at,-created_at,updated_at,-updated_at,severity,-severity,status,-status'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Du lieu dau vao khong hop le.',
                    'details' => $validator->errors()->toArray(),
                ]],
            ], 422);
        }

        $filters = $validator->validated();
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 20);
        $sort = (string) ($filters['sort'] ?? '-created_at');

        $query = DB::table('complaints as c');

        if (Schema::hasColumn('complaints', 'store_id')) {
            $query->where('c.store_id', $store->id);
        }

        if (!empty($filters['status']) && Schema::hasColumn('complaints', 'status')) {
            $query->whereRaw('LOWER(c.status) = ?', [mb_strtolower(trim((string) $filters['status']))]);
        }

        $hasComplaintCode = Schema::hasColumn('complaints', 'complaint_code');
        $hasComplaintTitle = Schema::hasColumn('complaints', 'title');

        if (!empty($filters['q']) && ($hasComplaintCode || $hasComplaintTitle)) {
            $keyword = trim((string) $filters['q']);
            $query->where(function (Builder $sub) use ($keyword): void {
                if (Schema::hasColumn('complaints', 'complaint_code')) {
                    $sub->orWhere('c.complaint_code', 'like', '%' . $keyword . '%');
                }
                if (Schema::hasColumn('complaints', 'title')) {
                    $sub->orWhere('c.title', 'like', '%' . $keyword . '%');
                }
            });
        }

        if (!empty($filters['from']) || !empty($filters['to'])) {
            $this->applyDateRange($query, $filters['from'] ?? null, $filters['to'] ?? null, ['created_at', 'updated_at']);
        }

        $canJoinUser = Schema::hasTable('users')
            && Schema::hasColumn('complaints', 'user_id')
            && Schema::hasColumn('users', 'id');

        if ($canJoinUser) {
            $query->leftJoin('users as u', 'u.id', '=', 'c.user_id');
        }

        $canJoinOrder = Schema::hasTable('orders')
            && Schema::hasColumn('complaints', 'order_id')
            && Schema::hasColumn('orders', 'id');

        if ($canJoinOrder) {
            $query->leftJoin('orders as o', 'o.id', '=', 'c.order_id');
        }

        $customerNameExpr = "''";
        if ($canJoinUser && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
        } elseif ($canJoinUser && Schema::hasColumn('users', 'full_name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
        } elseif ($canJoinUser && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
        }

        if (Schema::hasTable('complaint_messages') && Schema::hasColumn('complaint_messages', 'complaint_id')) {
            if (Schema::hasColumn('complaint_messages', 'is_read')) {
                $unreadSub = DB::table('complaint_messages as cm')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('cm.complaint_id', 'c.id')
                    ->where('cm.is_read', false);

                if (Schema::hasColumn('complaint_messages', 'is_from_admin')) {
                    $unreadSub->where('cm.is_from_admin', false);
                }

                $query->selectSub($unreadSub, 'unread_messages');
            }

            if (Schema::hasColumn('complaint_messages', 'message')) {
                $lastMessageSub = DB::table('complaint_messages as cm')
                    ->select('cm.message')
                    ->whereColumn('cm.complaint_id', 'c.id');

                if (Schema::hasColumn('complaint_messages', 'created_at')) {
                    $lastMessageSub->orderByDesc('cm.created_at');
                } else {
                    $lastMessageSub->orderByDesc('cm.id');
                }

                $query->selectSub($lastMessageSub->limit(1), 'latest_message');
            }

            if (Schema::hasColumn('complaint_messages', 'created_at')) {
                $lastMessageAtSub = DB::table('complaint_messages as cm')
                    ->select('cm.created_at')
                    ->whereColumn('cm.complaint_id', 'c.id')
                    ->orderByDesc('cm.created_at')
                    ->limit(1);

                $query->selectSub($lastMessageAtSub, 'latest_message_at');
            }
        }

        $this->applySort($query, $sort);
        $total = (int) (clone $query)->count('c.id');

        $rows = $query->forPage($page, $pageSize)->addSelect([
            'c.id',
            DB::raw(Schema::hasColumn('complaints', 'complaint_code') ? 'c.complaint_code' : 'NULL as complaint_code'),
            DB::raw(Schema::hasColumn('complaints', 'complaint_type') ? 'c.complaint_type' : 'NULL as complaint_type'),
            DB::raw(Schema::hasColumn('complaints', 'severity') ? 'c.severity' : 'NULL as severity'),
            DB::raw(Schema::hasColumn('complaints', 'title') ? 'c.title' : 'NULL as title'),
            DB::raw(Schema::hasColumn('complaints', 'refund_bank_info') ? 'c.refund_bank_info' : 'NULL as refund_bank_info'),
            DB::raw(Schema::hasColumn('complaints', 'status') ? 'c.status' : 'NULL as status'),
            DB::raw(Schema::hasColumn('complaints', 'order_id') ? 'c.order_id' : 'NULL as order_id'),
            DB::raw(Schema::hasColumn('complaints', 'created_at') ? 'c.created_at' : 'NULL as created_at'),
            DB::raw(Schema::hasColumn('complaints', 'updated_at') ? 'c.updated_at' : 'NULL as updated_at'),
            DB::raw($customerNameExpr . ' as customer_name'),
            DB::raw($canJoinOrder && Schema::hasColumn('orders', 'order_code') ? 'o.order_code' : 'NULL as order_code'),
        ])->get();

        $data = $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'complaint_code' => $row->complaint_code ?? null,
                'complaint_type' => $row->complaint_type ?? null,
                'severity' => $row->severity ?? null,
                'title' => $row->title ?? null,
                'refund_bank_info' => $this->decodeJsonObject($row->refund_bank_info ?? null),
                'status' => $row->status ?? null,
                'order' => [
                    'id' => $row->order_id ?? null,
                    'order_code' => $row->order_code ?? null,
                ],
                'customer' => [
                    'name' => $row->customer_name ?: null,
                ],
                'latest_message' => $row->latest_message ?? null,
                'latest_message_at' => $this->toIso($row->latest_message_at ?? null),
                'unread_messages' => isset($row->unread_messages) ? (int) $row->unread_messages : 0,
                'created_at' => $this->toIso($row->created_at ?? null),
                'updated_at' => $this->toIso($row->updated_at ?? null),
            ];
        })->values()->all();

        $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

        return response()->json([
            'data' => $data,
            'meta' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'filters' => [
                    'status' => $filters['status'] ?? null,
                    'q' => $filters['q'] ?? null,
                    'from' => $filters['from'] ?? null,
                    'to' => $filters['to'] ?? null,
                    'sort' => $sort,
                ],
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('complaints')) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai hoac khieu nai khong thuoc cua hang hien tai.', 404, true, true);
        }

        $canScopeByComplaintStore = Schema::hasColumn('complaints', 'store_id');
        $canScopeByOrderStore = Schema::hasTable('orders')
            && Schema::hasColumn('complaints', 'order_id')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');

        if (!$canScopeByComplaintStore && !$canScopeByOrderStore) {
            return $this->errorResponse('COMPLAINT_ACCESS_DENIED', 'Khong the xac minh quyen truy cap khieu nai cua cua hang hien tai.', 403, true, true);
        }

        $query = DB::table('complaints as c')->where('c.id', $id);

        if ($canScopeByOrderStore) {
            $query->leftJoin('orders as o', 'o.id', '=', 'c.order_id');
        }

        if (Schema::hasTable('users') && Schema::hasColumn('complaints', 'user_id') && Schema::hasColumn('users', 'id')) {
            $query->leftJoin('users as u', 'u.id', '=', 'c.user_id');
        }

        $canJoinRefund = Schema::hasTable('refunds')
            && Schema::hasColumn('complaints', 'refund_id')
            && Schema::hasColumn('refunds', 'id');

        if ($canJoinRefund) {
            $query->leftJoin('refunds as r', 'r.id', '=', 'c.refund_id');
        }

        if ($canScopeByComplaintStore) {
            $query->where('c.store_id', $store->id);
        } elseif ($canScopeByOrderStore) {
            $query->where('o.store_id', $store->id);
        }

        $customerNameExpr = "''";
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), NULLIF(u.name, ''), '')";
        } elseif (Schema::hasTable('users') && Schema::hasColumn('users', 'full_name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.full_name, ''), '')";
        } elseif (Schema::hasTable('users') && Schema::hasColumn('users', 'name')) {
            $customerNameExpr = "COALESCE(NULLIF(u.name, ''), '')";
        }

        $complaint = $query->select([
            'c.id',
            DB::raw(Schema::hasColumn('complaints', 'complaint_code') ? 'c.complaint_code' : 'NULL as complaint_code'),
            DB::raw(Schema::hasColumn('complaints', 'complaint_type') ? 'c.complaint_type' : 'NULL as complaint_type'),
            DB::raw(Schema::hasColumn('complaints', 'severity') ? 'c.severity' : 'NULL as severity'),
            DB::raw(Schema::hasColumn('complaints', 'title') ? 'c.title' : 'NULL as title'),
            DB::raw(Schema::hasColumn('complaints', 'description') ? 'c.description' : 'NULL as description'),
            DB::raw(Schema::hasColumn('complaints', 'images') ? 'c.images' : 'NULL as images'),
            DB::raw(Schema::hasColumn('complaints', 'refund_bank_info') ? 'c.refund_bank_info' : 'NULL as refund_bank_info'),
            DB::raw(Schema::hasColumn('complaints', 'status') ? 'c.status' : 'NULL as status'),
            DB::raw(Schema::hasColumn('complaints', 'user_id') ? 'c.user_id' : 'NULL as user_id'),
            DB::raw(Schema::hasColumn('complaints', 'order_id') ? 'c.order_id' : 'NULL as order_id'),
            DB::raw(Schema::hasColumn('complaints', 'resolution_note') ? 'c.resolution_note' : 'NULL as resolution_note'),
            DB::raw(Schema::hasColumn('complaints', 'resolution_type') ? 'c.resolution_type' : 'NULL as resolution_type'),
            DB::raw(Schema::hasColumn('complaints', 'refund_requested') ? 'c.refund_requested' : 'NULL as refund_requested'),
            DB::raw(Schema::hasColumn('complaints', 'refund_id') ? 'c.refund_id' : 'NULL as refund_id'),
            DB::raw(Schema::hasColumn('complaints', 'resolved_at') ? 'c.resolved_at' : 'NULL as resolved_at'),
            DB::raw(Schema::hasColumn('complaints', 'created_at') ? 'c.created_at' : 'NULL as created_at'),
            DB::raw(Schema::hasColumn('complaints', 'updated_at') ? 'c.updated_at' : 'NULL as updated_at'),
            DB::raw($customerNameExpr . ' as customer_name'),
            DB::raw(Schema::hasTable('users') && Schema::hasColumn('users', 'avatar_url') ? 'u.avatar_url' : 'NULL as customer_avatar_url'),
            DB::raw(Schema::hasTable('users') && Schema::hasColumn('users', 'phone') ? 'u.phone' : 'NULL as customer_phone'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'order_code') ? 'o.order_code' : 'NULL as order_code'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'status') ? 'o.status as order_status' : 'NULL as order_status'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'delivery_phone') ? 'o.delivery_phone' : 'NULL as delivery_phone'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'phone') ? 'o.phone as order_phone' : 'NULL as order_phone'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'delivery_address') ? 'o.delivery_address' : 'NULL as delivery_address'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'address') ? 'o.address as order_address' : 'NULL as order_address'),
            DB::raw($canScopeByOrderStore && Schema::hasColumn('orders', 'created_at') ? 'o.created_at as order_created_at' : 'NULL as order_created_at'),
            DB::raw($canJoinRefund && Schema::hasColumn('refunds', 'refund_code') ? 'r.refund_code' : 'NULL as refund_code'),
            DB::raw($canJoinRefund && Schema::hasColumn('refunds', 'refund_type') ? 'r.refund_type' : 'NULL as refund_type'),
            DB::raw($canJoinRefund && Schema::hasColumn('refunds', 'old_status') ? 'r.old_status as refund_old_status' : 'NULL as refund_old_status'),
            DB::raw($canJoinRefund && Schema::hasColumn('refunds', 'new_status') ? 'r.new_status as refund_new_status' : 'NULL as refund_new_status'),
            DB::raw($canJoinRefund && Schema::hasColumn('refunds', 'processed_at') ? 'r.processed_at' : 'NULL as refund_processed_at'),
        ])->first();

        if (!$complaint) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai hoac khieu nai khong thuoc cua hang hien tai.', 404, true, true);
        }

        $messages = $this->loadComplaintMessages((string) $complaint->id);
        $orderItems = $this->loadOrderItems((string) ($complaint->order_id ?? ''));
        $customerPhone = trim((string) ($complaint->delivery_phone ?? $complaint->order_phone ?? $complaint->customer_phone ?? ''));
        $deliveryAddress = trim((string) ($complaint->delivery_address ?? $complaint->order_address ?? ''));
        $resolutionType = trim((string) ($complaint->resolution_type ?? ''));
        $orderReturn = Schema::hasTable('order_returns') && Schema::hasColumn('order_returns', 'complaint_id')
            ? DB::table('order_returns')->where('complaint_id', (string) $complaint->id)->first()
            : null;
        $hasComplaintStoreLock = app(ComplaintStoreLockService::class)->hasActiveLock((string) $complaint->id);
        $canAcceptReturn = strtolower((string) ($complaint->status ?? '')) === 'awaiting_store_action'
            && (
                ($resolutionType === 'return_requested' && $orderReturn && strtolower((string) $orderReturn->status) === 'pending')
                || ($resolutionType === 'store_locked' && $hasComplaintStoreLock)
            );
        $sideEffects = $this->buildComplaintSideEffects((string) $complaint->id, $orderReturn);

        return response()->json([
            'data' => [
                'id' => $complaint->id,
                'complaint_code' => $complaint->complaint_code ?? null,
                'complaint_type' => $complaint->complaint_type ?? null,
                'severity' => $complaint->severity ?? null,
                'title' => $complaint->title ?? null,
                'description' => $complaint->description ?? null,
                'images' => $this->decodeImages($complaint->images ?? null),
                'refund_bank_info' => $this->decodeJsonObject($complaint->refund_bank_info ?? null),
                'status' => $complaint->status ?? null,
                'resolution_note' => $complaint->resolution_note ?? null,
                'resolution_type' => $resolutionType !== '' ? $resolutionType : null,
                'refund_requested' => isset($complaint->refund_requested) ? (bool) $complaint->refund_requested : false,
                'customer' => [
                    'id' => $complaint->user_id ?? null,
                    'name' => $complaint->customer_name ?: null,
                    'avatar_url' => $complaint->customer_avatar_url ?? null,
                    'phone' => $customerPhone !== '' ? $customerPhone : null,
                    'phone_masked' => $this->maskPhone($customerPhone !== '' ? $customerPhone : null),
                    'delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
                ],
                'order' => [
                    'id' => $complaint->order_id ?? null,
                    'order_code' => $complaint->order_code ?? null,
                    'status' => $complaint->order_status ?? null,
                    'created_at' => $this->toIso($complaint->order_created_at ?? null),
                    'delivery_phone' => $customerPhone !== '' ? $customerPhone : null,
                    'delivery_address' => $deliveryAddress !== '' ? $deliveryAddress : null,
                    'items' => $orderItems,
                ],
                'capabilities' => [
                    'is_read_only' => true,
                    'can_update_status' => false,
                    'can_reply' => false,
                    'can_request_refund' => false,
                    'can_accept_return' => $canAcceptReturn,
                    'accept_return_mode' => $canAcceptReturn
                        ? ($resolutionType === 'store_locked' ? 'accept_forced_refund' : 'approve_return')
                        : null,
                    'is_locked_by_complaint' => $hasComplaintStoreLock,
                ],
                'side_effects' => $sideEffects,
                'refund' => [
                    'id' => $complaint->refund_id ?? null,
                    'refund_code' => $complaint->refund_code ?? null,
                    'refund_type' => $complaint->refund_type ?? null,
                    'old_status' => $complaint->refund_old_status ?? null,
                    'new_status' => $complaint->refund_new_status ?? null,
                    'processed_at' => $this->toIso($complaint->refund_processed_at ?? null),
                ],
                'messages' => $messages,
                'resolved_at' => $this->toIso($complaint->resolved_at ?? null),
                'created_at' => $this->toIso($complaint->created_at ?? null),
                'updated_at' => $this->toIso($complaint->updated_at ?? null),
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        if (!Schema::hasTable('complaints')) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai.', 404, true, true);
        }

        $canScopeByComplaintStore = Schema::hasColumn('complaints', 'store_id');
        $canScopeByOrderStore = Schema::hasTable('orders')
            && Schema::hasColumn('complaints', 'order_id')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');

        if (!$canScopeByComplaintStore && !$canScopeByOrderStore) {
            return $this->errorResponse('COMPLAINT_ACCESS_DENIED', 'Khong the xac minh quyen truy cap khieu nai cua cua hang hien tai.', 403, true, true);
        }

        $complaintQuery = DB::table('complaints as c')->where('c.id', $id);
        if ($canScopeByComplaintStore) {
            $complaintQuery->where('c.store_id', $store->id);
        } else {
            $complaintQuery->join('orders as o', 'o.id', '=', 'c.order_id')
                ->where('o.store_id', $store->id);
        }

        $complaint = $complaintQuery->first(['c.id']);
        if (!$complaint) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai hoac khieu nai khong thuoc cua hang hien tai.', 404, true, true);
        }

        $updated = 0;
        if (
            Schema::hasTable('complaint_messages') &&
            Schema::hasColumn('complaint_messages', 'complaint_id') &&
            Schema::hasColumn('complaint_messages', 'is_read')
        ) {
            $messageQuery = DB::table('complaint_messages')
                ->where('complaint_id', $id)
                ->where('is_read', false);

            if (Schema::hasColumn('complaint_messages', 'is_from_admin')) {
                $messageQuery->where('is_from_admin', false);
            }

            $updated = (int) $messageQuery->update(['is_read' => true]);
        }

        return response()->json([
            'data' => [
                'id' => $id,
                'updated' => $updated,
                'unread_messages' => 0,
            ],
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [],
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        return $this->errorResponse(
            'STORE_COMPLAINT_READ_ONLY',
            'Cửa hàng chỉ có quyền xem khiếu nại. Trạng thái do Super Admin xử lý.',
            403,
            true,
            true
        );

        if (!Schema::hasTable('complaints')) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['required', 'string', 'in:pending,processing,resolved,closed'],
            'resolution_note' => ['nullable', 'string', 'max:2000', 'required_if:status,resolved,closed'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Du lieu dau vao khong hop le.',
                    'details' => $validator->errors()->toArray(),
                ]],
            ], 422);
        }

        $validated = $validator->validated();
        $newStatus = mb_strtolower(trim((string) $validated['status']));
        $resolutionNote = trim((string) ($validated['resolution_note'] ?? ''));

        $canScopeByComplaintStore = Schema::hasColumn('complaints', 'store_id');
        $canScopeByOrderStore = Schema::hasTable('orders')
            && Schema::hasColumn('complaints', 'order_id')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');

        if (!$canScopeByComplaintStore && !$canScopeByOrderStore) {
            return $this->errorResponse('COMPLAINT_ACCESS_DENIED', 'Khong the xac minh quyen truy cap khieu nai cua cua hang hien tai.', 403, true, true);
        }

        try {
            DB::beginTransaction();

            $complaint = DB::table('complaints')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$complaint) {
                DB::rollBack();
                return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai.', 404, true, true);
            }

            $isOwnedByStore = false;
            if ($canScopeByComplaintStore) {
                $isOwnedByStore = (string) ($complaint->store_id ?? '') === (string) $store->id;
            } elseif ($canScopeByOrderStore) {
                $orderStoreId = DB::table('orders')
                    ->where('id', $complaint->order_id ?? null)
                    ->value('store_id');
                $isOwnedByStore = (string) ($orderStoreId ?? '') === (string) $store->id;
            }

            if (!$isOwnedByStore) {
                DB::rollBack();
                return $this->errorResponse('COMPLAINT_FORBIDDEN', 'Khong co quyen cap nhat khieu nai nay.', 403, true, true);
            }

            $oldStatus = mb_strtolower(trim((string) ($complaint->status ?? 'pending')));

            $updatePayload = [];
            if (Schema::hasColumn('complaints', 'status')) {
                $updatePayload['status'] = $newStatus;
            }
            if (Schema::hasColumn('complaints', 'updated_at')) {
                $updatePayload['updated_at'] = now();
            }

            if (in_array($newStatus, ['resolved', 'closed'], true)) {
                if (Schema::hasColumn('complaints', 'resolved_by')) {
                    $updatePayload['resolved_by'] = $user->id;
                }
                if (Schema::hasColumn('complaints', 'resolved_at')) {
                    $updatePayload['resolved_at'] = now();
                }
                if (Schema::hasColumn('complaints', 'resolution_note')) {
                    $updatePayload['resolution_note'] = $resolutionNote;
                }
            } else {
                if (Schema::hasColumn('complaints', 'resolved_by')) {
                    $updatePayload['resolved_by'] = null;
                }
                if (Schema::hasColumn('complaints', 'resolved_at')) {
                    $updatePayload['resolved_at'] = null;
                }
                if ($resolutionNote !== '' && Schema::hasColumn('complaints', 'resolution_note')) {
                    $updatePayload['resolution_note'] = $resolutionNote;
                }
            }

            if (!empty($updatePayload)) {
                DB::table('complaints')->where('id', $id)->update($updatePayload);
            }

            // NOTE: Unlock tickets only when complaint is fully CLOSED by customer confirmation.
            // When status = 'resolved', tickets remain LOCKED until customer confirms by setting status = 'closed'.
            // This logic is now handled in ClientComplaintController.updateStatus() for customer confirmation.
            // Here, we only store the resolution info but keep tickets locked.

            if (Schema::hasTable('complaint_messages')) {
                $messagePayload = [];
                if (Schema::hasColumn('complaint_messages', 'id')) {
                    $messagePayload['id'] = (string) \Illuminate\Support\Str::uuid();
                }
                if (Schema::hasColumn('complaint_messages', 'complaint_id')) {
                    $messagePayload['complaint_id'] = $id;
                }
                if (Schema::hasColumn('complaint_messages', 'sender_id')) {
                    $messagePayload['sender_id'] = $user->id;
                }
                if (Schema::hasColumn('complaint_messages', 'sender_type')) {
                    $messagePayload['sender_type'] = 'system';
                }
                if (Schema::hasColumn('complaint_messages', 'message')) {
                    if ($newStatus === 'processing') {
                        $systemMessage = 'Đơn đã được xác nhận';
                    } elseif ($newStatus === 'resolved') {
                        $systemMessage = 'Đơn khiếu nại đã được xử lý. Vui lòng xác nhận để mở khóa vé.';
                    } elseif ($newStatus === 'closed') {
                        $systemMessage = 'Khiếu nại đã đóng, vé đã được mở khóa.';
                    } else {
                        $systemMessage = 'Trang thai khieu nai da duoc cap nhat tu ' . $oldStatus . ' sang ' . $newStatus . '.';
                    }

                    if ($resolutionNote !== '') {
                        $systemMessage .= ' Ghi chu: ' . $resolutionNote;
                    }
                    $messagePayload['message'] = $systemMessage;
                }
                if (Schema::hasColumn('complaint_messages', 'attachments')) {
                    $messagePayload['attachments'] = json_encode([]);
                }
                if (Schema::hasColumn('complaint_messages', 'is_from_admin')) {
                    $messagePayload['is_from_admin'] = true;
                }
                if (Schema::hasColumn('complaint_messages', 'is_read')) {
                    $messagePayload['is_read'] = false;
                }
                if (Schema::hasColumn('complaint_messages', 'created_at')) {
                    $messagePayload['created_at'] = now();
                }

                if (!empty($messagePayload)) {
                    DB::table('complaint_messages')->insert($messagePayload);
                }
            }

            $updated = DB::table('complaints')->where('id', $id)->first();

            DB::commit();

            Event::dispatch('complaint.updated', [
                'complaint_id' => $id,
                'store_id' => $store->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_by' => $user->id,
                'changed_at' => now()->toIso8601String(),
            ]);

            return response()->json([
                'data' => [
                    'id' => $updated->id ?? $id,
                    'complaint_code' => $updated->complaint_code ?? null,
                    'status' => $updated->status ?? $newStatus,
                    'resolved_by' => $updated->resolved_by ?? null,
                    'resolved_at' => $this->toIso($updated->resolved_at ?? null),
                    'resolution_note' => $updated->resolution_note ?? null,
                    'message' => 'Cap nhat trang thai khieu nai thanh cong.',
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('COMPLAINT_STATUS_UPDATE_FAILED', 'Khong the cap nhat trang thai khieu nai vao luc nay.', 500, true, true);
        }
    }

    public function storeMessage(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        return $this->errorResponse(
            'STORE_COMPLAINT_READ_ONLY',
            'Cửa hàng chỉ có quyền xem và không thể gửi phản hồi trực tiếp trong khiếu nại.',
            403,
            true,
            true
        );

        if (!Schema::hasTable('complaints')) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:3000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Du lieu dau vao khong hop le.',
                    'details' => $validator->errors()->toArray(),
                ]],
            ], 422);
        }

        $validated = $validator->validated();
        $messageText = trim((string) $validated['message']);

        $canScopeByComplaintStore = Schema::hasColumn('complaints', 'store_id');
        $canScopeByOrderStore = Schema::hasTable('orders')
            && Schema::hasColumn('complaints', 'order_id')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');

        if (!$canScopeByComplaintStore && !$canScopeByOrderStore) {
            return $this->errorResponse('COMPLAINT_ACCESS_DENIED', 'Khong the xac minh quyen truy cap khieu nai cua cua hang hien tai.', 403, true, true);
        }

        $complaintQuery = DB::table('complaints as c')->where('c.id', $id);
        if ($canScopeByComplaintStore) {
            $complaintQuery->where('c.store_id', $store->id);
        } else {
            $complaintQuery->join('orders as o', 'o.id', '=', 'c.order_id')
                ->where('o.store_id', $store->id);
        }

        $complaint = $complaintQuery->first(['c.id']);
        if (!$complaint) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai hoac khieu nai khong thuoc cua hang hien tai.', 404, true, true);
        }

        if (!Schema::hasTable('complaint_messages')) {
            return $this->errorResponse('COMPLAINT_MESSAGE_NOT_SUPPORTED', 'He thong tin nhan khieu nai chua san sang.', 503, true, true);
        }

        $now = now();
        $messagePayload = [];
        if (Schema::hasColumn('complaint_messages', 'id')) {
            $messagePayload['id'] = (string) Str::uuid();
        }
        if (Schema::hasColumn('complaint_messages', 'complaint_id')) {
            $messagePayload['complaint_id'] = $id;
        }
        if (Schema::hasColumn('complaint_messages', 'sender_id')) {
            $messagePayload['sender_id'] = $user->id;
        }
        if (Schema::hasColumn('complaint_messages', 'sender_type')) {
            $messagePayload['sender_type'] = 'admin';
        }
        if (Schema::hasColumn('complaint_messages', 'message')) {
            $messagePayload['message'] = $messageText;
        }
        if (Schema::hasColumn('complaint_messages', 'attachments')) {
            $messagePayload['attachments'] = json_encode([]);
        }
        if (Schema::hasColumn('complaint_messages', 'is_from_admin')) {
            $messagePayload['is_from_admin'] = true;
        }
        if (Schema::hasColumn('complaint_messages', 'is_read')) {
            $messagePayload['is_read'] = false;
        }
        if (Schema::hasColumn('complaint_messages', 'created_at')) {
            $messagePayload['created_at'] = $now;
        }

        if (!empty($messagePayload)) {
            DB::table('complaint_messages')->insert($messagePayload);
        }

        if (Schema::hasColumn('complaints', 'updated_at')) {
            DB::table('complaints')->where('id', $id)->update(['updated_at' => $now]);
        }

        event(new ComplaintMessageBroadcasted((string) $id, [
            'id' => isset($messagePayload['id']) ? (string) $messagePayload['id'] : null,
            'complaint_id' => (string) $id,
            'sender_id' => (string) $user->id,
            'sender_type' => 'admin',
            'sender' => 'admin',
            'is_from_admin' => true,
            'sender_name' => trim((string) ($user->full_name ?? $user->name ?? 'Admin store')),
            'message' => $messageText,
            'created_at' => $now->toIso8601String(),
        ]));

        return response()->json([
            'data' => [
                'id' => $messagePayload['id'] ?? null,
                'complaint_id' => $id,
                'sender_id' => $user->id,
                'sender_type' => 'admin',
                'message' => $messageText,
                'is_from_admin' => true,
                'is_read' => false,
                'created_at' => $now->toIso8601String(),
            ],
            'message' => 'Gui phan hoi thanh cong.',
            'meta' => [
                'authenticated' => true,
                'has_store' => true,
                'request_at' => $now->toIso8601String(),
            ],
            'errors' => [],
        ], 201);
    }

    public function requestTicketRefund(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        return $this->errorResponse(
            'STORE_COMPLAINT_READ_ONLY',
            'Cửa hàng không thể tự tạo đề xuất hoàn từ màn hình khiếu nại.',
            403,
            true,
            true
        );

        if (!Schema::hasTable('complaints')) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai.', 404, true, true);
        }

        $validator = Validator::make($request->all(), [
            'reason_code' => ['nullable', 'string', 'max:50'],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [[
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Du lieu dau vao khong hop le.',
                    'details' => $validator->errors()->toArray(),
                ]],
            ], 422);
        }

        $validated = $validator->validated();
        $reasonCode = strtoupper(trim((string) ($validated['reason_code'] ?? 'COMPLAINT_COMPENSATION')));
        $reasonText = trim((string) ($validated['reason_text'] ?? 'Cua hang da gui de xuat hoan ve len he thong Ecoluck.'));

        $canScopeByComplaintStore = Schema::hasColumn('complaints', 'store_id');
        $canScopeByOrderStore = Schema::hasTable('orders')
            && Schema::hasColumn('complaints', 'order_id')
            && Schema::hasColumn('orders', 'id')
            && Schema::hasColumn('orders', 'store_id');

        if (!$canScopeByComplaintStore && !$canScopeByOrderStore) {
            return $this->errorResponse('COMPLAINT_ACCESS_DENIED', 'Khong the xac minh quyen truy cap khieu nai cua cua hang hien tai.', 403, true, true);
        }

        if (!Schema::hasTable('order_tickets') || !Schema::hasColumn('order_tickets', 'order_id')) {
            return $this->errorResponse('ORDER_TICKETS_NOT_FOUND', 'Khong tim thay thong tin ve da su dung cho don hang lien quan.', 422, true, true);
        }

        try {
            DB::beginTransaction();

            $complaint = DB::table('complaints')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$complaint) {
                DB::rollBack();
                return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Khong tim thay khieu nai.', 404, true, true);
            }

            $isOwnedByStore = false;
            if ($canScopeByComplaintStore) {
                $isOwnedByStore = (string) ($complaint->store_id ?? '') === (string) $store->id;
            } elseif ($canScopeByOrderStore) {
                $orderStoreId = DB::table('orders')
                    ->where('id', $complaint->order_id ?? null)
                    ->value('store_id');
                $isOwnedByStore = (string) ($orderStoreId ?? '') === (string) $store->id;
            }

            if (!$isOwnedByStore) {
                DB::rollBack();
                return $this->errorResponse('COMPLAINT_FORBIDDEN', 'Khong co quyen gui de xuat hoan ve cho khieu nai nay.', 403, true, true);
            }

            $currentStatus = mb_strtolower(trim((string) ($complaint->status ?? 'pending')));
            if ($currentStatus === 'resolved') {
                DB::rollBack();
                return $this->errorResponse('INVALID_COMPLAINT_STATUS', 'Khieu nai da o trang thai resolved, khong the gui de xuat hoan ve.', 422, true, true, [
                    'current_status' => $complaint->status,
                ]);
            }

            $isRequested = (bool) ($complaint->refund_requested ?? false);
            if ($isRequested) {
                DB::rollBack();
                return response()->json([
                    'data' => [
                        'complaint_id' => $id,
                        'refund_requested' => true,
                        'status' => 'PENDING_APPROVAL',
                        'idempotent' => true,
                        'message' => 'Yeu cau hoan ve da duoc gui truoc do.',
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            $orderId = $complaint->order_id ?? null;
            if ($orderId === null) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khieu nai khong co don hang lien quan.', 422, true, true);
            }

            $orderExists = Schema::hasTable('orders')
                ? DB::table('orders')->where('id', $orderId)->exists()
                : false;
            if (!$orderExists) {
                DB::rollBack();
                return $this->errorResponse('ORDER_NOT_FOUND', 'Khong tim thay don hang lien quan cua khieu nai.', 422, true, true);
            }

            $ticketRows = DB::table('order_tickets')
                ->where('order_id', $orderId)
                ->select([
                    DB::raw(Schema::hasColumn('order_tickets', 'ticket_id') ? 'ticket_id' : 'NULL as ticket_id'),
                    DB::raw(Schema::hasColumn('order_tickets', 'user_id') ? 'user_id' : 'NULL as user_id'),
                ])
                ->get();

            $ticketId = $ticketRows->pluck('ticket_id')->filter()->first();
            $refundUserId = $complaint->user_id ?? $ticketRows->pluck('user_id')->filter()->first();

            if (!$ticketId) {
                DB::rollBack();
                return $this->errorResponse('ORDER_TICKETS_NOT_FOUND', 'Don hang khong co ve da su dung de de xuat hoan.', 422, true, true);
            }

            $existingRefund = null;
            if (Schema::hasTable('refunds') && Schema::hasColumn('refunds', 'source_type') && Schema::hasColumn('refunds', 'source_id')) {
                $existingRefund = DB::table('refunds')
                    ->whereRaw('LOWER(source_type) = ?', ['complaint'])
                    ->where('source_id', $id)
                    ->orderByDesc(Schema::hasColumn('refunds', 'created_at') ? 'created_at' : 'id')
                    ->first();
            }

            if ($existingRefund) {
                DB::table('complaints')->where('id', $id)->update(array_filter([
                    Schema::hasColumn('complaints', 'refund_requested') ? 'refund_requested' : null => true,
                    Schema::hasColumn('complaints', 'refund_id') ? 'refund_id' : null => $existingRefund->id ?? null,
                    Schema::hasColumn('complaints', 'updated_at') ? 'updated_at' : null => now(),
                ], static fn ($value, $key) => $key !== null, ARRAY_FILTER_USE_BOTH));

                DB::commit();

                return response()->json([
                    'data' => [
                        'complaint_id' => $id,
                        'refund_requested' => true,
                        'refund_id' => $existingRefund->id ?? null,
                        'refund_code' => $existingRefund->refund_code ?? null,
                        'status' => $existingRefund->new_status ?? 'PENDING_APPROVAL',
                        'idempotent' => true,
                        'message' => 'Yeu cau hoan ve da ton tai truoc do.',
                    ],
                    'meta' => [
                        'authenticated' => true,
                        'has_store' => true,
                        'request_at' => now()->toIso8601String(),
                    ],
                    'errors' => [],
                ]);
            }

            $now = now();
            $refundId = null;
            $refundCode = null;

            if (Schema::hasTable('refunds')) {
                $refundId = Schema::hasColumn('refunds', 'id') ? (string) Str::uuid() : null;
                $refundCode = 'RF-CPL-' . now()->format('ymdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));

                $refundPayload = [];
                if ($refundId !== null) {
                    $refundPayload['id'] = $refundId;
                }
                if (Schema::hasColumn('refunds', 'refund_code')) {
                    $refundPayload['refund_code'] = $refundCode;
                }
                if (Schema::hasColumn('refunds', 'user_id')) {
                    $refundPayload['user_id'] = $refundUserId;
                }
                if (Schema::hasColumn('refunds', 'ticket_id')) {
                    $refundPayload['ticket_id'] = $ticketId;
                }
                if (Schema::hasColumn('refunds', 'refund_type')) {
                    $refundPayload['refund_type'] = 'complaint_compensation';
                }
                if (Schema::hasColumn('refunds', 'reason')) {
                    $refundPayload['reason'] = $reasonText;
                }
                if (Schema::hasColumn('refunds', 'reason_code')) {
                    $refundPayload['reason_code'] = $reasonCode;
                }
                if (Schema::hasColumn('refunds', 'old_status')) {
                    $refundPayload['old_status'] = $complaint->status;
                }
                if (Schema::hasColumn('refunds', 'new_status')) {
                    $refundPayload['new_status'] = 'PENDING_APPROVAL';
                }
                if (Schema::hasColumn('refunds', 'source_type')) {
                    $refundPayload['source_type'] = 'COMPLAINT';
                }
                if (Schema::hasColumn('refunds', 'source_id')) {
                    $refundPayload['source_id'] = $id;
                }
                if (Schema::hasColumn('refunds', 'processed_by')) {
                    $refundPayload['processed_by'] = $user->id;
                }
                if (Schema::hasColumn('refunds', 'processed_at')) {
                    $refundPayload['processed_at'] = $now;
                }
                if (Schema::hasColumn('refunds', 'metadata')) {
                    $refundPayload['metadata'] = json_encode([
                        'complaint_id' => $id,
                        'order_id' => $orderId,
                        'store_id' => $store->id,
                        'requested_by' => $user->id,
                    ], JSON_UNESCAPED_UNICODE);
                }
                if (Schema::hasColumn('refunds', 'created_at')) {
                    $refundPayload['created_at'] = $now;
                }

                if (!empty($refundPayload)) {
                    DB::table('refunds')->insert($refundPayload);
                }
            }

            $complaintUpdatePayload = [];
            if (Schema::hasColumn('complaints', 'refund_requested')) {
                $complaintUpdatePayload['refund_requested'] = true;
            }
            if (Schema::hasColumn('complaints', 'refund_id')) {
                $complaintUpdatePayload['refund_id'] = $refundId;
            }
            if (Schema::hasColumn('complaints', 'updated_at')) {
                $complaintUpdatePayload['updated_at'] = $now;
            }
            if (!empty($complaintUpdatePayload)) {
                DB::table('complaints')->where('id', $id)->update($complaintUpdatePayload);
            }

            if (Schema::hasTable('complaint_messages')) {
                $messagePayload = [];
                if (Schema::hasColumn('complaint_messages', 'id')) {
                    $messagePayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('complaint_messages', 'complaint_id')) {
                    $messagePayload['complaint_id'] = $id;
                }
                if (Schema::hasColumn('complaint_messages', 'sender_id')) {
                    $messagePayload['sender_id'] = $user->id;
                }
                if (Schema::hasColumn('complaint_messages', 'sender_type')) {
                    $messagePayload['sender_type'] = 'system';
                }
                if (Schema::hasColumn('complaint_messages', 'message')) {
                    $messagePayload['message'] = 'Cua hang da gui yeu cau hoan ve len he thong Ecoluck. Vui long cho duyet.';
                }
                if (Schema::hasColumn('complaint_messages', 'attachments')) {
                    $messagePayload['attachments'] = json_encode([]);
                }
                if (Schema::hasColumn('complaint_messages', 'is_from_admin')) {
                    $messagePayload['is_from_admin'] = true;
                }
                if (Schema::hasColumn('complaint_messages', 'is_read')) {
                    $messagePayload['is_read'] = false;
                }
                if (Schema::hasColumn('complaint_messages', 'created_at')) {
                    $messagePayload['created_at'] = $now;
                }
                if (!empty($messagePayload)) {
                    DB::table('complaint_messages')->insert($messagePayload);
                }
            }

            if (Schema::hasTable('notifications')) {
                $notificationPayload = [];
                if (Schema::hasColumn('notifications', 'id')) {
                    $notificationPayload['id'] = (string) Str::uuid();
                }
                if (Schema::hasColumn('notifications', 'user_id')) {
                    $superAdminId = null;
                    if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
                        $superAdminId = DB::table('users')
                            ->whereIn(DB::raw('LOWER(role)'), ['admin', 'super_admin'])
                            ->orderBy('created_at')
                            ->value('id');
                    }
                    $notificationPayload['user_id'] = $superAdminId;
                }
                if (Schema::hasColumn('notifications', 'store_id')) {
                    $notificationPayload['store_id'] = (string) $store->id;
                }
                if (Schema::hasColumn('notifications', 'audience_scope')) {
                    $notificationPayload['audience_scope'] = !empty($notificationPayload['user_id'] ?? null)
                        ? 'admin_private'
                        : 'admin_shared';
                }
                if (Schema::hasColumn('notifications', 'type')) {
                    $notificationPayload['type'] = 'refund_proposal';
                }
                if (Schema::hasColumn('notifications', 'title')) {
                    $notificationPayload['title'] = 'De xuat hoan ve moi tu Store Admin';
                }
                if (Schema::hasColumn('notifications', 'content')) {
                    $notificationPayload['content'] = 'Complaint ' . ($complaint->complaint_code ?? $id) . ' vua gui de xuat hoan ve cho khach hang.';
                }
                if (Schema::hasColumn('notifications', 'data')) {
                    $notificationPayload['data'] = json_encode([
                        'complaint_id' => $id,
                        'refund_id' => $refundId,
                        'refund_code' => $refundCode,
                        'store_id' => $store->id,
                    ], JSON_UNESCAPED_UNICODE);
                }
                if (Schema::hasColumn('notifications', 'is_read')) {
                    $notificationPayload['is_read'] = false;
                }
                if (Schema::hasColumn('notifications', 'created_at')) {
                    $notificationPayload['created_at'] = $now;
                }
                if (Schema::hasColumn('notifications', 'updated_at')) {
                    $notificationPayload['updated_at'] = $now;
                }

                $notificationPayload = array_filter(
                    $notificationPayload,
                    static fn ($value, $key) => $key !== 'user_id' || $value !== null,
                    ARRAY_FILTER_USE_BOTH
                );

                if (!empty($notificationPayload)) {
                    DB::table('notifications')->insert($notificationPayload);
                }
            }

            DB::commit();

            Event::dispatch('complaint.refund_requested', [
                'complaint_id' => $id,
                'complaint_code' => $complaint->complaint_code ?? null,
                'store_id' => $store->id,
                'user_id' => $refundUserId,
                'refund_id' => $refundId,
                'refund_code' => $refundCode,
                'status' => 'PENDING_APPROVAL',
                'requested_by' => $user->id,
                'requested_at' => $now->toIso8601String(),
            ]);

            return response()->json([
                'data' => [
                    'complaint_id' => $id,
                    'complaint_code' => $complaint->complaint_code ?? null,
                    'refund_requested' => true,
                    'refund' => [
                        'id' => $refundId,
                        'refund_code' => $refundCode,
                        'ticket_id' => $ticketId,
                        'refund_type' => 'complaint_compensation',
                        'status' => 'PENDING_APPROVAL',
                    ],
                    'message' => 'Gui de xuat hoan ve thanh cong.',
                ],
                'meta' => [
                    'authenticated' => true,
                    'has_store' => true,
                    'request_at' => now()->toIso8601String(),
                ],
                'errors' => [],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->errorResponse('REQUEST_TICKET_REFUND_FAILED', 'Khong the gui de xuat hoan ve vao luc nay.', 500, true, true);
        }
    }

    public function acceptReturn(Request $request, string $id): JsonResponse
    {
        [$store, $user, $error] = $this->resolveOwnedStoreContext($request);
        if ($error) {
            return $error;
        }

        $complaint = Complaint::query()
            ->whereKey($id)
            ->where('store_id', (string) $store->id)
            ->first();
        if (! $complaint) {
            return $this->errorResponse('COMPLAINT_NOT_FOUND', 'Không tìm thấy khiếu nại của cửa hàng.', 404, true, true);
        }

        try {
            $return = app(ComplaintResolutionService::class)
                ->acceptStoreAction($complaint, $user, (string) $store->id);
            $complaint = $complaint->fresh();

            return response()->json([
                'data' => [
                    'complaint_id' => (string) $complaint->id,
                    'complaint_status' => (string) ($complaint->status ?? ''),
                    'order_return_id' => (string) $return->id,
                    'order_return_status' => (string) $return->status,
                    'refund_processed' => $return->status === 'refunded',
                    'side_effects' => $this->buildComplaintSideEffects((string) $complaint->id, $return),
                ],
                'errors' => [],
            ]);
        } catch (RuntimeException $e) {
            $conflicts = [
                'COMPLAINT_STATE_CONFLICT',
                'COMPLAINT_ACTION_NOT_AVAILABLE',
                'COMPLAINT_RETURN_STATE_CONFLICT',
            ];

            return $this->errorResponse(
                $e->getMessage(),
                'Không thể chấp nhận hoàn hàng ở trạng thái hiện tại.',
                in_array($e->getMessage(), $conflicts, true) ? 409 : 422,
                true,
                true
            );
        }
    }

    private function buildComplaintSideEffects(string $complaintId, mixed $orderReturn = null): array
    {
        $ticketIds = [];
        if (
            $complaintId !== ''
            && Schema::hasTable('complaint_ticket_locks')
            && Schema::hasColumn('complaint_ticket_locks', 'complaint_id')
            && Schema::hasColumn('complaint_ticket_locks', 'ticket_id')
        ) {
            $ticketQuery = DB::table('complaint_ticket_locks')
                ->where('complaint_id', $complaintId);

            if (Schema::hasColumn('complaint_ticket_locks', 'release_reason')) {
                $ticketQuery->where('release_reason', 'refund_processed');
            } elseif (Schema::hasColumn('complaint_ticket_locks', 'released_at')) {
                $ticketQuery->whereNotNull('released_at');
            }

            $ticketIds = $ticketQuery
                ->pluck('ticket_id')
                ->map(fn ($id): string => (string) $id)
                ->filter(fn (string $id): bool => trim($id) !== '')
                ->unique()
                ->values()
                ->all();
        }

        $ticketFeeRefund = 0.0;
        $walletCredit = 0.0;
        $debtRepayment = 0.0;
        $returnId = trim((string) ($orderReturn->id ?? ''));
        if (
            $returnId !== ''
            && Schema::hasTable('store_ticket_transactions')
            && Schema::hasColumn('store_ticket_transactions', 'source_type')
            && Schema::hasColumn('store_ticket_transactions', 'source_id')
            && Schema::hasColumn('store_ticket_transactions', 'event_type')
            && Schema::hasColumn('store_ticket_transactions', 'amount_vnd')
            && Schema::hasColumn('store_ticket_transactions', 'wallet_delta')
            && Schema::hasColumn('store_ticket_transactions', 'debt_delta')
        ) {
            $ledger = DB::table('store_ticket_transactions')
                ->where('source_type', 'order_return')
                ->where('source_id', $returnId)
                ->where('event_type', 'RETURN_TICKET_REFUND')
                ->selectRaw('COALESCE(SUM(amount_vnd), 0) as amount_vnd, COALESCE(SUM(wallet_delta), 0) as wallet_delta, COALESCE(SUM(debt_delta), 0) as debt_delta')
                ->first();

            $ticketFeeRefund = round((float) ($ledger->amount_vnd ?? 0), 2);
            $walletCredit = round((float) ($ledger->wallet_delta ?? 0), 2);
            $debtRepayment = round(abs((float) ($ledger->debt_delta ?? 0)), 2);
        }

        return [
            'client_tickets_revoked' => count($ticketIds) > 0,
            'client_tickets_revoked_count' => count($ticketIds),
            'client_ticket_ids' => $ticketIds,
            'store_ticket_fee_refunded' => $ticketFeeRefund > 0,
            'store_ticket_fee_refund_amount' => $ticketFeeRefund,
            'store_wallet_credit' => $walletCredit,
            'store_debt_repayment' => $debtRepayment,
        ];
    }

    private function loadComplaintMessages(string $complaintId): array
    {
        if ($complaintId === '' || !Schema::hasTable('complaint_messages') || !Schema::hasColumn('complaint_messages', 'complaint_id')) {
            return [];
        }

        $query = DB::table('complaint_messages as cm')->where('cm.complaint_id', $complaintId);

        $canJoinSender = Schema::hasTable('users')
            && Schema::hasColumn('complaint_messages', 'sender_id')
            && Schema::hasColumn('users', 'id');

        if ($canJoinSender) {
            $query->leftJoin('users as su', 'su.id', '=', 'cm.sender_id');
        }

        if (Schema::hasColumn('complaint_messages', 'created_at')) {
            $query->orderBy('cm.created_at');
        } else {
            $query->orderBy('cm.id');
        }

        $senderNameExpr = "''";
        if ($canJoinSender && Schema::hasColumn('users', 'full_name') && Schema::hasColumn('users', 'name')) {
            $senderNameExpr = "COALESCE(NULLIF(su.full_name, ''), NULLIF(su.name, ''), '')";
        } elseif ($canJoinSender && Schema::hasColumn('users', 'full_name')) {
            $senderNameExpr = "COALESCE(NULLIF(su.full_name, ''), '')";
        } elseif ($canJoinSender && Schema::hasColumn('users', 'name')) {
            $senderNameExpr = "COALESCE(NULLIF(su.name, ''), '')";
        }

        $rows = $query->select([
            'cm.id',
            DB::raw(Schema::hasColumn('complaint_messages', 'sender_id') ? 'cm.sender_id' : 'NULL as sender_id'),
            DB::raw(Schema::hasColumn('complaint_messages', 'sender_type') ? 'cm.sender_type' : 'NULL as sender_type'),
            DB::raw(Schema::hasColumn('complaint_messages', 'message') ? 'cm.message' : 'NULL as message'),
            DB::raw(Schema::hasColumn('complaint_messages', 'attachments') ? 'cm.attachments' : 'NULL as attachments'),
            DB::raw(Schema::hasColumn('complaint_messages', 'is_from_admin') ? 'cm.is_from_admin' : 'NULL as is_from_admin'),
            DB::raw(Schema::hasColumn('complaint_messages', 'is_read') ? 'cm.is_read' : 'NULL as is_read'),
            DB::raw(Schema::hasColumn('complaint_messages', 'created_at') ? 'cm.created_at' : 'NULL as created_at'),
            DB::raw($senderNameExpr . ' as sender_name'),
            DB::raw($canJoinSender && Schema::hasColumn('users', 'avatar_url') ? 'su.avatar_url' : 'NULL as sender_avatar_url'),
        ])->get();

        return $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'message' => $row->message ?? null,
                'attachments' => $this->decodeImages($row->attachments ?? null),
                'is_from_admin' => isset($row->is_from_admin) ? (bool) $row->is_from_admin : false,
                'is_read' => isset($row->is_read) ? (bool) $row->is_read : false,
                'sender' => [
                    'id' => $row->sender_id ?? null,
                    'type' => $row->sender_type ?? null,
                    'name' => $row->sender_name ?: null,
                    'avatar_url' => $row->sender_avatar_url ?? null,
                ],
                'created_at' => $this->toIso($row->created_at ?? null),
            ];
        })->values()->all();
    }

    private function loadOrderItems(string $orderId): array
    {
        if ($orderId === '' || !Schema::hasTable('order_items')) {
            return [];
        }

        $hasItemName = Schema::hasColumn('order_items', 'item_name');
        $hasProductName = Schema::hasColumn('order_items', 'product_name');
        $hasQuantity = Schema::hasColumn('order_items', 'quantity');
        $hasUnitPrice = Schema::hasColumn('order_items', 'unit_price');
        $hasLegacyPrice = Schema::hasColumn('order_items', 'price');
        $hasTotalPrice = Schema::hasColumn('order_items', 'total_price');

        $rows = DB::table('order_items')
            ->where('order_id', $orderId)
            ->select([
                'id',
                DB::raw($hasItemName ? 'item_name' : 'NULL as item_name'),
                DB::raw($hasProductName ? 'product_name' : 'NULL as product_name'),
                DB::raw($hasQuantity ? 'quantity' : '1 as quantity'),
                DB::raw($hasUnitPrice ? 'unit_price' : 'NULL as unit_price'),
                DB::raw($hasLegacyPrice ? 'price' : 'NULL as price'),
                DB::raw($hasTotalPrice ? 'total_price' : 'NULL as total_price'),
            ])
            ->get();

        return $rows->map(function ($row) {
            $unitPrice = isset($row->unit_price) ? (float) $row->unit_price : null;
            if ($unitPrice === null && isset($row->price)) {
                $unitPrice = (float) $row->price;
            }

            $quantity = isset($row->quantity) ? (int) $row->quantity : 1;
            $totalPrice = isset($row->total_price) ? (float) $row->total_price : null;
            if ($totalPrice === null && $unitPrice !== null) {
                $totalPrice = $unitPrice * max($quantity, 1);
            }

            return [
                'id' => $row->id,
                'name' => $row->item_name ?? $row->product_name ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
            ];
        })->values()->all();
    }

    private function decodeImages(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($item) => $item !== null && $item !== ''));
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return array_values(array_filter($decoded, static fn ($item) => $item !== null && $item !== ''));
        }

        return [$value];
    }

    private function decodeJsonObject(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $phone) ?: '';
        $len = strlen($normalized);

        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($normalized, 0, 3) . str_repeat('*', max($len - 5, 2)) . substr($normalized, -2);
    }

    private function applySort(Builder $query, string $sort): void
    {
        $sortMap = [
            'created_at' => ['c.created_at', 'asc'],
            '-created_at' => ['c.created_at', 'desc'],
            'updated_at' => ['c.updated_at', 'asc'],
            '-updated_at' => ['c.updated_at', 'desc'],
            'severity' => ['c.severity', 'asc'],
            '-severity' => ['c.severity', 'desc'],
            'status' => ['c.status', 'asc'],
            '-status' => ['c.status', 'desc'],
        ];

        $resolved = $sortMap[$sort] ?? $sortMap['-created_at'];
        $query->orderBy($resolved[0], $resolved[1]);
    }

    private function applyDateRange(Builder $query, ?string $from, ?string $to, array $preferredColumns): void
    {
        $column = null;
        foreach ($preferredColumns as $candidate) {
            if (Schema::hasColumn('complaints', $candidate)) {
                $column = $candidate;
                break;
            }
        }

        if (!$column) {
            return;
        }

        if ($from) {
            $query->whereDate('c.' . $column, '>=', $from);
        }

        if ($to) {
            $query->whereDate('c.' . $column, '<=', $to);
        }
    }

    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveOwnedStoreContext(Request $request): array
    {
        $context = $this->resolveContext($request);
        if ($context['error']) {
            return [null, null, $context['error']];
        }

        if (!$context['store']) {
            return [
                null,
                $context['user'],
                $this->errorResponse('STORE_NOT_FOUND', 'Khong tim thay cua hang thuoc quyen quan ly cua tai khoan nay.', 404, false, true),
            ];
        }

        return [$context['store'], $context['user'], null];
    }

    private function resolveContext(Request $request): array
    {
        return $this->resolveStoreAdminAccessContext($request);
    }

    private function errorResponse(string $code, string $message, int $status, bool $hasStore, bool $authenticated, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'data' => null,
            'meta' => [
                'authenticated' => $authenticated,
                'has_store' => $hasStore,
                'request_at' => now()->toIso8601String(),
            ],
            'errors' => [$error],
        ], $status);
    }
}
