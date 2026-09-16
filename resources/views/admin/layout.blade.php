@php
    $currentAdminId = (string) optional(auth()->user())->id;
    $AppSetting = \App\Models\appSetting::first();
    $transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    $logoUrl = $AppSetting?->logo_path ? asset('storage/' . $AppSetting->logo_path) : $transparentPixel;
    $faviconUrl = $AppSetting?->favicon_path ? asset('storage/' . $AppSetting->favicon_path) : $logoUrl;
    $isSpecialistNotificationInbox =
        \App\Support\AdminCmsAccess::isSale(auth()->user()) &&
        \Illuminate\Support\Facades\Schema::hasTable('specialist_notifications');

    if ($isSpecialistNotificationInbox) {
        app(\App\Services\SpecialistNotificationService::class)->syncDigestForSpecialist((int) $currentAdminId);

        $adminNotificationsQuery = \App\Models\SpecialistNotification::query()->where(
            'specialist_id',
            (int) $currentAdminId,
        );
        $notificationOpenRoute = 'admin.specialist-notifications.open';
        $notificationIndexRoute = 'admin.specialist-notifications.index';
        $notificationMarkAllRoute = 'admin.specialist-notifications.mark-all-read';

        $notifications = (clone $adminNotificationsQuery)->orderByDesc('created_at')->orderByDesc('id')->take(12)->get();
    } else {
        $adminNotificationsQuery = \App\Models\Notification::query()->where(function ($scopeQuery) use (
            $currentAdminId,
        ) {
            $scopeQuery
                ->whereNull('audience_scope')
                ->orWhere('audience_scope', '')
                ->orWhere('audience_scope', 'admin_shared');

            if ($currentAdminId !== '') {
                $scopeQuery->orWhere(function ($privateAdminQuery) use ($currentAdminId) {
                    $privateAdminQuery->where('audience_scope', 'admin_private')->where('user_id', $currentAdminId);
                });
            }
        });
        $notificationOpenRoute = 'admin.notifications.open';
        $notificationIndexRoute = 'admin.notifications.index';
        $notificationMarkAllRoute = 'admin.notifications.mark-all-read';

        $notifications = (clone $adminNotificationsQuery)->with('order')->orderByDesc('created_at')->orderByDesc('id')->take(12)->get();
    }

    $Countnotifications = (clone $adminNotificationsQuery)->where('is_read', false)->count();
    $isRoomManagementOpen =
        request()->routeIs('admin.room-schedules.*') ||
        request()->routeIs('admin.room-templates.*') ||
        request()->routeIs('admin.rooms.*');
    $isPrizeManagementOpen =
        request()->routeIs('admin.winners.*') ||
        request()->routeIs('admin.prize-settings.*') ||
        request()->routeIs('admin.vouchers.*') ||
        request()->routeIs('admin.rewards.*');
    $isTicketManagementOpen = request()->routeIs('admin.ticket-stats.*') || request()->routeIs('admin.tickets.unused*') || request()->routeIs('admin.ticket-settings.*');
    $isComplaintManagementOpen = request()->routeIs('admin.complaints.*');
    $isAffiliateManagementOpen = request()->routeIs('admin.affiliate.*');
    $isStoreManagementOpen =
        request()->routeIs('admin.stores.*') ||
        request()->routeIs('admin.store-approvals.*') ||
        request()->routeIs('admin.product-approvals.*') ||
        request()->routeIs('admin.sales.performance.*');
    $isOrderManagementOpen =
        request()->routeIs('admin.system-orders.*') || request()->routeIs('admin.reports.consolidated-revenue');
    $isReconciliationManagementOpen = request()->routeIs('admin.reconciliation.*');
    $isAnalyticsOpen = request()->routeIs('admin.reports.funnel') || request()->routeIs('admin.reports.retention');
    $isSpecialistProfileOpen = request()->routeIs('admin.users.sales*');
    $isSpecialistStoreModuleOpen = request()->routeIs('admin.specialist-care.*');
    $isSpecialistTicketModuleOpen =
        request()->routeIs('admin.tickets.specialist*') ||
        request()->routeIs('admin.specialist-income.*') ||
        request()->routeIs('admin.specialist-settlements.*') ||
        request()->routeIs('admin.specialist-fee-configs.*');
    $isSpecialistReviewReportModuleOpen =
        request()->routeIs('admin.specialist-ratings.*') ||
        request()->routeIs('admin.specialist-credit.*') ||
        request()->routeIs('admin.reports.specialists');
    // dd($AppSetting->logo_path);
