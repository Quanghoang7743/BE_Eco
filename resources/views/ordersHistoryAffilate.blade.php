@extends('layout')

@section('title', 'Lịch sử đơn hàng')

@section('content')
    <div class="container">
        <h3 class="mb-4 mt-3">Lịch sử đơn hàng liên kết</h3>

        @if ($orders->isEmpty())
            <p>Bạn chưa có đơn hàng nào.</p>
        @else
            @foreach ($orders as $order)
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>Mã đơn: #{{ $order->id }}</strong>
                        <div>
                            @php
                                $statusLabels = [
                                    'pending' => ['label' => 'Khởi tạo', 'class' => 'secondary'],
                                    'approved' => ['label' => 'Duyệt', 'class' => 'info'],
                                    'packed' => ['label' => 'Đóng gói', 'class' => 'primary'],
                                    'shipped' => ['label' => 'Xuất kho', 'class' => 'warning'],
                                    'completed' => ['label' => 'Hoàn thành', 'class' => 'success'],
                                    'cancelled' => ['label' => 'Hủy đơn', 'class' => 'danger'],
                                ];
                                $status = $statusLabels[$order->status] ?? [
                                    'label' => 'Không xác định',
                                    'class' => 'dark',
                                ];
                            @endphp

                            <span class="badge bg-{{ $status['class'] }}  text-uppercase">
                                {{ $status['label'] }}
                            </span>

                        </div>
                    </div>
                    <div class="card-body">
                        <p><strong>Họ tên:</strong> {{ $order->name }}</p>
                        <p><strong>Ngày đặt:</strong> {{ $order->created_at->format('d/m/Y H:i') }}</p>
                        <p><strong>Tổng tiền:</strong> {{ number_format($order->total, 0, ',', '.') }}đ</p>

                        <ul class="list-group">
                            @foreach ($order->items as $item)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <img src="{{ asset('storage/' . $item->thumbnail) }}" alt="{{ $item->product_name }}"
                                            width="50" class="me-2">
                                        <span>
                                            {{ $item->product_name }} (x{{ $item->quantity }})
                                            <span>
                                                Hoa hồng:{{ number_format($item->commission_amount, 0, ',', '.') }}đ
                                            </span>
                                        </span>
                                    </div>
                                    <span>{{ number_format($item->total_price ?? ($item->unit_price ?? $item->price ?? 0) * $item->quantity, 0, ',', '.') }}đ</span>
                                </li>
                            @endforeach
                        </ul>

                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
