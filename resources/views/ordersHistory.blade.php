@extends('layout')

@section('title', 'Lịch sử đơn hàng')

@section('content')
    <div class="container">
        <h3 class="mb-4 mt-3">Lịch sử đơn hàng</h3>

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
                                $statusPaymentLabels = [
                                    'pending' => ['label' => 'Chờ thanh toán', 'class' => 'danger'],
                                    'paid' => ['label' => 'Đã thanh toán', 'class' => 'success'],
                                    'failed' => ['label' => 'Thất bại', 'class' => 'danger'],
                                    'refunded' => ['label' => 'Hoàn tiền', 'class' => 'primary'],
                                ];
                                $status = $statusLabels[$order->status] ?? [
                                    'label' => 'Không xác định',
                                    'class' => 'dark',
                                ];
                                $paymentMethodLabels = [
                                    'COD' => 'Thanh toán khi nhận hàng',
                                    'VNPAY' => 'Chuyển khoản ngân hàng',
                                ];

                            @endphp
                            @if (!in_array($order->status, ['completed', 'cancelled']))
                                <div class="dropdown d-inline ms-2">
                                    <a class="btn btn-sm btn-light dropdown-toggle" href="#" role="button"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </a>
                                    <ul class="dropdown-menu">
                                        @if ($order->payment_method == 'VNPAY' && ($order->status_payment != 'paid' && $order->status_payment != 'refunded'))
                                            <li>
                                                <form action="{{ route('vnpay.checkout', $order->id) }}" method="POST">
                                                    @csrf
                                                    {{-- @method('PATCH') --}}
                                                    <input type="hidden" name="id" value="{{ $order->id }}">
                                                    <input type="hidden" name="total" value="{{ $order->total }}">
                                                    <button type="submit" class="dropdown-item text-primary">
                                                        <i class="bi bi-x-circle me-1"></i> Thanh toán
                                                    </button>
                                                </form>
                                            </li>
                                        @endif
                                        <li>
                                            <form action="{{ route('orders.cancel', $order->id) }}" method="POST"
                                                onsubmit="return confirm('Bạn có chắc muốn hủy đơn hàng này?');">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="bi bi-x-circle me-1"></i> Hủy đơn
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            @endif
                            <span class="badge bg-{{ $status['class'] }}  text-uppercase">
                                {{ $status['label'] }}
                            </span>

                        </div>
                    </div>
                    <div class="card-body">
                        <p><strong>Ngày đặt:</strong> {{ $order->created_at->format('d/m/Y H:i') }}</p>
                        <p><strong>Tổng tiền:</strong> {{ number_format($order->total, 0, ',', '.') }} VND</p>
                        <p><strong>Phương thức thanh toán:</strong> {{ $paymentMethodLabels[$order->payment_method] ?? $order->payment_method }}</p>
                        <p>
                            <strong>Trạng thái thanh toán:</strong>
                            @php
                                $paymentStatus = $statusPaymentLabels[$order->status_payment] ?? ['label' => 'Không xác định', 'class' => 'dark'];
                            @endphp
                            <span class="badge bg-{{ $paymentStatus['class'] }}">{{ $paymentStatus['label'] }}</span>
                        </p>

                        <ul class="list-group">
                            @foreach ($order->items as $item)
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div class="d-flex">
                                        <img src="{{ asset('storage/' . $item->thumbnail) }}"
                                            alt="{{ $item->product_name }}" width="50" class="me-2" style="width: 50px">
                                        {{ $item->product_name }} (x{{ $item->quantity }})
                                    </div>
                                    <span>{{ number_format($item->total_price ?? ($item->unit_price ?? $item->price ?? 0) * $item->quantity, 0, ',', '.') }} VND</span>
                                </li>
                            @endforeach
                        </ul>

                    </div>
                </div>
            @endforeach
        @endif
    </div>
@endsection
