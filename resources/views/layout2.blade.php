@php
    use Illuminate\Support\Facades\DB;
    use Carbon\Carbon;
    use App\Models\User;
    $AppSetting = \App\Models\appSetting::first();
    $banners = \App\Models\banner::where('position', 1)->where('status', 1)->get();
    $user = Auth::user();
    $products = \App\Models\Product::with('category', 'group')->latest()->take(10)->get();
    $topProducts = \App\Models\Product::select(
        'products.id',
        'products.name',
        'products.slug',
        'products.thumbnail',
        'products.sale_price',
        'products.stock',
        'products.price',
        DB::raw('SUM(order_items.quantity) as total_sold'),
    )
        ->join('order_items', 'products.id', '=', 'order_items.product_id')
        ->groupBy(
            'products.id',
            'products.name',
            'products.slug',
            'products.thumbnail',
            'products.sale_price',
            'products.stock',
            'products.price',
        )
        ->orderByDesc('total_sold')
        ->take(10)
        ->get();

    $articles = \App\Models\article::with('category')->where('display_area', 'article')->latest()->take(10)->get();
    $menu1 = \App\Models\banner::where('position', 2)->where('status', 1)->get();
    // Controller
    $finalTop = DB::table('order_items')
        ->select('referrer_id', DB::raw('SUM(price * quantity) as total_sales'))
        ->whereNotNull('referrer_id')
        ->groupBy('referrer_id')
        ->orderByDesc('total_sales')
        ->take(20)
        ->get()
        ->map(function ($item) {
            $user = User::find($item->referrer_id);
            return [
                'id' => $item->referrer_id,
                'name' => $user?->full_name ?? 'Không rõ',
                'avatar_url' => $user?->avatar,
                'total_sales' => $item->total_sales,
            ];
        });

    $oldUser = session()->get('oldUser');
    if ($oldUser) {
        session()->put('oldUser', true);
    }

@endphp

