@extends('admin.layout')

@section('content')
    <h1>Thêm ngân hàng</h1>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.banks.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label">Mã ngân hàng</label>
            <input type="text" name="code" class="form-control" value="{{ old('code') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Tên ngân hàng</label>
            <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Ảnh ngân hàng</label>
            <input type="file" name="image" class="form-control">
        </div>
        <div class="mb-3 form-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" {{ old('is_active', true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Kích hoạt</label>
        </div>

        <button type="submit" class="btn btn-primary">Lưu</button>
    </form>
@endsection
