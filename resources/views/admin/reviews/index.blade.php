@extends('admin.layout')

@section('content')
<div class="container-fluid">
    <div class="page-inner">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h2 class="mb-1">Quản lý đánh giá</h2>
                <div class="text-muted">Danh sách đánh giá sản phẩm từ khách hàng trong hệ thống.</div>
            </div>
            <span class="badge bg-primary px-3 py-2">{{ $reviews->total() }} đánh giá</span>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-12 col-md-8">
                        <label class="form-label">Từ khóa</label>
                        <input type="text" name="keyword" value="{{ $keyword }}" class="form-control" placeholder="Tên sản phẩm / tên cửa hàng / khách hàng / nội dung đánh giá">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Số sao</label>
                        <select name="rating" class="form-select">
                            <option value="">Tất cả</option>
                            @for ($i = 5; $i >= 1; $i--)
                                <option value="{{ $i }}" @selected((string) $rating === (string) $i)>{{ $i }} sao</option>
                            @endfor
                        </select>
                    </div>
                    <div class="col-12 col-md-2 d-grid">
                        <button type="submit" class="btn btn-primary">Lọc</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Cửa hàng</th>
                                <th>Sản phẩm</th>
                                <th>Khách hàng</th>
                                <th>Đánh giá</th>
                                <th>Nội dung</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($reviews as $review)
                                @php
                                    $extractProductNameFromComment = static function (?string $comment): ?string {
                                        $rawComment = trim((string) $comment);
                                        if ($rawComment === '') {
                                            return null;
                                        }

                                        if (preg_match('/\[San pham:\s*(.*?)\]/u', $rawComment, $matches) === 1) {
                                            $found = trim((string) ($matches[1] ?? ''));
                                            return $found !== '' ? $found : null;
                                        }

                                        return null;
                                    };

                                    $toAssetUrl = static function ($value): ?string {
                                        $rawValue = trim((string) $value);
                                        if ($rawValue === '') {
                                            return null;
                                        }

                                        if (str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://')) {
                                            return $rawValue;
                                        }

                                        if (str_starts_with($rawValue, '/')) {
                                            return asset(ltrim($rawValue, '/'));
                                        }

                                        if (str_starts_with($rawValue, 'storage/')) {
                                            return asset($rawValue);
                                        }

                                        return asset('storage/' . ltrim($rawValue, '/'));
                                    };

                                    $productName = $review->product_name
                                        ?: $review->menuItem?->item_name
                                        ?: $review->product?->name
                                        ?: $extractProductNameFromComment($review->comment)
                                        ?: '---';

                                    $productImages = is_array($review->product?->images) ? array_values(array_filter($review->product->images)) : [];

                                    $productImageUrl = $toAssetUrl($review->menuItem?->image_url)
                                        ?: $toAssetUrl($review->product?->thumbnail)
                                        ?: $toAssetUrl($productImages[0] ?? null);

                                    $reviewMediaEntries = is_array($review->images) ? $review->images : [];

                                    $reviewImageUrls = collect($reviewMediaEntries)
                                        ->map(function ($entry) use ($toAssetUrl) {
                                            if (is_string($entry)) {
                                                return $toAssetUrl($entry);
                                            }

                                            if (!is_array($entry)) {
                                                return null;
                                            }

                                            $type = strtolower((string) ($entry['type'] ?? 'image'));
                                            if ($type !== 'image') {
                                                return null;
                                            }

                                            return $toAssetUrl($entry['url'] ?? $entry['path'] ?? null);
                                        })
                                        ->filter()
                                        ->values()
                                        ->all();

                                    $reviewImagePreview = array_slice($reviewImageUrls, 0, 4);

                                    $displayComment = trim((string) ($review->comment ?? ''));
                                    if ($displayComment !== '' && str_starts_with($displayComment, '[San pham:')) {
                                        $closeDoubleBracketPos = mb_strpos($displayComment, ']]');
                                        if ($closeDoubleBracketPos !== false) {
                                            $displayComment = trim((string) mb_substr($displayComment, $closeDoubleBracketPos + 2));
                                        } else {
                                            $closeBracketPos = mb_strpos($displayComment, ']');
                                            if ($closeBracketPos !== false) {
                                                $displayComment = trim((string) mb_substr($displayComment, $closeBracketPos + 1));
                                            }
                                        }
                                    }

                                    if ($displayComment === '') {
                                        $displayComment = '---';
                                    }
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $review->store?->name ?? '---' }}</div>
                                        <div class="text-muted small">{{ $review->store?->store_code ?? '' }}</div>
                                    </td>
                                    <td style="min-width: 260px;">
                                        <div class="d-flex align-items-start gap-2">
                                            @if ($productImageUrl)
                                                <img src="{{ $productImageUrl }}" alt="{{ $productName }}" class="rounded border" style="width: 48px; height: 48px; object-fit: cover;">
                                            @endif
                                            <div class="min-w-0">
                                                <div class="fw-semibold text-break">{{ $productName }}</div>
                                                @if ($review->menuItem?->item_code)
                                                    <div class="text-muted small">{{ $review->menuItem->item_code }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $review->user?->full_name ?: $review->user?->name ?: '---' }}</div>
                                        <div class="text-muted small">#{{ $review->user_id }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning text-dark">{{ (int) $review->rating }}/5</span>
                                    </td>
                                    <td style="min-width: 280px;">
                                        @if (!empty($reviewImagePreview))
                                            <div class="d-flex flex-wrap gap-2 mb-2">
                                                @foreach ($reviewImagePreview as $imageUrl)
                                                    <a href="{{ $imageUrl }}" target="_blank" rel="noopener noreferrer">
                                                        <img src="{{ $imageUrl }}" alt="Ảnh đánh giá" class="rounded border" style="width: 52px; height: 52px; object-fit: cover;">
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="text-truncate" style="max-width: 360px;" title="{{ $displayComment }}">
                                            {{ $displayComment }}
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">Chưa có đánh giá nào.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-white">
                {{ $reviews->links() }}
            </div>
        </div>
    </div>
</div>
@endsection