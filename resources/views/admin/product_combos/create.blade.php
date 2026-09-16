@extends('admin.layout')

@section('content')
<h2>Thêm combo</h2>
<form action="{{ route('admin.product-combos.store') }}" method="POST">
    @csrf
    <div class="mb-3">
        <label>Sản phẩm mua</label>
        <select name="product_id" class="form-control">
            @foreach($products as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label>Sản phẩm tặng</label>
        <select name="bonus_product_id" class="form-control">
            @foreach($products as $p)
                <option value="{{ $p->id }}">{{ $p->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="mb-3">
        <label>Số lượng mua</label>
        <input type="number" name="buy_quantity" class="form-control" value="1">
    </div>
    <div class="mb-3">
        <label>Số lượng tặng</label>
        <input type="number" name="bonus_quantity" class="form-control" value="1">
    </div>
    <button class="btn btn-success">Lưu</button>
</form>
@endsection
