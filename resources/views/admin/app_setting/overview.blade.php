@extends('admin.layout')

@section('content')
@php
    $isSaleAdmin = \App\Support\AdminCmsAccess::isSale(auth()->user());
@endphp
<style>
    /* Design Tokens */
    :root {
        --sett-bg: #f8fafc;
        --sett-card-bg: #ffffff;
        --sett-border: #e2e8f0;
        --sett-text-primary: #1e293b;
        --sett-text-secondary: #64748b;
        --sett-icon-bg: #f1f5f9;
        --sett-icon-color: #334155;
        --sett-accent: #3b82f6;
        --sett-accent-bg: rgba(59, 130, 246, 0.08);
        --sett-radius: 12px;
        --sett-transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .sett-page-container {
        padding: 24px;
        background-color: var(--sett-bg);
        min-height: calc(100vh - 120px);
    }

    .sett-header {
        margin-bottom: 28px;
    }

    .sett-header h1 {
        font-size: 22px;
        font-weight: 700;
        color: var(--sett-text-primary);
        margin-bottom: 6px;
    }

    .sett-header p {
        font-size: 14px;
        color: var(--sett-text-secondary);
        margin: 0;
    }

    .sett-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }

    @media (max-width: 1200px) {
        .sett-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .sett-grid {
            grid-template-columns: 1fr;
        }
    }

    .sett-card {
        background-color: var(--sett-card-bg);
        border: 1px solid var(--sett-border);
        border-radius: var(--sett-radius);
        padding: 20px;
        display: flex;
        align-items: flex-start;
        gap: 16px;
        text-decoration: none;
        color: inherit;
        transition: var(--sett-transition);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }

    .sett-card:hover {
        transform: translateY(-2px);
        border-color: var(--sett-accent);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
        text-decoration: none;
    }

    .sett-card:hover .sett-card-title {
        color: var(--sett-accent);
    }

    .sett-card-icon-wrapper {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background-color: var(--sett-icon-bg);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: var(--sett-transition);
    }

    .sett-card:hover .sett-card-icon-wrapper {
        background-color: var(--sett-accent-bg);
    }

    .sett-card-icon-wrapper i {
        font-size: 18px;
        color: var(--sett-icon-color);
        transition: var(--sett-transition);
    }

    .sett-card:hover .sett-card-icon-wrapper i {
        color: var(--sett-accent);
    }

    .sett-card-content {
        flex-grow: 1;
    }

    .sett-card-title {
        font-size: 15px;
        font-weight: 600;
        color: var(--sett-text-primary);
        margin: 0 0 4px 0;
        transition: var(--sett-transition);
    }

    .sett-card-desc {
        font-size: 12.5px;
        color: var(--sett-text-secondary);
        line-height: 1.5;
        margin: 0;
    }

    /* Style for Expandable Cards (Accordion) */
    .sett-card-expandable {
        cursor: pointer;
        position: relative;
        display: flex;
        flex-direction: column;
        padding: 0 !important;
        overflow: hidden;
        background-color: var(--sett-card-bg);
        border: 1px solid var(--sett-border);
        border-radius: var(--sett-radius);
        transition: var(--sett-transition);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
    }

    .sett-card-expandable:hover {
        transform: translateY(-2px);
        border-color: var(--sett-accent);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
    }

    .sett-card-expandable.active {
        border-color: var(--sett-accent);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02);
    }

    .sett-card-main {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        padding: 20px;
        width: 100%;
    }

    .sett-card-expandable:hover .sett-card-title {
        color: var(--sett-accent);
    }

    .sett-card-expandable:hover .sett-card-icon-wrapper {
        background-color: var(--sett-accent-bg);
    }

    .sett-card-expandable:hover .sett-card-icon-wrapper i {
        color: var(--sett-accent);
    }

    .sett-card-toggle {
        margin-left: auto;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 6px;
        background-color: var(--sett-icon-bg);
        color: var(--sett-text-secondary);
        transition: var(--sett-transition);
        flex-shrink: 0;
        align-self: center;
    }

    .sett-card-expandable:hover .sett-card-toggle {
        background-color: var(--sett-accent-bg);
        color: var(--sett-accent);
    }

    .sett-card-expandable.active .sett-card-toggle {
        background-color: var(--sett-accent);
        color: #fff;
        transform: rotate(90deg);
    }

    .sett-card-dropdown {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        background-color: #f8fafc;
        width: 100%;
        border-top: 0px solid var(--sett-border);
    }

    .sett-card-expandable.active .sett-card-dropdown {
        max-height: 250px;
        border-top: 1px solid var(--sett-border);
    }

    .sett-dropdown-item {
        display: flex;
        align-items: center;
        padding: 12px 20px 12px 40px;
        color: var(--sett-text-secondary);
        text-decoration: none;
        font-size: 13.5px;
        font-weight: 500;
        transition: var(--sett-transition);
        border-bottom: 1px solid #f1f5f9;
    }

    .sett-dropdown-item:last-child {
        border-bottom: none;
    }

    .sett-dropdown-item:hover {
        background-color: var(--sett-accent-bg);
        color: var(--sett-accent);
        text-decoration: none;
        padding-left: 45px;
    }

    .sett-dropdown-item i {
        font-size: 10px;
        margin-right: 8px;
        opacity: 0.7;
    }
