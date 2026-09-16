{{--
    Partial form dùng chung cho create & edit.
    Biến cần truyền vào:
      $formAction   – route action
      $formMethod   – 'POST' | 'PUT'
      $banner       – (optional) model instance khi edit
      $bannerTypeOptions, $roomSelectionModeOptions, $roomOptions
--}}
@php
    $isEdit = isset($banner);
    $selType = old('banner_type', $isEdit ? ($banner->banner_type ?: 'promo') : 'promo');
    $selRoomMode = old('room_selection_mode', $isEdit ? ($banner->room_selection_mode ?: 'selected_room') : 'selected_room');
    $typeIcons = ['room_link'=>'fas fa-dice','image_only'=>'fas fa-images','promo'=>'fas fa-bullhorn'];
    $typeColors = ['room_link'=>['bg'=>'#ede9fe','color'=>'#7c3aed'],'image_only'=>['bg'=>'#eff6ff','color'=>'#3b82f6'],'promo'=>['bg'=>'#fef9c3','color'=>'#ca8a04']];
    $typeDescs = [
        'room_link'  => 'Hiển thị cố định 1 phòng quay, không chạy slider',
        'image_only' => 'Chỉ ảnh, chạy slider tự động',
        'promo'      => 'Ảnh + nội dung text, chạy slider',
    ];
@endphp

