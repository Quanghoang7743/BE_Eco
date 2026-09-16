            {{-- ────────────────────────────────────────────────────────
                 TAB 1 — Thông tin cửa hàng
            ──────────────────────────────────────────────────────────── --}}
            <div class="se-tab-pane {{ $activeTab === 'store-info' ? 'active' : '' }}" id="pane-store-info"
                role="tabpanel">

                <p class="se-section">Thông tin cơ bản</p>
                <div class="se-grid se-grid-2">
                    <div class="se-field" style="grid-column: 1;">
                        <label class="se-label">Mã cửa hàng <span class="req">*</span></label>
                        <input type="text" name="store_code" class="se-input"
                            value="{{ old('store_code', $store->store_code) }}" required form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Tên cửa hàng <span class="req">*</span></label>
                        <input type="text" name="name" class="se-input" value="{{ old('name', $store->name) }}"
                            required form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                </div>

                <div class="se-grid" style="margin-top:18px;">
                    <div class="se-field">
                        <label class="se-label">Địa chỉ <span class="req">*</span></label>
                        <input type="text" name="address" id="address" class="se-input"
                            value="{{ old('address', $store->address) }}" required form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                        <span class="se-hint">Nhập địa chỉ rồi bấm "Tìm trên bản đồ" hoặc click trực tiếp trên bản
                            đồ.</span>
                    </div>
                </div>

                <div style="margin-top:14px;">
                    <div class="se-map-actions">
                        <button type="button" id="btnGeocodeAddress" class="se-btn-map" {{ !$canManageStore ? 'disabled' : '' }}>🔍 Tìm trên bản đồ</button>
                        <button type="button" id="btnUseCurrentLocation" class="se-btn-map" {{ !$canManageStore ? 'disabled' : '' }}>📍 Vị trí hiện tại</button>
                    </div>
                    <div class="se-map-wrap" id="storeMap"></div>
                </div>

                <div class="se-grid se-grid-2" style="margin-top:18px;">
                    <div class="se-field">
                        <label class="se-label">Vĩ độ</label>
                        <input type="text" name="latitude" id="latitude" class="se-input"
                            value="{{ old('latitude', $latitude) }}" placeholder="Ví dụ: 10.762622" form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Kinh độ</label>
                        <input type="text" name="longitude" id="longitude" class="se-input"
                            value="{{ old('longitude', $longitude) }}" placeholder="Ví dụ: 106.660172"
                            form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                </div>

                <p class="se-section">Liên hệ & ngân hàng</p>
                <div class="se-grid se-grid-2">
                    <div class="se-field">
                        <label class="se-label">Số điện thoại</label>
                        <input type="text" name="phone" class="se-input" value="{{ old('phone', $store->phone) }}"
                            form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Email</label>
                        <input type="email" name="email" class="se-input" value="{{ old('email', $store->email) }}"
                            form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Người đại diện</label>
                        <input type="text" name="representative" class="se-input"
                            value="{{ old('representative', $store->representative) }}" form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Loại hình kinh doanh</label>
                        @php
                            $selectedBusinessType = old('business_type', $store->business_type);
                        @endphp
                        <select name="business_type" class="se-select" form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                            <option value="">Chọn loại hình kinh doanh</option>
                            @foreach ($businessTypes as $businessType)
                                <option value="{{ $businessType->name }}" {{ $selectedBusinessType === $businessType->name ? 'selected' : '' }}>
                                    {{ $businessType->name }}
                                </option>
                            @endforeach
                            @if ($selectedBusinessType && ! $businessTypes->contains('name', $selectedBusinessType))
                                <option value="{{ $selectedBusinessType }}" selected>{{ $selectedBusinessType }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Tên ngân hàng</label>
                        <input type="text" name="bank_name" class="se-input"
                            value="{{ old('bank_name', $store->bank_name) }}" form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Số tài khoản</label>
                        <input type="text" name="bank_account" class="se-input"
                            value="{{ old('bank_account', $store->bank_account) }}" form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                </div>

                <p class="se-section">Vận hành & trạng thái</p>
                <div class="se-grid se-grid-3">
                    <div class="se-field">
                        <label class="se-label">Giờ mở cửa</label>
                        <input type="time" name="open_time" class="se-input"
                            value="{{ old('open_time', optional($store->open_time)->format('H:i') ?? substr((string) $store->open_time, 0, 5)) }}"
                            form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Giờ đóng cửa</label>
                        <input type="time" name="close_time" class="se-input"
                            value="{{ old('close_time', optional($store->close_time)->format('H:i') ?? substr((string) $store->close_time, 0, 5)) }}"
                            form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                    </div>
                    <div class="se-field">
                        <label class="se-label">Trạng thái duyệt <span class="req">*</span></label>
                        <select name="status" class="se-select" required form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                            <option value="active" {{ old('status', $store->status) === 'active' ? 'selected' : '' }}>Đã
                                duyệt</option>
                            <option value="pending" {{ old('status', $store->status) === 'pending' ? 'selected' : '' }}>
                                Chờ duyệt</option>
                            <option value="rejected" {{ old('status', $store->status) === 'rejected' ? 'selected' : '' }}>
                                Từ chối</option>
                        </select>
                    </div>
                </div>

                <div class="se-grid se-grid-2" style="margin-top:18px;">
                    <div class="se-field">
                        <label class="se-label">Chuyên viên phụ trách</label>
                        <select name="sale_id" id="sale_id" class="se-select select2-sale" form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>
                            <option value="">-- Chưa gán --</option>
                            @foreach ($saleUsers as $sale)
                                <option value="{{ $sale->id }}" data-phone="{{ $sale->phone ?? '' }}"
                                    data-email="{{ $sale->email ?? '' }}"
                                    {{ (string) old('sale_id', $store->sale_id) === (string) $sale->id ? 'selected' : '' }}>
                                    {{ $sale->full_name ?: $sale->name }}
                                </option>
                            @endforeach
                        </select>
                        <span class="se-hint">Gõ tên, SĐT hoặc email để tìm nhanh.</span>
                        <div class="se-sale-card mt-2" id="saleInfoBox">
                            <div class="se-sale-avatar" id="saleAvatarEl">—</div>
                            <div>
                                <p class="se-sale-name" id="saleInfoName">Chưa chọn chuyên viên</p>
                                <p class="se-sale-meta" id="saleInfoPhone">SĐT: N/A</p>
                                <p class="se-sale-meta" id="saleInfoEmail">Email: N/A</p>
                            </div>
                        </div>
                    </div>

                    <div class="se-field" style="justify-content: flex-end; padding-bottom: 4px;">
                        <label class="se-label" style="margin-bottom:8px;">Trạng thái hoạt động</label>
                        <div class="se-toggle-wrap">
                            <input type="hidden" name="is_open" value="0" form="storeEditForm">
                            <input class="form-check-input" type="checkbox" value="1" id="is_open"
                                name="is_open" form="storeEditForm"
                                {{ old('is_open', $store->is_open ? '1' : '') ? 'checked' : '' }} {{ !$canManageStore ? 'disabled' : '' }}>
                            <label class="se-toggle-label" for="is_open">Cửa hàng đang mở bán</label>
                        </div>
                    </div>

                    <div class="se-field">
                        <label class="se-label">Số Zalo chuyên viên liên kết</label>
                        <input type="text" name="sale_zalo_phone" class="se-input"
                            value="{{ old('sale_zalo_phone', $store->sale_zalo_phone) }}"
                            placeholder="Ví dụ: 0987654321" form="storeEditForm">
                        <span class="se-hint">Liên kết Zalo trực tiếp với chuyên viên phụ trách khi khách khiếu nại.</span>
                    </div>
                </div>

                <div class="se-field" style="margin-top:18px; padding-bottom: 8px;">
                    <label class="se-label">Mô tả</label>
                    <textarea name="description" rows="4" class="se-textarea" placeholder="Mô tả ngắn về cửa hàng"
                        form="storeEditForm" {{ !$canManageStore ? 'disabled' : '' }}>{{ old('description', $store->description) }}</textarea>
                </div>
            </div>
