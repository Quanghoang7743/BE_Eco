@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Thêm mới voucher</h1>

    <form action="{{ route('admin.vouchers.store') }}" method="POST">
        @csrf

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="code" class="form-label">Mã giảm giá</label>
                    <input type="text" class="form-control" id="code" name="code" required value="{{ old('code') }}">
                </div>

                <div class="mb-3">
                    <label for="type" class="form-label">Loại giảm giá</label>
                    <select name="type" id="type" class="form-select" required>
                        <option value="percentage" {{ old('type') == 'percentage' ? 'selected' : '' }}>Phần trăm</option>
                        <option value="fixed" {{ old('type') == 'fixed' ? 'selected' : '' }}>Cố định</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="discount_value" class="form-label">Giá trị giảm</label>
                    <input type="number" name="discount_value" class="form-control" value="{{ old('discount_value') }}" required>
                </div>

                <div class="mb-3">
                    <label for="max_discount" class="form-label">Giảm tối đa (chỉ %)</label>
                    <input type="number" name="max_discount" class="form-control" value="{{ old('max_discount') }}">
                </div>
            </div>

            <div class="col-md-6">
                <div class="mb-3">
                    <label for="min_order_value" class="form-label">Giá trị đơn hàng tối thiểu</label>
                    <input type="number" name="min_order_value" class="form-control" value="{{ old('min_order_value') }}">
                </div>

                <div class="mb-3">
                    <label for="store_id" class="form-label">Cửa hàng áp dụng</label>
                    <select name="store_id" id="store_id" class="form-select">
                        <option value="">Toàn hệ thống</option>
                        @foreach ($stores as $store)
                            <option value="{{ $store->id }}" {{ old('store_id') === (string) $store->id ? 'selected' : '' }}>
                                {{ $store->name }}
                            </option>
                        @endforeach
                    </select>
                    <div class="form-text">Chọn cửa hàng để gửi thông báo cho người theo dõi cửa hàng đó.</div>
                </div>

                <div class="mb-3">
                    <label for="quantity" class="form-label">Số lượng</label>
                    <input type="number" name="quantity" class="form-control" value="{{ old('quantity') }}" required>
                </div>

                <div class="mb-3">
                    <label for="start_date" class="form-label">Bắt đầu</label>
                    <input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}" required>
                </div>

                <div class="mb-3">
                    <label for="end_date" class="form-label">Kết thúc</label>
                    <input type="date" name="end_date" class="form-control" value="{{ old('end_date') }}" required>
                </div>

                <div class="mb-3">
                    <label for="is_active" class="form-label">Kích hoạt</label>
                    <select name="is_active" class="form-select">
                        <option value="1" {{ old('is_active') == '1' ? 'selected' : '' }}>Hoạt động</option>
                        <option value="0" {{ old('is_active') == '0' ? 'selected' : '' }}>Ngừng</option>
                    </select>
                </div>
            </div>
        </div>

        <button class="btn btn-success">Lưu voucher</button>
        <a href="{{ route('admin.vouchers.index') }}" class="btn btn-secondary">Quay lại</a>
    </form>
@endsection