<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $AppSetting->app_name }}</title>
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $AppSetting->app_name }}">
    <meta property="og:description" content="{{ $AppSetting->description ?? $AppSetting->app_name }}">
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
            /* margin-top: 15px; */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .wallet-amount {
            font-size: 28px;
            font-weight: bold;
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

        .backgound {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 200px;
            background-color: #152379;
            border-bottom-left-radius: 70px;
            border-bottom-right-radius: 70px;
        }

        swiper-container {
            width: 100%;
        }

        swiper-slide {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            padding: 20px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }


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
        .avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 10px;
            border: 3px solid #ddd;
        }

        .name {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .sales {
            color: #d97706;
            font-weight: bold;
        }

        .top-badge {
            position: absolute;
            top: 10px;
            left: 10px;
        }
    </style>
    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.css" />
    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js"></script>

</head>

<body>
    <div style="position: relative">
        <div class="backgound">
        </div>
        <div class="header" style="position: absolute;">
            <div class="logo-top d-flex align-items-center gap-3" style="position: absolute; top: 50px; left: 20px;">
                <img src="{{ asset('storage/' . $AppSetting->favicon_path) }}" alt=""
                    style="background: white; border-radius: 50%; height: 70px;">
                <span class="text-white fs-3 fw-bold text-uppercase text-nowrap">
                    {{ $AppSetting->app_name ?? '' }}
                </span>
            </div>
        </div>
    </div>
    <div class="container" style="padding-top: 130px">
        <div class="wallet-box p-3 rounded-4 shadow-sm" style="position: relative">
            <div class="d-flex justify-content-between align-items-center">
                <div style="color: #152379;">
                    <p class="mb-1 fw-semibold">Tài khoản xác thực</p>
                    <p class="mb-0 fw-light small">Thành viên mới</p>
                </div>
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <div style="color: #152379; text-align: right;">
                        <p class="mb-1 fw-light small">Xin chào,</p>
                        <p class="mb-0 fw-semibold text-uppercase">{{ $user->full_name ?? '' }}</p>
                    </div>
                    <div style="width: 48px;border-radius: 50%; overflow: hidden; border: 1px solid #ccc;">
                        @if ($user)
                            <img src="{{ asset('storage/' . $user->avatar) }}" alt="" class="img-fluid">
                        @else
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/59/User-avatar.svg/2048px-User-avatar.svg.png"
                                alt="avatar" class="img-fluid">
                        @endif
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <div class="text-decoration-none d-flex justify-content-between">
                    <a href="{{ route('account.agency') }}" class="text-decoration-none">
                        <div class="ml-3 d-flex flex-column align-items-center">
                            <div class="fw-bold fs-1" style="color: #152379">
                                <i class="fas fa-medal"></i>
                            </div>
                            <small class="text-muted">Tích điểm</small>
                        </div>
                    </a>

                    <a href="{{ route('orders.history') }}" class="text-decoration-none">

                        <div class="ml-3 d-flex flex-column align-items-center">
                            <div class="fw-bold fs-1" style="color: #152379"><i class="fas fa-cart-arrow-down"></i>
                            </div>
                            <small class="text-muted">Đơn hàng</small>
                        </div>
                    </a>

                    <a href="tel:0855862466" class="text-decoration-none">
                        <div class="ml-3 d-flex flex-column align-items-center">
                            <div class="fw-bold fs-1" style="color: #152379">
                                <i class="fas fa-comments"></i>
                            </div>
                            <small class="text-muted">Liên hệ</small>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <div class="wallet-box p-3 rounded-4 shadow-sm mt-3">
            <div class="text-decoration-none d-flex justify-content-start gap-4">
                @foreach ($menu1 as $menu)
                    <a href="{{ $menu->link }}" class="text-decoration-none">
                        <div class="ml-3 d-flex flex-column align-items-center">
                            <div style="width: 48px;border-radius: 50%; overflow: hidden; border: 1px solid #ccc;">
                                @if ($menu)
                                    <img src="{{ asset('storage/' . $menu->image) }}" alt="{{ $menu->title }}"
                                        class="img-fluid">
                                @else
                                    <img src="{{ asset('storage/' . $AppSetting->logo_path) }}" alt=""
                                        class="img-fluid">
                                @endif
                            </div>
                            <small class="text-muted mt-2 text-uppercase fw-bold">{{ $menu->title }}</small>
                        </div>
                    </a>
                @endforeach
                {{-- <div class="ml-3 d-flex flex-column align-items-center">
                    <div style="width: 48px;border-radius: 50%; overflow: hidden; border: 1px solid #ccc;">
                        @if ($AppSetting->logo_path)
                            <img src="{{ asset('storage/' . $AppSetting->logo_path) }}" alt=""
                                class="img-fluid">
                        @else
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/59/User-avatar.svg/2048px-User-avatar.svg.png"
                                alt="avatar" class="img-fluid">
                        @endif
                    </div>
                    <small class="text-muted mt-2 text-uppercase fw-bold">COMBO</small>
                </div> --}}
            </div>
        </div>

        <!-- Swiper Banner -->
        <div class="swiper banner-swiper mt-3">
            <div class="swiper-wrapper">
                @foreach ($banners as $banner)
                    <div class="swiper-slide">
                        <a href="{{ $banner->link }}">
                            <img src="{{ asset('storage/' . $banner->image) }}" alt="{{ $banner->title }}"
                                class="img-fluid rounded-3 w-100">
                        </a>
                    </div>
                @endforeach

            </div>
            {{-- <!-- Optional navigation --> --}}
            <div class="swiper-pagination"></div>
        </div>

        <div class="d-flex justify-content-between align-items-end mt-3">
            <span class="fw-bold fs-4" style="color: #152379">Sản phẩm bán chạy</span>
            <a href="{{ route('getAllProduct') }}" class="text-decoration-none" style="color: #ccc">Xem thêm</a>
        </div>
        <div class="swiper mySwiper mt-2 ">
            <div class="swiper-wrapper">
                @foreach ($topProducts as $product)
                    <div class="swiper-slide">
                        <div class="product-card p-2 rounded-3 shadow-sm bg-white">
                            <a href="{{ route('product.show', $product->slug) }}">
                                <img src="{{ asset('storage/' . $product->thumbnail) }}" class="w-100 rounded-2 mb-2"
                                    alt="">
                            </a>
                            @if ($product->stock == 0)
                                <div class="top-badge">
                                    <span class="badge bg-danger">Hết hàng</span>
                                </div>
                            @endif
                            <div class="small text-dark fw-medium">{{ $product->name }}</div>
                            <div>
                                <p class="text-danger">
                                    @if ($product->price)
                                        <span class="text-secondary text-decoration-line-through mb-1 "
                                            style="margin-right: 10px">
                                            {{ number_format($product->price, 0, ',', '.') }} VND
                                        </span>
                                    @endif
                                    <span class="fw-bold fs-5">
                                        {{ number_format($product->sale_price, 0, ',', '.') }} VND
                                    </span>
                                </p>
                                {{-- {{ number_format($product->sale_price, 0, ',', '.') }} VND --}}
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <div class="small text-muted">
                                    <i class="fa fa-star text-warning"></i> 4.9 ({{ $product->total_sold }} đã bán)
                                </div>
                                <div>
                                    <a href="{{ route('cart.add', $product->id) }}"
                                        class="btn btn-light rounded-circle border">
                                        <i class="fa fa-plus"></i>
                                    </a>
                                    <button class="btn btn-light rounded-circle border share-product-btn"
                                        onclick="copyReferralLink('{{ route('product.show', $product->slug) }}?ref={{ auth()->id() }}')">
                                        <i class="fa fa-share"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-end mt-4">
            <span class="fw-bold fs-4" style="color: #152379">Top thu nhập cao nhất</span>
        </div>
        <swiper-container class="topReferrersSwiper" init="false">
            @foreach ($finalTop as $index => $user)
                <swiper-slide>
                    @if ($user['avatar_url'])
                        <img src="{{ asset('storage/' . $user['avatar_url']) }}" alt="{{ $user['name'] }}"
                            class="avatar">
                    @else
                        <img src="https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg"
                            alt="{{ $user['name'] }}" class="avatar">
                    @endif

                    @if ($index == 0)
                        <div class="top-badge">
                            <span class="badge bg-warning text-dark">TOP {{ $index + 1 }}</span>
                        </div>
                    @elseif ($index == 1)
                        <div class="top-badge">
                            <span class="badge bg-secondary text-white">TOP {{ $index + 1 }}</span>
                        </div>
                    @elseif ($index == 2)
                        <div class="top-badge">
                            <span class="badge bg-muted text-dark">TOP {{ $index + 1 }}</span>
                        </div>
                    @else
                        <div class="top-badge">
                            <span class="badge bg-light text-dark">TOP {{ $index + 1 }}</span>
                        </div>
                    @endif
                    <div class="name text-center">{{ $user['name'] }}</div>
                    <div class="sales text-center">{{ number_format($user['total_sales'], 0, ',', '.') }}₫</div>
                </swiper-slide>
            @endforeach
        </swiper-container>

        <div class="d-flex justify-content-between align-items-end mt-4">
            <span class="fw-bold fs-4" style="color: #152379">Tin tức</span>
            {{-- <span style="color: #ccc">Xem thêm</span> --}}
        </div>
        <div class="swiper mySwiper mt-2 ">
            <div class="swiper-wrapper">
                @foreach ($articles as $article)
                    <div class="swiper-slide">
                        <div class="">
                            <a href="{{ route('article_detail', $article->slug) }}">
                                <img src="{{ asset('storage/' . $article->image) }}" class="w-100 rounded-4 mb-2"
                                    style="height: 300px; object-fit: cover;" alt="Article Image">
                            </a>
                            <h5 class="text-dark fw-bold">{{ $article->title }}</h5>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-end mt-5">
            <span class="fw-bold fs-4" style="color: #152379">Gợi ý cho bạn</span>
            <a href="{{ route('getAllProduct') }}" class="text-decoration-none" style="color: #ccc">Xem thêm</a>
        </div>
        <div class="swiper mySwiper mt-2 ">
            <div class="swiper-wrapper">
                @foreach ($products as $product)
                    <div class="swiper-slide">
                        <div class="product-card p-2 rounded-3 shadow-sm bg-white">
                            <a href="{{ route('product.show', $product->slug) }}">
                                <img src="{{ asset('storage/' . $product->thumbnail) }}" class="w-100 rounded-2 mb-2"
                                    alt="">
                            </a>
                            @if ($product->stock == 0)
                                <div class="top-badge">
                                    <span class="badge bg-danger">Hết hàng</span>
                                </div>
                            @endif
                            <div class="small text-dark fw-medium">{{ $product->name }}</div>
                            {{-- <div class="fw-bold text-danger">{{ number_format($product->sale_price, 0, ',', '.') }}
                                VND</div> --}}
                            <div>
                                <p class="text-danger">
                                    @if ($product->price)
                                        <span class="text-secondary text-decoration-line-through mb-1 "
                                            style="margin-right: 10px">
                                            {{ number_format($product->price, 0, ',', '.') }} VND
                                        </span>
                                    @endif
                                    <span class="fw-bold fs-5">
                                        {{ number_format($product->sale_price, 0, ',', '.') }} VND
                                    </span>
                                </p>
                                {{-- {{ number_format($product->sale_price, 0, ',', '.') }} VND --}}
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <div class="small text-muted">
                                    <i class="fa fa-star text-warning"></i> 4.9
                                </div>
                                <div>
                                    <a href="{{ route('cart.add', $product->id) }}"
                                        class="btn btn-light rounded-circle border">
                                        <i class="fa fa-plus"></i>
                                    </a>
                                    <button id="shareProductBtn2" class="btn btn-light rounded-circle border"
                                        onclick="shareProduct('{{ $product->name }}', '{{ route('product.show', $product->slug) }}?ref={{ auth()->id() }}')">
                                        <i class="fa fa-share"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/swiper@10/swiper-bundle.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-element-bundle.min.js"></script>

    <script>
        const swiperEl = document.querySelector('swiper-container');

        Object.assign(swiperEl, {
            loop: true,
            spaceBetween: 20,
            autoplay: {
                delay: 3000,
                disableOnInteraction: false,
            },
            breakpoints: {
                "@0.00": {
                    slidesPerView: 1,
                    spaceBetween: 10,
                },
                "@0.75": {
                    slidesPerView: 2,
                    spaceBetween: 20,
                },
                "@1.00": {
                    slidesPerView: 3,
                    spaceBetween: 30,
                },
                "@1.50": {
                    slidesPerView: 4,
                    spaceBetween: 40,
                },
            },
        });

        swiperEl.initialize();
    </script>
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

        const swiper = new Swiper('.banner-swiper', {
            loop: true,
            autoplay: {
                delay: 3000,
                disableOnInteraction: false,
            },
            pagination: {
                el: '.swiper-pagination',
                clickable: true,
            },
        });
        new Swiper(".mySwiper", {
            slidesPerView: 1,
            spaceBetween: 10,
            breakpoints: {
                576: {
                    slidesPerView: 2,
                    spaceBetween: 15,
                },
                768: {
                    slidesPerView: 3,
                    spaceBetween: 20,
                }
            },
            loop: false,
            autoplay: false,
        });

        function copyReferralLink(link) {
            navigator.clipboard.writeText(link).then(() => {
                alert('Link giới thiệu đã được sao chép!');
            }).catch(err => {
                console.error('Không thể sao chép link', err);
            });
        }

        function shareProduct(name, url) {
            const shareData = {
                title: name,
                text: `Khám phá sản phẩm "${name}" này nhé!`,
                url: url
            };

            if (navigator.share) {
                navigator.share(shareData)
                    .then(() => console.log('✅ Chia sẻ thành công'))
                    .catch((err) => console.warn('❌ Chia sẻ bị huỷ hoặc lỗi:', err));
            } else {
                navigator.clipboard.writeText(url)
                    .then(() => alert('🔗 Link sản phẩm đã được sao chép!'))
                    .catch(() => alert('❌ Không thể sao chép link'));
            }
        }
    </script>
</body>

</html>
