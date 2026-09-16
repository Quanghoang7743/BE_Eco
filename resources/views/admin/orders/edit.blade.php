@extends('admin.layout')

@section('styles')
    <style>
        .card-premium {
            background-color: #ffffff !important;
            border: 1px solid #eef1ee !important;
            border-radius: 16px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -1px rgba(0, 0, 0, 0.02) !important;
            padding: 24px !important;
            margin-bottom: 24px !important;
            transition: all 0.3s ease !important;
        }

        .card-premium:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02) !important;
        }

        .avatar-initials {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 56px !important;
            height: 56px !important;
            border-radius: 50% !important;
            background-color: #dcefe1 !important;
            color: #287b36 !important;
            font-size: 18px !important;
            font-weight: 800 !important;
            letter-spacing: -0.5px !important;
        }

        .status-badge {
            display: inline-block !important;
            padding: 4px 12px !important;
            border-radius: 9999px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            text-transform: none !important;
        }

        .status-badge-blue {
            background-color: #eff6ff !important;
            color: #2563eb !important;
        }

        .status-badge-green {
            background-color: #ecfdf5 !important;
            color: #10b981 !important;
        }

        .status-badge-orange {
            background-color: #fff7ed !important;
            color: #ea580c !important;
        }

        .status-badge-red {
            background-color: #fef2f2 !important;
            color: #ef4444 !important;
        }

        .status-badge-gray {
            background-color: #f3f4f6 !important;
            color: #4b5563 !important;
        }

        .order-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .product-thumb {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            object-fit: cover;
            border: 1px solid #e5e7eb;
        }

        .product-thumb-placeholder {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background-color: #f3f4f6;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #9ca3af;
            border: 1px solid #e5e7eb;
        }

        .btn-custom {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 8px 16px !important;
            font-size: 14px !important;
            transition: all 0.2s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .btn-custom-outline {
            border: 1px solid #d1d5db !important;
            background-color: #ffffff !important;
            color: #374151 !important;
        }

        .btn-custom-outline:hover {
            background-color: #f9fafb !important;
            border-color: #cbd5e1 !important;
            color: #111827 !important;
            text-decoration: none !important;
        }

        .btn-custom-primary {
            background-color: #3b82f6 !important;
            border: 1px solid #3b82f6 !important;
            color: #ffffff !important;
        }

        .btn-custom-primary:hover {
            background-color: #2563eb !important;
            border-color: #2563eb !important;
            color: #ffffff !important;
            text-decoration: none !important;
        }

        .btn-custom-danger {
            background-color: #ef4444 !important;
            border: 1px solid #ef4444 !important;
            color: #ffffff !important;
        }

        .btn-custom-danger:hover {
            background-color: #dc2626 !important;
            border-color: #dc2626 !important;
            color: #ffffff !important;
            text-decoration: none !important;
        }

        .copy-btn {
            background: none !important;
            border: none !important;
            padding: 4px !important;
            color: #9ca3af !important;
            cursor: pointer !important;
            transition: color 0.15s ease !important;
        }

        .copy-btn:hover {
            color: #3b82f6 !important;
        }

        .custom-input {
            border: 1px solid #d1d5db !important;
            border-radius: 8px !important;
            height: 42px !important;
            font-size: 14px !important;
            color: #374151 !important;
            background-color: #fff !important;
            transition: all 0.2s ease-in-out !important;
            box-shadow: none !important;
        }

        .custom-input:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
        }

        /* Timeline Connector and Bullet Styles */
        .timeline-container {
            position: relative;
            padding: 10px 0;
        }

        .timeline-line {
            position: absolute;
            top: 28px;
            left: 15%;
            right: 15%;
            height: 4px;
            background-color: #e5e7eb;
            z-index: 1;
            border-radius: 2px;
        }

        .timeline-line-active {
            position: absolute;
            top: 28px;
            left: 15%;
            height: 4px;
            background-color: #10b981;
            z-index: 2;
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .timeline-step {
            position: relative;
            z-index: 3;
            width: 30%;
            text-align: center;
        }

        .timeline-icon-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #ffffff;
            border: 2px solid #e5e7eb;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 8px auto;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .timeline-step.active .timeline-icon-circle {
            background-color: #ecfdf5;
            border-color: #10b981;
            color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
        }

        .timeline-step.active .timeline-step-title {
            color: #10162c !important;
            font-weight: 700 !important;
        }

        .timeline-step-title {
            font-size: 13px;
            font-weight: 500;
            color: #727c91;
            margin-bottom: 2px;
        }

        .timeline-step-time {
            font-size: 11px;
            color: #9ca3af;
        }
    </style>