@endphp

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <title>@yield('title', 'Quản lý cửa hàng')</title>
    <meta content="width=device-width, initial-scale=1.0, shrink-to-fit=no" name="viewport" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="{{ $faviconUrl }}" type="image/x-icon" />

    <!-- Fonts and icons -->
    <script src="/assets/js/plugin/webfont/webfont.min.js"></script>
    <style>
        .notif-center {
            overflow: hidden;
        }
    </style>
    <script>
        WebFont.load({
            google: {
                families: ["Public Sans:300,400,500,600,700"]
            },
            custom: {
                families: [
                    "Font Awesome 5 Solid",
                    "Font Awesome 5 Regular",
                    "Font Awesome 5 Brands",
                    "simple-line-icons",
                ],
                urls: ["/assets/css/fonts.min.css"],
            },
            active: function() {
                sessionStorage.fonts = true;
            },
        });
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />

    <!-- CSS Files -->
    <link rel="stylesheet" href="/assets/css/bootstrap.min.css" />
    <link rel="stylesheet" href="/assets/css/plugins.min.css" />
    <link rel="stylesheet" href="/assets/css/kaiadmin.min.css" />

    <!-- CSS Just for demo purpose, don't include it in your project -->
    <link rel="stylesheet" href="/assets/css/demo.css" />
    @yield('styles')
    <style>
        ul {
            margin: 0;
        }

        /* Custom Notification Dropdown CSS */
        .notif-box {
            width: 400px !important;
            max-width: 90vw !important;
            border-radius: 12px !important;
            border: 1px solid rgba(0, 0, 0, 0.08) !important;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08) !important;
            padding: 0 !important;
            overflow: hidden !important;
        }

        .notif-header-circle {
            width: 36px;
            height: 36px;
            background-color: #eff6ff;
            color: #2262eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        .notif-btn-mark-all {
            border: 1px solid #3b82f6 !important;
            color: #2262eb !important;
            background-color: #ffffff !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            padding: 6px 12px !important;
            transition: all 0.2s ease !important;
        }

        .notif-btn-mark-all:hover {
            background-color: #eff6ff !important;
            border-color: #2563eb !important;
        }

        .notif-item {
            display: flex !important;
            align-items: flex-start !important;
            gap: 12px !important;
            padding: 14px 16px !important;
            background-color: #ffffff;
            border-bottom: 1px solid #f1f5f9 !important;
            transition: background-color 0.15s ease !important;
            text-decoration: none !important;
        }

        .notif-item:last-child {
            border-bottom: none !important;
        }

        .notif-item-unread {
            background-color: #f4f7fe !important;
        }

        .notif-item:hover {
            background-color: #f8fafc !important;
            text-decoration: none !important;
        }

        .notif-item-unread:hover {
            background-color: #ebeffb !important;
        }

        .unread-dot-indicator {
            width: 7px;
            height: 7px;
            background-color: #f97316;
            border-radius: 50%;
            margin-top: 15px;
            flex-shrink: 0;
        }

        .notif-item-circle-orange {
            width: 38px;
            height: 38px;
            background-color: #fff7ed;
            color: #ea580c;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .notif-item-circle-blue {
            width: 38px;
            height: 38px;
            background-color: #eff6ff;
            color: #2262eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .notif-item-content {
            flex-grow: 1 !important;
        }

        .notif-item-title {
            font-weight: 600 !important;
            color: #1e293b !important;
            font-size: 13.5px !important;
            margin-bottom: 2px !important;
            line-height: 1.4 !important;
        }

        .notif-item-body {
            color: #64748b !important;
            font-size: 12px !important;
            margin-bottom: 4px !important;
            line-height: 1.3 !important;
        }

        .notif-item-meta {
            color: #94a3b8 !important;
            font-size: 11.5px !important;
            display: flex !important;
            align-items: center !important;
            gap: 4px !important;
        }

        .notif-item-badge-new {
            background-color: #f97316 !important;
            color: #ffffff !important;
            font-size: 10px !important;
            font-weight: 700 !important;
            padding: 2px 8px !important;
            border-radius: 4px !important;
            white-space: nowrap !important;
            margin-top: 2px !important;
        }

        .notif-footer-link {
            display: flex !important;
            align-items: center !important;
            justify-content: justify !important;
            padding: 12px 16px !important;
            background-color: #ffffff !important;
            border-top: 1px solid #f1f5f9 !important;
            text-decoration: none !important;
            font-weight: 600 !important;
            color: #2262eb !important;
            font-size: 13px !important;
            transition: background-color 0.15s ease !important;
        }

        .notif-footer-link:hover {
            background-color: #f8fafc !important;
            color: #1d4ed8 !important;
            text-decoration: none !important;
        }

        /* Green notification count badge on bell icon */
        .navbar .navbar-nav .nav-item .nav-link .notification {
            background-color: #22c55e !important;
            color: #ffffff !important;
            font-size: 11px !important;
            font-weight: bold !important;
            padding: 2px 5px !important;
            height: 18px !important;
            min-width: 18px !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 50% !important;
            top: 10px !important;
            right: 5px !important;
        }

        .form-label {
            font-weight: bold
        }

        /* Premium Pagination Override */
        .pagination {
            display: flex !important;
            padding-left: 0 !important;
            list-style: none !important;
            gap: 6px !important;
            margin: 0 !important;
            justify-content: flex-end !important;
            align-items: center !important;
            border-radius: 0 !important;
        }

        .pagination .page-item,
        .pagination > li {
            margin: 0 !important;
        }

        .pagination .page-link,
        .pagination > li > a,
        .pagination > li > span {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            height: 36px !important;
            min-width: 36px !important;
            padding: 0 12px !important;
            font-size: 13px !important;
            font-weight: 500 !important;
            color: #4b5563 !important;
            background-color: #ffffff !important;
            border: 1px solid #e5e7eb !important;
            border-radius: 6px !important;
            transition: all 0.2s ease !important;
            box-shadow: none !important;
            text-decoration: none !important;
            margin: 0 !important;
        }

        /* Active state */
        .pagination .page-item.active .page-link,
        .pagination > li.active > a,
        .pagination > li.active > span {
            color: #ffffff !important;
            background-color: #3b82f6 !important;
            border-color: #3b82f6 !important;
            font-weight: 600 !important;
        }

        /* Hover state */
        .pagination .page-item:not(.active):not(.disabled) .page-link:hover,
        .pagination > li:not(.active):not(.disabled) > a:hover,
        .pagination > li:not(.active):not(.disabled) > span:hover {
            color: #1f2937 !important;
            background-color: #f3f4f6 !important;
            border-color: #d1d5db !important;
            text-decoration: none !important;
        }

        /* Disabled state */
        .pagination .page-item.disabled .page-link,
        .pagination > li.disabled > a,
        .pagination > li.disabled > span {
            color: #9ca3af !important;
            background-color: #f9fafb !important;
            border-color: #e5e7eb !important;
            cursor: not-allowed !important;
            opacity: 0.6 !important;
            pointer-events: none !important;
        }

        /* Adjust padding for Previous/Next text buttons */
        .pagination .page-item.previous .page-link,
        .pagination .page-item.next .page-link,
        .pagination > li:first-child > a,
        .pagination > li:last-child > a,
        .pagination > li:first-child > span,
        .pagination > li:last-child > span,
        .pagination .page-item:first-child .page-link,
        .pagination .page-item:last-child .page-link {
            padding: 0 16px !important;
            font-weight: 500 !important;
            border-radius: 6px !important;
        }

        /* Custom Table Info & Length Menus Styling */
        .dataTables_wrapper .dataTables_info {
            font-size: 13px !important;
            color: #6b7280 !important;
            font-weight: 500 !important;
            padding-top: 12px !important;
        }

        .dataTables_wrapper .dataTables_length {
            font-size: 13px !important;
            color: #4b5563 !important;
            font-weight: 500 !important;
            padding-top: 12px !important;
        }

        .dataTables_wrapper .dataTables_length select {
            display: inline-block !important;
            width: auto !important;
            border: 1px solid #d1d5db !important;
            border-radius: 6px !important;
            height: 34px !important;
            font-size: 13px !important;
            color: #374151 !important;
            background-color: #fff !important;
            padding: 4px 24px 4px 8px !important;
            margin: 0 4px !important;
            outline: none !important;
            box-shadow: none !important;
            transition: all 0.2s ease !important;
        }

        .dataTables_wrapper .dataTables_length select:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
        }

        /* Expert Sidebar Integration */
        @media (min-width: 992px) {
            .main-panel {
                transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1) !important;
                width: calc(100% - 280px) !important;
                margin-left: 280px !important;
            }

            #expert-sidebar.collapsed+.main-panel,
            .sidebar-collapsed-mode .main-panel {
                width: calc(100% - 72px) !important;
                margin-left: 72px !important;
            }
        }

        @media (max-width: 991px) {

            .wrapper,
            .main-panel {
                width: 100% !important;
                margin-left: 0 !important;
                transform: none !important;
                left: 0 !important;
            }

            .main-panel {
                padding-top: 0 !important;
            }
        }

        .sidebar-collapsed-mode .main-header {
            width: calc(100% - 72px) !important;
        }

        .app-toast {
            --app-toast-accent: #2563eb;
            background: #ffffff;
            color: #0f172a;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-left: 4px solid var(--app-toast-accent);
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
            min-width: 320px;
            max-width: 420px;
            overflow: hidden;
        }

        .app-toast--success {
            --app-toast-accent: #16a34a;
        }

        .app-toast--danger {
            --app-toast-accent: #dc2626;
        }

        .app-toast--warning {
            --app-toast-accent: #d97706;
        }

        .app-toast--info {
            --app-toast-accent: #2563eb;
        }

        .app-toast__content {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
        }

        .app-toast__icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, 0.12);
            color: var(--app-toast-accent);
            font-size: 16px;
            flex: 0 0 auto;
        }

        .app-toast--success .app-toast__icon {
            background: rgba(22, 163, 74, 0.12);
        }

        .app-toast--danger .app-toast__icon {
            background: rgba(220, 38, 38, 0.12);
        }

        .app-toast--warning .app-toast__icon {
            background: rgba(217, 119, 6, 0.12);
        }

        .app-toast__title {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .app-toast__message {
            font-size: 0.9rem;
            color: #475569;
        }

        .app-toast__close {
            margin-left: auto;
            align-self: flex-start;
            filter: none;
            opacity: 0.6;
        }

        .app-toast__close:hover {
            opacity: 1;
        }

        .app-toast__bar {
            height: 3px;
            background: var(--app-toast-accent);
            opacity: 0.7;
            transform-origin: left;
            animation: app-toast-progress linear forwards;
        }

        @keyframes app-toast-progress {
            from {
                transform: scaleX(1);
            }

            to {
                transform: scaleX(0);
            }
        }
    </style>
</head>

<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <x-admin.sidebar />
        <!-- End Sidebar -->

        <div class="main-panel">
            <div class="main-header">
                <div class="main-header-logo">
                    <!-- Logo Header -->
                    <div class="logo-header" data-background-color="dark">
                        <a href="/admin" class="logo">
                            <img src="{{ $logoUrl }}" alt="navbar brand" class="navbar-brand" height="60"
                                style=" border-radius: 50%; " />
                        </a>
                        <div class="nav-toggle">
                            <button class="btn btn-toggle toggle-sidebar">
                                <i class="gg-menu-right"></i>
                            </button>
                            <button class="btn btn-toggle sidenav-toggler">
                                <i class="gg-menu-left"></i>
                            </button>
                        </div>
                        <button class="topbar-toggler more">
                            <i class="gg-more-vertical-alt"></i>
                        </button>
                    </div>
                    <!-- End Logo Header -->
                </div>
                <!-- Navbar Header -->
                <nav class="navbar navbar-header navbar-header-transparent navbar-expand-lg border-bottom">
                    <div class="container-fluid">
                        <nav
                            class="navbar navbar-header-left navbar-expand-lg navbar-form nav-search p-0 d-none d-lg-flex">
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <button type="submit" class="btn btn-search pe-1">
                                        <i class="fa fa-search search-icon"></i>
                                    </button>
                                </div>
                                <input type="text" placeholder="Search ..." class="form-control" />
                            </div>
                        </nav>

                        <ul class="navbar-nav topbar-nav ms-md-auto align-items-center">
                            <li class="nav-item topbar-icon dropdown hidden-caret d-flex d-lg-none">
                                <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#"
                                    role="button" aria-expanded="false" aria-haspopup="true">
                                    <i class="fa fa-search"></i>
                                </a>
                                <ul class="dropdown-menu dropdown-search animated fadeIn">
                                    <form class="navbar-left navbar-form nav-search">
                                        <div class="input-group">
                                            <input type="text" placeholder="Search ..." class="form-control" />
                                        </div>
                                    </form>
                                </ul>
                            </li>
                            <li class="nav-item topbar-icon dropdown hidden-caret">
                                <a class="nav-link dropdown-toggle" href="#" id="notifDropdown" role="button"
                                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <i class="fa fa-bell"></i>
                                    <span class="notification">{{ $Countnotifications ?? 0 }}</span>
                                </a>
                                <ul class="dropdown-menu notif-box animated fadeIn" aria-labelledby="notifDropdown">
                                    <li>
                                        <div class="d-flex align-items-center justify-content-between p-3 border-bottom bg-white">
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="notif-header-circle">
                                                    <i class="far fa-bell"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold text-dark" style="font-size: 15px;">Thông báo</div>
                                                    <small class="text-muted" style="font-size: 12px; display: block; margin-top: -2px;">
                                                        Bạn có {{ $Countnotifications ?? 0 }} thông báo chưa đọc
                                                    </small>
                                                </div>
                                            </div>
                                            @if (($Countnotifications ?? 0) > 0)
                                                <form method="POST" action="{{ route($notificationMarkAllRoute) }}" class="m-0">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-sm notif-btn-mark-all d-flex align-items-center gap-1">
                                                        <i class="fas fa-check" style="font-size: 11px;"></i> Đánh dấu tất cả đã đọc
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </li>
                                    <li>
                                        <div class="notif-scroll scrollbar-outer" style="max-height: 360px; overflow-y: auto;">
                                            <div class="notif-center">
                                                     @forelse ($notifications as $notification)
                                                     @php
                                                         $isRead = (bool) ($notification->is_read ?? false);
                                                         $openNotificationUrl = route(
                                                             $notificationOpenRoute,
                                                             $notification->id,
                                                         );
                                                         $linkedOrder = $notification->linked_order;
                                                         $hasOrder = !empty($linkedOrder);
                                                     @endphp
                                                     <a href="{{ $openNotificationUrl }}" class="notif-item {{ !$isRead ? 'notif-item-unread' : '' }}">
                                                         @if (!$isRead)
                                                             <span class="unread-dot-indicator"></span>
                                                         @endif
                                                         <div class="{{ !$isRead ? 'notif-item-circle-orange' : 'notif-item-circle-blue' }}">
                                                             <i class="far fa-bell"></i>
                                                         </div>
                                                         <div class="notif-item-content">
                                                             <div class="notif-item-title">
                                                                 {{ $notification->title ?: ($notification->message ?: $notification->body) }}
                                                             </div>
                                                             @if ($hasOrder)
                                                                 <div class="notif-item-body">
                                                                     Đơn hàng #{{ substr($linkedOrder->order_code ?? $linkedOrder->id, 0, 12) }} • Tổng tiền {{ number_format($linkedOrder->final_amount ?? 0, 0, ',', '.') }}đ
                                                                 </div>
                                                                 <div class="notif-item-meta">
                                                                     <i class="far fa-user" style="font-size: 11px;"></i>
                                                                     <span>{{ $linkedOrder->name ?? 'Khách hàng' }}</span>
                                                                     <span>•</span>
                                                                     <span>{{ optional($notification->created_at)->diffForHumans() }}</span>
                                                                 </div>
                                                             @else
                                                                 @if ($notification->title && ($notification->message ?: $notification->body))
                                                                     <div class="notif-item-body">
                                                                         {{ $notification->message ?: $notification->body }}
                                                                     </div>
                                                                 @endif
                                                                 <div class="notif-item-meta">
                                                                     <i class="far fa-clock" style="font-size: 11px;"></i>
                                                                     <span>{{ optional($notification->created_at)->diffForHumans() }}</span>
                                                                 </div>
                                                             @endif
                                                         </div>
                                                         @if (!$isRead)
                                                             <span class="notif-item-badge-new">Mới</span>
                                                         @endif
                                                     </a>
                                                @empty
                                                    <div class="px-3 py-4 text-muted text-center" style="font-size: 13.5px;">
                                                        Chưa có thông báo.
                                                    </div>
                                                @endforelse
                                            </div>
                                        </div>
                                    </li>
                                    <li>
                                        <a class="notif-footer-link d-flex align-items-center justify-content-between" href="{{ route($notificationIndexRoute) }}">
                                            <div class="d-flex align-items-center gap-2">
                                                <i class="far fa-file-alt" style="font-size: 14px;"></i>
                                                <span>Xem tất cả thông báo</span>
                                            </div>
                                            <i class="fas fa-chevron-right" style="font-size: 11px;"></i>
                                        </a>
                                    </li>
                                </ul>
                            </li>
                            {{-- <li class="nav-item topbar-icon dropdown hidden-caret">
                                <a class="nav-link" data-bs-toggle="dropdown" href="#" aria-expanded="false">
                                    <i class="fas fa-layer-group"></i>
                                </a>
                                <div class="dropdown-menu quick-actions animated fadeIn">
                                    <div class="quick-actions-header">
                                        <span class="title mb-1">Quick Actions</span>
                                        <span class="subtitle op-7">Shortcuts</span>
                                    </div>
                                    <div class="quick-actions-scroll scrollbar-outer">
                                        <div class="quick-actions-items">
                                            <div class="row m-0">
                                                <a class="col-6 col-md-4 p-0" href="#">
                                                    <div class="quick-actions-item">
                                                        <div class="avatar-item bg-danger rounded-circle">
                                                            <i class="far fa-calendar-alt"></i>
                                                        </div>
                                                        <span class="text">Calendar</span>
                                                    </div>
                                                </a>
                                                <a class="col-6 col-md-4 p-0" href="#">
                                                    <div class="quick-actions-item">
                                                        <div class="avatar-item bg-warning rounded-circle">
                                                            <i class="fas fa-map"></i>
                                                        </div>
                                                        <span class="text">Maps</span>
                                                    </div>
                                                </a>
                                                <a class="col-6 col-md-4 p-0" href="#">
                                                    <div class="quick-actions-item">
                                                        <div class="avatar-item bg-info rounded-circle">
                                                            <i class="fas fa-file-excel"></i>
                                                        </div>
                                                        <span class="text">Reports</span>
                                                    </div>
                                                </a>
                                                <a class="col-6 col-md-4 p-0" href="#">
                                                    <div class="quick-actions-item">
                                                        <div class="avatar-item bg-success rounded-circle">
                                                            <i class="fas fa-envelope"></i>
                                                        </div>
                                                        <span class="text">Emails</span>
                                                    </div>
                                                </a>
                                                <a class="col-6 col-md-4 p-0" href="#">
                                                    <div class="quick-actions-item">
                                                        <div class="avatar-item bg-primary rounded-circle">
                                                            <i class="fas fa-file-invoice-dollar"></i>
                                                        </div>
                                                        <span class="text">Invoice</span>
                                                    </div>
                                                </a>
                                                <a class="col-6 col-md-4 p-0" href="#">
                                                    <div class="quick-actions-item">
                                                        <div class="avatar-item bg-secondary rounded-circle">
                                                            <i class="fas fa-credit-card"></i>
                                                        </div>
                                                        <span class="text">Payments</span>
                                                    </div>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li> --}}

                            <li class="nav-item topbar-user dropdown hidden-caret">
                                <a class="dropdown-toggle profile-pic" data-bs-toggle="dropdown" href="#"
                                    aria-expanded="false">
                                    <div class="avatar-sm">
                                        @if (Auth::user()->avatar)
                                            <img src="{{ Str::startsWith(Auth::user()->avatar, ['http://', 'https://']) ? Auth::user()->avatar : asset('storage/' . Auth::user()->avatar) }}"
                                                alt="..." class="avatar-img rounded-circle" />
                                        @else
                                            <img src="https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg"
                                                alt="..." class="avatar-img rounded-circle" />
                                        @endif
                                    </div>
                                    <span class="profile-username">
                                        <span class="op-7">Hi,</span>
                                        <span class="fw-bold">{{ Auth::user()->full_name }}</span>
                                    </span>
                                </a>
                                <ul class="dropdown-menu dropdown-user animated fadeIn">
                                    <div class="dropdown-user-scroll scrollbar-outer">
                                        <li>
                                            <div class="user-box">
                                                <div class="avatar-lg">
                                                    @if (Auth::user()->avatar)
                                                        <img src="{{ Str::startsWith(Auth::user()->avatar, ['http://', 'https://']) ? Auth::user()->avatar : asset('storage/' . Auth::user()->avatar) }}"
                                                            alt="image profile" class="avatar-img rounded" />
                                                    @else
                                                        <img src="https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg"
                                                            alt="image profile" class="avatar-img rounded" />
                                                    @endif
                                                </div>
                                                <div class="u-text">
                                                    <h4>{{ Auth::user()->full_name }}</h4>
                                                    <p class="text-muted">{{ Auth::user()->email }}</p>
                                                    <a href="{{ route('admin.profile') }}"
                                                        class="btn btn-xs btn-secondary btn-sm">View Profile</a>
                                                </div>
                                            </div>
                                        </li>
                                        <li>
                                            {{-- <div class="dropdown-divider"></div> --}}
                                            {{-- <a class="dropdown-item" href="#">My Profile</a> --}}
                                            {{-- <a class="dropdown-item" href="#">My Balance</a> --}}
                                            {{-- <a class="dropdown-item" href="#">Inbox</a> --}}
                                            {{-- <div class="dropdown-divider"></div> --}}
                                            {{-- <a class="dropdown-item" href="#">Account Setting</a> --}}
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item" href="{{ route('logout') }}">Logout</a>
                                        </li>
                                    </div>
                                </ul>
                            </li>
                        </ul>
                    </div>
                </nav>
                <!-- End Navbar -->
            </div>

            <div class="container">
                <div class="page-inner">
                    @yield('content')
                </div>
            </div>

            <footer class="footer">
                <div class="container-fluid d-flex justify-content-between text-center">
                    <div class="copyright w-100 text-center">
                        2025, Được thực hiện bởi
                        <a href="https://www.facebook.com/profile.php?id=100053830937586">Trần Huy Hưng</a> <i
                            class="fa fa-heart heart text-danger"></i>
                    </div>
                </div>
            </footer>
        </div>

        <!-- Custom template | don't include it in your project! -->
        <div class="custom-template">
            <div class="title">Settings</div>
            <div class="custom-content">
                <div class="switcher">
                    <div class="switch-block">
                        <h4>Logo Header</h4>
                        <div class="btnSwitch">
                            <button type="button" class="selected changeLogoHeaderColor" data-color="dark"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="blue"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="purple"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="light-blue"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="green"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="orange"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="red"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="white"></button>
                            <br />
                            <button type="button" class="changeLogoHeaderColor" data-color="dark2"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="blue2"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="purple2"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="light-blue2"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="green2"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="orange2"></button>
                            <button type="button" class="changeLogoHeaderColor" data-color="red2"></button>
                        </div>
                    </div>
                    <div class="switch-block">
                        <h4>Navbar Header</h4>
                        <div class="btnSwitch">
                            <button type="button" class="changeTopBarColor" data-color="dark"></button>
                            <button type="button" class="changeTopBarColor" data-color="blue"></button>
                            <button type="button" class="changeTopBarColor" data-color="purple"></button>
                            <button type="button" class="changeTopBarColor" data-color="light-blue"></button>
                            <button type="button" class="changeTopBarColor" data-color="green"></button>
                            <button type="button" class="changeTopBarColor" data-color="orange"></button>
                            <button type="button" class="changeTopBarColor" data-color="red"></button>
                            <button type="button" class="selected changeTopBarColor" data-color="white"></button>
                            <br />
                            <button type="button" class="changeTopBarColor" data-color="dark2"></button>
                            <button type="button" class="changeTopBarColor" data-color="blue2"></button>
                            <button type="button" class="changeTopBarColor" data-color="purple2"></button>
                            <button type="button" class="changeTopBarColor" data-color="light-blue2"></button>
                            <button type="button" class="changeTopBarColor" data-color="green2"></button>
                            <button type="button" class="changeTopBarColor" data-color="orange2"></button>
                            <button type="button" class="changeTopBarColor" data-color="red2"></button>
                        </div>
                    </div>
                    <div class="switch-block">
                        <h4>Sidebar</h4>
                        <div class="btnSwitch">
                            <button type="button" class="changeSideBarColor" data-color="white"></button>
                            <button type="button" class="selected changeSideBarColor" data-color="dark"></button>
                            <button type="button" class="changeSideBarColor" data-color="dark2"></button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="custom-toggle">
                <i class="icon-settings"></i>
            </div>
        </div>
    </div>
    <script>
        window.AppSetting = {
            logoLight: "{{ $logoUrl }}",
            logoDark: "{{ $logoUrl }}"
        };
    </script>
    <!--   Core JS Files   -->
    <script src="/assets/js/core/jquery-3.7.1.min.js"></script>
    <script src="/assets/js/core/popper.min.js"></script>
    <script src="/assets/js/core/bootstrap.min.js"></script>

    <!-- jQuery Scrollbar -->
    <script src="/assets/js/plugin/jquery-scrollbar/jquery.scrollbar.min.js"></script>

    <!-- Chart JS -->
    <script src="/assets/js/plugin/chart.js/chart.min.js"></script>

    <!-- jQuery Sparkline -->
    <script src="/assets/js/plugin/jquery.sparkline/jquery.sparkline.min.js"></script>

    <!-- Chart Circle -->
    <script src="/assets/js/plugin/chart-circle/circles.min.js"></script>

    <!-- Datatables -->
    <script src="/assets/js/plugin/datatables/datatables.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">

    <!-- Bootstrap Notify -->
    <script src="/assets/js/plugin/bootstrap-notify/bootstrap-notify.min.js"></script>

    <!-- jQuery Vector Maps -->
    <script src="/assets/js/plugin/jsvectormap/jsvectormap.min.js"></script>
    <script src="/assets/js/plugin/jsvectormap/world.js"></script>

    <!-- Sweet Alert -->
    <script src="/assets/js/plugin/sweetalert/sweetalert.min.js"></script>

    <!-- Kaiadmin JS -->
    <script src="/assets/js/kaiadmin.min.js"></script>
    <script>
        (function() {
            var buttons = document.querySelectorAll('.toggle-sidebar');
            if (!buttons.length) {
                return;
            }

            function updateToggleIcon() {
                var wrapper = document.querySelector('.wrapper');
                var collapsed = wrapper?.classList.contains('sidebar_minimize');
                buttons.forEach(function(button) {
                    button.innerHTML = collapsed ? '<i class="fas fa-chevron-right"></i>' :
                        '<i class="fas fa-chevron-left"></i>';
                });
            }
            buttons.forEach(function(button) {
                button.addEventListener('click', function() {
                    setTimeout(function() {
                        var wrapper = document.querySelector('.wrapper');
                        if (wrapper) {
                            localStorage.setItem('adminSidebarCollapsed', wrapper.classList
                                .contains('sidebar_minimize') ? '1' : '0');
                        }
                        updateToggleIcon();
                    }, 50);
                });
            });
            updateToggleIcon();
        })();
    </script>

    <script>
        window.showAppToast = function(message, type = 'success', title = '') {
            var containerId = 'appToastContainer';
            var container = document.getElementById(containerId);
            if (!container) {
                container = document.createElement('div');
                container.id = containerId;
                container.className = 'toast-container position-fixed top-0 end-0 p-4';
                container.style.zIndex = '11050';
                document.body.appendChild(container);
            }

            var toastEl = document.createElement('div');
            var resolvedType = ['success', 'danger', 'warning', 'info'].includes(type) ? type : 'info';
            toastEl.className = 'toast app-toast app-toast--' + resolvedType;
            toastEl.setAttribute('role', 'alert');
            toastEl.setAttribute('aria-live', 'assertive');
            toastEl.setAttribute('aria-atomic', 'true');

            var wrapper = document.createElement('div');
            wrapper.className = 'app-toast__content';

            var icon = document.createElement('div');
            icon.className = 'app-toast__icon';
            var icons = {
                success: '<i class="fa-solid fa-check"></i>',
                danger: '<i class="fa-solid fa-xmark"></i>',
                warning: '<i class="fa-solid fa-triangle-exclamation"></i>',
                info: '<i class="fa-solid fa-circle-info"></i>'
            };
            icon.innerHTML = icons[resolvedType] || icons.info;

            var body = document.createElement('div');
            body.className = 'app-toast__body';
            if (title) {
                body.innerHTML = '<div class="app-toast__title">' + title + '</div>' +
                    '<div class="app-toast__message">' + message + '</div>';
            } else {
                body.innerHTML = '<div class="app-toast__message">' + message + '</div>';
            }

            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'btn-close app-toast__close';
            closeBtn.setAttribute('data-bs-dismiss', 'toast');
            closeBtn.setAttribute('aria-label', 'Close');

            wrapper.appendChild(icon);
            wrapper.appendChild(body);
            wrapper.appendChild(closeBtn);
            toastEl.appendChild(wrapper);

            var progress = document.createElement('div');
            progress.className = 'app-toast__bar';
            toastEl.appendChild(progress);
            container.appendChild(toastEl);

            var toastDelay = 4500;
            var toastInstance = new bootstrap.Toast(toastEl, {
                autohide: true,
                delay: toastDelay,
            });
            progress.style.animationDuration = toastDelay + 'ms';
            toastInstance.show();
            toastEl.addEventListener('hidden.bs.toast', function() {
                toastEl.remove();
            });
        };

        window.showAppSuccess = function(message, title = '') {
            window.showAppToast(message, 'success', title);
        };

        window.showAppError = function(message, title = '') {
            window.showAppToast(message, 'danger', title);
        };
    </script>

    <!-- Kaiadmin DEMO methods, don't include it in your project! -->
    <script src="/assets/js/setting-demo.js"></script>
    <script src="/assets/js/demo.js"></script>
    {{-- <script src="{{ mix('js/app.js') }}"></script> --}}
    <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
    <!-- Thêm Axios từ CDN -->
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script>
        axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
        axios.defaults.headers.common['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').getAttribute(
            'content');

        Pusher.logToConsole = true;

        var pusher = new Pusher('ecc5ac907199f6950c31', {
            cluster: 'ap1'
        });

        var channel = pusher.subscribe('orders');

        var approvalChannel = pusher.subscribe('product-approvals');
        approvalChannel.bind('product.approval.updated', function(data) {
            if (data && data.store_id) {
                var match = window.location.pathname.match(/\/admin\/stores\/([^\/]+)\/edit/);
                if (match) {
                    var currentStoreId = match[1];
                    if (currentStoreId === String(data.store_id)) {
                        var pendingCount = Number(data.pending_count ?? 0);
                        var tabButtons = document.querySelectorAll('#tab-store-product-approvals');
                        tabButtons.forEach(function(tabButton) {
                            if (!tabButton) return;

                            var badge = tabButton.querySelector('span.badge');
                            if (pendingCount > 0) {
                                if (badge) {
                                    badge.textContent = pendingCount;
                                } else {
                                    var newBadge = document.createElement('span');
                                    newBadge.className = 'badge bg-warning text-dark ms-1';
                                    newBadge.textContent = pendingCount;
                                    tabButton.appendChild(newBadge);
                                }
                            } else if (badge) {
                                badge.remove();
                            }
                        });

                        var panelBadges = document.querySelectorAll(
                            '#pane-store-product-approvals .badge.bg-warning.text-dark');
                        panelBadges.forEach(function(panelBadge) {
                            panelBadge.textContent = pendingCount + ' chờ duyệt';
                        });

                        var approvalTabIsOpen = Array.from(tabButtons).some(function(tabButton) {
                            return tabButton.classList.contains('active') || tabButton.getAttribute('aria-selected') ===
                                'true';
                        });

                        if (approvalTabIsOpen) {
                            window.location.reload();
                            return;
                        }
                    }
                }
            }

            // Real-time sidebar update for pending products count
            if (data && typeof data.global_pending_count !== 'undefined') {
                var globalPendingCount = Number(data.global_pending_count);
                var productBadge = document.getElementById('pending-products-badge');
                var productLink = document.querySelector('a[href="{{ route('admin.product-approvals.index') }}"]');

                if (productLink) {
                    if (globalPendingCount > 0) {
                        if (!productBadge) {
                            productBadge = document.createElement('span');
                            productBadge.id = 'pending-products-badge';
                            productBadge.className = 'sb-badge badge bg-danger';
                            productLink.appendChild(productBadge);
                        }
                        productBadge.style.display = 'inline-block';
                        productBadge.innerText = String(globalPendingCount);
                    } else if (productBadge) {
                        productBadge.remove();
                    }
                }

                if (typeof window.updateCuaHangTotalBadge === 'function') {
                    window.updateCuaHangTotalBadge();
                }
            }
        });

        var storeApprovalChannel = pusher.subscribe('store-approvals');
        storeApprovalChannel.bind('store.approval.updated', function(data) {
            if (data && typeof data.pending_count !== 'undefined') {
                var pendingCount = Number(data.pending_count);
                var storeBadge = document.getElementById('pending-stores-badge');
                var storeLink = document.querySelector('a[href="{{ route('admin.store-approvals.index') }}"]');

                if (storeLink) {
                    if (pendingCount > 0) {
                        if (!storeBadge) {
                            storeBadge = document.createElement('span');
                            storeBadge.id = 'pending-stores-badge';
                            storeBadge.className = 'sb-badge badge bg-danger';
                            storeLink.appendChild(storeBadge);
                        }
                        storeBadge.style.display = 'inline-block';
                        storeBadge.innerText = String(pendingCount);
                    } else if (storeBadge) {
                        storeBadge.remove();
                    }
                }

                if (typeof window.updateCuaHangTotalBadge === 'function') {
                    window.updateCuaHangTotalBadge();
                }
            }
        });

        var clientVerificationChannel = pusher.subscribe('client-user-verifications');
        clientVerificationChannel.bind('client.user.verification.updated', function(data) {
            var pendingCount = Number(data && data.pending_count ? data.pending_count : 0);
            var link = document.querySelector('a[href="{{ route('admin.users.index', ['tab' => 'customers']) }}"]');
            var badge = document.getElementById('pending-client-verifications-badge');

            if (link) {
                if (pendingCount > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.id = 'pending-client-verifications-badge';
                        badge.className = 'sb-badge badge bg-danger';
                        link.appendChild(badge);
                    }
                    badge.textContent = String(pendingCount);
                } else if (badge) {
                    badge.remove();
                }
            }

            // Update parent header badge
            var parentLink = document.getElementById('nguoi-dung-link');
            var totalBadge = document.getElementById('total-nguoi-dung-pending-badge');
            if (parentLink) {
                if (pendingCount > 0) {
                    if (!totalBadge) {
                        totalBadge = document.createElement('span');
                        totalBadge.id = 'total-nguoi-dung-pending-badge';
                        totalBadge.className = 'sb-badge badge bg-danger';
                        var caret = parentLink.querySelector('.sb-caret');
                        if (caret) {
                            parentLink.insertBefore(totalBadge, caret);
                        } else {
                            parentLink.appendChild(totalBadge);
                        }
                    }
                    totalBadge.textContent = String(pendingCount);
                } else if (totalBadge) {
                    totalBadge.remove();
                }
            }

            if (typeof window.showAppSuccess === 'function') {
                var action = String(data && data.action ? data.action : '');
                var message = action === 'submitted'
                    ? 'Có hồ sơ xác thực người dùng mới.'
                    : (action === 'deleted'
                        ? 'Một hồ sơ xác thực vừa được xóa.'
                        : 'Trạng thái hồ sơ xác thực vừa thay đổi.');
                window.showAppSuccess(message, 'Quản lý người dùng');
            }

            window.dispatchEvent(new CustomEvent('client-user-verification-updated', {
                detail: data || {}
            }));
        });

        channel.bind('new.order.placed', function(data) {
            console.log(data);
            location.reload();

            $.notify({
                icon: 'icon-bell',
                title: 'Đơn hàng mới',
                message: 'Bạn có 1 đơn hàng mới từ' + data.order.name,
            }, {
                type: 'secondary',
                placement: {
                    from: "bottom",
                    align: "right"
                },
                time: 3000,
            });
            const data2 = {
                audience_scope: "admin_shared",
                type: "order",
                title: "Đơn hàng mới",
                message: "Bạn có một đơn hàng mới từ " + data.order.name,
                order_id: data.order.id
            };

            axios.post('/admin/notifications', data2)
                .then(response => {
                    console.log('Thông báo đã được thêm:', response);
                    // Có thể reload bảng thông báo hoặc làm gì đó sau khi thêm thông báo
                    // loadNotifications(); // Hàm load lại thông báo

                })
                .catch(error => {
                    console.error('Lỗi khi thêm thông báo:', error);
                });


        });
        $("#lineChart").sparkline([102, 109, 120, 99, 110, 105, 115], {
            type: "line",
            height: "70",
            width: "100%",
            lineWidth: "2",
            lineColor: "#177dff",
            fillColor: "rgba(23, 125, 255, 0.14)",
        });

        $("#lineChart2").sparkline([99, 125, 122, 105, 110, 124, 115], {
            type: "line",
            height: "70",
            width: "100%",
            lineWidth: "2",
            lineColor: "#f3545d",
            fillColor: "rgba(243, 84, 93, .14)",
        });

        $("#lineChart3").sparkline([105, 103, 123, 100, 95, 105, 115], {
            type: "line",
            height: "70",
            width: "100%",
            lineWidth: "2",
            lineColor: "#ffa534",
            fillColor: "rgba(255, 165, 52, .14)",
        });
    </script>
    @yield('scripts')
</body>

</html>
