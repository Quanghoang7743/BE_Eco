@php
    $businessType = $storeBusinessType ?? null;
@endphp

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form action="{{ $action }}" method="POST">
    @csrf
    @if (($method ?? 'POST') !== 'POST')
        @method($method)
    @endif

    <div class="mb-3">
        <label class="form-label">Tên loại hình <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control" value="{{ old('name', $businessType?->name) }}" required>
    </div>

    <div class="mb-3">
        <label class="form-label">Slug</label>
        <input type="text" name="slug" class="form-control" value="{{ old('slug', $businessType?->slug) }}"
            placeholder="Để trống để tự tạo từ tên">
    </div>

    <div class="mb-3">
        <label class="form-label">Icon URL</label>
        <input type="text" name="icon_url" class="form-control" value="{{ old('icon_url', $businessType?->icon_url) }}"
            placeholder="https://... hoặc đường dẫn icon">
    </div>

    <div class="mb-3">
        <label class="form-label">Thứ tự hiển thị</label>
        <input type="number" name="sort_order" class="form-control"
            value="{{ old('sort_order', $businessType?->sort_order ?? 0) }}" min="0" step="1">
    </div>

    <div class="mb-3 form-check">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active"
            {{ old('is_active', $businessType?->is_active ?? true) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Kích hoạt cho User chọn</label>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">{{ $submitLabel ?? 'Lưu' }}</button>
        <a href="{{ route('admin.store-business-types.index') }}" class="btn btn-outline-secondary">Hủy</a>
    </div>
</form>