@endsection

@section('content')
@php
    $canManageOrders = ! \App\Support\AdminCmsAccess::isSale();
    
    // Status text mapping
    $statusLabels = [
        'pending' => ['label' => 'Mới', 'class' => 'blue'],
        'pending_payment' => ['label' => 'Chờ thanh toán', 'class' => 'orange'],
        'confirmed' => ['label' => 'Đã xác nhận', 'class' => 'blue'],
        'preparing' => ['label' => 'Đang chuẩn bị', 'class' => 'orange'],
        'ready' => ['label' => 'Sẵn sàng giao', 'class' => 'orange'],
        'picked_up' => ['label' => 'Đang giao', 'class' => 'orange'],
        'delivered' => ['label' => 'Đã giao', 'class' => 'green'],
        'completed' => ['label' => 'Hoàn thành', 'class' => 'green'],
        'cancelled' => ['label' => 'Đã hủy', 'class' => 'red'],
        'returned' => ['label' => 'Hoàn trả', 'class' => 'gray'],
    ];
    $status = $statusLabels[$order->status] ?? ['label' => ucfirst($order->status), 'class' => 'gray'];

    // Shipping text mapping
    $shippingLabels = [
        'pickup' => 'Tự đến lấy',
        'delivery' => 'Giao tận nơi',
        'later' => 'Gọi món sau',
    ];
    $shippingLabel = $shippingLabels[$order->shipping_method] ?? 'Chưa rõ';

    // Payment text mapping
    $paymentStatusLabels = [
        'pending' => ['label' => 'Chưa thanh toán', 'class' => 'red'],
        'paid' => ['label' => 'Đã thanh toán', 'class' => 'green'],
        'refunded' => ['label' => 'Đã hoàn tiền', 'class' => 'blue'],
    ];
    $paymentStatus = $paymentStatusLabels[$order->status_payment] ?? ['label' => 'Chưa rõ', 'class' => 'gray'];

    // Subtotal calculation
    $subtotal = ($order->total_amount && $order->total_amount > 0)
        ? $order->total_amount
        : $order->items->sum(function($item) {
            return ($item->unit_price ?? $item->price ?? 0) * $item->quantity;
        });

    // Customer initials
    $words = explode(' ', $order->name);
    $initials = '';
    if (count($words) >= 2) {
        $initials = mb_substr($words[0], 0, 1) . mb_substr($words[count($words) - 1], 0, 1);
    } elseif (count($words) == 1) {
        $initials = mb_substr($words[0], 0, 1);
    }
    $initials = mb_strtoupper($initials);

    // Timeline steps activation logic
    $step1Active = !empty($order->confirmed_at) || !in_array($order->status, ['pending', 'pending_payment']);
    $step2Active = $order->status_payment === 'paid';
    $step3Active = in_array($order->status, ['delivered', 'completed']);

    $lineWidth = '0%';
    if ($step1Active && $step2Active && $step3Active) {
        $lineWidth = '70%';
    } elseif ($step1Active && $step2Active) {
        $lineWidth = '35%';
    }
@endphp

