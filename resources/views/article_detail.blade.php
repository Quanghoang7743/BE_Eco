@extends('layout')

@section('title', $article->title)
@section('content')

<div class="container ">
    <div class="row">
        {{-- <div class="col-md-8"> --}}
            <!-- Bài viết chi tiết -->
            <div class="article-detail card border-0 shadow-sm rounded-3">
                <img src="{{ asset('storage/' . $article->image) }}" class="card-img-top rounded-3"  alt="Article Image">
                <div class="card-body">
                    <h2 class="card-title fw-bold mb-3">{{ $article->title }}</h2>
                    <p class="small text-muted mb-3">Đăng vào {{ $article->created_at->format('d/m/Y') }} 

                    <!-- Nội dung bài viết -->
                    <div class="content">
                        {!! $article->content !!}
                    </div>

                    <!-- Thông tin chia sẻ -->
                    <div class="mt-4">
                        <button class="btn btn-primary rounded-5">
                            <i class="fa fa-share-alt"></i> Chia sẻ
                        </button>
                        <a href="#" class="btn btn-light rounded-5 border" onclick="copyReferralLink('{{ route('article_detail', $article->slug) }}')">
                            <i class="fa fa-link"></i> Sao chép link
                        </a>
                    </div>
                </div>
            </div>

            <!-- Bài viết liên quan -->
            <div class="related-articles mt-5">
                <h5 class="fw-bold mb-3">Bài viết liên quan</h5>
                <div class="row">
                    @foreach ($relatedArticles as $related)
                        <div class="col-md-4">
                            <div class="card border-0 shadow-sm rounded-3">
                                <a href="{{ route('article_detail', $related->slug) }}">
                                    <img src="{{ asset('storage/' . $related->image) }}" class="card-img-top rounded-3" alt="Related Article Image" style="height: 200px; object-fit: cover;">
                                </a>
                                <div class="card-body p-2">
                                    <h6 class="card-title text-truncate mb-1">{{ $related->title }}</h6>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

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