</style>

<div class="sett-page-container">
    <div class="sett-header">
        <h1>Cài đặt hệ thống</h1>
        <p>Quản lý cấu hình chung, phân quyền, thanh toán, vận chuyển và các thiết lập hệ thống khác</p>
    </div>

    <div class="sett-grid">
        <!-- 1. Cấu hình chung -->
        <a href="{{ route('admin.app-setting.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-cog"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Cấu hình chung</h3>
                <p class="sett-card-desc">Cấu hình thông tin chung cửa hàng của bạn</p>
            </div>
        </a>

        <!-- 2. Tài khoản -->
        <a href="{{ route('admin.users.index', ['tab' => 'accounts']) }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-user-alt"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Tài khoản</h3>
                <p class="sett-card-desc">Quản lý tài khoản, nhóm tài khoản và phân quyền</p>
            </div>
        </a>

        <!-- 3. Nhóm phân quyền -->
        <a href="{{ route('admin.permissions.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-users-cog"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Nhóm phân quyền</h3>
                <p class="sett-card-desc">Cung cấp thông tin cơ bản và cấp phép cho nhóm quyền</p>
            </div>
        </a>

        <!-- 4. Thanh toán -->
        <a href="{{ route('admin.payment-settings.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-credit-card"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Thanh toán</h3>
                <p class="sett-card-desc">Quản lý, cấu hình phương thức thanh toán</p>
            </div>
        </a>

        @unless($isSaleAdmin)
        <!-- 5. Danh sách ngân hàng -->
        <a href="{{ route('admin.banks.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-university"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Danh sách ngân hàng</h3>
                <p class="sett-card-desc">Quản lý danh sách các ngân hàng liên kết</p>
            </div>
        </a>

        <!-- 6. Yêu cầu rút tiền -->
        <a href="{{ route('admin.bank-accounts.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-hand-holding-usd"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Yêu cầu rút tiền</h3>
                <p class="sett-card-desc">Quản lý và duyệt các yêu cầu rút tiền</p>
            </div>
        </a>

        <!-- 7. Cấu hình danh mục -->
        <a href="{{ route('admin.product-categories.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-list-ul"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Cấu hình danh mục</h3>
                <p class="sett-card-desc">Quản lý danh mục sản phẩm</p>
            </div>
        </a>

        <!-- 8. Cấu hình Header/Home -->
        <a href="{{ route('admin.product-categories.home-config') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-desktop"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Cấu hình Header/Home</h3>
                <p class="sett-card-desc">Cấu hình hiển thị danh mục tại Header và Trang chủ</p>
            </div>
        </a>

        <!-- Cấu hình Footer -->
        <a href="{{ route('admin.menus.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-sitemap"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Cấu hình Footer</h3>
                <p class="sett-card-desc">Quản lý các liên kết, menu và biểu tượng mạng xã hội ở phần Footer</p>
            </div>
        </a>

        <!-- 9. Cấu hình Phòng quay (Expandable Card) -->
        <div class="sett-card sett-card-expandable" id="card-phong-quay">
            <div class="sett-card-main">
                <div class="sett-card-icon-wrapper">
                    <i class="fas fa-dice"></i>
                </div>
                <div class="sett-card-content">
                    <h3 class="sett-card-title">Phòng quay</h3>
                    <p class="sett-card-desc">Cấu hình phòng quay, danh sách phòng mẫu và phòng quay</p>
                </div>
                <div class="sett-card-toggle" title="Xem danh mục con">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
            <div class="sett-card-dropdown">
                <a href="{{ route('admin.room-schedules.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Cấu hình phòng quay
                </a>
                <a href="{{ route('admin.room-templates.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Danh sách phòng mẫu
                </a>
                <a href="{{ route('admin.rooms.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Danh sách phòng quay
                </a>
            </div>
        </div>

        <!-- 10. Cấu hình Giải thưởng (Expandable Card) -->
        <div class="sett-card sett-card-expandable" id="card-giai-thuong">
            <div class="sett-card-main">
                <div class="sett-card-icon-wrapper">
                    <i class="fas fa-award"></i>
                </div>
                <div class="sett-card-content">
                    <h3 class="sett-card-title">Giải thưởng</h3>
                    <p class="sett-card-desc">Cấu hình giải thưởng, danh sách người trúng, tạo voucher, quà tặng</p>
                </div>
                <div class="sett-card-toggle" title="Xem danh mục con">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
            <div class="sett-card-dropdown">
                <a href="{{ route('admin.prize-settings.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Cấu hình giải thưởng
                </a>
                <a href="{{ route('admin.winners.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Danh sách người trúng
                </a>
                <a href="{{ route('admin.vouchers.create') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Tạo voucher
                </a>
                <a href="{{ route('admin.rewards.create') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Tạo quà tặng
                </a>
            </div>
        </div>

        <!-- 11. Vé (Ticket) (Expandable Card) -->
        <div class="sett-card sett-card-expandable" id="card-ticket">
            <div class="sett-card-main">
                <div class="sett-card-icon-wrapper">
                    <i class="fas fa-ticket-alt"></i>
                </div>
                <div class="sett-card-content">
                    <h3 class="sett-card-title">Vé (Ticket)</h3>
                    <p class="sett-card-desc">Cấu hình loại vé, danh sách vé đã phát và chưa sử dụng</p>
                </div>
                <div class="sett-card-toggle" title="Xem danh mục con">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
            <div class="sett-card-dropdown">
                <a href="{{ route('admin.ticket-stats.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Tổng số Ticket đã phát
                </a>
                <a href="{{ route('admin.tickets.unused') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Ticket chưa dùng
                </a>
                <a href="{{ route('admin.ticket-settings.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Cấu hình loại Ticket
                </a>
            </div>
        </div>

        <!-- 12. Quản lý Banner -->
        <a href="{{ route('admin.banners.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="far fa-images"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Quản lý Banner</h3>
                <p class="sett-card-desc">Cấu hình và quản lý các banner hiển thị trên ứng dụng/website</p>
            </div>
        </a>

        <!-- 13. Cấu hình Affiliate (Expandable Card) -->
        <div class="sett-card sett-card-expandable" id="card-affiliate">
            <div class="sett-card-main">
                <div class="sett-card-icon-wrapper">
                    <i class="fas fa-bullhorn"></i>
                </div>
                <div class="sett-card-content">
                    <h3 class="sett-card-title">Affiliate</h3>
                    <p class="sett-card-desc">Báo cáo Affiliate và đối soát thanh toán Affiliate</p>
                </div>
                <div class="sett-card-toggle" title="Xem danh mục con">
                    <i class="fas fa-bars"></i>
                </div>
            </div>
            <div class="sett-card-dropdown">
                <a href="{{ route('admin.affiliate.reports.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Affiliate Reports
                </a>
                <a href="{{ route('admin.affiliate.payout-batches.index') }}" class="sett-dropdown-item">
                    <i class="fas fa-chevron-right"></i> Đối soát & thanh toán
                </a>
            </div>
        </div>
        @endunless
        @unless($isSaleAdmin)
        {{-- Security: Manual Top-up --}}
        <a href="{{ route('admin.topup-security-settings.index') }}" class="sett-card">
            <div class="sett-card-icon-wrapper">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div class="sett-card-content">
                <h3 class="sett-card-title">Bảo mật — Nạp hạn mức</h3>
                <p class="sett-card-desc">Cấu hình mã khóa truy cập &amp; OTP cho chức năng nạp hạn mức thủ công của Admin hệ thống</p>
            </div>
        </a>
        @endunless
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const expandableCards = document.querySelectorAll('.sett-card-expandable');
        
        expandableCards.forEach(card => {
            const mainSection = card.querySelector('.sett-card-main');
            mainSection.addEventListener('click', function(e) {
                // Ignore clicks if they occurred on a link inside the dropdown
                if (e.target.closest('.sett-dropdown-item')) {
                    return;
                }
                
                const isActive = card.classList.contains('active');
                
                // Close other expandable cards (accordion style)
                expandableCards.forEach(c => {
                    if (c !== card) {
                        c.classList.remove('active');
                        c.querySelector('.sett-card-toggle i').className = 'fas fa-bars';
                    }
                });
                
                if (!isActive) {
                    card.classList.add('active');
                    card.querySelector('.sett-card-toggle i').className = 'fas fa-times';
                } else {
                    card.classList.remove('active');
                    card.querySelector('.sett-card-toggle i').className = 'fas fa-bars';
                }
            });
        });
    });
</script>
@endsection
