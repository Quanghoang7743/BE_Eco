@extends('admin.layout')

@section('content')
    <h1>Chỉnh sửa ngân hàng</h1>

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.banks.update', $bank) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label class="form-label">Mã ngân hàng</label>
            <input type="text" name="code" class="form-control" value="{{ old('code', $bank->code) }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Tên ngân hàng</label>
            <input type="text" name="name" class="form-control" value="{{ old('name', $bank->name) }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Ảnh ngân hàng</label>
            <input type="file" name="image" class="form-control">
            @if($bank->image)
                <div class="mt-2">
                    <img src="{{ asset('storage/' . $bank->image) }}" width="140" alt="{{ $bank->name }}">
                </div>
            @endif
        </div>
        <div class="mb-3 form-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active" {{ old('is_active', $bank->is_active) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Kích hoạt</label>
        </div>

        <button type="submit" class="btn btn-primary">Cập nhật</button>
    </form>
@endsection
