            {{-- ────────────────────────────────────────────────────────
                 TAB 4 — Duyệt sản phẩm
            ──────────────────────────────────────────────────────────── --}}
            <div class="se-tab-pane {{ $activeTab === 'store-product-approvals' ? 'active' : '' }}"
                id="pane-store-product-approvals" role="tabpanel">
                <div class="se-tab-header">
                    <span class="se-tab-header-text">Danh sách sản phẩm đang chờ duyệt của cửa hàng</span>
                    <span class="se-badge se-badge-warn">
                        {{ (int) ($storeModuleStats['pending_approval'] ?? 0) }} chờ duyệt
                    </span>
                </div>

                <div class="se-table-card">
                    <table class="se-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Sản phẩm</th>
                                <th>L&#7847;n duy&#7879;t</th>
                                <th>Danh mục</th>
                                <th>Giá</th>
                                <th>Biến thể</th>
                                <th>Ticket</th>
                                <th>AFF</th>
                                <th>Ngày gửi</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingApprovalProducts as $product)
                                <tr>
                                    <td style="color:#aaa; font-size:12.5px;">{{ $product->id }}</td>
                                    <td>
                                        <div class="se-product-name">{{ $product->name }}</div>
                                        <div class="se-product-sku">SKU: {{ $product->sku ?? 'N/A' }}</div>
                                    </td>
                                    @php
                                        $latestApprovalHistory = $product->latestApprovalHistory;
                                        $approvalAttemptNumber = $latestApprovalHistory
                                            ? max(1, (int) $latestApprovalHistory->attempt_number)
                                            : 1;
                                        if (
                                            $product->status === 'pending_approval'
                                            && $latestApprovalHistory
                                            && $latestApprovalHistory->status !== 'pending'
                                        ) {
                                            $approvalAttemptNumber++;
                                        }
                                    @endphp
                                    <td style="text-align:center;">
                                        <div class="se-product-name">L&#7847;n {{ $approvalAttemptNumber }}</div>
                                        <div class="se-product-sku">{{ (int) ($product->approval_histories_count ?? 0) }} l&#7883;ch s&#7917;</div>
                                    </td>
                                    <td>{{ $product->category->name ?? 'N/A' }}</td>
                                    <td class="se-price">
                                        {{ number_format((float) ($product->sale_price ?? ($product->price ?? 0)), 0, ',', '.') }}<span
                                            style="font-size:11px; color:#aaa;"> đ</span>
                                    </td>
                                    <td style="text-align:center;">{{ (int) ($product->variants_count ?? 0) }}</td>
                                    @php
                                        $productCommissionValue = (float) ($product->commission_rate ?? 0);
                                        $variantCommissionValue = (float) ($product->variants?->max('commission_rate') ?? 0);
                                        $variantCommissionSource = $product->variants
                                            ?->sortByDesc(fn ($variant) => (float) ($variant->commission_rate ?? 0))
                                            ->first();
                                        $approvalCommission = $variantCommissionValue > 0
                                            ? $variantCommissionValue
                                            : $productCommissionValue;
                                        $approvalCommissionMode = $variantCommissionValue > 0
                                            ? ($variantCommissionSource->commission_rate_mode ?? $product->commission_rate_mode ?? 'percent')
                                            : ($product->commission_rate_mode ?? $variantCommissionSource->commission_rate_mode ?? 'percent');
                                        $approvalTicketQty = max(
                                            (int) ($product->ticket_reward_quantity ?? 0),
                                            (int) ($product->variants?->max('ticket_reward_quantity') ?? 0)
                                        );
                                        $approvalTicketType = $product->ticket_type_code
                                            ?: optional($product->variants?->firstWhere('ticket_reward_quantity', $approvalTicketQty))->ticket_type_code
                                            ?: 'default';
                                    @endphp
                                    <td>
                                        @if ($approvalTicketQty > 0)
                                            <div class="se-product-name">+{{ number_format($approvalTicketQty, 0, ',', '.') }}</div>
                                            <div class="se-product-sku">{{ strtoupper($approvalTicketType) }}</div>
                                        @else
                                            <span style="color:#aaa; font-size:12.5px;">Không có</span>
                                        @endif
                                    </td>
                                    <td style="text-align:center;">
                                        @if ($approvalCommissionMode === 'fixed')
                                            <strong>{{ number_format($approvalCommission, 0, ',', '.') }} đ</strong>
                                        @else
                                            <strong>{{ number_format($approvalCommission, 2, ',', '.') }}%</strong>
                                        @endif
                                    </td>
                                    <td style="font-size:12.5px; color:#888;">
                                        {{ optional($product->created_at)->format('d/m/Y H:i') ?? 'N/A' }}
                                    </td>
                                    <td>
                                        <div class="se-approval-actions">
                                            <a href="{{ route('admin.product-approvals.show', ['product' => $product, 'return_store_id' => $store->id, 'active_tab' => 'store-product-approvals']) }}"
                                                class="se-btn se-btn-outline-info se-btn-sm">Chi tiết</a>

                                            <form method="POST"
                                                action="{{ route('admin.product-approvals.approve', $product) }}"
                                                onsubmit="return confirm('Duyệt sản phẩm {{ addslashes($product->name) }}?');">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="return_store_id"
                                                    value="{{ $store->id }}">
                                                <input type="hidden" name="active_tab" value="store-product-approvals">
                                                <button type="submit" class="se-btn se-btn-success se-btn-sm">✓
                                                    Duyệt</button>
                                            </form>

                                            <form method="POST"
                                                action="{{ route('admin.product-approvals.reject', $product) }}"
                                                onsubmit="const reason = prompt('Nhập lý do từ chối cho sản phẩm {{ addslashes($product->name) }}:'); if (reason === null) { return false; } const cleanReason = reason.trim(); if (!cleanReason) { alert('Vui lòng nhập lý do từ chối.'); return false; } this.querySelector('input[name=rejection_reason]').value = cleanReason; return true;">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="rejection_reason" value="">
                                                <input type="hidden" name="return_store_id"
                                                    value="{{ $store->id }}">
                                                <input type="hidden" name="active_tab" value="store-product-approvals">
                                                <button type="submit" class="se-btn se-btn-danger se-btn-sm">✕ Từ
                                                    chối</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10">
                                        <div class="se-empty">
                                            <span class="se-empty-icon">✅</span>
                                            Không có sản phẩm chờ duyệt.
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="se-pagination">{{ $pendingApprovalProducts->links('pagination::bootstrap-5') }}</div>
            </div>
