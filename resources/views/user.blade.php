@extends('layout')

@section('title', 'Tài khoản')
@section('content')
    {{-- <div class="navbar">
    <div class="d-flex justify-content-between align-items-center bg-white gap-3">
        <img src="{{ asset('logo.png') }}" alt="ST SHINE" width="50" height="50">
        <div class="d-flex flex-column text-right">
            <span>{{ auth()->check() ? 'Đã đăng ký cộng tác viên' : 'Chưa đăng ký cộng tác viên' }}</span>
            <span>Hạng: {{ auth()->check() ? 'Thành viên cũ' : 'Thành viên mới' }}</span>
        </div>
    </div>
</div> --}}

    <div class="profile-section mt-4 d-flex align-items-center justify-content-end m-3 gap-3"
        style="flex-direction: row-reverse">
        <div>
            <span>Xin chào, <span class="fw-bold">{{ auth()->user()->full_name ?? '' }}</span> </span>
        </div>
        <div class="ml-3">
            @if (auth()->user())
                <img src="{{ asset('storage/' . auth()->user()->avatar) }}" alt="Profile" width="50" height="50"
                    class="rounded-circle">
            @endif
        </div>
    </div>

    <div class="container mt-4">
        <a href="{{ route('orders.history') }}" class="text-decoration-none">
            <div
                class="menu-item border p-3 mb-3 d-flex align-items-center bg-white gap-3 shadow-sm rounded hover-shadow transition">
                <i class="fas fa-box"></i>
                <div class="ml-3">
                    <div class="fw-bold text-dark">Lịch sử đơn hàng</div>
                    <small class="text-muted">Theo dõi đơn hàng đã đặt</small>
                </div>
            </div>
        </a>

        <a href="{{ route('account.accoutPayment') }}" class="text-decoration-none">
            <div
                class="menu-item border p-3 mb-3 d-flex align-items-center bg-white gap-3 shadow-sm rounded hover-shadow transition">
                <i class="fas fa-credit-card"></i>
                <div class="ml-3">
                    <div class="fw-bold text-dark">Thông tin thanh toán</div>
                    <small class="text-muted">Tài khoản nhận tiền hoa hồng</small>
                </div>
            </div>
        </a>
        <a href="{{ route('article_detail', "chinh-sach-cong-tac-vien") }}" class="text-decoration-none">

            <div class="menu-item border p-3 mb-3 d-flex align-items-center bg-white gap-3 shadow-sm rounded hover-shadow transition">
                <i class="fas fa-users"></i>
                <div class="ml-3">
                    <span class="fw-bold text-dark">Chính sách cộng tác viên</span><br>
                    <small class="text-muted">Chính sách bán hàng CTV</small>
                </div>
            </div>
        </a>

        <a href="{{ route('editUser') }}" class="text-decoration-none">

            <div class="menu-item border p-3 mb-3 d-flex align-items-center bg-white gap-3 shadow-sm rounded hover-shadow transition">
                <i class="fas fa-info-circle"></i>
                <div class="ml-3">
                    <span class="fw-bold text-dark">Tài khoản</span><br>
                    <small class="text-muted">Cập nhật thông tin tài khoản</small>
                </div>
            </div>
        </a>

        <div class="menu-item border p-3 mb-3 d-flex align-items-center bg-white gap-3">
            <i class="fas fa-gift"></i>
            <div class="ml-3">
                <span>Kho Voucher</span><br>
                <small>Các voucher khuyến mãi</small>
            </div>
        </div>
        <div class="menu-item border p-3 mb-3 d-flex align-items-center bg-white gap-3">
            <i class="fas fa-cogs"></i>
            <div class="ml-3">
                <span>Tạo icon app trên màn hình chính</span><br>
                <small>Dễ dàng truy cập miniapp hơn</small>
            </div>
        </div>
    </div>
@endsection