<form id="bannerForm" action="{{ $formAction }}" method="POST" enctype="multipart/form-data">
    @csrf
    @if($formMethod === 'PUT') @method('PUT') @endif

    <div class="bn-grid">

        {{-- ── Cột trái: nội dung chính ── --}}
        <div>

            {{-- Card: Loại banner --}}
            <div class="bn-card">
                <div class="bn-ch">
                    <div class="chi" style="background:#ede9fe;"><i class="fas fa-layer-group" style="color:#7c3aed;font-size:12px;"></i></div>
                    <div><h2>Loại banner</h2><p>Chọn kiểu hiển thị phù hợp</p></div>
                </div>
                <div class="bn-cb">
                    <div class="bn-type-cards">
                        @foreach($bannerTypeOptions as $val => $lbl)
                        @php $ic = $typeIcons[$val] ?? 'fas fa-image'; $cl = $typeColors[$val] ?? ['bg'=>'#f1f5f9','color'=>'#64748b']; @endphp
                        <input type="radio" name="banner_type" id="bt_{{ $val }}" value="{{ $val }}"
                            class="bn-tc-in" {{ $selType === $val ? 'checked' : '' }}>
                        <label for="bt_{{ $val }}" class="bn-tc-lb">
                            <div class="bn-tc-icon" style="background:{{ $cl['bg'] }};">
                                <i class="{{ $ic }}" style="color:{{ $cl['color'] }};"></i>
                            </div>
                            <div>
                                <div class="bn-tc-title">{{ $lbl }}</div>
                                <div class="bn-tc-desc">{{ $typeDescs[$val] ?? '' }}</div>
                            </div>
                        </label>
                        @endforeach
                    </div>
                    @error('banner_type')
                        <div class="err" style="margin-top:8px;font-size:12px;color:#dc2626;"><i class="fas fa-exclamation-circle"></i> {{ $message }}</div>
                    @enderror
                </div>
            </div>

            {{-- Card: Ảnh banner --}}
            <div class="bn-card">
                <div class="bn-ch">
                    <div class="chi" style="background:#fdf4ff;"><i class="fas fa-image" style="color:#a855f7;font-size:12px;"></i></div>
                    <div><h2>Ảnh banner</h2><p>{{ $isEdit ? 'Để trống nếu không muốn đổi ảnh' : 'Bắt buộc — JPEG, PNG, WebP ≤ 4MB' }}</p></div>
                </div>
                <div class="bn-cb">
                    <label class="bn-upload {{ ($isEdit && $banner->image) ? 'has-img' : '' }}" id="uploadZone">
                        <input type="file" name="image" accept="image/*" onchange="bnPreviewImg(this)">
                        @if($isEdit && $banner->image)
                            <div style="position:relative;width:100%;">
                                <img id="bnImgPreview" src="{{ asset('storage/'.$banner->image) }}" class="bn-img-preview" alt="Banner">
                                <button type="button" class="bn-img-change" onclick="event.preventDefault()">
                                    <i class="fas fa-camera"></i> Đổi ảnh
                                </button>
                            </div>
                        @else
                            <div id="bnUploadPlaceholder">
                                <div class="bn-upload-icon"><i class="fas fa-cloud-upload-alt" style="color:#fff;font-size:20px;"></i></div>
                                <div class="bn-upload-title">Kéo thả hoặc click để chọn ảnh</div>
                                <div class="bn-upload-sub">JPEG, PNG, GIF, WebP, SVG — tối đa 4MB</div>
                            </div>
                            <div id="bnImgWrap" style="display:none;position:relative;width:100%;">
                                <img id="bnImgPreview" src="" class="bn-img-preview" alt="">
                                <button type="button" class="bn-img-change" onclick="event.preventDefault()">
                                    <i class="fas fa-camera"></i> Đổi ảnh
                                </button>
                            </div>
                        @endif
                    </label>
                    @error('image')
                        <div style="margin-top:6px;font-size:12px;color:#dc2626;display:flex;align-items:center;gap:4px;">
                            <i class="fas fa-exclamation-circle"></i> {{ $message }}
                        </div>
                    @enderror
                </div>
            </div>

            {{-- Card: Nội dung theo loại (promo) --}}
            <div class="bn-card banner-section" data-section="promo" style="{{ $selType === 'promo' ? '' : 'display:none;' }}">
                <div class="bn-ch">
                    <div class="chi" style="background:#fef9c3;"><i class="fas fa-bullhorn" style="color:#ca8a04;font-size:12px;"></i></div>
                    <div><h2>Nội dung Slider</h2><p>Ít nhất 1 trường bắt buộc</p></div>
                </div>
                <div class="bn-cb">
                    <div class="bn-row2">
                        <div class="bn-f">
                            <label>Nhãn phụ (Eyebrow)</label>
                            <input type="text" name="title" class="bn-in {{ $errors->has('title') ? 'err' : '' }}"
                                value="{{ old('title', $isEdit ? $banner->title : '') }}"
                                placeholder="VD: Cơ hội trúng lớn">
                            @error('title')<div class="err"><i class="fas fa-exclamation-circle"></i> {{ $message }}</div>@enderror
                        </div>
                        <div class="bn-f">
                            <label>Headline</label>
                            <input type="text" name="headline" class="bn-in"
                                value="{{ old('headline', $isEdit ? $banner->headline : '') }}"
                                placeholder="VD: Mỗi món = 1 Ticket quay thưởng">
                        </div>
                    </div>
                    <div class="bn-f">
                        <label>Mô tả</label>
                        <textarea name="description" class="bn-ta"
                            placeholder="Nội dung mô tả ngắn...">{{ old('description', $isEdit ? $banner->description : '') }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Card: Phòng quay (room_link) --}}
            <div class="bn-card banner-section" data-section="room_link" style="{{ $selType === 'room_link' ? '' : 'display:none;' }}">
                <div class="bn-ch">
                    <div class="chi" style="background:#ede9fe;"><i class="fas fa-dice" style="color:#7c3aed;font-size:12px;"></i></div>
                    <div><h2>Cấu hình phòng quay</h2><p>Chọn phòng hiển thị trên banner</p></div>
                </div>
                <div class="bn-cb">
                    <div class="bn-f">
                        <label>Cách chọn phòng</label>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            @foreach($roomSelectionModeOptions as $val => $lbl)
                            <label class="bn-room-mode-label {{ $selRoomMode === $val ? 'active-mode' : '' }}"
                                id="rml_{{ $val }}">
                                <input type="radio" name="room_selection_mode" value="{{ $val }}"
                                    {{ $selRoomMode === $val ? 'checked' : '' }}>
                                <span>{{ $lbl }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="bn-f" id="roomPickerWrap" style="{{ $selRoomMode === 'selected_room' ? '' : 'display:none;' }}">
                        <label>Phòng quay cụ thể</label>
                        <select name="room_id" id="room_id" class="bn-sel {{ $errors->has('room_id') ? 'err' : '' }}">
                            <option value="">-- Chọn phòng --</option>
                            @foreach($roomOptions as $room)
                            <option value="{{ $room->id }}"
                                {{ old('room_id', $isEdit ? (string)$banner->room_id : '') === (string)$room->id ? 'selected' : '' }}>
                                {{ $room->room_date ? \Carbon\Carbon::parse($room->room_date)->format('d/m/Y') : '' }}
                                — Phòng {{ (int)$room->room_number }}
                                — {{ strtoupper((string)$room->status) }}
                                — {{ (int)$room->current_votes }}/{{ max(1,(int)$room->required_votes) }} Ticket
                            </option>
                            @endforeach
                        </select>
                        @error('room_id')<div class="err"><i class="fas fa-exclamation-circle"></i> {{ $message }}</div>@enderror
                    </div>
                    <div class="bn-f">
                        <label>Nhãn nút CTA</label>
                        <input type="text" name="cta_label" class="bn-in"
                            value="{{ old('cta_label', $isEdit ? $banner->cta_label : 'Xem phòng quay') }}"
                            placeholder="VD: Xem phòng quay">
                    </div>
                </div>
            </div>

            {{-- Card: image_only info --}}
            <div class="bn-card banner-section" data-section="image_only" style="{{ $selType === 'image_only' ? '' : 'display:none;' }}">
                <div class="bn-ch">
                    <div class="chi" style="background:#eff6ff;"><i class="fas fa-images" style="color:#3b82f6;font-size:12px;"></i></div>
                    <div><h2>Slider ảnh</h2><p>Chỉ hiển thị ảnh, chạy tự động</p></div>
                </div>
                <div class="bn-cb">
                    <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:#eff6ff;border-radius:10px;border:1px solid #bfdbfe;font-size:13px;color:#1d4ed8;">
                        <i class="fas fa-info-circle" style="margin-top:1px;flex-shrink:0;"></i>
                        <span>Loại này chỉ cần ảnh. Bạn có thể thêm link điều hướng ở phần bên dưới.</span>
                    </div>
                    <div class="bn-f" style="margin-top:14px;">
                        <label>Tên nội bộ (tuỳ chọn)</label>
                        <input type="text" name="title" class="bn-in"
                            value="{{ old('title', $isEdit ? $banner->title : '') }}"
                            placeholder="VD: Banner khuyến mãi tháng 5">
                    </div>
                </div>
            </div>

            {{-- Card: Link & CTA chung (không phải room_link) --}}
            <div class="bn-card banner-section-notroom" style="{{ $selType === 'room_link' ? 'display:none;' : '' }}">
                <div class="bn-ch">
                    <div class="chi" style="background:#f0fdf4;"><i class="fas fa-link" style="color:#16a34a;font-size:12px;"></i></div>
                    <div><h2>Nút & Liên kết</h2><p>Tuỳ chọn — để trống nếu không cần</p></div>
                </div>
                <div class="bn-cb">
                    <div class="bn-row2">
                        <div class="bn-f" style="margin-bottom:0;">
                            <label>Nhãn nút CTA</label>
                            <input type="text" name="cta_label" class="bn-in"
                                value="{{ old('cta_label', $isEdit ? $banner->cta_label : '') }}"
                                placeholder="VD: Xem giải thưởng">
                        </div>
                        <div class="bn-f" style="margin-bottom:0;">
                            <label>Đường dẫn</label>
                            <input type="text" name="link" class="bn-in"
                                value="{{ old('link', $isEdit ? $banner->link : '') }}"
                                placeholder="/draw-room hoặc https://...">
                        </div>
                    </div>
                </div>
            </div>

        </div>{{-- /cột trái --}}

        {{-- ── Cột phải: cài đặt ── --}}
        <div>

            {{-- Card: Cài đặt chung --}}
            <div class="bn-card">
                <div class="bn-ch">
                    <div class="chi" style="background:#f0fdf4;"><i class="fas fa-cog" style="color:#16a34a;font-size:12px;"></i></div>
                    <div><h2>Cài đặt chung</h2></div>
                </div>
                <div class="bn-cb">
                    <div class="bn-f">
                        <label>Trạng thái</label>
                        <div class="bn-status-grp">
                            @php $curStatus = old('status', $isEdit ? ($banner->status ? '1' : '0') : '1'); @endphp
                            <input type="radio" name="status" id="st1" value="1" class="bn-st-in" {{ $curStatus === '1' ? 'checked' : '' }}>
                            <label for="st1" class="bn-st-lb"><i class="fas fa-circle" style="font-size:8px;"></i> Bật</label>
                            <input type="radio" name="status" id="st0" value="0" class="bn-st-in" {{ $curStatus === '0' ? 'checked' : '' }}>
                            <label for="st0" class="bn-st-lb"><i class="fas fa-circle" style="font-size:8px;"></i> Tắt</label>
                        </div>
                    </div>
                    <div class="bn-f" style="margin-bottom:0;">
                        <label>Thứ tự hiển thị</label>
                        <input type="number" name="order" class="bn-in"
                            min="1" max="9999"
                            value="{{ old('order', $isEdit ? ($banner->order ?: 1) : 1) }}">
                        <div class="hint"><i class="fas fa-info-circle" style="color:var(--accent);"></i> Số nhỏ hơn hiển thị trước.</div>
                    </div>
                </div>
            </div>

            {{-- Nút lưu --}}
            <div style="margin-top:16px;">
                <button type="submit" class="bn-btn-save full">
                    <i class="fas fa-save"></i> {{ $isEdit ? 'Cập nhật banner' : 'Thêm banner' }}
                </button>
            </div>
            <div style="margin-top:10px;">
                <a href="{{ route('admin.banners.index') }}" class="bn-btn-back" style="width:100%;justify-content:center;">
                    <i class="fas fa-arrow-left"></i> Quay lại danh sách
                </a>
            </div>

            {{-- Note --}}
            <div style="margin-top:12px;padding:12px 14px;background:#f8fafc;border-radius:10px;border:1px solid var(--border);">
                <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:flex-start;gap:7px;">
                    <i class="fas fa-shield-alt" style="color:var(--accent);margin-top:1px;flex-shrink:0;"></i>
                    <span>Chỉ 1 loại banner được hiển thị tại 1 thời điểm. Dùng trang danh sách để chuyển đổi loại.</span>
                </div>
            </div>

        </div>{{-- /cột phải --}}

    </div>{{-- /bn-grid --}}
</form>

<script>
(function(){
    const typeInputs   = document.querySelectorAll('input[name="banner_type"]');
    const sections     = document.querySelectorAll('.banner-section');
    const notRoomSects = document.querySelectorAll('.banner-section-notroom');
    const roomModeInputs = document.querySelectorAll('input[name="room_selection_mode"]');
    const roomPickerWrap = document.getElementById('roomPickerWrap');
    const roomModeLabels = document.querySelectorAll('.bn-room-mode-label');

    function applyType(type) {
        sections.forEach(s => s.style.display = s.dataset.section === type ? '' : 'none');
        notRoomSects.forEach(s => s.style.display = type === 'room_link' ? 'none' : '');
    }

    function applyRoomMode(mode) {
        if (roomPickerWrap) roomPickerWrap.style.display = mode === 'selected_room' ? '' : 'none';
        roomModeLabels.forEach(lb => {
            const inp = lb.querySelector('input');
            lb.classList.toggle('active-mode', inp && inp.value === mode);
        });
    }

    typeInputs.forEach(inp => inp.addEventListener('change', () => applyType(inp.value)));
    roomModeInputs.forEach(inp => inp.addEventListener('change', () => applyRoomMode(inp.value)));

    // Image preview
    window.bnPreviewImg = function(input) {
        if (!input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            const zone = document.getElementById('uploadZone');
            const placeholder = document.getElementById('bnUploadPlaceholder');
            const wrap = document.getElementById('bnImgWrap');
            const preview = document.getElementById('bnImgPreview');
            if (preview) preview.src = e.target.result;
            if (placeholder) placeholder.style.display = 'none';
            if (wrap) wrap.style.display = 'block';
            if (zone) { zone.classList.add('has-img'); }
        };
        reader.readAsDataURL(input.files[0]);
    };

    // Init
    const checkedType = document.querySelector('input[name="banner_type"]:checked');
    if (checkedType) applyType(checkedType.value);
    const checkedMode = document.querySelector('input[name="room_selection_mode"]:checked');
    if (checkedMode) applyRoomMode(checkedMode.value);
})();
</script>
