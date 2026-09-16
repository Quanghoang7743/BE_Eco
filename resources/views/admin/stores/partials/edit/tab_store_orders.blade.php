{{-- ────────────────────────────────────────────────────────
     TAB — Quản lý đơn hàng
──────────────────────────────────────────────────────────── --}}
<div class="se-tab-pane {{ $activeTab === 'store-orders' ? 'active' : '' }}" id="pane-store-orders"
    role="tabpanel">
    <div class="se-tab-header">
        <span class="se-tab-header-text">Danh sách đơn hàng của cửa hàng</span>
    </div>

    <div class="se-table-card">
        <table class="se-table">
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Khách hàng</th>
                    <th>Sản phẩm</th>
                    <th>Tổng tiền</th>
                    <th>Ngày tạo</th>
                    <th>Trạng thái</th>
                    <th>Thanh toán</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($storeOrders as $order)
                    @php
                        $statusLabels = [
                            'pending' => ['label' => 'Khởi tạo', 'class' => 'se-badge-warn'],
                            'pending_payment' => ['label' => 'Chờ thanh toán', 'class' => 'se-badge-warn'],
                            'paid_pending_call' => ['label' => 'Chờ gọi xác nhận', 'class' => 'se-badge-success'],
                            'approved' => ['label' => 'Duyệt', 'class' => 'se-badge-success'],
                            'confirmed' => ['label' => 'Đã xác nhận', 'class' => 'se-badge-success'],
                            'packed' => ['label' => 'Đóng gói', 'class' => 'se-badge-success'],
                            'preparing' => ['label' => 'Đang chuẩn bị', 'class' => 'se-badge-success'],
                            'ready' => ['label' => 'Sẵn sàng', 'class' => 'se-badge-success'],
                            'shipped' => ['label' => 'Xuất kho', 'class' => 'se-badge-success'],
                            'picked_up' => ['label' => 'Đã lấy hàng', 'class' => 'se-badge-success'],
                            'completed' => ['label' => 'Hoàn thành', 'class' => 'se-badge-success'],
                            'delivered' => ['label' => 'Đã giao', 'class' => 'se-badge-success'],
                            'call_completed' => ['label' => 'Gọi hoàn tất', 'class' => 'se-badge-success'],
                            'returned' => ['label' => 'Hoàn hàng', 'class' => 'se-badge-danger'],
                            'cancelled' => ['label' => 'Hủy đơn', 'class' => 'se-badge-danger'],
                            'canceled' => ['label' => 'Hủy đơn', 'class' => 'se-badge-danger'],
                        ];
                        $status = $statusLabels[strtolower($order->status)] ?? ['label' => $order->status ?: 'Không xác định', 'class' => 'se-badge-warn'];

                        $paymentLabels = [
                            'pending' => ['label' => 'Chờ thanh toán', 'class' => 'se-badge-danger'],
                            'paid' => ['label' => 'Đã thanh toán', 'class' => 'se-badge-success'],
                            'failed' => ['label' => 'Thất bại', 'class' => 'se-badge-danger'],
                            'refunded' => ['label' => 'Hoàn tiền', 'class' => 'se-badge-warn'],
                        ];
                        $payment = $paymentLabels[strtolower($order->status_payment)] ?? ['label' => $order->status_payment ?: 'Không xác định', 'class' => 'se-badge-warn'];
                    @endphp
                    <tr>
                        <td>
                            @if (!empty($order->order_code))
                                <a href="{{ route('admin.orders.show', $order->id) }}" style="font-weight:600; color:#1a1a1a; text-decoration:none; hover:underline;">
                                    {{ $order->order_code }}
                                </a>
                                <div style="font-size:11.5px; color:#aaa; margin-top:2px;">ID: {{ $order->id }}</div>
                            @else
                                <a href="{{ route('admin.orders.show', $order->id) }}" style="font-weight:600; color:#1a1a1a; text-decoration:none; hover:underline;">
                                    {{ $order->id }}
                                </a>
                            @endif
                        </td>
                        <td>
                            @if ($order->user)
                                <div class="se-product-name">{{ $order->user->full_name ?: $order->user->name }}</div>
                            @else
                                <div class="se-product-name">{{ $order->name ?? 'N/A' }}</div>
                            @endif
                            <div class="se-product-sku">{{ $order->phone ?? ($order->delivery_phone ?? 'N/A') }}</div>
                        </td>
                        <td>
                            <div style="display:flex; flex-direction:column; gap:3px;">
                                @forelse ($order->items as $item)
                                    <div style="font-size: 12.5px; color: #555;">
                                        • {{ $item->product_name ?? ($item->product->name ?? 'Sản phẩm không tồn tại') }} (x{{ $item->quantity }})
                                    </div>
                                @empty
                                    <span style="color:#ccc; font-size:12px;">Không có sản phẩm</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="se-price" style="font-weight: 500;">
                            @php
                                $amount = 0;
                                foreach (['final_amount', 'total_amount', 'total'] as $col) {
                                    if ($order->{$col} !== null) {
                                        $amount = (float)$order->{$col};
                                        break;
                                    }
                                }
                            @endphp
                            {{ number_format($amount, 0, ',', '.') }}<span style="font-size:11px; color:#aaa;"> đ</span>
                        </td>
                        <td style="font-size:12.5px; color:#666;">
                            {{ $order->created_at ? $order->created_at->format('d/m/Y H:i') : '—' }}
                        </td>
                        <td>
                            <span class="se-badge {{ $status['class'] }}">{{ $status['label'] }}</span>
                        </td>
                        <td>
                            <span class="se-badge {{ $payment['class'] }}">{{ $payment['label'] }}</span>
                        </td>
                        <td>
                            <a href="{{ route('admin.orders.show', $order->id) }}" class="se-btn se-btn-secondary se-btn-sm">
                                Xem
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div class="se-empty">
                                <span class="se-empty-icon">📋</span>
                                Chưa có đơn hàng nào tại cửa hàng này.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="se-pagination">{{ $storeOrders->links('pagination::bootstrap-5') }}</div>
</div>
