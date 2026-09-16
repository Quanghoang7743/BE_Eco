@php
    $banner = \App\Models\banner::where('position', 1)->where('status', 1)->first();
@endphp
@extends('layout')

@section('title', 'Chính sách cộng tác viên')
@section('content')
    <div class="container ">
        <div class="row">
            {{-- <div class="col-md-8"> --}}
            <!-- Bài viết chi tiết -->
            <div class="article-detail card border-0 shadow-sm rounded-3">
                <img src="{{ asset($banner->image) }}" class="card-img-top rounded-3" alt="Article Image">
                <div class="card-body">
                    <h2 class="card-title fw-bold mb-3">Chính sách cộng tác viên</h2>
                    {{-- <p class="small text-muted mb-3">Đăng vào {{ $article->created_at->format('d/m/Y') }}  --}}

                    <!-- Nội dung bài viết -->
                    <div class="content">
                        <h1>🔥 CƠ HỘI KIẾM TIỀN CÙNG ST SKIN – ĐĂNG KÝ AFFILIATE NGAY! 🔥</h1>

                        <p>Bạn muốn bắt đầu kinh doanh mà <strong>không cần vốn nhập hàng</strong>?<br>
                            Bạn đang tìm kiếm một <strong>nguồn thu nhập thụ động bền vững</strong>?<br>
                            👉 <strong>Hãy trở thành Affiliate của ST SKIN ngay hôm nay</strong> và tận hưởng những quyền
                            lợi hấp dẫn chưa từng có!</p>

                        <hr>

                        <h2>✅ Quyền lợi khi trở thành Affiliate của ST SKIN:</h2>

                        <h3>🎓 Khóa học Kinh doanh thực chiến 7 ngày – Trị giá 10.000.000 VND</h3>
                        <ul>
                            <li>Miễn phí 100% cho Affiliate mới</li>
                            <li>Trang bị đầy đủ kiến thức thực chiến để bùng nổ doanh thu ngay từ những ngày đầu</li>
                        </ul>

                        <h3>💰 Chiết khấu hấp dẫn 20% - 60%</h3>
                        <ul>
                            <li>Áp dụng cho tất cả sản phẩm trong hệ sinh thái ST SKIN</li>
                            <li>Tiết kiệm chi phí cho bạn khi sử dụng hoặc giới thiệu sản phẩm</li>
                        </ul>

                        <h3>💸 Hoa hồng lên đến 20%</h3>
                        <ul>
                            <li>Tính trên <strong>giá trị sản phẩm bán ra</strong></li>
                            <li><strong>Không cần nhập hàng, không lo tồn kho</strong>, chỉ cần tập trung giới thiệu sản
                                phẩm</li>
                        </ul>

                        <h3>🔁 Thu nhập trọn đời</h3>
                        <ul>
                            <li><strong>Khách hàng cũ mua lại qua link giới thiệu</strong>, bạn vẫn nhận hoa hồng đều đặn
                            </li>
                        </ul>

                        <h3>🎉 Thưởng nóng 600.000 VND</h3>
                        <ul>
                            <li>Khi bạn giới thiệu thành công một Affiliate mới</li>
                        </ul>

                        <h3>🤝 Cam kết đồng hành đến khi bạn kiếm được tiền</h3>
                        <ul>
                            <li>Đội ngũ ST SKIN hỗ trợ từ A-Z</li>
                            <li>Chỉ cần bạn quyết tâm, chúng tôi sẽ luôn bên bạn</li>
                        </ul>

                        <hr>

                        <h2>🚀 Mục tiêu thu nhập: <strong>10 – 100 triệu VND/tháng</strong></h2>
                        <p><em>Chỉ sau <strong>3 tháng làm việc nghiêm túc</strong>, bạn có thể đạt được mức thu nhập như
                                mong muốn!</em></p>

                        <hr>

                        <h2>🎁 Đăng ký ngay – Nhận quà tặng trị giá 1.000.000 VND:</h2>
                        <ul>
                            <li>💎 Kem dưỡng trắng da TINO</li>
                            <li>💎 Kem mờ thâm nám TINO</li>
                            <li>💎 Serum đa chức năng TINO</li>
                            <li>💎 Nước hoa vùng kín TINO</li>
                        </ul>

                        <hr>

                        <h2>🌟 ĐỪNG BỎ LỠ – Hành trình kiếm tiền của bạn bắt đầu từ đây! 🌟</h2>

                        <p style="text-align:center;">
                            👉 <a href="#"
                                style="font-size: 18px; font-weight: bold; background: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">ĐĂNG
                                KÝ NGAY</a>
                        </p>

                    </div>

                    <!-- Thông tin chia sẻ -->
                    <div class="mt-4">
                        <button class="btn btn-primary rounded-5">
                            <i class="fa fa-share-alt"></i> Chia sẻ
                        </button>
                        <a href="#" class="btn btn-light rounded-5 border"
                            onclick="copyReferralLink('{{ route('articleAffilate') }}')">
                            <i class="fa fa-link"></i> Sao chép link
                        </a>
                    </div>
                </div>
            </div>

            <!-- Bài viết liên quan -->
            {{-- <div class="related-articles mt-5">
                <h5 class="fw-bold mb-3">Bài viết liên quan</h5>
                <div class="row">
                    @foreach ($relatedArticles as $related)
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-3">
                                <a href="{{ route('article_detail', $related->slug) }}">
                                    <img src="{{ asset('storage/' . $related->image) }}" class="card-img-top rounded-3"
                                        alt="Related Article Image" style="height: 200px; object-fit: cover;">
                                </a>
                                <div class="card-body p-2">
                                    <h6 class="card-title text-truncate mb-1">{{ $related->title }}</h6>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div> --}}

            {{-- </div> --}}

            <!-- Sidebar -->
            {{-- <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body">
                    <h5 class="fw-bold">Thông tin tác giả</h5>
                    <p>{{ $article->author->bio }}</p>
                </div>
            </div>
        </div> --}}
        </div>
    </div>

    <script>
        function copyReferralLink(link) {
            navigator.clipboard.writeText(link).then(() => {
                alert('Link bài viết đã được sao chép!');
            }).catch(err => {
                console.error('Không thể sao chép link', err);
            });
        }
    </script>

@endsection
