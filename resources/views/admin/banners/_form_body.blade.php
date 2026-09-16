{{-- Cột trái --}}
<div>
    {{-- Loại banner --}}
    <div class="bf-card">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#ede9fe;"><i class="fas fa-layer-group" style="color:#7c3aed;font-size:11px;"></i></div>
            <div><h2>Loại banner</h2><p>Chọn kiểu hiển thị</p></div>
        </div>
        <div class="bf-cb">
            <div class="bf-type-list">
                @foreach($bannerTypeOptions as $val => $lbl)
                @php
                    if ($val === 'room_link' || $val === 'promo') continue;
                    $ic=$typeIcons[$val]??'fas fa-image'; $cl=$typeColors[$val]??['bg'=>'#f1f5f9','c'=>'#64748b'];
                @endphp
                <input type="radio" name="banner_type" id="bt_{{ $val }}" value="{{ $val }}" class="bf-tc-in" {{ $selType===$val?'checked':'' }}>
                <label for="bt_{{ $val }}" class="bf-tc-lb">
                    <div class="bf-tc-icon" style="background:{{ $cl['bg'] }};"><i class="{{ $ic }}" style="color:{{ $cl['c'] }};"></i></div>
                    <div>
                        <div class="bf-tc-title">{{ $lbl }}</div>
                        <div class="bf-tc-desc">{{ $typeDescs[$val]??'' }}</div>
                    </div>
                </label>
                @endforeach
            </div>
            @error('banner_type')<div class="ferr" style="margin-top:6px;font-size:12px;color:#dc2626;">{{ $message }}</div>@enderror
        </div>
    </div>

    {{-- Ảnh --}}
    <div class="bf-card">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#fdf4ff;"><i class="fas fa-image" style="color:#a855f7;font-size:11px;"></i></div>
            <div><h2>Ảnh banner</h2><p>{{ $isEdit ? 'Để trống nếu không đổi ảnh' : 'Bắt buộc — JPEG, PNG, WebP ≤ 4MB' }}</p></div>
        </div>
        <div class="bf-cb">
            <label class="bf-upload {{ ($isEdit && isset($banner) && $banner->image) ? 'has-img' : '' }}" id="bfUploadZone">
                <input type="file" name="image" accept="image/*" onchange="bfPreview(this)">
                @if($isEdit && isset($banner) && $banner->image)
                    <div style="position:relative;width:100%;">
                        <img id="bfImgPreview" src="{{ asset('storage/'.$banner->image) }}" class="bf-img-preview" alt="">
                        <button type="button" class="bf-img-change" onclick="event.preventDefault()"><i class="fas fa-camera"></i> Đổi ảnh</button>
                    </div>
                @else
                    <div id="bfPlaceholder">
                        <div class="bf-upload-icon"><i class="fas fa-cloud-upload-alt" style="color:#fff;font-size:18px;"></i></div>
                        <div class="bf-upload-title">Click hoặc kéo thả ảnh vào đây</div>
                        <div class="bf-upload-sub">JPEG, PNG, GIF, WebP — tối đa 4MB</div>
                    </div>
                    <div id="bfImgWrap" style="display:none;position:relative;width:100%;">
                        <img id="bfImgPreview" src="" class="bf-img-preview" alt="">
                        <button type="button" class="bf-img-change" onclick="event.preventDefault()"><i class="fas fa-camera"></i> Đổi ảnh</button>
                    </div>
                @endif
            </label>
            @error('image')<div style="margin-top:5px;font-size:12px;color:#dc2626;">{{ $message }}</div>@enderror
        </div>
    </div>

    {{-- Nội dung promo --}}
    <div class="bf-card bf-section" data-sec="promo" style="{{ $selType==='promo'?'':'display:none;' }}">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#fef9c3;"><i class="fas fa-bullhorn" style="color:#ca8a04;font-size:11px;"></i></div>
            <div><h2>Nội dung Slider</h2><p>Ít nhất 1 trường bắt buộc</p></div>
        </div>
        <div class="bf-cb">
            <div class="bf-row2">
                <div class="bf-field">
                    <label>Nhãn phụ</label>
                    <input type="text" name="title" class="bf-in" value="{{ old('title', $isEdit&&isset($banner)?$banner->title:'') }}" placeholder="VD: Cơ hội trúng lớn">
                    @error('title')<div class="ferr">{{ $message }}</div>@enderror
                </div>
                <div class="bf-field">
                    <label>Headline</label>
                    <input type="text" name="headline" class="bf-in" value="{{ old('headline', $isEdit&&isset($banner)?$banner->headline:'') }}" placeholder="VD: Mỗi món = 1 Ticket">
                </div>
            </div>
            <div class="bf-field">
                <label>Mô tả</label>
                <textarea name="description" class="bf-ta" placeholder="Nội dung mô tả ngắn...">{{ old('description', $isEdit&&isset($banner)?$banner->description:'') }}</textarea>
            </div>
        </div>
    </div>

    {{-- Phòng quay --}}
    <div class="bf-card bf-section" data-sec="room_link" style="{{ $selType==='room_link'?'':'display:none;' }}">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#ede9fe;"><i class="fas fa-dice" style="color:#7c3aed;font-size:11px;"></i></div>
            <div><h2>Cấu hình phòng quay</h2></div>
        </div>
        <div class="bf-cb">
            <div class="bf-field">
                <label>Cách chọn phòng</label>
                @foreach($roomSelectionModeOptions as $val => $lbl)
                <label class="bf-rm-lb {{ $selRoomMode===$val?'active':'' }}" id="rml_{{ $val }}">
                    <input type="radio" name="room_selection_mode" value="{{ $val }}" {{ $selRoomMode===$val?'checked':'' }}>
                    <span>{{ $lbl }}</span>
                </label>
                @endforeach
            </div>
            <div class="bf-field" id="bfRoomPicker" style="{{ $selRoomMode==='selected_room'?'':'display:none;' }}">
                <label>Phòng quay cụ thể</label>
                <select name="room_id" class="bf-sel">
                    <option value="">-- Chọn phòng --</option>
                    @foreach($roomOptions as $room)
                    <option value="{{ $room->id }}" {{ old('room_id', $isEdit&&isset($banner)?(string)$banner->room_id:'') === (string)$room->id ? 'selected' : '' }}>
                        {{ $room->room_date ? \Carbon\Carbon::parse($room->room_date)->format('d/m/Y') : '' }}
                        — Phòng {{ (int)$room->room_number }} — {{ strtoupper((string)$room->status) }}
                        — {{ (int)$room->current_votes }}/{{ max(1,(int)$room->required_votes) }} Ticket
                    </option>
                    @endforeach
                </select>
                @error('room_id')<div style="font-size:12px;color:#dc2626;margin-top:4px;">{{ $message }}</div>@enderror
            </div>
            <div class="bf-field">
                <label>Nhãn nút CTA</label>
                <input type="text" name="cta_label" class="bf-in" value="{{ old('cta_label', $isEdit&&isset($banner)?$banner->cta_label:'Xem phòng quay') }}" placeholder="Xem phòng quay">
            </div>
        </div>
    </div>

    {{-- Slider ảnh --}}
    <div class="bf-card bf-section" data-sec="image_only" style="{{ $selType==='image_only'?'':'display:none;' }}">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#eff6ff;"><i class="fas fa-images" style="color:#3b82f6;font-size:11px;"></i></div>
            <div><h2>Slider ảnh</h2><p>Chỉ cần ảnh, chạy tự động</p></div>
        </div>
        <div class="bf-cb">
            <div class="bf-field">
                <label>Tên nội bộ (tuỳ chọn)</label>
                <input type="text" name="title" class="bf-in" value="{{ old('title', $isEdit&&isset($banner)?$banner->title:'') }}" placeholder="VD: Banner tháng 5">
            </div>
        </div>
    </div>

    {{-- Link & CTA (không phải room_link) --}}
    <div class="bf-card bf-notroom" style="{{ $selType==='room_link'?'display:none;':'' }}">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#f0fdf4;"><i class="fas fa-link" style="color:#16a34a;font-size:11px;"></i></div>
            <div><h2>Nút & Liên kết</h2><p>Tuỳ chọn</p></div>
        </div>
        <div class="bf-cb">
            <div class="bf-row2">
                <div class="bf-field" style="margin-bottom:0;">
                    <label>Nhãn nút CTA</label>
                    <input type="text" name="cta_label" class="bf-in" value="{{ old('cta_label', $isEdit&&isset($banner)?$banner->cta_label:'') }}" placeholder="Xem giải thưởng">
                </div>
                <div class="bf-field" style="margin-bottom:0;">
                    <label>Đường dẫn</label>
                    <input type="text" name="link" class="bf-in" value="{{ old('link', $isEdit&&isset($banner)?$banner->link:'') }}" placeholder="/draw-room hoặc https://...">
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Cột phải --}}
<div>
    <div class="bf-card">
        <div class="bf-ch">
            <div class="bf-chi" style="background:#f0fdf4;"><i class="fas fa-cog" style="color:#16a34a;font-size:11px;"></i></div>
            <div><h2>Cài đặt</h2></div>
        </div>
        <div class="bf-cb">
            <div class="bf-field">
                <label>Trạng thái</label>
                @php $curSt = old('status', $isEdit&&isset($banner)?($banner->status?'1':'0'):'1'); @endphp
                <div class="bf-st-grp">
                    <input type="radio" name="status" id="st1" value="1" class="bf-st-in" {{ $curSt==='1'?'checked':'' }}>
                    <label for="st1" class="bf-st-lb"><i class="fas fa-circle" style="font-size:7px;"></i> Bật</label>
                    <input type="radio" name="status" id="st0" value="0" class="bf-st-in" {{ $curSt==='0'?'checked':'' }}>
                    <label for="st0" class="bf-st-lb"><i class="fas fa-circle" style="font-size:7px;"></i> Tắt</label>
                </div>
            </div>
            <div class="bf-field" style="margin-bottom:0;">
                <label>Thứ tự hiển thị</label>
                <input type="number" name="order" class="bf-in" min="1" max="9999" value="{{ old('order', $isEdit&&isset($banner)?($banner->order?:1):1) }}">
                <div class="hint" style="font-size:12px;color:var(--tm);margin-top:4px;">Số nhỏ hơn hiển thị trước.</div>
            </div>
        </div>
    </div>

    <div style="margin-top:14px;">
        <button type="submit" class="bf-btn-save">
            <i class="fas fa-save"></i> {{ $isEdit ? 'Cập nhật banner' : 'Thêm banner' }}
        </button>
        <a href="{{ route('admin.banners.index') }}" class="bf-btn-back">
            <i class="fas fa-arrow-left"></i> Quay lại danh sách
        </a>
    </div>

    <div style="margin-top:12px;padding:11px 13px;background:#f8fafc;border-radius:10px;border:1px solid var(--bd);font-size:12px;color:var(--tm);display:flex;align-items:flex-start;gap:7px;">
        <i class="fas fa-info-circle" style="color:var(--ac);margin-top:1px;flex-shrink:0;"></i>
        <span>Chỉ 1 loại banner hiển thị tại 1 thời điểm. Dùng trang danh sách để chuyển đổi.</span>
    </div>
