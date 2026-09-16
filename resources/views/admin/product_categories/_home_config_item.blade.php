@php
    $children = collect($category->children ?? []);
    $level = (int) ($category->computed_level ?? $category->level ?? 1);
    $isActive = (int) ($category->is_active ?? 1) === 1;
    $showOnHome = (int) ($category->show_on_home ?? 0) === 1;
    $imageUrl = $category->image ? asset('storage/' . $category->image) : null;
@endphp

<li class="dd-item pc-category-item" data-id="{{ $category->id }}">
    <div class="pc-category-row">
        <div class="dd-handle pc-drag-handle" title="Kéo để sắp xếp">
            <i class="bi bi-arrows-move"></i>
        </div>

        <div class="pc-category-card">
            <div class="pc-category-main">
                <div class="pc-category-thumb">
                    @if ($imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $category->name }}">
                    @else
                        <i class="bi bi-folder2-open"></i>
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="d-flex align-items-center flex-wrap gap-2">
                        <strong class="pc-category-name">{{ $category->name }}</strong>
                        <span class="badge bg-light text-dark border">Level {{ $level }}</span>
                        @if ($category->store_id)
                            <span class="badge bg-info text-dark">Cửa hàng: {{ $category->store?->name ?? $category->store_id }}</span>
                        @else
                            <span class="badge bg-secondary">Hệ thống</span>
                        @endif
                    </div>
                    <div class="small text-muted mt-1">
                        Slug: {{ $category->slug ?: '-' }} · Sort: {{ $category->sort_order ?? 0 }}
                    </div>
                </div>
            </div>

            <div class="pc-category-actions">
                <div class="form-check form-switch mb-0">
                    <input
                        class="form-check-input pc-display-toggle"
                        type="checkbox"
                        data-field="show_on_home"
                        data-url="{{ route('admin.product-categories.display.update', $category->id) }}"
                        id="show_on_home_{{ $category->id }}"
                        {{ $showOnHome ? 'checked' : '' }}>
                    <label class="form-check-label small" for="show_on_home_{{ $category->id }}">Header/Home</label>
                </div>
                <div class="form-check form-switch mb-0">
                    <input
                        class="form-check-input pc-display-toggle"
                        type="checkbox"
                        data-field="is_active"
                        data-url="{{ route('admin.product-categories.display.update', $category->id) }}"
                        id="is_active_{{ $category->id }}"
                        {{ $isActive ? 'checked' : '' }}>
                    <label class="form-check-label small" for="is_active_{{ $category->id }}">Bật</label>
                </div>
                <a href="{{ route('admin.product-categories.edit', $category->id) }}" class="btn btn-outline-warning btn-sm" title="Sửa danh mục">
                    <i class="bi bi-pencil"></i>
                </a>
            </div>
        </div>
    </div>

    @if ($children->isNotEmpty())
        <ol class="dd-list">
            @foreach ($children as $child)
                @include('admin.product_categories._home_config_item', ['category' => $child])
            @endforeach
        </ol>
    @endif
</li>
