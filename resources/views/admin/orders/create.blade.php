@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Thêm mới sản phẩm</h1>

    <form action="{{ route('admin.products.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="row">
            <!-- Cột bên trái - Thông tin cơ bản -->
            <div class="col-md-8">
                <div class="mb-3">
                    <label for="name" class="form-label">Tên sản phẩm</label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name"
                        name="name" value="{{ old('name') }}" required>
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="summary" class="form-label">Nội dung tóm tắt</label>
                    <textarea class="form-control @error('summary') is-invalid @enderror" id="summary" name="summary">{{ old('summary') }}</textarea>
                    @error('summary')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Nội dung chi tiết</label>
                    <textarea class="form-control @error('content') is-invalid @enderror" id="content" name="content">{{ old('content') }}</textarea>
                    @error('content')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="sku" class="form-label">Mã sản phẩm</label>
                    <input type="text" class="form-control @error('sku') is-invalid @enderror" id="sku"
                        name="sku" value="{{ old('sku') }}">
                    @error('sku')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="slug" class="form-label">Đường dẫn đến sản phẩm:</label>
                    <input type="text" class="form-control @error('slug') is-invalid @enderror" id="slug"
                        name="slug" value="{{ old('slug') }}">
                    @error('slug')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-3">
                    <label for="category_id" class="form-label">Danh mục sản phẩm</label>
                    <select class="form-select @error('category_id') is-invalid @enderror" id="category_id"
                        name="category_id" required>
                        <option value="">-- Chọn danh mục --</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                                {{ $cat->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="group_id" class="form-label">Nhóm sản phẩm</label>
                    <select class="form-select @error('group_id') is-invalid @enderror" id="group_id" name="group_id">
                        <option value="">-- Chọn nhóm --</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" {{ old('group_id') == $group->id ? 'selected' : '' }}>
                                {{ $group->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('group_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="row">
                    <div class="mb-3 col-md-6">
                        <label for="price" class="form-label">Giá niêm yết</label>
                        <input type="number" class="form-control @error('price') is-invalid @enderror" id="price"
                            name="price" value="{{ old('price') }}">
                        @error('price')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3 col-md-6">
                        <label for="sale_price" class="form-label">Giá bán</label>
                        <input type="number" class="form-control @error('sale_price') is-invalid @enderror" id="sale_price"
                            name="sale_price" value="{{ old('sale_price') }}" required>
                        @error('sale_price')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="mb-3">
                    <label for="thumbnail" class="form-label">Ảnh đại diện</label>
                    <input type="file" class="form-control @error('thumbnail') is-invalid @enderror" id="thumbnail"
                        name="thumbnail">
                    @error('thumbnail')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="images" class="form-label">Ảnh sản phẩm (nhiều ảnh)</label>
                    <input type="file" class="form-control @error('images.*') is-invalid @enderror" id="images"
                        name="images[]" multiple>
                    @error('images.*')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>


            </div>
        </div>
        <button type="submit" class="btn btn-success">Lưu sản phẩm</button>
        <a href="{{ route('admin.stores.index') }}" class="btn btn-secondary">Quay lại</a>
    </form>
    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
    <script>
        ClassicEditor
            .create(document.querySelector('#content'), {
                ckfinder: {
                    uploadUrl: '{{ route('upload') . '?_token=' . csrf_token() }}'
                }
            })
            .then(editor => {
                editor.editing.view.change(writer => {
                    writer.setStyle('min-height', '300px', editor.editing.view.document.getRoot());
                });
            })
            .catch(error => {
                console.error(error);
            });
    </script>
@endsection
