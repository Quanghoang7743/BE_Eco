@php
    $currentAdminId = (string) optional(auth()->user())->id;
    $AppSetting = \App\Models\appSetting::first();
    $transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    $logoUrl = $AppSetting?->logo_path ? asset('storage/' . $AppSetting->logo_path) : $transparentPixel;
    $faviconUrl = $AppSetting?->favicon_path ? asset('storage/' . $AppSetting->favicon_path) : $logoUrl;
    $adminNotificationsQuery = \App\Models\Notification::query()->where(function ($scopeQuery) use ($currentAdminId) {
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

    $notifications = (clone $adminNotificationsQuery)->orderByDesc('created_at')->orderByDesc('id')->take(12)->get();

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
    $isTicketManagementOpen =
        request()->routeIs('admin.ticket-stats.*') ||
        request()->routeIs('admin.tickets.unused*') ||
        request()->routeIs('admin.ticket-settings.*');
    $isComplaintManagementOpen = request()->routeIs('admin.complaints.*');
    $isAffiliateManagementOpen = request()->routeIs('admin.affiliate.*') &&
        !request()->routeIs('admin.affiliate.reports.*') &&
        !request()->routeIs('admin.affiliate.payout-batches.*');
    $isStoreManagementOpen =
        request()->routeIs('admin.stores.*') ||
        request()->routeIs('admin.store-business-types.*') ||
        request()->routeIs('admin.store-approvals.*') ||
        request()->routeIs('admin.product-approvals.*') ||
        request()->routeIs('admin.store-wallets.*') ||
        request()->routeIs('admin.reconciliation.*') ||
        request()->routeIs('admin.store-wallet-withdrawals.*') ||
        request()->routeIs('admin.sales.performance.*');
    $isStoreWalletOpen = request()->routeIs('admin.store-wallets.*');
    $isStoreWalletWithdrawalOpen = request()->routeIs('admin.store-wallet-withdrawals.*');
    $pendingStoreWalletWithdrawals = \Illuminate\Support\Facades\Schema::hasTable('store_wallet_withdrawal_requests')
        ? \Illuminate\Support\Facades\DB::table('store_wallet_withdrawal_requests')
            ->whereIn('status', ['pending', 'approved', 'processing'])
            ->count()
        : 0;
    $isProductCategoryManagementOpen = request()->routeIs('admin.product-categories.*');
    $isOrderManagementOpen =
        request()->routeIs('admin.orders.*') ||
        request()->routeIs('admin.system-orders.*') ||
        request()->routeIs('admin.reports.consolidated-revenue');
    $isAnalyticsOpen =
        request()->routeIs('admin.reports.funnel') ||
        request()->routeIs('admin.reports.retention');
    $isSpecialistProfileOpen =
        request()->routeIs('admin.users.sales*') ||
        request()->routeIs('admin.specialist-care.*') ||
        request()->routeIs('admin.tickets.specialist*') ||
        request()->routeIs('admin.specialist-income.*') ||
        request()->routeIs('admin.specialist-settlements.*') ||
        request()->routeIs('admin.specialist-fee-configs.*') ||
        request()->routeIs('admin.specialist-ratings.*') ||
        request()->routeIs('admin.specialist-credit.*') ||
        request()->routeIs('admin.reports.specialists');
    $isBannerManagementOpen = request()->routeIs('admin.banners.*');
    $isBankManagementOpen =
        request()->routeIs('admin.payment-settings.*') ||
        request()->routeIs('admin.banks.*') ||
        request()->routeIs('admin.bank-accounts.*');
    $isHomepageBlockOpen = request()->routeIs('admin.app-setting.homepage-block*');
    $isAppSettingOpen = request()->routeIs('admin.app-setting.*') && ! $isHomepageBlockOpen;
    $isCategoryManagementOpen = request()->routeIs('admin.categories.*');
    $isArticleManagementOpen = request()->routeIs('admin.articles.*');
    
    // Corrected client user management checks
    $isClientUserManagementOpen =
        request()->routeIs('admin.user-verifications.*') ||
        (request()->routeIs('admin.users.index') && request()->query('tab') === 'verifications');
        
    $isUserManagementOpen =
        (
            request()->routeIs('admin.users.index') &&
            (request()->query('tab') === 'accounts' || !request()->has('tab'))
        ) ||
        request()->routeIs('admin.permissions.*');
        
    $isSaleAdmin = \App\Support\AdminCmsAccess::isSale(auth()->user());

    // Category Active Checks
    $isDashboardCategoryActive = request()->routeIs('admin') || $isAnalyticsOpen || request()->routeIs('admin.specialist-notifications.*');
    $isKhachHangCategoryActive = $isComplaintManagementOpen;
    $isCuaHangCategoryActive = $isStoreManagementOpen;
    $isDonHangCategoryActive = $isOrderManagementOpen;
    $isNoiDungCategoryActive = $isCategoryManagementOpen || $isArticleManagementOpen;
    $isAffiliateCategoryActive = $isAffiliateManagementOpen;
    $isNguoiDungCategoryActive = $isClientUserManagementOpen || (request()->routeIs('admin.users.index') && request()->query('tab') === 'customers');
    $isCaiDatCategoryActive = $isAppSettingOpen || $isHomepageBlockOpen || request()->routeIs('admin.menus.*') || $isBankManagementOpen || $isProductCategoryManagementOpen || $isRoomManagementOpen || $isPrizeManagementOpen || $isTicketManagementOpen || $isBannerManagementOpen || request()->routeIs('admin.affiliate.reports.*') || request()->routeIs('admin.affiliate.payout-batches.*');
    $isHeThongCategoryActive = $isUserManagementOpen;

    $pendingStoresCount = \App\Models\Store::where('status', 'pending')->count();
    $pendingProductsCount = \App\Models\Product::where('status', 'pending_approval')->count();
    $totalCuaHangPending = $pendingStoresCount + $pendingProductsCount;
    $pendingAffiliateProfiles = \Illuminate\Support\Facades\Schema::hasTable('affiliate_profiles')
        ? \App\Models\AffiliateProfile::whereIn('status', \App\Models\AffiliateProfile::REVIEWABLE_STATUSES)->count()
        : 0;
    $pendingAffWithdrawals = \Illuminate\Support\Facades\Schema::hasTable('withdraw_requests')
        ? \App\Models\WithdrawRequest::where('status', \App\Models\WithdrawRequest::STATUS_PENDING)->whereHas('user.affiliateProfile')->count()
        : 0;
    $totalAffPending = $pendingAffiliateProfiles + $pendingAffWithdrawals;
@endphp

<!-- Lucide Icons & Inter Font -->
<script src="https://unpkg.com/lucide@latest"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<style>
    :root {
        --sb-width: 280px;
        --sb-width-collapsed: 72px;
        --sb-bg: #0f172a;
        --sb-accent: #6366f1;
        --sb-accent-hover: #4f46e5;
        --sb-text: #94a3b8;
        --sb-text-active: #ffffff;
        --sb-section-title: #475569;
        --sb-border: #ffffff69;
        --sb-glass: rgba(255, 255, 255, 0.03);
    }

    body {
        font-family: 'Inter', sans-serif;
    }

    #expert-sidebar {
        width: var(--sb-width);
        height: 100vh;
        background-color: #0f172a !important;
        position: fixed;
        left: 0;
        top: 0;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 1000;
        display: flex;
        flex-direction: column;
        border-right: 1px solid var(--sb-border);
        overflow-y: auto;
        overflow-x: visible;
    }

    #expert-sidebar.collapsed {
        width: var(--sb-width-collapsed);
        display: flex;
        flex-direction: column;
        align-items: center;
        overflow-x: visible;
    }

    .sb-header {
        height: 80px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 16px 0 24px;
        border-bottom: 1px solid var(--sb-border);
        flex-shrink: 0;
    }

    .sb-logo-container {
        display: flex;
        align-items: center;
        overflow: hidden;
        transition: all 0.3s;
    }

    .collapsed .sb-logo-container {
        width: 40px;
        justify-content: center;
    }

    .sb-logo-text {
        font-weight: 800;
        font-size: 18px;
        color: #fff;
        margin-left: 12px;
        white-space: nowrap;
        transition: opacity 0.3s, transform 0.3s;
    }

    .collapsed .sb-logo-text {
        opacity: 0;
        transform: translateX(-20px);
        pointer-events: none;
    }

    .sb-toggle-btn {
        background: var(--sb-glass);
        border: 1px solid var(--sb-border);
        color: #fff;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s;
    }

    .sb-toggle-btn:hover {
        background: var(--sb-accent);
        border-color: var(--sb-accent);
    }

    .collapsed .sb-toggle-btn {
        margin-left: auto;
        margin-right: auto;
        position: absolute;
        left: 20px;
    }

    .sb-search-container {
        padding: 16px 20px;
        flex-shrink: 0;
        transition: opacity 0.3s, height 0.3s;
    }

    .collapsed .sb-search-container {
        opacity: 0;
        height: 0;
        padding: 0;
        visibility: hidden;
    }

    .sb-search-wrapper {
        position: relative;
        background: var(--sb-glass);
        border-radius: 12px;
        padding: 8px 12px;
        display: flex;
        align-items: center;
        border: 1px solid transparent;
        transition: border 0.3s;
    }

    .sb-search-wrapper:focus-within {
        border-color: var(--sb-accent);
    }

    .sb-search-input {
        background: transparent;
        border: none;
        color: #fff;
        width: 100%;
        margin-left: 10px;
        font-size: 14px;
        outline: none;
    }

    .collapsed .sb-search-wrapper {
        justify-content: center;
        padding: 10px;
    }

    .collapsed .sb-search-input {
        display: none;
    }

    .sb-menu-wrapper {
        flex-grow: 1;
        overflow-y: auto;
        padding: 10px 0;
    }

    .sb-menu-wrapper::-webkit-scrollbar {
        width: 4px;
    }

    .sb-menu-wrapper::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
    }

    .sb-group-title {
        padding: 24px 24px 8px;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        color: var(--sb-section-title);
        letter-spacing: 1.5px;
        white-space: nowrap;
        transition: opacity 0.3s, visibility 0.3s;
    }

    .collapsed .sb-group-title {
        opacity: 0;
        visibility: hidden;
        height: 0;
        padding: 0;
        overflow: hidden;
    }

    .sb-item {
        margin: 2px 16px;
        position: relative;
    }

    .sb-link {
        display: flex;
        align-items: center;
        padding: 12px 14px;
        color: var(--sb-text);
        text-decoration: none;
        border-radius: 12px;
        font-weight: 500;
        font-size: 14px;
        transition: all 0.3s;
        cursor: pointer;
    }

    .sb-link:hover {
        background: var(--sb-glass);
        color: var(--sb-text-active);
    }

    .sb-link.active {
        background: var(--sb-accent);
        color: #fff;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
    }

    .sb-link i {
        /* font-size: 18px; */
        min-width: 24px;
        transition: all 0.3s;
    }

    .sb-link span {
        margin-left: 12px;
        white-space: nowrap;
        transition: opacity 0.3s;
    }

    .collapsed .sb-link span,
    .collapsed .sb-link .sb-caret,
    .collapsed .sb-link .sb-badge {
        display: none !important;
    }

    .collapsed .sb-link {
        justify-content: center;
        padding: 14px;
    }

    /* .collapsed .sb-link i {
        font-size: 24px;
    } */

    .collapsed .sb-item:hover .sb-link {
        background: var(--sb-glass);
        border-radius: 12px;
    }

    .sb-item.expanded>.sb-link {
        color: #fff;
    }

    .collapsed .sb-link.active {
        background: var(--sb-accent);
        border-radius: 12px;
        width: 48px;
        margin: 0 auto;
        height: 48px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
    }

    .collapsed .sb-link.active span,
    .collapsed .sb-link.active .sb-badge,
    .collapsed .sb-link.active .sb-caret {
        display: none !important;
    }

    .sb-submenu {
        margin-left: 15px;
        display: none;
        border-left: 1px solid var(--sb-border);
        padding: 5px 0;
    }

    .sb-item.expanded>.sb-submenu {
        display: block;
    }

    .collapsed .sb-submenu {
        display: none !important;
    }

    .collapsed .sb-submenu.popover-active {
        display: block !important;
        visibility: visible !important;
        position: fixed;
        left: 72px;
        top: auto;
        width: 250px;
        background-color: #0f172a !important;
        border: 1px solid var(--sb-border);
        border-radius: 12px;
        box-shadow: 12px 12px 40px rgba(0, 0, 0, 0.6);
        z-index: 99999;
        margin-left: 0;
        padding: 0;
        backdrop-filter: blur(20px);
        animation: sb-flyout 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    @keyframes sb-flyout {
        0% {
            opacity: 0;
            transform: translateX(10px);
        }

        100% {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .collapsed .sb-submenu.popover-active::before {
        content: attr(data-popover-title);
        display: block;
        padding: 14px 20px;
        font-weight: 700;
        font-size: 13px;
        text-transform: uppercase;
        color: #fff;
        background: rgba(255, 255, 255, 0.05);
        border-bottom: 1px solid var(--sb-border);
        border-radius: 12px 12px 0 0;
        margin-bottom: 5px;
    }

    .collapsed .sb-submenu.popover-active .sb-sublink {
        padding: 10px 20px;
        font-size: 14px;
        display: flex !important;
    }

    .collapsed .sb-submenu.popover-active .sb-sublink .sb-badge {
        display: inline-block !important;
        margin-left: auto;
    }

    .sb-sublink {
        display: flex;
        align-items: center;
        padding: 8px 12px 8px 24px;
        color: var(--sb-text);
        font-size: 13px;
        text-decoration: none;
        transition: color 0.2s;
        opacity: 0.8;
    }

    .sb-sublink:hover {
        color: #fff;
        opacity: 1;
    }

    .sb-sublink.active {
        color: var(--sb-accent);
        opacity: 1;
        font-weight: 600;
    }

    .sb-caret {
        margin-left: auto;
        transition: transform 0.3s;
    }

    .sb-item.expanded>.sb-link .sb-caret {
        transform: rotate(180deg);
    }

    .collapsed .sb-caret {
        display: none;
    }

    .sb-badge {
        background: #ef4444;
        color: #fff;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 999px;
        margin-left: 6px;
        box-shadow: 0 0 10px rgba(239, 68, 68, 0.3);
    }

    @keyframes skeleton-loading {
        0% {
            background-position: -468px 0;
        }

        100% {
            background-position: 468px 0;
        }
    }

    .skeleton {
        background: #1e293b;
        background-image: linear-gradient(90deg, #1e293b 0px, #334155 40px, #1e293b 80px);
        background-size: 600px 100%;
        animation: skeleton-loading 2s infinite linear;
    }

    .sb-loading {
        display: flex;
        flex-direction: column;
        padding: 20px;
        gap: 15px;
    }

    .sb-loading-item {
        height: 40px;
        border-radius: 8px;
        width: 100%;
    }

    .sb-loading {
        display: none;
    }

    .is-loading .sb-menu-wrapper {
        display: none;
    }

    .is-loading .sb-loading {
        display: flex;
    }

    /* Level 2 Sub-headers inside Dropdowns */
    .sb-sub-header {
        font-size: 11px;
        font-weight: 700;
        color: var(--sb-accent);
        padding: 14px 12px 6px 24px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        opacity: 0.8;
    }

    .sb-divider {
        height: 1px;
        background: var(--sb-border);
        margin: 15px 16px;
        opacity: 0.5;
    }

    /* Collapsed state adjustments for popover */
    .collapsed .sb-submenu.popover-active {
        max-height: 85vh;
        overflow-y: auto;
    }

    .collapsed .sb-submenu.popover-active::-webkit-scrollbar {
        width: 4px;
    }
    .collapsed .sb-submenu.popover-active::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
    }

    @media (max-width: 991px) {
        #expert-sidebar {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            height: 100vh !important;
            width: 280px !important;
            transform: translateX(-100%) !important;
            background-color: #0f172a !important;
            z-index: 99999 !important;
            visibility: visible !important;
            display: flex !important;
            transition: transform 0.3s ease-in-out !important;
        }

        #expert-sidebar.show-mobile {
            transform: translateX(0) !important;
        }

        #expert-sidebar.collapsed {
            width: 280px !important;
            align-items: stretch;
        }

        .sb-toggle-btn {
            display: none !important;
        }

        .collapsed .sb-submenu.popover-active {
            display: none !important;
        }

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6) !important;
            backdrop-filter: blur(4px);
            z-index: 99998 !important;
        }

        #expert-sidebar.show-mobile~.mobile-overlay {
            display: block !important;
        }
    }

    .sb-footer-menu {
        border-top: 1px solid var(--sb-border);
        padding: 10px 0;
        background-color: #0f172a;
        flex-shrink: 0;
    }
