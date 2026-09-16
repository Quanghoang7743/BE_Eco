@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Chỉnh sửa danh mục</h1>

    <!-- Form chỉnh sửa danh mục -->
    <form action="{{ route('admin.categories.update', $category->id) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="title">Tiêu đề</label>
            <input type="text" name="title" class="form-control" value="{{ old('title', $category->title) }}" required>
        </div>

        <div class="form-group">
            <label for="slug">Slug</label>
            <input type="text" name="slug" class="form-control" value="{{ old('slug', $category->slug) }}">
        </div>

        <div class="form-group">
            <label for="parent_category_id">Chuyên mục cha</label>
            <select name="parent_category_id" class="form-control">
                <option value="">-- Không có --</option>
                @foreach ($categories as $cat)
                    @if ($cat->id !== $category->id)
                        <option value="{{ $cat->id }}" {{ $cat->id == old('parent_category_id', $category->parent_category_id) ? 'selected' : '' }}>
                            {{ $cat->title }}
                        </option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label for="description">Mô tả</label>
            <textarea name="description" class="form-control" rows="3">{{ old('description', $category->description) }}</textarea>
        </div>

        <button type="submit" class="btn btn-success">Cập nhật chuyên mục</button>
        <a href="{{ route('admin.categories.index') }}" class="btn btn-secondary">Quay lại</a>
    </form>
@endsection
