@extends('admin.layout')

@section('content')
    <div>
        <h1 class="mb-4">Danh sách phần thưởng</h1>

        <a href="{{ route('admin.rewards.create') }}" class="btn btn-primary mb-3">Thêm phần thưởng</a>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Tên</th>
                    <th>Loại</th>
                    <th>Giá trị</th>
                    <th>Số lượng</th>
                    <th>Xác suất (%)</th>
                    <th>Hành động</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rewards as $reward)
                    <tr>
                        <td>{{ $reward->name }}</td>
                        <td>{{ $reward->type }}</td>
                        <td>
                            @switch($reward->type)
                                @case('point')
                                    {{ number_format($reward->value) }} điểm
                                @break

                                @case('extra_spin')
                                    +{{ $reward->value }} lượt quay
                                @break

                                @case('voucher')
                                    {{ $reward->voucher->code ?? '—' }}
                                @break

                                @case('product')
                                    {{ $reward->product->name ?? '—' }}
                                @break

                                @default
                                    —
                            @endswitch
                        </td>

                        <td>{{ $reward->quantity ?? '∞' }}</td>
                        <td>{{ $reward->probability }}</td>
                        <td>
                            <a href="{{ route('admin.rewards.edit', $reward) }}" class="btn btn-sm btn-warning">Sửa</a>
                            <form action="{{ route('admin.rewards.destroy', $reward) }}" method="POST"
                                style="display:inline-block;">
                                @csrf
                                @method('DELETE')
                                <button onclick="return confirm('Xoá phần thưởng này?')"
                                    class="btn btn-sm btn-danger">Xóa</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
