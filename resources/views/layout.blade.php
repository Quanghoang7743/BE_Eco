@php
    $AppSetting = \App\Models\appSetting::first();
@endphp

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $AppSetting->app_name }}">
    <meta property="og:description" content="{{ $AppSetting->description ?? '' }}">
    <meta property="og:image" content="{{ asset('storage/' . $AppSetting->logo_path) }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link rel="icon" href="{{ asset('storage/' . $AppSetting->favicon_path) }}" type="image/x-icon" />
    <style>
        body {
            background-color: #f2f4f8;
            font-family: 'Arial', sans-serif;
            padding-bottom: 80px;
            margin: 0;
        }

        img{
            width: 100%;
        }

        .header-fixed {
            position: sticky;
            top: 0;
            z-index: 1000;
            background-color: #fff;
            padding: 10px 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .banner img {
            width: 100%;
            border-radius: 10px;
        }

        .member-box,
        .wallet-box,
        .product-select-box {
            background: #fff;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .wallet-amount {
            /* font-size: 28px; */
            font-weight: bold;

        .app-toast {
            --app-toast-accent: #2563eb;
            background: #ffffff;
            color: #0f172a;
            border: 1px solid rgba(15, 23, 42, 0.12);
            border-left: 4px solid var(--app-toast-accent);
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.16);
            min-width: 280px;
            max-width: 360px;
            overflow: hidden;
        }

        .app-toast--success {
            --app-toast-accent: #16a34a;
        }

        .app-toast--danger {
            --app-toast-accent: #dc2626;
        }

        .app-toast__content {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 12px 14px;
        }

        .app-toast__icon {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, 0.12);
            color: var(--app-toast-accent);
            font-size: 14px;
            flex: 0 0 auto;
        }

        .app-toast--success .app-toast__icon {
            background: rgba(22, 163, 74, 0.12);
        }

        .app-toast--danger .app-toast__icon {
            background: rgba(220, 38, 38, 0.12);
        }

        .app-toast__title {
            font-weight: 600;
            font-size: 0.92rem;
            margin-bottom: 4px;
        }

        .app-toast__message {
            font-size: 0.88rem;
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
            color: #152379;
        }

        .btn-rounded {
            border-radius: 50px;
        }

        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #152379;
            border-top: 1px solid #ddd;
            z-index: 999;
            display: flex;
            justify-content: space-around;
            padding: 10px 0;
        }

        .bottom-nav a {
            color: white;
            text-align: center;
            font-size: 13px;
            text-decoration: none;
        }

        .bottom-nav a .fa {
            display: block;
            font-size: 20px;
            margin-bottom: 3px;
        }

        .badge-noti {
            font-size: 10px;
            position: absolute;
            top: -3px;
            right: -6px;
        }

        .nav-icons {
            position: relative;
        }
    </style>
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css" />
    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js"></script>

</head>

<body>

    <!-- Sticky Header -->
    <div class="header-fixed d-flex justify-content-between align-items-center">
        <img src="{{ asset('storage/' . $AppSetting->logo_path) }}" alt="logo" style="width: 40px">
        <div>
            {{-- <i class="fas fa-search me-3 fs-3"></i>
            <span class="position-relative me-3 fs-3 nav-icons">
                <i class="fas fa-bell"></i>
                <span class="badge bg-danger badge-noti">0</span>
            </span> --}}
            <span class="position-relative fs-3 nav-icons">
                <a href="{{ route('cart.view') }}" style="color: unset">
                    <i class="fas fa-shopping-cart"></i>
                    <span class="badge bg-danger badge-noti">{{ collect(session('cart', []))->sum('quantity') }}</span>
                </a>
            </span>
        </div>
    </div>

    @yield('content')
    @if (session('success') || session('error'))
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
            <div id="liveToast"
                class="toast app-toast {{ session('success') ? 'app-toast--success' : 'app-toast--danger' }} show"
                role="alert" aria-live="assertive" aria-atomic="true">
                <div class="app-toast__content">
                    <div class="app-toast__icon">
                        @if (session('success'))
                            <i class="fa-solid fa-check"></i>
                        @else
                            <i class="fa-solid fa-xmark"></i>
                        @endif
                    </div>
                    <div class="app-toast__body">
                        <div class="app-toast__title">
                            {{ session('success') ? 'Thành công' : 'Lỗi' }}
                        </div>
                        <div class="app-toast__message">
                            {{ session('success') ?? session('error') }}
                        </div>
                    </div>
                    <button type="button" class="btn-close app-toast__close" data-bs-dismiss="toast"
                        aria-label="Close"></button>
                </div>
                <div class="app-toast__bar" style="animation-duration: 4000ms;"></div>
            </div>
        </div>
    @endif

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <a href="{{ route('home') }}">
            <i class="fa fa-home"></i>
            Trang chủ
        </a>
        <a href="{{ route('getAllProduct') }}">
            <i class="fa fa-box"></i>
            Sản phẩm
        </a>

        <a href="{{ route('cart.view') }}" class="position-relative nav-icons">
            <i class="fa fa-shopping-cart"></i>
            Giỏ hàng
            <span class="badge bg-danger badge-noti">{{ collect(session('cart', []))->sum('quantity') }}</span>
        </a>
        <a href="/agency">
            <i class="fa fa-id-badge"></i>
            Đại lý
        </a>
        <a href="{{ route('account.index') }}">
            <i class="fa fa-user"></i>
            Tài khoản
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.addEventListener('DOMContentLoaded', () => {
            const toastEl = document.getElementById('liveToast');
            if (toastEl) {
                const toast = new bootstrap.Toast(toastEl, {
                    autohide: true,
                    delay: 4000,
                });
                toast.show();
            }
        });
    </script>

</body>

</html>
