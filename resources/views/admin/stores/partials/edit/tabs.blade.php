            {{-- ── Tabs ── --}}
            <div class="se-tabs" role="tablist">
                <button class="se-tab-btn {{ $activeTab === 'store-info' ? 'active' : '' }}" data-tab="pane-store-info"
                    role="tab" aria-selected="{{ $activeTab === 'store-info' ? 'true' : 'false' }}">
                    Thông tin cửa hàng
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-admins' ? 'active' : '' }}" data-tab="pane-store-admins"
                    role="tab" aria-selected="{{ $activeTab === 'store-admins' ? 'true' : 'false' }}">
                    Admin cửa hàng
                    @if (($storeAdmins ?? collect())->count())
                        <span class="se-tab-badge">
                            {{ ($storeAdmins ?? collect())->count() }}
                        </span>
                    @endif
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-products' ? 'active' : '' }}"
                    data-tab="pane-store-products" role="tab"
                    aria-selected="{{ $activeTab === 'store-products' ? 'true' : 'false' }}">
                    Quản lý sản phẩm
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-inventory' ? 'active' : '' }}"
                    data-tab="pane-store-inventory" role="tab"
                    aria-selected="{{ $activeTab === 'store-inventory' ? 'true' : 'false' }}">
                    Quản lý tồn kho
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-product-approvals' ? 'active' : '' }}"
                    data-tab="pane-store-product-approvals" role="tab"
                    aria-selected="{{ $activeTab === 'store-product-approvals' ? 'true' : 'false' }}">
                    Duyệt sản phẩm
                    @if (($storeModuleStats['pending_approval'] ?? 0) > 0)
                        <span class="se-tab-badge">{{ (int) ($storeModuleStats['pending_approval'] ?? 0) }}</span>
                    @endif
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-customers' ? 'active' : '' }}"
                    data-tab="pane-store-customers" role="tab"
                    aria-selected="{{ $activeTab === 'store-customers' ? 'true' : 'false' }}">
                    Quản lý khách hàng
                    @if (($storeModuleStats['customers'] ?? 0) > 0)
                        <span class="se-tab-badge">{{ (int) ($storeModuleStats['customers'] ?? 0) }}</span>
                    @endif
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-orders' ? 'active' : '' }}"
                    data-tab="pane-store-orders" role="tab"
                    aria-selected="{{ $activeTab === 'store-orders' ? 'true' : 'false' }}">
                    Quản lý đơn hàng
                    @if (($storeModuleStats['orders'] ?? 0) > 0)
                        <span class="se-tab-badge">{{ (int) ($storeModuleStats['orders'] ?? 0) }}</span>
                    @endif
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-reviews' ? 'active' : '' }}"
                    data-tab="pane-store-reviews" role="tab"
                    aria-selected="{{ $activeTab === 'store-reviews' ? 'true' : 'false' }}">
                    Khách hàng đánh giá
                    @if (($storeModuleStats['reviews'] ?? 0) > 0)
                        <span class="se-tab-badge">{{ (int) ($storeModuleStats['reviews'] ?? 0) }}</span>
                    @endif
                </button>
                <button class="se-tab-btn {{ $activeTab === 'store-limit-topup' ? 'active' : '' }}"
                    data-tab="pane-store-limit-topup" role="tab"
                    aria-selected="{{ $activeTab === 'store-limit-topup' ? 'true' : 'false' }}">
                    Nạp hạn mức
                </button>
            </div>
