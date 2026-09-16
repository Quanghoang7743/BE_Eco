@extends('admin.layout')

@section('content')
    <h2>Cập nhật combo</h2>

    <form action="{{ route('admin.product-combos.update', $productCombo->id) }}" method="POST">
        @csrf
        @method('PUT')

        <div class="mb-3">
            <label>Sản phẩm mua</label>
            <select name="product_id" class="form-control">
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" {{ $productCombo->product_id == $p->id ? 'selected' : '' }}>
                        {{ $p->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label>Sản phẩm tặng</label>
            <select name="bonus_product_id" class="form-control">
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" {{ $productCombo->bonus_product_id == $p->id ? 'selected' : '' }}>
                        {{ $p->name }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="mb-3">
            <label>Số lượng mua</label>
            <input type="number" name="buy_quantity" class="form-control" value="{{ $productCombo->buy_quantity }}"
                min="1">
        </div>

        <div class="mb-3">
            <label>Số lượng tặng</label>
            <input type="number" name="bonus_quantity" class="form-control" value="{{ $productCombo->bonus_quantity }}"
                min="1">
        </div>

        <button class="btn btn-primary">Cập nhật</button>
        <a href="{{ route('admin.product-combos.index') }}" class="btn btn-secondary">Quay lại</a>
    </form>
@endsection