<div class="container-fluid py-4" style="max-width: 1400px; margin: 0 auto; font-family: 'Public Sans', sans-serif;">
    
    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm mb-4 d-flex align-items-center" style="border-radius: 8px; background-color: #ecfdf5; color: #10b981;">
            <i class="fas fa-check-circle me-2 fs-5"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    {{-- Top Action Header --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <h2 class="mb-0 fw-extrabold text-dark" style="font-size: 26px; letter-spacing: -0.5px;">Chi tiết đơn hàng</h2>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ route('admin.orders.invoice', $order->id) }}" target="_blank" class="btn btn-custom btn-custom-outline">
                <i class="fas fa-print"></i> Xuất hóa đơn
            </a>
            @if (!in_array($order->status, ['completed', 'cancelled'], true) && $canManageOrders)
                <form action="{{ route('admin.orders.updateStatus', $order->id) }}" method="POST" class="d-inline" onsubmit="return confirm('Bạn chắc chắn muốn hủy đơn hàng này?')">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="status" value="cancelled">
                    <button type="submit" class="btn btn-custom btn-custom-danger">
                        <i class="fas fa-ban"></i> Hủy đơn hàng
                    </button>
                </form>
            @endif
        </div>
    </div>

    {{-- 1. FOUR TOP SUMMARY CARD STATS --}}
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6 col-12">
            <div class="bg-white border rounded-4 p-3 d-flex align-items-center gap-3 shadow-none" style="border-color: #eef1ee !important; border-radius: 16px;">
                <div class="order-icon-wrapper bg-light-blue" style="background-color: #eff6ff; color: #2563eb;">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <p class="text-muted mb-1 text-uppercase fw-bold" style="font-size: 10px; letter-spacing: 0.8px;">Trạng thái đơn hàng</p>
                    <span class="status-badge status-badge-{{ $status['class'] }}">{{ $status['label'] }}</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="bg-white border rounded-4 p-3 d-flex align-items-center gap-3 shadow-none" style="border-color: #eef1ee !important; border-radius: 16px;">
                <div class="order-icon-wrapper" style="background-color: {{ $order->status_payment == 'paid' ? '#ecfdf5' : '#fef2f2' }}; color: {{ $order->status_payment == 'paid' ? '#10b981' : '#ef4444' }};">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <p class="text-muted mb-1 text-uppercase fw-bold" style="font-size: 10px; letter-spacing: 0.8px;">Thanh toán</p>
                    <span class="status-badge status-badge-{{ $paymentStatus['class'] }}">{{ $paymentStatus['label'] }}</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="bg-white border rounded-4 p-3 d-flex align-items-center gap-3 shadow-none" style="border-color: #eef1ee !important; border-radius: 16px;">
                <div class="order-icon-wrapper" style="background-color: #f0fdf4; color: #22c55e;">
                    <i class="fas fa-wallet"></i>
                </div>
                <div>
                    <p class="text-muted mb-1 text-uppercase fw-bold" style="font-size: 10px; letter-spacing: 0.8px;">Tổng tiền</p>
                    <span class="fw-extrabold text-success" style="font-size: 18px;">{{ number_format($order->total, 0, ',', '.') }}đ</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 col-12">
            <div class="bg-white border rounded-4 p-3 d-flex align-items-center gap-3 shadow-none" style="border-color: #eef1ee !important; border-radius: 16px;">
                <div class="order-icon-wrapper bg-light text-dark">
                    <i class="fas fa-shopping-bag"></i>
                </div>
                <div>
                    <p class="text-muted mb-1 text-uppercase fw-bold" style="font-size: 10px; letter-spacing: 0.8px;">Số sản phẩm</p>
                    <span class="fw-extrabold text-dark" style="font-size: 18px;">{{ count($order->items) }} sản phẩm</span>
                </div>
            </div>
        </div>
    </div>

    {{-- 2. MAIN LAYOUT GRID --}}
    <div class="row">
        
        {{-- LEFT COLUMN --}}
        <div class="col-lg-8 col-12">
            
            {{-- Order Metadata Box --}}
            <div class="card-premium">
                <div class="row g-3">
                    <div class="col-md-6">
                        <small class="text-muted d-block text-uppercase fw-bold mb-1" style="font-size: 10px;">Mã đơn hàng</small>
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-extrabold text-primary" style="font-size: 16px;">{{ $order->order_code ?? $order->id }}</span>
                            <button class="copy-btn" onclick="navigator.clipboard.writeText('{{ $order->order_code ?? $order->id }}'); alert('Đã sao chép mã đơn hàng!')">
                                <i class="far fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block text-uppercase fw-bold mb-1" style="font-size: 10px;">Ngày tạo</small>
                        <span class="fw-bold text-dark"><i class="far fa-calendar-alt me-1 text-muted"></i> {{ $order->created_at->format('H:i, d/m/Y') }}</span>
                    </div>
                </div>
            </div>

            {{-- Products Table Box --}}
            <div class="card-premium">
                <h5 class="fw-bold text-dark pb-3 border-bottom mb-3" style="font-size: 16px;">
                    <i class="fas fa-shopping-basket me-2 text-muted"></i> Sản phẩm đã đặt
                </h5>
                <div class="table-responsive">
                    <table class="table table-borderless align-middle" style="font-size: 13.5px;">
                        <thead>
                            <tr class="text-muted" style="border-bottom: 1px solid #f3f4f6;">
                                <th class="pb-2">Sản phẩm</th>
                                <th class="pb-2 text-center">Đơn giá</th>
                                <th class="pb-2 text-center">Số lượng</th>
                                <th class="pb-2 text-end">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($order->items as $item)
                                <tr style="border-bottom: 1px solid #f9fafb;">
                                    <td class="py-3 d-flex align-items-center gap-3">
                                        @if($item->product && $item->product->thumbnail)
                                            <img src="{{ asset('storage/' . $item->product->thumbnail) }}" alt="{{ $item->product->name }}" class="product-thumb">
                                        @else
                                            <div class="product-thumb-placeholder"><i class="fas fa-box"></i></div>
                                        @endif
                                        <div>
                                            <div class="fw-bold text-dark">{{ $item->product_name ?? ($item->product->name ?? 'Sản phẩm không rõ') }}</div>
                                            @if($item->variant_id)
                                                <small class="text-muted d-block mt-0.5">Biến thể ID: {{ $item->variant_id }}</small>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-3 text-center text-muted fw-medium">{{ number_format($item->unit_price ?? $item->price ?? 0, 0, ',', '.') }} đ</td>
                                    <td class="py-3 text-center fw-bold text-dark">x{{ $item->quantity }}</td>
                                    <td class="py-3 text-end fw-bold text-dark">{{ number_format($item->total_price ?? ($item->unit_price ?? $item->price ?? 0) * $item->quantity, 0, ',', '.') }} đ</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Price Breakdown --}}
                <div class="border-top border-dashed pt-4 mt-3" style="border-top-style: dashed !important; border-top-color: #e5e7eb !important;">
                    <div class="d-flex justify-content-between text-muted mb-2" style="font-size: 13.5px;">
                        <span>Số lượng sản phẩm</span>
                        <span class="text-dark fw-medium">{{ $order->items->sum('quantity') }}</span>
                    </div>
                    <div class="d-flex justify-content-between text-muted mb-2" style="font-size: 13.5px;">
                        <span>Tạm tính</span>
                        <span class="text-dark fw-medium">{{ number_format($subtotal, 0, ',', '.') }} đ</span>
                    </div>
                    <div class="d-flex justify-content-between text-muted mb-2" style="font-size: 13.5px;">
                        <span>Khuyến mãi</span>
                        <span class="text-danger fw-medium">-{{ number_format($order->discount_amount ?? 0, 0, ',', '.') }} đ</span>
                    </div>
                    <div class="d-flex justify-content-between text-muted mb-3" style="font-size: 13.5px;">
                        <span>Phí vận chuyển</span>
                        <span class="text-dark fw-medium">{{ number_format($order->shipping_fee ?? 0, 0, ',', '.') }} đ</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-3 border-top" style="border-top-color: #f3f4f6 !important;">
                        <span class="fw-bold text-muted" style="font-size: 14px;">Tổng thanh toán</span>
                        <span class="fw-extrabold text-primary" style="font-size: 24px;">{{ number_format($order->total, 0, ',', '.') }} đ</span>
                    </div>
                </div>

                {{-- Payment summary box --}}
                <div class="p-3 bg-light rounded-3 mt-4" style="border-radius: 12px; background-color: #f8fafc !important;">
                    <div class="d-flex align-items-center gap-2 mb-3 fw-bold text-dark" style="font-size: 13.5px;">
                        <i class="fas fa-check-circle text-success fs-5"></i> 
                        <span>{{ $order->payment_method ?? 'COD (Thanh toán khi nhận hàng)' }}</span>
                    </div>
                    <div class="row g-2 text-muted" style="font-size: 12.5px;">
                        <div class="col-sm-4">
                            <span class="d-block">Số tiền đã thanh toán</span>
                            <strong class="text-dark">{{ number_format($order->status_payment == 'paid' ? $order->total : 0, 0, ',', '.') }} đ</strong>
                        </div>
                        <div class="col-sm-4">
                            <span class="d-block">Số tiền đã hoàn trả</span>
                            <strong class="text-dark">{{ number_format($order->status_payment == 'refunded' ? $order->total : 0, 0, ',', '.') }} đ</strong>
                        </div>
                        <div class="col-sm-4">
                            <span class="d-block">Số tiền thực nhận</span>
                            <strong class="text-dark">{{ number_format($order->status_payment == 'paid' ? $order->total : 0, 0, ',', '.') }} đ</strong>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Ghi chú Card --}}
            <div class="card-premium">
                <h5 class="fw-bold text-dark pb-3 border-bottom mb-3" style="font-size: 16px;">
                    <i class="far fa-edit me-2 text-muted"></i> Ghi chú
                </h5>
                <form action="{{ route('admin.orders.update', $order->id) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="d-flex gap-2">
                        <input type="text" name="delivery_note" class="form-control custom-input" placeholder="Thêm ghi chú cho đơn hàng..." value="{{ $order->delivery_note }}">
                        <button type="submit" class="btn btn-custom btn-custom-primary px-4"><i class="fas fa-save"></i> Lưu</button>
                    </div>
                </form>
            </div>

            {{-- Progress Timeline --}}
            <div class="card-premium">
                <h5 class="fw-bold text-dark pb-3 border-bottom mb-3" style="font-size: 16px;">
                    <i class="far fa-clock me-2 text-muted"></i> Lịch trình đơn hàng
                </h5>
                <div class="timeline-container d-flex justify-content-between align-items-start">
                    <!-- Lines -->
                    <div class="timeline-line"></div>
                    <div class="timeline-line-active" style="width: {{ $lineWidth }};"></div>

                    <!-- Step 1 -->
                    <div class="timeline-step {{ $step1Active ? 'active' : '' }}">
                        <div class="timeline-icon-circle">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <div class="timeline-step-title">Đã xác thực đơn hàng</div>
                        <div class="timeline-step-time">{{ $order->confirmed_at ? $order->confirmed_at->format('H:i d/m/Y') : ($step1Active ? $order->created_at->format('H:i d/m/Y') : '-') }}</div>
                    </div>

                    <!-- Step 2 -->
                    <div class="timeline-step {{ $step2Active ? 'active' : '' }}">
                        <div class="timeline-icon-circle">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <div class="timeline-step-title">Đã thanh toán</div>
                        <div class="timeline-step-time">{{ $step2Active && $order->confirmed_at ? $order->confirmed_at->addMinutes(3)->format('H:i d/m/Y') : ($step2Active ? $order->updated_at->format('H:i d/m/Y') : '-') }}</div>
                    </div>

                    <!-- Step 3 -->
                    <div class="timeline-step {{ $step3Active ? 'active' : '' }}">
                        <div class="timeline-icon-circle">
                            <i class="fas fa-truck"></i>
                        </div>
                        <div class="timeline-step-title">Đã giao hàng</div>
                        <div class="timeline-step-time">{{ $order->delivered_at ? $order->delivered_at->format('H:i d/m/Y') : ($step3Active ? $order->updated_at->format('H:i d/m/Y') : '-') }}</div>
                    </div>
                </div>
            </div>

        </div>

        {{-- RIGHT COLUMN (Sidebar) --}}
        <div class="col-lg-4 col-12">
            
            {{-- Customer Info Box --}}
            <div class="card-premium">
                <h5 class="fw-bold text-dark pb-3 border-bottom mb-3" style="font-size: 15px;">Thông tin khách hàng</h5>
                <div class="d-flex align-items-center gap-3">
                    <div class="avatar-initials">{{ $initials }}</div>
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 15px;">{{ $order->name }}</div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <span class="badge bg-primary px-2 py-0.5 text-white" style="font-size: 10px; border-radius: 4px;">Member</span>
                            <span class="text-muted" style="font-size: 11px;"><i class="fas fa-star text-warning"></i> 0 điểm thưởng</span>
                        </div>
                        <div class="text-muted mt-2" style="font-size: 13px;"><i class="fas fa-phone-alt me-1"></i> {{ $order->phone }}</div>
                    </div>
                </div>
            </div>

            {{-- Shipping Method Box --}}
            <div class="card-premium">
                <h5 class="fw-bold text-dark pb-3 border-bottom mb-3" style="font-size: 15px;">Hình thức giao hàng</h5>
                <div class="d-flex align-items-center gap-3">
                    <div class="order-icon-wrapper" style="background-color: #fff7ed; color: #ea580c; font-size: 20px;">
                        @if($order->shipping_method == 'pickup')
                            <i class="fas fa-store"></i>
                        @else
                            <i class="fas fa-motorcycle"></i>
                        @endif
                    </div>
                    <div class="fw-bold text-dark" style="font-size: 15px;">{{ $shippingLabel }}</div>
                </div>
            </div>

            {{-- Shipping Address Box --}}
            <div class="card-premium">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
                    <h5 class="fw-bold text-dark mb-0" style="font-size: 15px;">Địa chỉ giao hàng</h5>
                    <span class="text-muted" style="cursor: pointer;"><i class="fas fa-pencil-alt"></i></span>
                </div>
                <div class="space-y-1.5" style="font-size: 13.5px; color: #374151;">
                    <div class="fw-bold text-dark">{{ $order->name }}</div>
                    <div>SĐT: {{ $order->phone }}</div>
                    <div class="mt-2 text-muted"><i class="fas fa-map-marker-alt me-1"></i> {{ $order->address }}</div>
                    <div class="text-muted mt-1">Vietnam</div>
                </div>
            </div>

            {{-- Staff Branch Box --}}
            <div class="card-premium">
                <h5 class="fw-bold text-dark pb-3 border-bottom mb-3" style="font-size: 15px;">Nhân viên tạo đơn hàng</h5>
                <div class="space-y-3" style="font-size: 13.5px;">
                    <div class="fw-bold text-dark"><i class="far fa-user me-2 text-muted"></i> {{ $order->user->name ?? $order->name }}</div>
                    
                    <div class="border-top border-dashed pt-3 mt-3" style="border-top-style: dashed !important; border-top-color: #e5e7eb !important;">
                        <div class="d-flex justify-content-between align-items-center text-muted mb-2">
                            <span><i class="fas fa-store me-1"></i> Chi nhánh giao hàng</span>
                            <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                        </div>
                        <div class="p-2 bg-light rounded" style="background-color: #f8fafc !important; border-radius: 8px;">
                            <strong class="text-dark d-block mb-1">{{ $order->store->name ?? 'Cửa hàng Ecoluck' }}</strong>
                            <span class="text-muted d-block" style="font-size: 12px;">{{ $order->store->address ?? 'Địa chỉ cửa hàng' }}</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>

</div>
@endsection
