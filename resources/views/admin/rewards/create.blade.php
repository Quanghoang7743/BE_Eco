@extends('admin.layout')

@section('content')
    <div>
        <h1>{{ isset($reward) ? 'Sửa' : 'Thêm' }} phần thưởng</h1>

        <form action="{{ isset($reward) ? route('admin.rewards.update', $reward) : route('admin.rewards.store') }}"
            method="POST">
            @csrf
            @if (isset($reward))
                @method('PUT')
            @endif

            <div class="mb-3">
                <label>Tên phần thưởng</label>
                <input type="text" name="name" class="form-control" value="{{ old('name', $reward->name ?? '') }}"
                    required>
            </div>

            <div class="mb-3">
                <label>Loại</label>
                <select name="type" class="form-control" required>
                    @foreach (['none', 'point', 'voucher', 'product', 'extra_spin'] as $type)
                        <option value="{{ $type }}"
                            {{ old('type', $reward->type ?? '') === $type ? 'selected' : '' }}>
                            {{ ucfirst($type) }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Chọn sản phẩm nếu type = product --}}
            <div class="mb-3" id="product-select" style="display: none;">
                <label>Chọn sản phẩm</label>
                <select name="product_id" class="form-control">
                    <option value="">-- Chọn sản phẩm --</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}"
                            {{ old('product_id', $reward->product_id ?? '') == $product->id ? 'selected' : '' }}>
                            {{ $product->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            {{-- Chọn voucher nếu type = voucher --}}
            <div class="mb-3" id="voucher-select" style="display: none;">
                <label>Chọn voucher</label>
                <select name="voucher_id" class="form-control">
                    <option value="">-- Chọn voucher --</option>
                    @foreach ($vouchers as $voucher)
                        <option value="{{ $voucher->id }}"
                            {{ old('voucher_id', $reward->voucher_id ?? '') == $voucher->id ? 'selected' : '' }}>
                            {{ $voucher->code }} - {{ number_format($voucher->discount_value, 0, ',', '.') }}đ
                        </option>
                    @endforeach
                </select>
            </div>


            <div class="mb-3" id="value-field">
                <label>Giá trị (tuỳ theo loại)</label>
                <input type="number" name="value" class="form-control" value="{{ old('value', $reward->value ?? '') }}">
            </div>


            <div class="mb-3">
                <label>Số lượng (để trống nếu không giới hạn)</label>
                <input type="number" name="quantity" class="form-control"
                    value="{{ old('quantity', $reward->quantity ?? '') }}">
            </div>

            <div class="mb-3">
                <label>Xác suất (%)</label>
                <input type="number" name="probability" class="form-control"
                    value="{{ old('probability', $reward->probability ?? 0) }}" required>
            </div>

            <button type="submit" class="btn btn-success">Lưu</button>
        </form>
    </div>
    <script>
        function toggleExtraFields() {
            const type = document.querySelector('select[name="type"]').value;
            document.getElementById('product-select').style.display = type === 'product' ? 'block' : 'none';
            document.getElementById('voucher-select').style.display = type === 'voucher' ? 'block' : 'none';
            document.getElementById('value-field').style.display = (type === 'point' || type === 'extra_spin') ? 'block' :
                'none';
        }


        document.addEventListener('DOMContentLoaded', () => {
            const typeSelect = document.querySelector('select[name="type"]');

            // Xử lý hiện theo old() nếu có
            const currentType = typeSelect.value;
            if (currentType === 'product') {
                document.getElementById('product-select').style.display = 'block';
            } else if (currentType === 'voucher') {
                document.getElementById('voucher-select').style.display = 'block';
            }

            // Bắt sự kiện khi người dùng thay đổi type
            typeSelect.addEventListener('change', () => {
                toggleExtraFields();
            });
        });
    </script>
@endsection
