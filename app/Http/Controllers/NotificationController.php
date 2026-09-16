<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Http\Requests\UpdateNotificationRequest;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NotificationController extends Controller
{
    private const ACTION_KEY_FALLBACKS = [
        'admin.store_approvals.pending' => [
            'admin_route' => 'admin.store-approvals.pending',
            'client_path' => null,
        ],
        'client.profile.store_registration' => [
            'admin_route' => null,
            'client_path' => '/profile/store-registration',
        ],
        'client.store.application_status' => [
            'admin_route' => null,
            'client_path' => '/profile/store-registration',
        ],
    ];

    public function __construct(
        private readonly NotificationService $notificationService
    ) {
    }

    private const AUDIENCE_SCOPES = [
        'admin_shared',
        'admin_private',
        'user_private',
        'store_private',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $supportsReadStatus = true;
        $unreadCount = $this->adminAudienceQuery($request)->where('is_read', false)->count();

        return view('admin.notifications.index', [
            'unreadCount' => $unreadCount,
            'supportsReadStatus' => $supportsReadStatus,
        ]);
    }

    public function datatable(Request $request): JsonResponse
    {
        $baseQuery = $this->adminAudienceQuery($request);
        $recordsTotal = (clone $baseQuery)->count();

        $query = clone $baseQuery;
        $this->applyNotificationDataTableFilters($query, $request);

        $recordsFiltered = (clone $query)->count();

        $columns = [
            0 => 'title',
            1 => 'is_read',
            2 => 'created_at',
        ];

        $orderColumn = (int) $request->input('order.0.column', 2);
        $orderField = $columns[$orderColumn] ?? 'created_at';
        $orderDir = $request->input('order.0.dir', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($orderField, $orderDir);

        if ($orderField === 'created_at') {
            $query->orderBy('id', $orderDir);
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        if ($length <= 0) {
            $length = 10;
        }

        $notifications = $query
            ->skip($start)
            ->take($length)
            ->get();

        $data = $notifications->map(function (Notification $notification) use ($request) {
            return $this->formatNotificationDataTableRow($notification, $request);
        });

        return response()->json([
            'draw' => (int) $request->input('draw', 1),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:255',
            'order_id' => 'nullable|exists:orders,id',
            'title' => 'nullable|string|max:191',
            'body' => 'nullable|string',
            'type' => 'nullable|string|max:80',
            'action_url' => 'nullable|string|max:500',
            'audience_scope' => ['nullable', 'string', Rule::in(self::AUDIENCE_SCOPES)],
            'user_id' => 'nullable|string|max:100',
            'store_id' => 'nullable|string|max:100',
        ]);

        $audienceScope = $this->resolveAudienceScopeFromPayload($validated);

        if (in_array($audienceScope, ['admin_private', 'user_private'], true) && empty($validated['user_id'])) {
            throw ValidationException::withMessages([
                'user_id' => 'Thông báo dạng riêng theo tài khoản cần user_id.',
            ]);
        }

        if ($audienceScope === 'store_private' && empty($validated['store_id'])) {
            throw ValidationException::withMessages([
                'store_id' => 'Thông báo dạng riêng theo cửa hàng cần store_id.',
            ]);
        }

        $payload = [
            'message' => trim((string) $validated['message']),
            'audience_scope' => $audienceScope,
            'user_id' => $validated['user_id'] ?? null,
            'store_id' => $validated['store_id'] ?? null,
            'is_read' => false,
            'read_at' => null,
            'order_id' => $validated['order_id'] ?? null,
            'title' => $validated['title'] ?? null,
            'body' => $validated['body'] ?? null,
            'type' => $validated['type'] ?? 'system',
            'action_url' => $validated['action_url'] ?? null,
        ];

        if ($audienceScope === 'admin_shared') {
            $payload['user_id'] = null;
            $payload['store_id'] = null;
        }

        $notification = $this->notificationService->create($payload);

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json($notification);
        }

        return back()->with('success', 'Đã tạo thông báo thành công.');
    }

    public function open(Request $request, Notification $notification): RedirectResponse
    {
        if (!$this->canAccessAdminNotification($notification, $request)) {
            abort(404);
        }

        $this->markNotificationAsRead($notification);

        $overrideRedirect = $this->sanitizeInternalRedirect((string) $request->query('redirect', ''));
        if ($overrideRedirect !== null) {
            return redirect()->to($overrideRedirect);
        }

        return redirect()->to($this->resolveNotificationTargetUrl($notification));
    }

    public function markAsRead(Request $request, Notification $notification): RedirectResponse|JsonResponse
    {
        if (!$this->canAccessAdminNotification($notification, $request)) {
            abort(404);
        }

        $this->markNotificationAsRead($notification);

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã đánh dấu thông báo là đã đọc.',
                'data' => [
                    'id' => $notification->id,
                    'is_read' => true,
                    'read_at' => optional($notification->read_at)->toDateTimeString(),
                ],
            ]);
        }

        return redirect()->to($this->resolveNotificationListRedirect($request))
            ->with('success', 'Đã đánh dấu thông báo là đã đọc.');
    }

    public function markAsUnread(Request $request, Notification $notification): RedirectResponse|JsonResponse
    {
        if (!$this->canAccessAdminNotification($notification, $request)) {
            abort(404);
        }

        $this->notificationService->markAsUnread($notification);

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã đánh dấu thông báo là chưa đọc.',
                'data' => [
                    'id' => $notification->id,
                    'is_read' => false,
                    'read_at' => null,
                ],
            ]);
        }

        return redirect()->to($this->resolveNotificationListRedirect($request))
            ->with('success', 'Đã đánh dấu thông báo là chưa đọc.');
    }

    public function markAllAsRead(Request $request): RedirectResponse|JsonResponse
    {
        $affectedRows = $this->notificationService->markAllAsRead($this->adminAudienceQuery($request));

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Đã đánh dấu tất cả thông báo là đã đọc.',
                'data' => [
                    'affected_rows' => $affectedRows,
                ],
            ]);
        }

        return redirect()->to($this->resolveNotificationListRedirect($request))
            ->with('success', 'Đã đánh dấu tất cả thông báo là đã đọc.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Notification $notification)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Notification $notification)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateNotificationRequest $request, Notification $notification)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Notification $notification)
    {
        //
    }

    private function markNotificationAsRead(Notification $notification): void
    {
        $this->notificationService->markAsRead($notification);
    }

    private function resolveNotificationTargetUrl(Notification $notification): string
    {
        $rawAction = trim((string) ($notification->action_url ?? ''));

        // Prioritize matching order and redirecting to the admin order detail page
        $linkedOrder = $notification->linked_order;
        if ($linkedOrder) {
            return route('admin.orders.show', $linkedOrder->id);
        }

        if (preg_match('/order-detail\?id=([a-f0-9\-]{36})/i', $rawAction, $matches)) {
            return route('admin.orders.show', $matches[1]);
        }

        $resolvedKeyTarget = $this->resolveActionKeyTarget($rawAction);
        if ($resolvedKeyTarget !== null) {
            return $resolvedKeyTarget;
        }

        $actionUrl = $this->sanitizeInternalRedirect($rawAction);
        if ($actionUrl !== null) {
            return $actionUrl;
        }

        return route('admin.notifications.index');
    }

    private function resolveActionKeyTarget(string $rawAction): ?string
    {
        $actionKey = $this->extractActionKey($rawAction);
        if ($actionKey === null) {
            return null;
        }

        $config = config('notification_actions.keys.' . $actionKey);
        if (!is_array($config)) {
            $config = self::ACTION_KEY_FALLBACKS[$actionKey] ?? null;
        }

        if (!is_array($config)) {
            return null;
        }

        $adminRouteName = trim((string) ($config['admin_route'] ?? ''));
        if ($adminRouteName !== '' && Route::has($adminRouteName)) {
            return route($adminRouteName);
        }

        $clientPath = $this->sanitizeInternalRedirect((string) ($config['client_path'] ?? ''));
        if ($clientPath !== null) {
            return $clientPath;
        }

        return null;
    }

    private function extractActionKey(string $rawAction): ?string
    {
        $candidate = trim($rawAction);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, 'key:')) {
            $candidate = substr($candidate, 4);
        }

        $candidate = strtolower(trim($candidate));

        return $candidate === '' ? null : $candidate;
    }

    private function sanitizeInternalRedirect(?string $redirect): ?string
    {
        $candidate = trim((string) $redirect);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '/')) {
            return $candidate;
        }

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($appUrl !== '' && ($candidate === $appUrl || str_starts_with($candidate, $appUrl . '/'))) {
            return $candidate;
        }

        return null;
    }

    private function applyNotificationDataTableFilters(Builder $query, Request $request): void
    {
        $status = trim((string) $request->input('status', 'all'));
        if ($status !== '' && $status !== 'all') {
            if ($status === 'read') {
                $query->where('is_read', true);
            } elseif ($status === 'unread') {
                $query->where('is_read', false);
            }
        }

        $type = trim((string) $request->input('type', 'all'));
        if ($type !== '' && $type !== 'all') {
            $query->where('type', $type);
        }

        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('message', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }
    }

    private function formatNotificationDataTableRow(Notification $notification, Request $request): array
    {
        $isRead = (bool) ($notification->is_read ?? false);
        $hasDirectTarget = !empty($notification->action_url)
            || !empty($notification->order_id)
            || !empty($notification->linked_order);

        $openRouteParams = ['notification' => $notification->id];
        if (!$hasDirectTarget) {
            $openRouteParams['redirect'] = $this->resolveNotificationDataTableRedirect($request);
        }

        $notificationUrl = route('admin.notifications.open', $openRouteParams);
        $title = trim((string) ($notification->title ?? ''));
        $message = trim((string) (($notification->message ?: $notification->body) ?? ''));
        if ($message === '') {
            $message = 'Không có nội dung.';
        }

        $titleHtml = $title !== ''
            ? '<div class="fw-bold mb-0.5 text-dark notification-title-hover" style="font-size: 13.5px;">' . e($title) . '</div>'
            : '';

        $contentHtml = '<a href="' . e($notificationUrl) . '" class="notification-content-link text-decoration-none">'
            . $titleHtml
            . '<div class="text-muted notification-body-hover" style="font-size: 12.5px;">' . e($message) . '</div>'
            . '</a>';

        $statusHtml = $isRead
            ? '<span class="badge rounded-pill badge-status-read"><i class="far fa-check-circle"></i> Đã đọc</span>'
            : '<span class="badge rounded-pill badge-status-unread"><span class="status-dot-orange"></span> Chưa đọc</span>';

        $createdAtHtml = '<span>-</span>';
        if ($notification->created_at) {
            $createdAtHtml = '<div class="d-flex align-items-center gap-1.5">'
                . '<i class="far fa-clock text-muted"></i>'
                . '<span>' . e($notification->created_at->diffForHumans()) . '</span>'
                . '</div>';
        }

        return [
            'content' => $contentHtml,
            'status' => $statusHtml,
            'created_at' => $createdAtHtml,
            'row_class' => $isRead ? 'notification-row-read' : 'notification-row-unread',
        ];
    }

    private function resolveNotificationDataTableRedirect(Request $request): string
    {
        $redirectFromRequest = $this->sanitizeInternalRedirect((string) $request->input('redirect_url', ''));
        if ($redirectFromRequest !== null) {
            return $redirectFromRequest;
        }

        $params = [];
        $search = trim((string) $request->input('search.value', ''));
        $status = trim((string) $request->input('status', 'all'));
        $type = trim((string) $request->input('type', 'all'));

        if ($search !== '') {
            $params['search'] = $search;
        }

        if ($status !== '' && $status !== 'all') {
            $params['status'] = $status;
        }

        if ($type !== '' && $type !== 'all') {
            $params['type'] = $type;
        }

        return route('admin.notifications.index', $params);
    }

    private function adminAudienceQuery(Request $request): Builder
    {
        $query = Notification::query();

        $currentAdminId = trim((string) optional($request->user())->id);

        $query->where(function (Builder $scopeQuery) use ($currentAdminId): void {
            $scopeQuery
                ->whereNull('audience_scope')
                ->orWhere('audience_scope', '')
                ->orWhere('audience_scope', 'admin_shared');

            if ($currentAdminId !== '') {
                $scopeQuery->orWhere(function (Builder $privateAdminQuery) use ($currentAdminId): void {
                    $privateAdminQuery
                        ->where('audience_scope', 'admin_private')
                        ->where('user_id', $currentAdminId);
                });
            }
        });

        return $query;
    }

    private function canAccessAdminNotification(Notification $notification, Request $request): bool
    {
        $audienceScope = trim((string) ($notification->audience_scope ?? ''));
        if ($audienceScope === '' || $audienceScope === 'admin_shared') {
            return true;
        }

        if ($audienceScope === 'admin_private') {
            return (string) ($notification->user_id ?? '') === (string) optional($request->user())->id;
        }

        return false;
    }

    private function resolveAudienceScopeFromPayload(array $validated): string
    {
        $audienceScope = strtolower(trim((string) ($validated['audience_scope'] ?? '')));
        if ($audienceScope !== '') {
            return $audienceScope;
        }

        if (!empty($validated['user_id'])) {
            return 'user_private';
        }

        if (!empty($validated['store_id'])) {
            return 'store_private';
        }

        return 'admin_shared';
    }

    private function resolveNotificationListRedirect(Request $request): string
    {
        $redirectFromForm = $this->sanitizeInternalRedirect((string) $request->input('redirect_url', ''));
        if ($redirectFromForm !== null) {
            return $redirectFromForm;
        }

        $redirectFromQuery = $this->sanitizeInternalRedirect((string) $request->query('redirect', ''));
        if ($redirectFromQuery !== null) {
            return $redirectFromQuery;
        }

        return route('admin.notifications.index');
    }
}
