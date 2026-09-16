@extends('layout')

@section('title', 'Mã giới thiệu')

@section('content')
    <div class="container d-flex align-items-center justify-content-center mt-2">
        <div class="card p-4 shadow-sm rounded-4 text-center" style="max-width: 400px; width: 100%;">

            <div class="mb-3 p-3 bg-light rounded-3 d-flex justify-content-between">
                <div class="text-muted">Người giới thiệu</div>
                @if ($user->referrer_id)
                    <div class="fw-bold text-primary">GT{{ $user->referrer_id }}</div>
                @else
                    <div class="fw-bold text-primary">Không có</div>
                @endif
            </div>

            <div class="mb-4 p-3 bg-light rounded-3 d-flex justify-content-between">
                <div class="text-muted">Mã giới thiệu của bạn</div>
                <div class="fw-bold text-primary">GT{{ $user->id }}</div>
            </div>

            <button id="shareBtn" class="btn"
                style="background-color: #152379; color: white; border-radius: 999px; padding: 10px 30px">
                Chia sẻ link giới thiệu
            </button>
        </div>
    </div>
    <script>
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
    </script>
@endsection
