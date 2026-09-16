@extends('admin.layout')

@section('content')
<style>
    .sc-page {
        max-width: 100%;
        padding: 0;
    }
    .sc-breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 24px;
        font-size: 13px;
    }
    .sc-back-link {
        color: #64748b;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
        transition: color 0.2s;
    }
    .sc-back-link:hover {
        color: #1a6636;
        text-decoration: none;
    }
    .sc-breadcrumb-sep {
        color: #cbd5e1;
    }
    .sc-breadcrumb-current {
        color: #475569;
        font-weight: 500;
    }
    .sc-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 28px;
    }
    .sc-header-icon-box {
        width: 56px;
        height: 56px;
        border-radius: 12px;
        border: 1px solid #d4efdf;
        background: #f4fbf7;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sc-header-text {
        flex: 1;
    }
    .sc-title {
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 4px;
    }
    .sc-subtitle {
        font-size: 13px;
        color: #64748b;
        margin: 0;
    }
    .sc-layout-grid {
        display: grid;
        grid-template-columns: 1.5fr 1fr;
        gap: 24px;
        align-items: start;
    }
    @media(max-width: 992px) {
        .sc-layout-grid {
            grid-template-columns: 1fr;
        }
    }
    .sc-main-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 32px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .sc-sec-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 20px;
    }
    .sc-sec-title {
        font-size: 15px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .sc-divider {
        border: none;
        border-top: 1px solid #f1f5f9;
        margin: 28px 0;
    }
    .sc-field {
        margin-bottom: 20px;
    }
    .sc-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
    }
    .sc-hint {
        font-size: 12px;
        color: #94a3b8;
        margin-top: 6px;
        display: block;
        line-height: 1.4;
    }
    .sc-input {
        width: 100%;
        padding: 11px 14px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 14px;
        color: #1e293b;
        box-sizing: border-box;
        transition: all 0.2s;
        background-color: #fff;
    }
    .sc-input:focus {
        outline: none;
        border-color: #1a6636;
        box-shadow: 0 0 0 3px rgba(26,102,54,0.15);
    }
    .sc-input-wrapper {
        position: relative;
    }
    .sc-toggle-password {
        position: absolute;
        right: 14px;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #94a3b8;
        font-size: 15px;
        user-select: none;
        transition: color 0.2s;
    }
    .sc-toggle-password:hover {
        color: #64748b;
    }
    .sc-badge-set {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #d4efdf;
        color: #1a6636;
        font-size: 12px;
        font-weight: 600;
        padding: 4px 12px;
        border-radius: 20px;
    }
    .sc-badge-notset {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fdecea;
        color: #c0392b;
        font-size: 12px;
        font-weight: 600;
        padding: 4px 12px;
        border-radius: 20px;
    }
    .sc-btn-save {
        background: #1a6636;
        color: #fff;
        border: none;
        padding: 12px 24px;
        font-weight: 600;
        border-radius: 8px;
        font-size: 14px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }
    .sc-btn-save:hover {
        background: #124d27;
        box-shadow: 0 4px 12px rgba(26,102,54,0.2);
    }
    .sc-alert-success {
        background: #d4efdf;
        border-left: 4px solid #27ae60;
        color: #1a6636;
        padding: 14px 16px;
        border-radius: 8px;
        font-size: 13.5px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sc-alert-error {
        background: #fdecea;
        border-left: 4px solid #e74c3c;
        color: #c0392b;
        padding: 14px 16px;
        border-radius: 8px;
        font-size: 13.5px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sc-row-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    @media(max-width:560px) {
        .sc-row-2 {
            grid-template-columns: 1fr;
        }
    }
    .sc-sidebar-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .sc-sidebar-card h4 {
        font-size: 14.5px;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 18px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sc-checklist {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .sc-checklist li {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        font-size: 13px;
        color: #475569;
        margin-bottom: 12px;
    }
    .sc-checklist li:last-child {
        margin-bottom: 0;
    }
    .sc-flow {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-top: 15px;
    }
    .sc-flow-step {
        text-align: center;
        flex: 1;
    }
    .sc-flow-icon-circle {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 8px;
        position: relative;
    }
    .sc-flow-step-num {
        position: absolute;
        bottom: -4px;
        background: #475569;
        color: #fff;
        font-size: 9px;
        font-weight: 700;
        width: 14px;
        height: 14px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #fff;
    }
    .sc-flow-step.active .sc-flow-icon-circle {
        border-color: #d4efdf;
        background: #f4fbf7;
    }
    .sc-flow-step.active .sc-flow-step-num {
        background: #27ae60;
    }
    .sc-flow-text-title {
        font-size: 11.5px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 4px;
        line-height: 1.2;
    }
    .sc-flow-text-desc {
        font-size: 10px;
        color: #64748b;
        line-height: 1.3;
    }
    .sc-flow-arrow {
        color: #cbd5e1;
        font-size: 12px;
        margin-bottom: 24px; /* Align with circles */
    }
    .sc-info-card {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        color: #475569;
        font-size: 13px;
        line-height: 1.5;
    }
</style>

<div class="sc-page">
    {{-- Breadcrumb --}}
    <div class="sc-breadcrumb">
        <a href="{{ route('admin.settings.index') }}" class="sc-back-link">
            <i class="fas fa-arrow-left"></i> Quay lại Cài đặt
        </a>
        <span class="sc-breadcrumb-sep">/</span>
        <span class="sc-breadcrumb-current">Cài đặt bảo mật</span>
    </div>

    {{-- Header --}}
    <div class="sc-header">
        <div class="sc-header-icon-box">
            <i class="fa-solid fa-shield-halved" style="color: #1a6636; font-size: 24px;"></i>
        </div>
        <div class="sc-header-text">
            <h1 class="sc-title">Cài đặt bảo mật — Nạp hạn mức thủ công</h1>
            <p class="sc-subtitle">Cấu hình mã khóa truy cập và các thông số OTP cho chức năng nạp hạn mức thủ công của Admin hệ thống.</p>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <div class="sc-alert-success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="sc-alert-error"><i class="fas fa-exclamation-triangle"></i> {{ $errors->first() }}</div>
    @endif

    {{-- Grid Layout --}}
    <div class="sc-layout-grid">
        {{-- Left column: main form --}}
        <div class="sc-main-card">
            <form action="{{ route('admin.topup-security-settings.update') }}" method="POST" autocomplete="off">
                @csrf
                @method('PUT')

                {{-- Access Code section --}}
                <div class="sc-sec-header">
                    <i class="fa-solid fa-key" style="color: #27ae60; font-size: 18px; margin-right: 8px;"></i>
                    <h3 class="sc-sec-title">Mã khóa truy cập</h3>
                </div>

                <div class="sc-status-row" style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px; font-size: 13.5px; color: #475569;">
                    <span>Trạng thái mã khóa hiện tại:</span>
                    @if($hasAccessCode)
                        <span class="sc-badge-set"><i class="fas fa-check-circle"></i> Đã cấu hình</span>
                    @else
                        <span class="sc-badge-notset"><i class="fas fa-exclamation-circle"></i> Chưa cấu hình</span>
                    @endif
                </div>

                <div class="sc-field">
                    <label class="sc-label" for="new_access_code">Mã khóa mới <small style="font-weight:400;color:#94a3b8;">(để trống nếu không đổi)</small></label>
                    <div class="sc-input-wrapper">
                        <input type="password" name="new_access_code" id="new_access_code" class="sc-input" placeholder="Nhập mã khóa mới (tối thiểu 4 ký tự)…" autocomplete="new-password" style="padding-right: 42px;">
                        <span id="toggle-new-code" class="sc-toggle-password" title="Hiện/Ẩn">👁</span>
                    </div>
                    <span class="sc-hint" style="color: #e74c3c; font-weight: 500;">Mã khóa được lưu dưới dạng hash bcrypt — không thể khôi phục, chỉ thay đổi. Mặc định ban đầu: <code>123456</code>.</span>
                </div>

                <hr class="sc-divider">

                {{-- OTP section --}}
                <div class="sc-sec-header">
                    <i class="fa-solid fa-mobile-screen-button" style="color: #2980b9; font-size: 18px; margin-right: 8px;"></i>
                    <h3 class="sc-sec-title">Cấu hình OTP</h3>
                </div>

                <div class="sc-row-2">
                    <div class="sc-field">
                        <label class="sc-label" for="otp_expiry_minutes">Thời gian hết hạn OTP (phút)</label>
                        <input type="number" name="otp_expiry_minutes" id="otp_expiry_minutes" class="sc-input" min="1" max="60" value="{{ old('otp_expiry_minutes', $otpExpiryMinutes) }}">
                        <span class="sc-hint">Từ 1 đến 60 phút. Khuyến nghị: 5 phút.</span>
                    </div>

                    <div class="sc-field">
                        <label class="sc-label" for="otp_length">Độ dài mã OTP (chữ số)</label>
                        <input type="number" name="otp_length" id="otp_length" class="sc-input" min="4" max="10" value="{{ old('otp_length', $otpLength) }}">
                        <span class="sc-hint">Từ 4 đến 10 chữ số. Khuyến nghị: 6.</span>
                    </div>
                </div>

                <div class="sc-field" style="margin-top: 15px;">
                    <label class="sc-label" for="manual_topup_otp_email">Email nhận mã OTP</label>
                    <input type="email" name="manual_topup_otp_email" id="manual_topup_otp_email" class="sc-input" placeholder="Ví dụ: security-admin@domain.com…" value="{{ old('manual_topup_otp_email', $otpEmail) }}">
                    <span class="sc-hint">Nhập email cụ thể của Super Admin hoặc người kiểm soát để nhận OTP.<br>Nếu để trống, mã OTP sẽ được gửi trực tiếp về email của tài khoản Admin đang đăng nhập thực hiện nạp tiền.</span>
                </div>

                <hr class="sc-divider">

                <button type="submit" class="sc-btn-save">
                    <i class="fa-solid fa-lock"></i> Lưu cài đặt bảo mật
                </button>
            </form>
        </div>

        {{-- Right column: sidebar cards --}}
        <div>
            {{-- Checklist --}}
            <div class="sc-sidebar-card">
                <h4>
                    <i class="fa-solid fa-shield-halved" style="color: #27ae60;"></i> Checklist bảo mật
                </h4>
                <ul class="sc-checklist">
                    <li>
                        <i class="fa-solid fa-circle-check" style="color: #27ae60; font-size: 13px; margin-top: 2px;"></i>
                        <span>Sử dụng mã khóa mạnh, tối thiểu 4 ký tự</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check" style="color: #27ae60; font-size: 13px; margin-top: 2px;"></i>
                        <span>Không chia sẻ mã khóa truy cập cho người khác</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check" style="color: #27ae60; font-size: 13px; margin-top: 2px;"></i>
                        <span>Đảm bảo email nhận OTP đang hoạt động</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check" style="color: #27ae60; font-size: 13px; margin-top: 2px;"></i>
                        <span>Kiểm tra hộp thư Spam nếu không nhận được OTP</span>
                    </li>
                    <li>
                        <i class="fa-solid fa-circle-check" style="color: #27ae60; font-size: 13px; margin-top: 2px;"></i>
                        <span>Thường xuyên cập nhật cấu hình bảo mật</span>
                    </li>
                </ul>
            </div>

            {{-- Flow --}}
            <div class="sc-sidebar-card">
                <h4>
                    <i class="fa-solid fa-arrows-spin" style="color: #1a6636;"></i> Luồng xác thực khi nạp hạn mức
                </h4>
                <div class="sc-flow">
                    <div class="sc-flow-step active">
                        <div class="sc-flow-icon-circle">
                            <i class="fa-solid fa-lock" style="color: #27ae60; font-size: 16px;"></i>
                            <span class="sc-flow-step-num">1</span>
                        </div>
                        <div class="sc-flow-text-title">Nhập mã khóa</div>
                        <div class="sc-flow-text-desc">Admin nhập mã khóa để bắt đầu nạp.</div>
                    </div>

                    <div class="sc-flow-arrow">
                        <i class="fa-solid fa-arrow-right"></i>
                    </div>

                    <div class="sc-flow-step active">
                        <div class="sc-flow-icon-circle">
                            <i class="fa-solid fa-envelope" style="color: #27ae60; font-size: 16px;"></i>
                            <span class="sc-flow-step-num">2</span>
                        </div>
                        <div class="sc-flow-text-title">Nhận OTP</div>
                        <div class="sc-flow-text-desc">Hệ thống gửi OTP đến email.</div>
                    </div>

                    <div class="sc-flow-arrow">
                        <i class="fa-solid fa-arrow-right"></i>
                    </div>

                    <div class="sc-flow-step active">
                        <div class="sc-flow-icon-circle">
                            <i class="fa-solid fa-circle-check" style="color: #27ae60; font-size: 16px;"></i>
                            <span class="sc-flow-step-num">3</span>
                        </div>
                        <div class="sc-flow-text-title">Xác thực OTP</div>
                        <div class="sc-flow-text-desc">Nhập OTP để hoàn tất.</div>
                    </div>
                </div>
            </div>

            {{-- Important note --}}
            <div class="sc-info-card">
                <h5 style="margin: 0 0 8px; font-weight: 700; color: #1e293b; font-size: 13.5px; display: flex; align-items: center; gap: 6px;">
                    <i class="fa-solid fa-circle-info" style="color: #1a6636;"></i> Lưu ý quan trọng
                </h5>
                <p style="margin: 0;">Mã khóa truy cập được lưu dưới dạng hash bcrypt, không thể khôi phục. Nếu quên mã khóa, vui lòng liên hệ quản trị hệ thống để đặt lại.</p>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
document.getElementById('toggle-new-code').addEventListener('click', function () {
    const inp = document.getElementById('new_access_code');
    inp.type = inp.type === 'password' ? 'text' : 'password';
});
</script>
@endsection
