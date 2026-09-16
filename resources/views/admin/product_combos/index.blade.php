@extends('admin.layout')

@section('content')
<h2>Danh sách combo</h2>
<a href="{{ route('admin.product-combos.create') }}" class="btn btn-primary mb-3">+ Thêm combo</a>
<table class="table table-bordered">
    <thead>
        <tr>
            <th>ID</th>
            <th>Sản phẩm mua</th>
            <th>Sản phẩm tặng</th>
            <th>Số lượng mua</th>
            <th>Số lượng tặng</th>
            <th>Hành động</th>
        </tr>
    </thead>
    <tbody>
        @foreach($combos as $combo)
        <tr>
            <td>{{ $combo->id }}</td>
            <td>{{ $combo->product->name }}</td>
            <td>{{ $combo->bonusProduct->name }}</td>
            <td>{{ $combo->buy_quantity }}</td>
            <td>{{ $combo->bonus_quantity }}</td>
            <td>
                <a href="{{ route('admin.product-combos.edit', $combo->id) }}" class="btn btn-sm btn-warning">Sửa</a>
                <form action="{{ route('admin.product-combos.destroy', $combo->id) }}" method="POST" class="d-inline"
                      onsubmit="return confirm('Bạn có chắc chắn muốn xóa?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-danger">Xóa</button>
                </form>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{ $combos->links() }}
@endsection
