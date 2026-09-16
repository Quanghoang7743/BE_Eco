{{-- ────────────────────────────────────────────────────────
     TAB — Khách hàng đánh giá
──────────────────────────────────────────────────────────── --}}
@php
    $resolveStoreReviewMedia = static function ($entry): ?array {
        $type = null;
        $rawValue = null;

        if (is_string($entry) || is_numeric($entry)) {
            $rawValue = (string) $entry;
        } elseif (is_array($entry)) {
            $type = isset($entry['type']) ? mb_strtolower(trim((string) $entry['type'])) : null;
            $rawValue = $entry['url']
                ?? $entry['public_url']
                ?? $entry['image_url']
                ?? $entry['path']
                ?? null;
        } elseif (is_object($entry)) {
            $entry = (array) $entry;
            $type = isset($entry['type']) ? mb_strtolower(trim((string) $entry['type'])) : null;
            $rawValue = $entry['url']
                ?? $entry['public_url']
                ?? $entry['image_url']
                ?? $entry['path']
                ?? null;
        }

        if (!is_string($rawValue) && !is_numeric($rawValue)) {
            return null;
        }

        $rawValue = trim((string) $rawValue);
        if ($rawValue === '') {
            return null;
        }

        if (filter_var($rawValue, FILTER_VALIDATE_URL)) {
            $url = $rawValue;
            $path = parse_url($rawValue, PHP_URL_PATH);
        } else {
            $path = ltrim($rawValue, '/');
            if ($path === '') {
                return null;
            }

            $url = str_starts_with($path, 'storage/')
                ? asset($path)
                : asset('storage/' . $path);
        }

        $extension = mb_strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION));
        $isVideo = $type === 'video' || in_array($extension, ['mp4', 'mov', 'webm'], true);

        return [
            'url' => $url,
            'is_video' => $isVideo,
        ];
    };
@endphp

<div class="se-tab-pane {{ $activeTab === 'store-reviews' ? 'active' : '' }}" id="pane-store-reviews"
    role="tabpanel">
    <div class="se-tab-header">
        <span class="se-tab-header-text">Danh sách khách hàng đánh giá sản phẩm của cửa hàng</span>
    </div>

    <div class="se-table-card">
        <table class="se-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Khách hàng</th>
                    <th>Sản phẩm</th>
                    <th>Đánh giá & Bình luận</th>
                    <th>Ngày đánh giá</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($storeReviews as $review)
                    <tr>
                        <td style="color:#aaa; font-size:12.5px;">{{ substr($review->id, 0, 8) }}...</td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: #1a1a1a; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13.5px; flex-shrink: 0;">
                                    {{ mb_strtoupper(mb_substr($review->user->full_name ?? ($review->user->name ?? 'U'), 0, 1)) }}
                                </div>
                                <div>
                                    <div class="se-product-name">{{ $review->user->full_name ?? ($review->user->name ?? 'Người dùng') }}</div>
                                    <div class="se-product-sku">username: {{ $review->user->username ?? 'N/A' }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                @if ($review->product && $review->product->thumbnail)
                                    <img src="{{ asset($review->product->thumbnail) }}" style="width: 40px; height: 40px; border-radius: 6px; object-fit: cover; flex-shrink: 0;" />
                                @else
                                    <div style="width: 40px; height: 40px; border-radius: 6px; background: #faf9f6; display: flex; align-items: center; justify-content: center; font-size: 16px; border: 1px dashed #ddd; flex-shrink: 0;">📦</div>
                                @endif
                                <div>
                                    @if ($review->product)
                                        <div class="se-product-name" style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="{{ $review->product->name }}">{{ $review->product->name }}</div>
                                        <div class="se-product-sku">SKU: {{ $review->product->sku ?? 'N/A' }}</div>
                                    @else
                                        <div class="se-product-name">{{ $review->product_name ?? 'Sản phẩm đã xóa' }}</div>
                                        @if ($review->product_id)
                                            <div class="se-product-sku">ID: {{ $review->product_id }}</div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="margin-bottom: 4px;">
                                @for ($i = 1; $i <= 5; $i++)
                                    <i class="{{ $i <= $review->rating ? 'fas' : 'far' }} fa-star" style="color: {{ $i <= $review->rating ? '#ffc107' : '#e4e5e9' }}; font-size: 13px;"></i>
                                @endfor
                                <span style="font-size: 12.5px; font-weight: 500; color: #555; margin-left: 4px;">({{ $review->rating }}/5)</span>
                            </div>
                            
                            @if ($review->comment)
                                <div style="font-size: 13.5px; color: #333; line-height: 1.4; word-break: break-word;">
                                    {{ $review->comment }}
                                </div>
                            @else
                                <div style="font-size: 13px; color: #aaa; font-style: italic;">
                                    Không có nội dung bình luận.
                                </div>
                            @endif

                            {{-- Review images --}}
                            @if (is_array($review->images) && count($review->images) > 0)
                                <div style="display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap;">
                                    @foreach ($review->images as $img)
                                        @php $media = $resolveStoreReviewMedia($img); @endphp
                                        @if ($media)
                                            @if ($media['is_video'])
                                                <a href="{{ $media['url'] }}" target="_blank" rel="noopener noreferrer" class="se-btn se-btn-outline-info se-btn-sm">
                                                    Xem video
                                                </a>
                                            @else
                                                <a href="{{ $media['url'] }}" target="_blank" rel="noopener noreferrer">
                                                    <img src="{{ $media['url'] }}" style="width: 42px; height: 42px; border-radius: 6px; object-fit: cover; border: 1px solid #ddd;" />
                                                </a>
                                            @endif
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            {{-- Reply from store --}}
                            @if ($review->reply_text)
                                <div style="margin-top: 8px; padding: 8px 12px; background: #faf9f6; border-left: 3px solid #1a1a1a; border-radius: 4px; font-size: 12.5px;">
                                    <div style="font-weight: 600; color: #555; margin-bottom: 2px;">
                                        <i class="fas fa-reply" style="transform: rotate(180deg); margin-right: 4px; font-size: 11px;"></i>
                                        Cửa hàng phản hồi:
                                    </div>
                                    <div style="color: #666; font-style: italic;">
                                        "{{ $review->reply_text }}"
                                    </div>
                                    <div style="font-size: 11px; color: #aaa; margin-top: 4px;">
                                        {{ $review->replied_at ? \Carbon\Carbon::parse($review->replied_at)->format('d/m/Y H:i') : '' }}
                                    </div>
                                </div>
                            @endif
                        </td>
                        <td style="font-size:12.5px; color:#666; white-space: nowrap;">
                            {{ $review->created_at ? $review->created_at->format('d/m/Y H:i') : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="se-empty">
                                <span class="se-empty-icon">⭐</span>
                                Chưa có khách hàng nào đánh giá sản phẩm của cửa hàng này.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="se-pagination">{{ $storeReviews->links('pagination::bootstrap-5') }}</div>
</div>
