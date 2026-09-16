@extends('layout')

@section('title', $product->name)

@section('content')
    @php
        $images = json_decode($product->images, true) ?? [];
    @endphp
    <style>
        .bottom-nav {
            display: none;
        }

        .bottom-nav2 {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 55%;
            background: white;
            border-top: 2px solid #ccc;
            z-index: 999;
            display: flex;
            justify-content: space-around;
            padding: 14px 0;
        }

        .bottom-nav3 {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 45%;
            background: #152379;
            border-top: 2px solid #ccc;
            z-index: 999;
            display: flex;
            justify-content: space-around;
            padding: 14px 0;
        }

        .bottom-nav2 a {
            color: #152379;
            text-align: center;
            font-size: 13px;
            text-decoration: none;
        }

        .bottom-nav3 a {
            color: white;
            text-align: center;
            font-size: 13px;
            text-decoration: none;
        }

        .bottom-nav2 a .fa,
        .bottom-nav3 a .fa {
            display: block;
            font-size: 20px;
            margin-bottom: 3px;
        }
    </style>
    <div>
        <div class="swiper productSwiper mb-4">
            <div class="swiper-wrapper">
                <div class="swiper-slide">
                    <img src="{{ asset('storage/' . $product->thumbnail) }}" alt="Thumbnail">
                </div>

                @foreach ($images as $img)
                    <div class="swiper-slide">
                        <img src="{{ asset('storage/' . str_replace('\\', '', $img)) }}" alt="Ảnh sản phẩm">
                    </div>
                @endforeach
            </div>

            <!-- Navigation -->
            <div class="swiper-button-next"></div>
            <div class="swiper-button-prev"></div>

            <!-- Pagination -->
            <div class="swiper-pagination"></div>
        </div>
        <div class="container py-4" style="background: white">
            <div class="product-info">
                <h4 class="mb-2">{{ $product->name }}</h4>
                <p class="text-danger price mb-2">
                    @if ($product->price)
                        <span class="text-secondary text-decoration-line-through mb-1 "
                            style="margin-right: 10px;font-size: .8em">
                            {{ number_format($product->price, 0, ',', '.') }} VND
                        </span>
                    @endif
                    <span id="amout" class="fw-bold fs-5">
                        {{ number_format($product->sale_price, 0, ',', '.') }} VND
                    </span>
                </p>
                {{-- <div class="text-danger fw-bold price mb-2">
                    {{ number_format($product->sale_price, 0, ',', '.') }} VND
                </div> --}}
                <div id="stock" class="text-muted small mb-4">Tồn kho: {{ $product->stock }}</div>

            </div>
            {{-- @if (!empty($variantData))
                <div class="mt-4">
                    <h5 class="fw-bold mb-2">Lựa chọn biến thể</h5>
                    @foreach ($variantData as $index => $variant)
                        @php
                            $attributeText = implode(
                                ', ',
                                array_map(
                                    fn($k, $v) => "$k: $v",
                                    array_keys($variant['attributes']),
                                    $variant['attributes'],
                                ),
                            );
                            $isOutOfStock = $variant['stock'] == 0;
                        @endphp
                        <button class="btn btn-outline-primary variant-option d-flex flex-column align-items-center mb-2"
                            data-attributes='@json($variant['attributes'])' data-index="{{ $index }}"
                            {{ $isOutOfStock ? 'disabled' : '' }}>
                            <span>{{ $attributeText }}</span>
                        </button>
                    @endforeach
                </div>
            @endif --}}

            @if (!empty($variantData))
                @php
                    // Lấy danh sách tất cả thuộc tính
                    $allAttributes = collect($variantData)
                        ->pluck('attributes')
                        ->reduce(function ($carry, $item) {
                            foreach ($item as $key => $value) {
                                $carry[$key][] = $value;
                            }
                            return $carry;
                        }, []);

                    // Lọc trùng
                    foreach ($allAttributes as $key => $values) {
                        $allAttributes[$key] = array_unique($values);
                    }
                @endphp

                <div class="mt-4">
                    {{-- <h5 class="fw-bold mb-2">Lựa chọn biến thể</h5> --}}

                    @foreach ($allAttributes as $attrName => $options)
                        <div class="mb-2">
                            <label class="fw-bold">{{ $attrName }}:</label>
                            <div class="d-flex flex-wrap gap-2 mt-1" data-attribute="{{ $attrName }}">
                                @foreach ($options as $option)
                                    <button class="btn btn-outline-primary variant-attr-option"
                                        data-attribute="{{ $attrName }}" data-value="{{ $option }}">
                                        {{ $option }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <script>
                    const variantData = @json($variantData);
                    let selectedAttributes = {};

                    document.querySelectorAll('.variant-attr-option').forEach(button => {
                        button.addEventListener('click', () => {
                            const attribute = button.getAttribute('data-attribute');
                            const value = button.getAttribute('data-value');

                            // Cập nhật selected
                            selectedAttributes[attribute] = value;

                            // Highlight button
                            document.querySelectorAll(`[data-attribute="${attribute}"]`).forEach(btn => {
                                btn.classList.remove('active');
                            });
                            button.classList.add('active');

                            // Kiểm tra nếu đã chọn đủ tất cả thuộc tính
                            const totalAttributes = Object.keys({!! json_encode($allAttributes) !!}).length;
                            if (Object.keys(selectedAttributes).length === totalAttributes) {
                                // Tìm biến thể phù hợp
                                const foundVariant = variantData.find(variant => {
                                    return Object.entries(selectedAttributes).every(([attr, val]) => {
                                        return variant.attributes[attr] === val;
                                    });
                                });

                                if (foundVariant) {
                                    document.getElementById('amout').textContent = Number(foundVariant.sale_price)
                                        .toLocaleString('vi-VN') + ' VND';
                                    document.getElementById('stock').textContent = 'Tồn kho: ' + foundVariant.stock;
                                    console.log(foundVariant);
                                    window.selectedVariantIndex = variantData.indexOf(foundVariant);

                                }
                            }
                        });
                    });
                </script>

            @endif




            <div class="product-info">
                {{-- <a href="{{ route('cart.add', $product->id) }}" class="btn btn-danger w-100">
                <i class="fa fa-cart-plus me-1"></i> Thêm vào giỏ hàng
            </a> --}}
                <div class="d-flex justify-content-between align-items-center btn btn-primary w-100 mt-3 px-5">
                    <span class="fw-bold">
                        @if ($product->sale_price * ($product->commission_rate / 100) != 0)
                            Chia sẻ để nhận ngay
                            {{ number_format($product->sale_price * ($product->commission_rate / 100), 0, ',', '.') }}đ
                        @else
                            Chia sẻ để nhận ngay 400K
                        @endif
                    </span>
                    <i class="fas fa-share" style="font-size: 2em"></i>
                </div>
            </div>
            @if ($product->content)
                <div class="mt-4">
                    <h5 class="fw-bold mb-2">Mô tả sản phẩm</h5>
                    <div class="text-muted" style="line-height: 1.6">
                        {!! $product->content !!}
                    </div>
                </div>
            @endif
        </div>

        <div class="bottom-nav2">
            {{-- <a href="{{ route('cart.add', $product->id, 'variant_index' => $variantIndex) }}" class="d-flex align-items-center gap-2"> --}}
            <a href="{{ route('cart.add', ['productId' => $product->id]) }}" id="addToCartBtn"
                class="d-flex align-items-center gap-2">

                <i class="fa fa-cart-plus me-1"></i>
                <span class="fw-bold fs-5">Thêm vào giỏ hàng</span>
            </a>


        </div>
        <div class="bottom-nav3">
            <a href="{{ route('cart.view') }}" class="position-relative nav-icons d-flex align-items-center gap-2">
                <i class="fa fa-shopping-cart"></i>
                <span class="fw-bold fs-5">Xem giỏ hàng</span>
                {{-- <span class="badge bg-danger badge-noti">{{ collect(session('cart', []))->sum('quantity') }}</span> --}}
            </a>


        </div>
    </div>

    <!-- Swiper CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />

    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script>
    document.getElementById('addToCartBtn').addEventListener('click', function (event) {
        if (window.selectedVariantIndex !== undefined) {
            event.preventDefault();
            const url = this.getAttribute('href');
            const newUrl = url + '?variant_index=' + window.selectedVariantIndex;
            window.location.href = newUrl;
        }
    });
</script>


    <script>
        const swiper = new Swiper(".productSwiper", {
            loop: true,
            pagination: {
                el: ".swiper-pagination",
                clickable: true,
            },
            navigation: {
                nextEl: ".swiper-button-next",
                prevEl: ".swiper-button-prev",
            },
        });
    </script>

    <style>
        .swiper-slide img {
            /* height: 300px; */
            object-fit: cover;
            border-radius: 1rem;
            width: 100%;
        }

        .product-info h4 {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .price {
            font-size: 1.25rem;
        }

        .swiper {
            border-radius: 1rem;
            overflow: hidden;
        }
    </style>
@endsection
