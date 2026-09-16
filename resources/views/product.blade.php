@extends('layout')

@section('title', 'Trang chủ')
@section('content')
    <!-- Banner -->
    <style>
        .top-badge {
            position: absolute;
            top: 10px;
            left: 10px;
        }

        .btn-category {
            color: #152379;
            border: 1px solid #152379;
            background-color: transparent;
        }

        .btn-category.active,
        .btn-category:hover {
            background-color: #152379;
            color: #fff;
        }
    </style>
    <div class="product-section px-3 mt-4">
        <div class=" mt-4">
            {{-- <h5 class="fw-bold mb-3">Danh sách sản phẩm</h5> --}}
            <!-- Menu danh mục -->
            <div class="mb-3 overflow-auto d-flex" style="white-space: nowrap;">
                <button class="btn btn-sm btn-category me-2 {{ request('category_id') == null ? 'active' : '' }}"
                    onclick="filterCategory('all')">Tất cả</button>
                @foreach ($categories as $category)
                    <button
                        class="btn btn-sm btn-category me-2 {{ request('category_id') == $category->id ? 'active' : '' }}"
                        onclick="filterCategory('{{ $category->id }}')">
                        {{ $category->name }}
                    </button>
                @endforeach
            </div>




            <div class="row g-3">
                <!-- Sản phẩm 1 -->
                @foreach ($products as $product)
                    <div class="col-md-6 product-item" data-category="{{ $product->category_id }}">
                        <div class="card border-0 shadow-sm rounded-3">
                            <a href="{{ route('product.show', $product->slug) }}">
                                <img src="{{ asset('storage/' . $product->thumbnail) }}" class="card-img-top rounded-top-3"
                                    alt="">
                            </a>
                            @if ($product->stock == 0)
                                <div class="top-badge">
                                    <span class="badge bg-danger">Hết hàng</span>
                                </div>
                            @endif
                            <div class="card-body p-2">
                                <h6 class="card-title text-truncate mb-1">{{ $product->name }}</h6>
                                <p class="text-danger mb-1">
                                    @if ($product->price)
                                        <span class="text-secondary text-decoration-line-through mb-1 " style="margin-right: 10px">
                                            {{ number_format($product->price, 0, ',', '.') }} VND
                                        </span>
                                    @endif
                                    <span class="fw-bold fs-5">
                                        {{ number_format($product->sale_price, 0, ',', '.') }} VND
                                    </span>
                                </p>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <div class="small text-muted">
                                        {{-- <i class="fa fa-star text-warning"></i> 4.9 --}}
                                        Bạn có thể nhận
                                        {{ number_format($product->sale_price * ($product->commission_rate / 100), 0, ',', '.') }}đ
                                    </div>
                                    {{-- <button class="btn btn-light rounded-circle border"><i class="fa fa-plus"></i></button> --}}
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
                    </div>
                @endforeach
            </div>
        </div>


    </div>
    <script>
        function copyReferralLink(link) {
            navigator.clipboard.writeText(link).then(() => {
                alert('Link giới thiệu đã được sao chép!');
            }).catch(err => {
                console.error('Không thể sao chép link', err);
            });
        }



        function filterCategory(categoryId) {
            const items = document.querySelectorAll('.product-item');
            // console.log(categoryId);

            items.forEach(item => {
                if (categoryId == 'all' || item.dataset.category == categoryId) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });

            // Active button
            const buttons = document.querySelectorAll('.btn-category');
            buttons.forEach(btn => btn.classList.remove('active'));
            if (event && event.target.classList) {
                event.target.classList.add('active');
            }

            // Update the URL with category_id
            let url = new URL(window.location.href);
            url.searchParams.set('category_id', categoryId == 'all' ? '' : categoryId); // If 'all', remove category_id
            window.history.pushState({}, '', url); // Update URL without refreshing the page
        }
        document.addEventListener('DOMContentLoaded', function() {
            // Lấy category_id từ URL
            const categoryId = new URLSearchParams(window.location.search).get('category_id');
            console.log(categoryId);

            // Kiểm tra nếu category_id = 2 thì gọi filterCategory(2)
            if (categoryId == '2') {
                filterCategory(2);
            }
            const categoryButton = document.querySelector(`[onclick="filterCategory('${categoryId}')"]`);
            if (categoryButton) {
                categoryButton.classList.add('active');
            }

        });
    </script>
@endsection
