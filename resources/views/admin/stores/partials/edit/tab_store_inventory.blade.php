            {{-- ────────────────────────────────────────────────────────
                 TAB 3 — Quản lý tồn kho
            ──────────────────────────────────────────────────────────── --}}
            <div class="se-tab-pane {{ $activeTab === 'store-inventory' ? 'active' : '' }}" id="pane-store-inventory"
                role="tabpanel">
                <div class="se-tab-header">
                    <span class="se-tab-header-text">Cập nhật tồn kho từng sản phẩm</span>
                    @php $outOfStock = $inventoryProducts->where('stock', 0)->count(); @endphp
                    @if ($outOfStock > 0)
                        <span class="se-badge se-badge-danger">{{ $outOfStock }} hết hàng</span>
                    @endif
                </div>

                <div class="se-table-card">
                    <table class="se-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tên sản phẩm</th>
                                <th>Ảnh</th>
                                <th>Giá</th>
                                <th>Tồn kho</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($inventoryProducts as $product)
                                <tr class="{{ (int) $product->stock === 0 ? 'out-of-stock' : '' }}">
                                    <td style="color:#aaa; font-size:12.5px;">{{ $product->id }}</td>
                                    <td>
                                        <span class="se-product-name">{{ $product->name }}</span>
                                        @if ((int) $product->stock === 0)
                                            <span class="se-badge se-badge-danger" style="margin-left:6px;">Hết
                                                hàng</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if (!empty($product->thumbnail))
                                            <img src="{{ asset('storage/' . $product->thumbnail) }}" width="44"
                                                height="44" alt="Ảnh">
                                        @else
                                            <span style="color:#ccc; font-size:12px;">—</span>
                                        @endif
                                    </td>
                                    <td class="se-price" style="font-weight:500;">
                                        {{ number_format((float) ($product->sale_price ?? ($product->price ?? 0)), 0, ',', '.') }}<span
                                            style="font-size:11px; color:#aaa;"> đ</span>
                                    </td>
                                    <td>
                                        <form action="{{ route('admin.inventory.updateStock', $product->id) }}"
                                            method="POST" class="se-stock-form js-stock-update-form"
                                            data-product-id="{{ $product->id }}">
                                            @csrf @method('PUT')
                                            <input type="number" name="stock" value="{{ $product->stock }}"
                                                min="0" required class="se-stock-input js-stock-input">
                                            <input type="hidden" name="return_store_id" value="{{ $store->id }}">
                                            <input type="hidden" name="active_tab" value="store-inventory">
                                            <button type="submit"
                                                class="se-btn se-btn-success se-btn-sm js-stock-submit-btn">Lưu</button>
                                        </form>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.products.edit', $product->id) }}?store_id={{ urlencode((string) $store->id) }}"
                                            class="se-btn se-btn-outline-info se-btn-sm">Cập nhật</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6">
                                        <div class="se-empty">
                                            <span class="se-empty-icon">🗃️</span>
                                            Chưa có sản phẩm tồn kho cho cửa hàng này.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="se-pagination">{{ $inventoryProducts->links('pagination::bootstrap-5') }}</div>
            </div>
