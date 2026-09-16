@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Danh sách voucher</h1>

    <div class="mb-3 text-end">
        <a href="{{ route('admin.vouchers.create') }}" class="btn btn-success">
            <i class="fas fa-plus"></i> Thêm mới
        </a>
    </div>

    <div style="overflow-x:auto;">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Mã</th>
                    <th>Cửa hàng</th>
                    <th>Loại</th>
                    <th>Giảm</th>
                    <th>SL còn</th>
                    <th>Đơn tối thiểu</th>
                    <th>Thời gian</th>
                    <th>Trạng thái</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($vouchers as $voucher)
                    <tr>
                        <td>{{ $voucher->code }}</td>
                        <td>{{ $voucher->store?->name ?? 'Toàn hệ thống' }}</td>
                        <td>
                            <span class="badge bg-info">{{ $voucher->type == 'percentage' ? 'Phần trăm' : 'Cố định' }}</span>
                        </td>
                        <td>
                            @if ($voucher->type === 'percentage')
                                {{ rtrim(rtrim(number_format($voucher->discount_value, 2), '0'), '.') }}%
                            @else
                                {{ number_format($voucher->discount_value, 0, ',', '.') }} VND
                            @endif
                        </td>

                        <td>{{ $voucher->quantity - $voucher->used }}</td>
                        <td>{{ number_format($voucher->min_order_value, 0, ',', '.') }} VND</td>
                        <td>{{ $voucher->start_date->format('d/m/Y') }} - {{ $voucher->end_date->format('d/m/Y') }}</td>
                        <td>
                            <span class="badge bg-{{ $voucher->is_active ? 'success' : 'secondary' }}">
                                {{ $voucher->is_active ? 'Hoạt động' : 'Ngừng' }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('admin.vouchers.edit', $voucher->id) }}" class="btn btn-sm btn-warning">
                                <i class="fas fa-edit"></i>
                            </a>
                            <form action="{{ route('admin.vouchers.destroy', $voucher->id) }}" method="POST"
                                class="d-inline-block" onsubmit="return confirm('Xóa voucher này?')">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-danger">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{ $vouchers->links('pagination::bootstrap-5') }}
@endsection
