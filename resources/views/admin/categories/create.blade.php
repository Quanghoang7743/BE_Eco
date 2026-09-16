@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Thêm mới danh mục</h1>

    <!-- Form thêm mới danh mục -->
    <form action="{{ route('admin.categories.store') }}" method="POST">
        @csrf
        <div class="form-group">
            <label for="title">Tiêu đề</label>
            <input type="text" name="title" class="form-control" required>
        </div>

        <div class="form-group">
            <label for="slug">Slug (nếu không nhập sẽ tự tạo)</label>
            <input type="text" name="slug" class="form-control">
        </div>

        <div class="form-group">
            <label for="parent_category_id">Chuyên mục cha</label>
            <select name="parent_category_id" class="form-control">
                <option value="">-- Không có --</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->title }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-group">
            <label for="description">Mô tả</label>
            <textarea name="description" class="form-control" rows="3"></textarea>
        </div>

        <button type="submit" class="btn btn-primary">Thêm chuyên mục</button>
    </form>
@endsection