</style>

<aside id="expert-sidebar">
    <div class="sb-header">
        <div class="sb-logo-container">
            <img src="{{ $AppSetting && $AppSetting->logo_path ? asset('storage/' . $AppSetting->logo_path) : '/assets/img/logo.svg' }}"
                alt="Logo" style="height: 55px;">
            <span class="sb-logo-text">{{ $AppSetting->app_name ?? 'ADMIN PANEL' }}</span>
        </div>
        <button class="sb-toggle-btn" onclick="toggleCollapse()" title="Toggle Sidebar">
            <i data-lucide="chevron-left" id="toggle-icon"></i>
        </button>
    </div>

    <div class="sb-loading">
        <div class="sb-loading-item skeleton"></div>
        <div class="sb-loading-item skeleton"></div>
        <div class="sb-loading-item skeleton" style="width: 80%"></div>
        <div class="sb-loading-item skeleton"></div>
        <div class="sb-loading-item skeleton" style="width: 60%"></div>
    </div>

    <nav class="sb-menu-wrapper">
        <!-- 1. Dashboard -->
        <div class="sb-item {{ $isDashboardCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link">
                <i class="fas fa-th-large"></i>
                <span>Dashboard</span>
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <a href="{{ route('admin') }}" class="sb-sublink {{ request()->routeIs('admin') ? 'active' : '' }}">
                    <span>Trang chủ</span>
                </a>
                @if(auth()->user()?->role === 'sale')
                <a href="{{ route('admin.specialist-notifications.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.specialist-notifications.*') ? 'active' : '' }}">
                    <span>Thông báo của tôi</span>
                </a>
                @endif
                @unless($isSaleAdmin)
                <a href="{{ route('admin.reports.funnel') }}"
                    class="sb-sublink {{ request()->routeIs('admin.reports.funnel') ? 'active' : '' }}">
                    <span>Funnel chuyển đổi (S41)</span>
                </a>
                <a href="{{ route('admin.reports.retention') }}"
                    class="sb-sublink {{ request()->routeIs('admin.reports.retention') ? 'active' : '' }}">
                    <span>Retention User (S42)</span>
                </a>
                @endunless
            </div>
        </div>

        <div class="sb-item">
            <a href="{{ route('admin.complaints.index') }}"
                class="sb-link {{ request()->routeIs('admin.complaints.*') ? 'active' : '' }}"
                id="khieu-nai-link">
                <i class="fas fa-exclamation-circle"></i>
                <span>Khiếu nại</span>
                @php
                    $pendingComplaints = \Illuminate\Support\Facades\Schema::hasTable('complaints')
                        ? \App\Models\Complaint::where('status', 'pending')->count()
                        : 0;
                @endphp
                @if ($pendingComplaints > 0)
                    <span id="total-khieu-nai-pending-badge" class="sb-badge badge bg-danger">{{ $pendingComplaints }}</span>
                @endif
            </a>
        </div>

        <div class="sb-item {{ $isCuaHangCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link" id="cua-hang-link">
                <i class="fas fa-store"></i>
                <span>Cửa hàng</span>
                @if ($totalCuaHangPending > 0)
                    <span id="total-cua-hang-badge" class="sb-badge badge bg-danger">{{ $totalCuaHangPending }}</span>
                @endif
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <a href="{{ route('admin.stores.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.stores.*') ? 'active' : '' }}">
                    <span>Tất cả cửa hàng</span>
                </a>
                @if(auth()->user()?->role === 'super_admin')
                <a href="{{ route('admin.store-business-types.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.store-business-types.*') ? 'active' : '' }}">
                    <span>Loại hình kinh doanh</span>
                </a>
                @endif
                <a href="{{ route('admin.store-approvals.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.store-approvals.*') ? 'active' : '' }}">
                    <span>Duyệt đăng ký cửa hàng</span>
                    @if ($pendingStoresCount > 0)
                        <span id="pending-stores-badge" class="sb-badge badge bg-danger">{{ $pendingStoresCount }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.product-approvals.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.product-approvals.*') ? 'active' : '' }}">
                    <span>Duyệt đăng ký sản phẩm</span>
                    @if ($pendingProductsCount > 0)
                        <span id="pending-products-badge" class="sb-badge badge bg-danger">{{ $pendingProductsCount }}</span>
                    @endif
                </a>
                @if(auth()->user()?->role === 'admin' || auth()->user()?->role === 'super_admin')
                <a href="{{ route('admin.store-wallets.index') }}"
                    class="sb-sublink {{ $isStoreWalletOpen ? 'active' : '' }}">
                    <span>Quản lý hạn mức cửa hàng</span>
                </a>
                <a href="{{ route('admin.store-wallet-withdrawals.index') }}"
                    class="sb-sublink {{ $isStoreWalletWithdrawalOpen ? 'active' : '' }}">
                    <span>Lệnh rút hạn mức cửa hàng</span>
                    @if ($pendingStoreWalletWithdrawals > 0)
                        <span class="sb-badge badge bg-danger">{{ $pendingStoreWalletWithdrawals }}</span>
                    @endif
                </a>
                @endif
                @if(auth()->user()?->role === 'admin' || auth()->user()?->role === 'super_admin' || auth()->user()?->role === 'accountant')
                <a href="{{ route('admin.reconciliation.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.reconciliation.*') ? 'active' : '' }}">
                    <span>Đối soát tài chính</span>
                </a>
                @endif
            </div>
        </div>

        <div class="sb-item {{ $isDonHangCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link" id="don-hang-link">
                <i class="fas fa-box"></i>
                <span>Đơn hàng</span>
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <a href="{{ route('admin.orders.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                    <span>{{ auth()->user()?->role === 'sale' ? 'Đơn hàng thành công' : 'Danh sách đơn hàng' }}</span>
                </a>
                <a href="{{ route('admin.system-orders.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.system-orders.*') ? 'active' : '' }}">
                    <span>Tất cả đơn hàng hệ thống</span>
                </a>
                <a href="{{ route('admin.reports.consolidated-revenue') }}"
                    class="sb-sublink {{ request()->routeIs('admin.reports.consolidated-revenue') ? 'active' : '' }}">
                    <span>Báo cáo doanh thu hợp nhất</span>
                </a>
            </div>
        </div>

        @unless($isSaleAdmin)
        <div class="sb-item {{ $isNoiDungCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link">
                <i class="fas fa-file-alt"></i>
                <span>Nội dung</span>
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <div class="sb-sub-header">Danh mục bài viết</div>
                <a href="{{ route('admin.categories.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.categories.index') ? 'active' : '' }}">
                    <span>Danh sách danh mục</span>
                </a>
                <a href="{{ route('admin.categories.create') }}"
                    class="sb-sublink {{ request()->routeIs('admin.categories.create') ? 'active' : '' }}">
                    <span>Thêm mới danh mục</span>
                </a>

                <div class="sb-sub-header">Bài viết</div>
                <a href="{{ route('admin.articles.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.articles.index') ? 'active' : '' }}">
                    <span>Danh sách bài viết</span>
                </a>
                <a href="{{ route('admin.articles.create') }}"
                    class="sb-sublink {{ request()->routeIs('admin.articles.create') ? 'active' : '' }}">
                    <span>Thêm mới bài viết</span>
                </a>
                @if(auth()->user()?->role === 'admin')
                    <a href="{{ route('admin.policies.index') }}"
                        class="sb-sublink {{ request()->routeIs('admin.policies.*') ? 'active' : '' }}">
                        <span>Điều khoản &amp; chính sách</span>
                    </a>
                @endif
            </div>
        </div>
        @endunless

        @unless($isSaleAdmin)
        <div class="sb-item {{ $isAffiliateCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link">
                <i class="fas fa-bullhorn"></i>
                <span>Affiliate</span>
                @if ($totalAffPending > 0)
                    <span class="sb-badge badge bg-danger">{{ $totalAffPending }}</span>
                @endif
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <a href="{{ route('admin.affiliate.dashboard') }}"
                    class="sb-sublink {{ request()->routeIs('admin.affiliate.dashboard') ? 'active' : '' }}">
                    <span>Tổng quan Affiliate</span>
                </a>
                <a href="{{ route('admin.affiliate.requests.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.affiliate.requests.*') ? 'active' : '' }}">
                    <span>Duyệt hồ sơ Affiliate</span>
                    @if (isset($pendingAffiliateProfiles) && $pendingAffiliateProfiles > 0)
                        <span class="sb-badge badge bg-danger">{{ $pendingAffiliateProfiles }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.affiliate.customers.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.affiliate.customers.*') ? 'active' : '' }}">
                    <span>Khách hàng Affiliate</span>
                </a>
                <a href="{{ route('admin.affiliate.commissions.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.affiliate.commissions.*') ? 'active' : '' }}">
                    <span>Hoa hồng Affiliate</span>
                </a>
                <a href="{{ route('admin.affiliate.withdrawals.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.affiliate.withdrawals.*') ? 'active' : '' }}">
                    <span>Yêu cầu rút tiền Affiliate</span>
                    @if (isset($pendingAffWithdrawals) && $pendingAffWithdrawals > 0)
                        <span class="sb-badge badge bg-danger">{{ $pendingAffWithdrawals }}</span>
                    @endif
                </a>
            </div>
        </div>
        @endunless

        @unless($isSaleAdmin)
        <div class="sb-item {{ $isNguoiDungCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link" id="nguoi-dung-link">
                <i class="fas fa-user-shield"></i>
                <span>Người dùng</span>
                @php
                    $pendingClientVerifications = \Illuminate\Support\Facades\Schema::hasTable('client_user_verifications')
                        ? \App\Models\ClientUserVerification::where('status', \App\Models\ClientUserVerification::STATUS_PENDING)->count()
                        : 0;
                @endphp
                @if ($pendingClientVerifications > 0)
                    <span id="total-nguoi-dung-pending-badge" class="sb-badge badge bg-danger">{{ $pendingClientVerifications }}</span>
                @endif
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <a href="{{ route('admin.users.index', ['tab' => 'customers']) }}"
                    class="sb-sublink {{ request()->routeIs('admin.users.index') && request()->query('tab') === 'customers' ? 'active' : '' }}">
                    <span>Danh sách khách hàng</span>
                    @if ($pendingClientVerifications > 0)
                        <span id="pending-client-verifications-badge" class="sb-badge badge bg-danger">{{ $pendingClientVerifications }}</span>
                    @endif
                </a>
            </div>
        </div>

        <div class="sb-item {{ $isHeThongCategoryActive ? 'expanded' : '' }}">
            <a class="sb-link">
                <i class="fas fa-cogs"></i>
                <span>Hệ thống</span>
                <i class="fas fa-chevron-down sb-caret"></i>
            </a>
            <div class="sb-submenu">
                <div class="sb-sub-header">Tài khoản</div>
                <a href="{{ route('admin.users.index', ['tab' => 'accounts']) }}"
                    class="sb-sublink {{ request()->routeIs('admin.users.index') && !in_array(request()->query('tab'), ['customers', 'roles'], true) ? 'active' : '' }}">
                    <span>Danh sách tài khoản</span>
                </a>
                <a href="{{ route('admin.permissions.index') }}"
                    class="sb-sublink {{ request()->routeIs('admin.permissions.*') ? 'active' : '' }}">
                    <span>Vai trò &amp; Phân quyền</span>
                </a>
            </div>
        </div>
        @endunless

        <div class="sb-group-title">QUẢN LÝ CHUYÊN VIÊN</div>

        @if(auth()->user()?->role === 'sale')
            {{-- ===== MENU CHO TÀI KHOẢN CHUYÊN VIÊN (SALE) ===== --}}

            {{-- Đã có chăm sóc của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.specialist-care.*') ? 'expanded' : '' }}">
                <a href="{{ route('admin.specialist-care.index') }}"
                    class="sb-link {{ request()->routeIs('admin.specialist-care.*') ? 'active' : '' }}">
                    <i class="fas fa-store"></i>
                    <span>Đã có chăm sóc của tôi</span>
                </a>
            </div>

            {{-- Lucky Ticket của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.tickets.specialist*') ? 'expanded' : '' }}">
                <a href="{{ route('admin.tickets.specialist') }}"
                    class="sb-link {{ request()->routeIs('admin.tickets.specialist*') ? 'active' : '' }}">
                    <i class="fas fa-ticket-alt"></i>
                    <span>Lucky Ticket của tôi</span>
                </a>
            </div>

            {{-- Thu nhập của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.specialist-income.*') ? 'expanded' : '' }}">
                <a href="{{ route('admin.specialist-income.index') }}"
                    class="sb-link {{ request()->routeIs('admin.specialist-income.*') ? 'active' : '' }}">
                    <i class="fas fa-circle-dollar-to-slot"></i>
                    <span>Thu nhập của tôi</span>
                </a>
            </div>

            {{-- Đối soát của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.specialist-settlements.*') ? 'expanded' : '' }}">
                <a href="{{ route('admin.specialist-settlements.index') }}"
                    class="sb-link {{ request()->routeIs('admin.specialist-settlements.*') ? 'active' : '' }}">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>Đối soát của tôi</span>
                </a>
            </div>

            {{-- Đánh giá sao của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.specialist-ratings.*') ? 'expanded' : '' }}">
                <a href="{{ route('admin.specialist-ratings.index') }}"
                    class="sb-link {{ request()->routeIs('admin.specialist-ratings.*') ? 'active' : '' }}">
                    <i class="fas fa-star"></i>
                    <span>Đánh giá sao của tôi</span>
                </a>
            </div>

            {{-- Điểm tín dụng của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.specialist-credit.*') ? 'expanded' : '' }}">
                <a href="{{ route('admin.specialist-credit.index') }}"
                    class="sb-link {{ request()->routeIs('admin.specialist-credit.*') ? 'active' : '' }}">
                    <i class="fas fa-coins"></i>
                    <span>Điểm tín dụng của tôi</span>
                </a>
            </div>

            {{-- Báo cáo của tôi --}}
            <div class="sb-item {{ request()->routeIs('admin.reports.specialists') ? 'expanded' : '' }}">
                <a href="{{ route('admin.reports.specialists') }}"
                    class="sb-link {{ request()->routeIs('admin.reports.specialists') ? 'active' : '' }}">
                    <i class="fas fa-chart-bar"></i>
                    <span>Báo cáo của tôi</span>
                </a>
            </div>

        @else
            {{-- ===== MENU CHO ADMIN TỔNG / SUPER ADMIN ===== --}}
            {{-- Tất cả quản lý chuyên viên đã nằm trong trang chi tiết chuyên viên --}}
            <div class="sb-item {{ $isSpecialistProfileOpen ? 'expanded' : '' }}">
                <a href="{{ route('admin.users.sales') }}"
                    class="sb-link {{ $isSpecialistProfileOpen ? 'active' : '' }}">
                    <i class="fas fa-id-badge"></i>
                    <span>Danh sách chuyên viên</span>
                </a>
            </div>
        @endif
    </nav>

    @if(!$isSaleAdmin || auth()->user()?->hasRole('super_admin') || auth()->user()?->can('MENU_VIEW'))
    <div class="sb-footer-menu">
        <div class="sb-item">
            <a href="{{ route('admin.settings.index') }}" 
               class="sb-link {{ (request()->routeIs('admin.settings.index') || $isCaiDatCategoryActive) ? 'active' : '' }}">
                <i class="fas fa-cog"></i>
                <span>Cài đặt</span>
            </a>
        </div>
    </div>
    @endif
</aside>

<div class="mobile-overlay" onclick="toggleMobile()"></div>

<script>
    window.toggleSub = function(target) {
        const link = target.closest('.sb-link');
        if (!link) return;

        const item = link.closest('.sb-item');
        const sidebar = document.getElementById('expert-sidebar');
        const submenu = item.querySelector('.sb-submenu');

        if (sidebar && sidebar.classList.contains('collapsed')) {
            if (!submenu) return;
            const isAlreadyActive = submenu.classList.contains('popover-active');
            document.querySelectorAll('.sb-submenu.popover-active').forEach(i => i.classList.remove(
                'popover-active'));
            if (!isAlreadyActive) {
                const rect = link.getBoundingClientRect();
                submenu.style.top = rect.top + 'px';
                submenu.classList.add('popover-active');
                
                // Adjust position if popover overflows screen height
                const submenuRect = submenu.getBoundingClientRect();
                const viewportHeight = window.innerHeight;
                if (submenuRect.bottom > viewportHeight) {
                    const diff = submenuRect.bottom - viewportHeight;
                    const newTop = Math.max(10, rect.top - diff - 10);
                    submenu.style.top = newTop + 'px';
                }
            }
        } else if (item) {
            const wasExpanded = item.classList.contains('expanded');
            
            // Close all other expanded sb-items
            document.querySelectorAll('.sb-item.expanded').forEach(otherItem => {
                if (otherItem !== item) {
                    otherItem.classList.remove('expanded');
                }
            });
            
            if (wasExpanded) {
                item.classList.remove('expanded');
            } else {
                item.classList.add('expanded');
            }
        }
    }

    window.toggleCollapse = function() {
        const sidebar = document.getElementById('expert-sidebar');
        const icon = document.getElementById('toggle-icon');
        const isCollapsed = sidebar.classList.toggle('collapsed');
        if (icon) {
            icon.setAttribute('data-lucide', isCollapsed ? 'chevron-right' : 'chevron-left');
            lucide.createIcons();
        }
        document.body.classList.toggle('sidebar-collapsed-mode', isCollapsed);
        localStorage.setItem('sidebar_collapsed', isCollapsed);
    }

    window.toggleMobile = function() {
        const sidebar = document.getElementById('expert-sidebar');
        if (sidebar) {
            const isShowing = sidebar.classList.toggle('show-mobile');
            console.log('Mobile menu toggled:', isShowing);

            document.documentElement.classList.remove('nav_open');
            document.body.classList.remove('sidebar_minimize', 'static-sidebar');

            if (window.innerWidth <= 991) {
                sidebar.classList.remove('collapsed');
            }
        }
    }

    document.addEventListener('click', function(e) {
        const toggleBtn = e.target.closest('.sidenav-toggler, .toggle-sidebar, .btn-toggle, .sb-toggle-btn');
        if (toggleBtn) {
            e.preventDefault();
            e.stopImmediatePropagation();
            console.log('Sidebar Toggle Triggered');

            document.querySelectorAll('.sb-submenu.popover-active').forEach(i => i.classList.remove(
                'popover-active'));

            if (window.innerWidth <= 991) {
                toggleMobile();
            } else {
                toggleCollapse();
            }
            return;
        }

        const sidebarLink = e.target.closest('.sb-link');
        if (sidebarLink) {
            const item = sidebarLink.closest('.sb-item');
            if (item && item.querySelector('.sb-submenu')) {
                e.preventDefault();
                toggleSub(sidebarLink);
            }
            return;
        }

        if (!e.target.closest('.sb-item')) {
            document.querySelectorAll('.sb-submenu.popover-active').forEach(i => i.classList.remove(
                'popover-active'));
        }
    });

    lucide.createIcons();

    const searchInput = document.getElementById('sb-search');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            const items = document.querySelectorAll('.sb-item');
            items.forEach(item => {
                const text = item.innerText.toLowerCase();
                if (text.includes(query)) {
                    item.style.display = 'block';
                    if (query.length > 0) {
                        item.classList.add('expanded');
                    }
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }

    if (localStorage.getItem('sidebar_collapsed') === 'true' && window.innerWidth > 991) {
        const sidebar = document.getElementById('expert-sidebar');
        if (sidebar) {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed-mode');
            const icon = document.getElementById('toggle-icon');
            if (icon) icon.setAttribute('data-lucide', 'chevron-right');
        }
    } else if (window.innerWidth <= 991) {
        const sidebar = document.getElementById('expert-sidebar');
        if (sidebar) sidebar.classList.remove('collapsed');
        document.body.classList.remove('sidebar-collapsed-mode');
    }

    window.addEventListener('resize', () => {
        const sidebar = document.getElementById('expert-sidebar');
        if (window.innerWidth <= 991 && sidebar) {
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed-mode');
        }
    });

    window.addEventListener('load', () => {
        const sidebar = document.getElementById('expert-sidebar');
        if (sidebar) sidebar.classList.remove('is-loading');
    });

    window.updateCuaHangTotalBadge = function() {
        const storeBadge = document.getElementById('pending-stores-badge');
        const productBadge = document.getElementById('pending-products-badge');

        let storeCount = 0;
        if (storeBadge && storeBadge.style.display !== 'none') {
            storeCount = parseInt(storeBadge.innerText || '0', 10);
        }
        let productCount = 0;
        if (productBadge && productBadge.style.display !== 'none') {
            productCount = parseInt(productBadge.innerText || '0', 10);
        }

        if (isNaN(storeCount) || storeCount < 0) storeCount = 0;
        if (isNaN(productCount) || productCount < 0) productCount = 0;

        const total = storeCount + productCount;

        let totalBadge = document.getElementById('total-cua-hang-badge');
        if (total > 0) {
            if (!totalBadge) {
                const cuaHangLink = document.getElementById('cua-hang-link');
                if (cuaHangLink) {
                    totalBadge = document.createElement('span');
                    totalBadge.id = 'total-cua-hang-badge';
                    totalBadge.className = 'sb-badge badge bg-danger';
                    const caret = cuaHangLink.querySelector('.sb-caret');
                    if (caret) {
                        cuaHangLink.insertBefore(totalBadge, caret);
                    } else {
                        cuaHangLink.appendChild(totalBadge);
                    }
                }
            }
            if (totalBadge) {
                totalBadge.style.display = 'inline-block';
                totalBadge.innerText = total;
            }
        } else {
            if (totalBadge) {
                totalBadge.remove();
            }
        }
    };

    window.updatePendingProductsBadge = function(change = -1) {
        const badge = document.getElementById('pending-products-badge');
        if (badge) {
            let current = parseInt(badge.innerText || '0', 10);
            current += change;
            if (current <= 0) {
                badge.remove();
            } else {
                badge.innerText = current;
            }
        }
        window.updateCuaHangTotalBadge();
    };
</script>
