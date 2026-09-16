{{-- ────────────────────────────────────────────────────────
     TAB — Quản lý khách hàng
──────────────────────────────────────────────────────────── --}}
<div class="se-tab-pane {{ $activeTab === 'store-customers' ? 'active' : '' }}" id="pane-store-customers"
    role="tabpanel">
    <div class="se-tab-header">
        <span class="se-tab-header-text">Danh sách khách hàng đã mua sản phẩm của cửa hàng</span>
    </div>

    <div class="se-table-card">
        <table class="se-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Khách hàng</th>
                    <th>Số điện thoại</th>
                    <th>Email</th>
                    <th>Số đơn mua</th>
                    <th>Tổng chi tiêu</th>
                    <th>Lần mua cuối</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($storeCustomers as $customer)
                    <tr>
                        <td style="color:#aaa; font-size:12.5px;">{{ $customer->id }}</td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: #1a1a1a; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 13.5px; flex-shrink: 0;">
                                    {{ mb_strtoupper(mb_substr($customer->full_name ?: ($customer->name ?: 'U'), 0, 1)) }}
                                </div>
                                <div>
                                    <div class="se-product-name">{{ $customer->full_name ?: $customer->name }}</div>
                                    <div class="se-product-sku">username: {{ $customer->username ?? 'N/A' }}</div>
                                </div>
                            </div>
                        </td>
                        <td>
                            {{ $customer->phone ?? 'N/A' }}
                        </td>
                        <td>
                            {{ $customer->email ?? 'N/A' }}
                        </td>
                        <td style="font-weight: 500;">
                            {{ number_format((int)$customer->store_orders_count) }} đơn
                        </td>
                        <td class="se-price" style="font-weight: 500; color: #1a6636;">
                            {{ number_format((float)($customer->store_orders_total ?? 0), 0, ',', '.') }}<span style="font-size:11px; color:#aaa;"> đ</span>
                        </td>
                        <td style="font-size:12.5px; color:#666;">
                            {{ $customer->last_ordered_at ? \Carbon\Carbon::parse($customer->last_ordered_at)->format('d/m/Y H:i') : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="se-empty">
                                <span class="se-empty-icon">👤</span>
                                Chưa có khách hàng mua sản phẩm nào của cửa hàng này.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="se-pagination">{{ $storeCustomers->links('pagination::bootstrap-5') }}</div>
</div>
