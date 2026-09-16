@php
    $currentPage = $products->currentPage();
    $lastPage = $products->lastPage();
    $startPage = max(1, $currentPage - 2);
    $endPage = min($lastPage, $currentPage + 2);
@endphp

<div class="homepage-product-grid" id="productGrid">
    @foreach($products as $p)
        @php
            $selected = in_array($p->id, (array) $selectedIds);
            $discountPct = null;
            $discountText = 'Không giảm giá';
            if ($p->price && $p->sale_price && $p->price > 0) {
                $discountPct = round((1 - ($p->sale_price / $p->price)) * 100, 0);
                $discountText = "Giảm {$discountPct}%";
            }
        @endphp
        <label class="homepage-product-item" data-product-item data-search-text="{{ Str::lower($p->name . ' ' . number_format($p->price ?? 0,0,',','.') . ' ' . number_format($p->sale_price ?? 0,0,',','.') . ' ' . $discountText) }}">
            <input type="checkbox" class="form-check-input homepage-product-check" data-product-id="{{ $p->id }}" {{ $selected ? 'checked' : '' }}>
            <span class="homepage-product-item-body">
                <span class="homepage-product-meta">
                    <span class="homepage-product-name">{{ $p->name }}</span>
                    <span class="badge text-bg-light border">#{{ $p->id }}</span>
                </span>
                <span class="homepage-product-prices">
                    <span>{{ number_format($p->price ?? 0, 0, ',', '.') }}</span>
                    <span class="homepage-product-arrow">→</span>
                    <span>{{ number_format($p->sale_price ?? 0, 0, ',', '.') }}</span>
                </span>
                <span class="homepage-product-discount {{ $discountPct !== null ? 'text-success' : 'text-muted' }}">{{ $discountText }}</span>
            </span>
        </label>
    @endforeach
</div>

@if($products->hasPages())
    <div class="homepage-product-pagination d-flex flex-wrap justify-content-center align-items-center gap-2 mt-3">
        <button type="button" class="btn btn-sm {{ $products->onFirstPage() ? 'btn-light' : 'btn-outline-secondary' }}" data-page="{{ max(1, $currentPage - 1) }}" {{ $products->onFirstPage() ? 'disabled' : '' }}>
            Trước
        </button>

        @for($page = $startPage; $page <= $endPage; $page++)
            <button type="button" class="btn btn-sm {{ $page === $currentPage ? 'btn-primary' : 'btn-outline-secondary' }}" data-page="{{ $page }}">
                {{ $page }}
            </button>
        @endfor

        <button type="button" class="btn btn-sm {{ $products->hasMorePages() ? 'btn-outline-secondary' : 'btn-light' }}" data-page="{{ min($lastPage, $currentPage + 1) }}" {{ $products->hasMorePages() ? '' : 'disabled' }}>
            Sau
        </button>
    </div>
@endif
