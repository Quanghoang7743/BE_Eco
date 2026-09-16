<?php

namespace App\Http\Controllers;

use App\Models\SpecialistNotification;
use App\Services\SpecialistNotificationService;
use App\Support\AdminCmsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SpecialistNotificationController extends Controller
{
    private const TYPE_LABELS = [
        'store_assigned' => 'Cửa hàng mới được phân công',
        'first_order' => 'Có đơn đầu tiên',
        'abnormal_ticket' => 'Ticket treo bất thường',
        'settlement_created' => 'Kỳ đối soát mới',
        'income_approved' => 'Thu nhập được duyệt',
        'income_paid' => 'Thu nhập đã thanh toán',
        'task_new' => 'Có nhiệm vụ mới',
        'rating_new' => 'Có kết quả đánh giá sao mới',
    ];

    public function __construct(
        private readonly SpecialistNotificationService $specialistNotificationService
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $specialistId = AdminCmsAccess::isSale($user)
            ? (int) $user->id
            : (int) $request->query('specialist_id', 0);

        if ($specialistId > 0) {
            $this->specialistNotificationService->syncForSpecialist($specialistId);
        }

        $query = SpecialistNotification::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (AdminCmsAccess::isSale($user)) {
            $query->forSpecialist((int) $user->id);
        } elseif ($specialistId > 0) {
            $query->forSpecialist($specialistId);
        }

        $type = trim((string) $request->query('type', 'all'));
        if ($type !== '' && $type !== 'all' && isset(self::TYPE_LABELS[$type])) {
            $query->where('type', $type);
        }

        $readStatus = trim((string) $request->query('read_status', 'all'));
        if ($readStatus === 'unread') {
            $query->where('is_read', false);
        } elseif ($readStatus === 'read') {
            $query->where('is_read', true);
        }

        $notifications = (clone $query)
            ->paginate(20)
            ->appends($request->query());

        $unreadCountQuery = SpecialistNotification::query();
        if (AdminCmsAccess::isSale($user)) {
            $unreadCountQuery->forSpecialist((int) $user->id);
        } elseif ($specialistId > 0) {
            $unreadCountQuery->forSpecialist($specialistId);
        }

        return view('admin.specialists.notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $unreadCountQuery->where('is_read', false)->count(),
            'typeLabels' => self::TYPE_LABELS,
            'filters' => [
                'type' => $type !== '' ? $type : 'all',
                'read_status' => $readStatus !== '' ? $readStatus : 'all',
                'specialist_id' => $specialistId ?: null,
            ],
            'isSaleUser' => AdminCmsAccess::isSale($user),
        ]);
    }

    public function open(Request $request, SpecialistNotification $notification): RedirectResponse
    {
        $this->authorizeNotificationAccess($request, $notification);

        if (AdminCmsAccess::isSale($request->user())) {
            $this->specialistNotificationService->markAsRead($notification);
        }

        $targetUrl = $this->sanitizeInternalRedirect((string) ($notification->action_url ?? ''))
            ?? route('admin.specialist-notifications.index');

        return redirect()->to($targetUrl);
    }

    public function markAsRead(Request $request, SpecialistNotification $notification): RedirectResponse|JsonResponse
    {
        $this->authorizeSaleMutation($request, $notification);
        $this->specialistNotificationService->markAsRead($notification);

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true]);
        }

        return redirect()->to($this->listRedirect($request))
            ->with('success', 'Đã đánh dấu thông báo là đã đọc.');
    }

    public function markAsUnread(Request $request, SpecialistNotification $notification): RedirectResponse|JsonResponse
    {
        $this->authorizeSaleMutation($request, $notification);
        $this->specialistNotificationService->markAsUnread($notification);

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true]);
        }

        return redirect()->to($this->listRedirect($request))
            ->with('success', 'Đã đánh dấu thông báo là chưa đọc.');
    }

    public function markAllAsRead(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(AdminCmsAccess::isSale($user), 403);

        $affectedRows = $this->specialistNotificationService->markAllAsRead(
            SpecialistNotification::query()->forSpecialist((int) $user->id)
        );

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'affected_rows' => $affectedRows,
            ]);
        }

        return redirect()->to($this->listRedirect($request))
            ->with('success', 'Đã đánh dấu tất cả thông báo là đã đọc.');
    }

    private function authorizeNotificationAccess(Request $request, SpecialistNotification $notification): void
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (AdminCmsAccess::isSale($user)) {
            abort_unless((int) $notification->specialist_id === (int) $user->id, 404);
        }
    }

    private function authorizeSaleMutation(Request $request, SpecialistNotification $notification): void
    {
        $user = $request->user();
        abort_unless(AdminCmsAccess::isSale($user), 403);
        abort_unless((int) $notification->specialist_id === (int) $user->id, 404);
    }

    private function listRedirect(Request $request): string
    {
        return $this->sanitizeInternalRedirect((string) $request->input('redirect_url', ''))
            ?? route('admin.specialist-notifications.index');
    }

    private function sanitizeInternalRedirect(string $redirect): ?string
    {
        $candidate = trim($redirect);
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
}