</div>

<script>
(function(){
    var typeInputs   = document.querySelectorAll('input[name="banner_type"]');
    var sections     = document.querySelectorAll('.bf-section');
    var notRooms     = document.querySelectorAll('.bf-notroom');
    var modeInputs   = document.querySelectorAll('input[name="room_selection_mode"]');
    var roomPicker   = document.getElementById('bfRoomPicker');
    var modeLabels   = document.querySelectorAll('.bf-rm-lb');

    function applyType(type) {
        sections.forEach(function(s){ s.style.display = s.dataset.sec === type ? '' : 'none'; });
        notRooms.forEach(function(s){ s.style.display = type === 'room_link' ? 'none' : ''; });
    }

    function applyMode(mode) {
        if (roomPicker) roomPicker.style.display = mode === 'selected_room' ? '' : 'none';
        modeLabels.forEach(function(lb){
            var inp = lb.querySelector('input');
            lb.classList.toggle('active', inp && inp.value === mode);
        });
    }

    typeInputs.forEach(function(inp){ inp.addEventListener('change', function(){ applyType(inp.value); }); });
    modeInputs.forEach(function(inp){ inp.addEventListener('change', function(){ applyMode(inp.value); }); });

    window.bfPreview = function(input) {
        if (!input.files || !input.files[0]) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            var zone = document.getElementById('bfUploadZone');
            var ph   = document.getElementById('bfPlaceholder');
            var wrap = document.getElementById('bfImgWrap');
            var prev = document.getElementById('bfImgPreview');
            if (prev) prev.src = e.target.result;
            if (ph)   ph.style.display = 'none';
            if (wrap) wrap.style.display = 'block';
            if (zone) zone.classList.add('has-img');
        };
        reader.readAsDataURL(input.files[0]);
    };

    var ct = document.querySelector('input[name="banner_type"]:checked');
    if (ct) applyType(ct.value);
    var cm = document.querySelector('input[name="room_selection_mode"]:checked');
    if (cm) applyMode(cm.value);
})();
</script>
