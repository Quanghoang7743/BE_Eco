@extends('layout')



@section('title', 'Trang đại lý')
@section('content')
    <div class="container py-3">

        <!-- Banner -->
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

        <!-- Member Section -->
        <div class="mt-3 mb-1 d-flex justify-content-center">
            <a href="{{ route('users.ambassador') }}" class="btn" style="background-color: #152379;color: white;">Nâng
                cấp
                gói thành
                viên</a>
        </div>
        <div>
            <p class="text-center">Để hưởng những quyền lợi của thành viên vàng</p>
        </div>

        <div class="wallet-box p-3 rounded-3 shadow-sm">
            <div class="d-flex justify-content-between align-items-center">

                <!-- Bên trái -->
                <div style="color: #152379;">
                    <p class="mb-1 fw-semibold">Chưa đăng ký cộng tác viên</p>
                    <p class="mb-0 fw-light small">Thành viên mới</p>
                </div>

                <!-- Bên phải -->
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <div style="color: #152379; text-align: right;">
                        <p class="mb-1 fw-light small">Xin chào,</p>
                        <p class="mb-0 fw-semibold text-uppercase">{{ $user->full_name ?? '' }}</p>
                    </div>
                    <div style="width: 48px; height: 48px; border-radius: 50%; overflow: hidden; border: 1px solid #ccc;">
                        <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/59/User-avatar.svg/2048px-User-avatar.svg.png"
                            alt="avatar" class="img-fluid">
                    </div>
                </div>

            </div>
        </div>




        <!-- Wallet -->
        <div class="wallet-box">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div>Số dư ví</div>
                    @if ($user)
                        <div class="wallet-amount" style="font-size: 2em">{{ number_format($user->balance, 0, ',', '.') }} đ
                        </div>
                    @else
                        <div class="wallet-amount">0đ
                        </div>
                    @endif
                </div>
                {{-- <button class="btn btn-outline-primary btn-sm">Lịch sử</button> --}}
            </div>
            <div class="small text-muted mt-2">
                Bạn đã có {{ number_format($commission_pending ?? 0, 0, ',', '.') }}đ đang chờ duyệt, chia sẻ để kiếm thêm!
                <i class="fas fa-paper-plane text-primary"></i>
            </div>
        </div>
        <div class="row">
            <div class="col-6">
                <div class="wallet-box">
                    <div class="d-flex align-items-center gap-3">
                        <div><i class="fa-solid fs-1 fa-sack-dollar" style="color: #152379"></i></div>
                        <div>
                            <div class="small text-muted mt-2" style="    font-size: .75em;">
                                Doanh số đội nhóm
                            </div>
                            @if ($user)
                                <div class="wallet-amount">{{ number_format($teamSales, 0, ',', '.') }}đ
                                </div>
                            @else
                                <div class="wallet-amount">0đ
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="wallet-box">
                    <div class="d-flex align-items-center gap-3">
                        <div><i class="fa-solid fs-1 fa-sack-dollar" style="color: #152379"></i></div>
                        <div>
                            <div class="small text-muted mt-2" style="    font-size: .75em;">
                                Doanh số cá nhân
                            </div>
                            @if ($user)
                                <div class="wallet-amount">{{ number_format($userSale, 0, ',', '.') }}đ
                                </div>
                            @else
                                <div class="wallet-amount">0đ
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="wallet-box">
                    <div class="d-flex align-items-center gap-3">
                        <div><i class="fa-solid fs-1 fa-sack-dollar" style="color: #152379"></i></div>
                        <div>
                            <div class="small text-muted mt-2" style="    font-size: .75em;">
                                Hoa hồng chờ duyệt
                            </div>
                            @if ($user)
                                <div class="wallet-amount">{{ number_format($commission_pending, 0, ',', '.') }}đ
                                </div>
                            @else
                                <div class="wallet-amount">0đ
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="wallet-box">
                    <div class="d-flex align-items-center gap-3">
                        <div><i class="fa-solid fs-1 fa-sack-dollar" style="color: #152379"></i></div>
                        <div>
                            <div class="small text-muted mt-2" style="    font-size: .75em;">
                                Hoa hồng đã duyệt
                            </div>
                            @if ($user)
                                <div class="wallet-amount">{{ number_format($commission_completed, 0, ',', '.') }}đ
                                </div>
                            @else
                                <div class="wallet-amount">0đ
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="wallet-box">
                    <div class="d-flex align-items-center gap-3">
                        <div><i class="fa-solid fs-1 fa-sack-dollar" style="color: #152379"></i></div>
                        <div>
                            <div class="small text-muted mt-2" style="    font-size: .75em;">
                                Đơn thành công
                            </div>
                            @if ($user)
                                <div class="wallet-amount">{{ $count_order_completed }}
                                </div>
                            @else
                                <div class="wallet-amount">0
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="wallet-box">
                    <div class="d-flex align-items-center gap-3">
                        <div><i class="fa-solid fs-1 fa-sack-dollar" style="color: #152379"></i></div>
                        <div>
                            <div class="small text-muted mt-2" style="    font-size: .75em;">
                                Số thành viên nhóm
                            </div>
                            @if ($user)
                                <div class="wallet-amount">{{ $count_user_referrer }}
                                </div>
                            @else
                                <div class="wallet-amount">0
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="wallet-box">
            <div>
                <div class="mb-2 fw-bold" style="font-size: 1.2em">Công cụ</div>
                <div class="row">
                    <div class="col-4">
                        <a href="{{ route('orders.history.affilate') }}" class="text-decoration-none">
                            <div class="d-flex flex-column align-items-center">
                                <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcS9QmbnMDHpGBLwzsWXuWwEhKZxodBxPikWnw&s"
                                    height="80">
                                <div class="wallet-amount">Đơn hàng</div>
                            </div>
                        </a>
                    </div>
                    <div class="col-4">
                        <a href="{{ route('users.member') }}" class="text-decoration-none">
                            <div class="d-flex flex-column align-items-center">
                                <img src="https://canhquan.net/Content/Images/FileUpload/customerfiles/94/images/Meet-the-Team-Team-Icon.png"
                                    height="80">

                                <div class="wallet-amount">Đội nhóm</div>
                            </div>
                        </a>

                    </div>
                    <div class="col-4">
                        <a href="{{ route('article_detail', 'chinh-sach-cong-tac-vien') }}" class="text-decoration-none">
                            <div class="d-flex flex-column align-items-center">
                                <img src="https://hocdohoaonline.com/wp-content/uploads/2015/11/long-icon-011.jpg"
                                    height="80">
                                <div class="wallet-amount">Chính sách
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

        </div>

        <div class="mt-3 mb-3">
            <button id="shareBtn"
                class="w-100 btn rounded-pill border-black d-flex justify-content-center gap-3 align-items-center"
                style="border: 1px solid #ccc;font-weight: bold;font-size: 1rem;">Chia sẻ ngay để nhận tới
                600.000đ <i class="fas fa-share" style="font-size: 2rem;color:blue"></i></button>
        </div>
        <div>
            <div class="mb-2 fw-bold text-center" style="font-size: 1.2em">3 bước để nhận hoa hồng dễ dàng</div>
            <div class="row">
                <div class="col-3">
                    <div class="d-flex flex-column align-items-center">
                        <img src="https://cdn-icons-png.flaticon.com/512/1646/1646851.png" height="80">
                        <div class="wallet-amount text-center">Chia sẻ link</div>
                    </div>
                </div>
                <div class="col-1">
                    <div class="d-flex flex-column align-items-center h-100 justify-content-center">
                        <img src="https://static.thenounproject.com/png/790923-200.png" height="30">
                        {{-- <div class="wallet-amount">Chia sẻ link</div> --}}
                    </div>
                </div>
                <div class="col-4">
                    <div class="d-flex flex-column align-items-center">
                        <img src="https://cdn-icons-png.flaticon.com/256/1057/1057240.png" height="80">

                        <div class="wallet-amount text-center">Nhận giới thiệu</div>
                    </div>

                </div>
                <div class="col-1">
                    <div class="d-flex flex-column align-items-center h-100 justify-content-center">
                        <img src="https://static.thenounproject.com/png/790923-200.png" height="30">
                        {{-- <div class="wallet-amount">Chia sẻ link</div> --}}
                    </div>
                </div>
                <div class="col-3">
                    <div class="d-flex flex-column align-items-center">
                        <img src="https://cdn-icons-png.flaticon.com/256/9603/9603812.png" height="80">
                        <div class="wallet-amount text-center">Nhận hoa hồng
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        // document.getElementById('shareBtn').addEventListener('click', function() {
        //     const shareData = {
        //         title: 'Ứng dụng mua hàng trực tuyến',
        //         text: 'Tải app và nhận tới 600.000đ!',
        //         url: window.location.origin
        //     };

        //     if (navigator.share) {
        //         // Trên thiết bị hỗ trợ Web Share API (mobile)
        //         navigator.share(shareData)
        //             .then(() => console.log('Shared successfully'))
        //             .catch((error) => console.error('Error sharing:', error));
        //     } else {
        //         // Fallback: Copy URL to clipboard (desktop)
        //         navigator.clipboard.writeText(shareData.url).then(() => {
        //             alert('Link đã được sao chép! Hãy chia sẻ với bạn bè của bạn nhé 🎉');
        //         });
        //     }
        // });
        document.getElementById('shareBtn').addEventListener('click', function() {
            const userId = "{{ auth()->user()->id }}"; // lấy user_id từ Laravel
            const shareData = {
                title: 'Ứng dụng mua hàng trực tuyến',
                text: 'Tải app và nhận tới 600.000đ!',
                url: window.location.origin + '/referrer?ref=' + userId
            };

            if (navigator.share) {
                navigator.share(shareData)
                    .then(() => console.log('Shared successfully'))
                    .catch((error) => console.error('Error sharing:', error));
            } else {
                navigator.clipboard.writeText(shareData.url).then(() => {
                    alert('Link đã được sao chép! Hãy chia sẻ với bạn bè của bạn nhé 🎉');
                });
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
    </script>



@endsection
