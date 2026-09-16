@extends('layout')

@section('title', 'Trang chủ')
@section('content')
    <!-- Banner -->
    <div class="banner">
        <img src="https://tttctt.1cdn.vn/2024/10/22/msb_1.jpg" alt="Banner">
    </div>

    <!-- Product Selection Box -->
    <div class="product-select-box">
        <h6>Chọn danh mục sản phẩm</h6>
        <div class="row row-cols-4 g-2 mt-2">
            <div class="col text-center">
                <button class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa fa-mobile-screen fa-lg mb-1"></i><br>Điện thoại
                </button>
            </div>
            <div class="col text-center">
                <button class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa fa-laptop fa-lg mb-1"></i><br>Laptop
                </button>
            </div>
            <div class="col text-center">
                <button class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa fa-headphones fa-lg mb-1"></i><br>Phụ kiện
                </button>
            </div>
            <div class="col text-center">
                <button class="btn btn-outline-secondary btn-sm w-100">
                    <i class="fa fa-tv fa-lg mb-1"></i><br>TV
                </button>
            </div>
        </div>
    </div>

    <div class="product-section px-3 mt-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0 fw-semibold">Sản phẩm bán chạy</h5>
            <a href="#" class="text-muted small">Xem thêm</a>
        </div>

        <div class="swiper mySwiper">
            <div class="swiper-wrapper">

                <!-- SP 1 -->
                @foreach ($products as $product)
                    <div class="swiper-slide">
                        <div class="product-card p-2 rounded-3 shadow-sm bg-white">
                            <a href="{{ route('product.show', $product->slug) }}">
                                <img src="{{ asset('storage/' . $product->thumbnail) }}" class="w-100 rounded-2 mb-2"
                                    alt="">
                            </a>
                            <div class="small text-dark fw-medium">{{ $product->name }}</div>
                            <div class="fw-bold text-danger">{{ number_format($product->sale_price, 0, ',', '.') }} VND
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-1">
                                <div class="small text-muted">
                                    <i class="fa fa-star text-warning"></i> 4.9 (0 đã bán)
                                </div>
                                <div>
                                    <a href="{{ route('cart.add', $product->id) }}"
                                        class="btn btn-light rounded-circle border">
                                        <i class="fa fa-plus"></i>
                                    </a>
                                    <a href="#" class="btn btn-light rounded-circle border"
                                        onclick="copyReferralLink('{{ route('product.show', $product->slug) }}?ref={{ auth()->id() }}')">
                                        <i class="fa fa-share"></i>
                                    </a>
                                </div>


                            </div>
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

    <script>
        var swiper = new Swiper(".mySwiper", {
            slidesPerView: 1,
            spaceBetween: 15,
            freeMode: true,
        });

        function copyReferralLink(link) {
            navigator.clipboard.writeText(link).then(() => {
                alert('Link giới thiệu đã được sao chép!');
            }).catch(err => {
                console.error('Không thể sao chép link', err);
            });
        }
    </script>
@endsection
