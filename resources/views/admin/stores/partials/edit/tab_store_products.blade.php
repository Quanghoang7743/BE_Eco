            {{-- ────────────────────────────────────────────────────────
                 TAB 2 — Quản lý sản phẩm
            ──────────────────────────────────────────────────────────── --}}
            <div class="se-tab-pane {{ $activeTab === 'store-products' ? 'active' : '' }}" id="pane-store-products"
                role="tabpanel">
                <div class="se-tab-header">
                    <span class="se-tab-header-text">Danh sách sản phẩm của cửa hàng</span>
                    <a href="{{ route('admin.products.create', ['store_id' => $store->id]) }}"
                        class="se-btn se-btn-primary se-btn-sm">
                        + Thêm sản phẩm
                    </a>
                </div>

                <div class="se-table-card">
                    <table class="se-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Tên sản phẩm</th>
                                <th>Slug</th>
                                <th>Danh mục</th>
                                <th>Ảnh</th>
                                <th>Giá</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($storeProducts as $product)
                                <tr>
                                    <td style="color:#aaa; font-size:12.5px;">{{ $product->id }}</td>
                                    <td>
                                        <span class="se-product-name">{{ $product->name }}</span>
                                    </td>
                                    <td style="font-size:12.5px; color:#888; font-family:monospace;">{{ $product->slug }}
                                    </td>
                                    <td>
                                        @if ($product->category)
                                            <span class="se-badge se-badge-success">{{ $product->category->name }}</span>
                                        @else
                                            <span style="color:#ccc;">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if (!empty($product->thumbnail))
                                            <img src="{{ asset('storage/' . $product->thumbnail) }}" width="44"
                                                height="44" alt="Ảnh"
                                                style="border-radius:8px; object-fit:cover;">
                                        @else
                                            <span style="color:#ccc; font-size:12px;">—</span>
                                        @endif
                                    </td>
                                    <td class="se-price" style="font-weight:500;">
                                        {{ number_format((float) ($product->sale_price ?? ($product->price ?? 0)), 0, ',', '.') }}<span
                                            style="font-size:11px; color:#aaa;"> đ</span>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px;">
                                            <a href="{{ route('admin.products.edit', $product->id) }}?store_id={{ urlencode((string) $store->id) }}"
                                                class="se-btn se-btn-warn se-btn-sm">Sửa</a>
                                            <form action="{{ route('admin.products.destroy', $product->id) }}"
                                                method="POST" style="display:inline-block"
                                                onsubmit="return confirm('Xóa sản phẩm này?')">
                                                @csrf @method('DELETE')
                                                <input type="hidden" name="store_id" value="{{ $store->id }}">
                                                <input type="hidden" name="return_store_id"
                                                    value="{{ $store->id }}">
                                                <input type="hidden" name="active_tab" value="store-products">
                                                <button type="submit" class="se-btn se-btn-danger se-btn-sm">Xóa</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="se-empty">
                                            <span class="se-empty-icon">📦</span>
                                            Chưa có sản phẩm cho cửa hàng này.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="se-pagination">{{ $storeProducts->links('pagination::bootstrap-5') }}</div>
            </div>
