@extends('admin.layout')

@section('content')
<style>
/* ── Fix layout: page-inner full-width ───────────────────────── */
.page-inner { padding: 20px 28px !important; max-width: 100% !important; }

/* ── Design tokens (đồng bộ sidebar) ─────────────────────────── */
:root {
    --accent:       #6366f1;
    --accent-light: #ede9fe;
    --bg-card:      #ffffff;
    --border:       #e2e8f0;
    --text-main:    #1e293b;
    --text-sub:     #64748b;
    --text-muted:   #94a3b8;
    --radius-card:  14px;
    --radius-input: 8px;
    --shadow-card:  0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
}

.as-wrap { padding: 4px 0 48px; }

/* ── Page header ──────────────────────────────────────────────── */
.as-page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.as-page-header-left { display: flex; align-items: center; gap: 14px; }
.as-page-header-icon {
    width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
    background: linear-gradient(135deg, #0ea5e9, var(--accent));
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(14,165,233,.3);
}
.as-page-header h1 { font-size: 20px; font-weight: 700; color: var(--text-main); margin: 0; }
.as-page-header p  { font-size: 13px; color: var(--text-sub); margin: 2px 0 0; }

/* ── Alert ────────────────────────────────────────────────────── */
.as-alert {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; border-radius: 10px; margin-bottom: 20px;
    font-size: 13px; font-weight: 500;
}
.as-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.as-alert.error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }

/* ── 2-column grid ────────────────────────────────────────────── */
.as-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 20px;
    align-items: start;
}
@media (max-width: 1280px) { .as-grid { grid-template-columns: 1fr; } }

/* ── Card ─────────────────────────────────────────────────────── */
.as-card {
    background: var(--bg-card); border-radius: var(--radius-card);
    border: 1px solid var(--border); box-shadow: var(--shadow-card);
    overflow: hidden;
}
.as-card + .as-card { margin-top: 20px; }
.as-card-header {
    padding: 16px 20px 13px; border-bottom: 1px solid #f8fafc;
    display: flex; align-items: center; gap: 10px;
}
.as-card-header .ch-icon {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.as-card-header h2 { font-size: 14px; font-weight: 600; color: var(--text-main); margin: 0; }
.as-card-header p  { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }
.as-card-body { padding: 18px 20px; }

/* ── Field ────────────────────────────────────────────────────── */
.as-field { margin-bottom: 16px; }
.as-field:last-child { margin-bottom: 0; }
.as-field > label {
    display: block; font-size: 11px; font-weight: 700; color: #475569;
    margin-bottom: 6px; text-transform: uppercase; letter-spacing: .4px;
}
.as-field .hint { font-size: 12px; color: var(--text-muted); margin-top: 5px; display: flex; align-items: center; gap: 5px; }

/* ── Inputs ───────────────────────────────────────────────────── */
.as-input {
    border: 1px solid var(--border); border-radius: var(--radius-input);
    padding: 9px 12px; font-size: 13px; color: var(--text-main);
    background: #fff; width: 100%; box-sizing: border-box;
    transition: border .18s, box-shadow .18s;
}
.as-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.as-input.has-prefix { padding-left: 34px; }
textarea.as-input { resize: vertical; min-height: 88px; }

.as-input-wrap { position: relative; }
.as-input-wrap .prefix {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: var(--text-muted); font-size: 13px; pointer-events: none;
}

/* ── 2-col inside card ────────────────────────────────────────── */
.as-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 600px) { .as-row-2 { grid-template-columns: 1fr; } }

/* ── Image upload ─────────────────────────────────────────────── */
.img-upload {
    border: 2px dashed var(--border); border-radius: 12px;
    padding: 14px 16px; cursor: pointer; position: relative;
    transition: border .18s, background .18s; display: block;
}
.img-upload:hover { border-color: var(--accent); background: #fafbff; }
.img-upload input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.img-upload-inner { display: flex; align-items: center; gap: 14px; pointer-events: none; }
.img-thumb {
    width: 52px; height: 52px; border-radius: 10px; object-fit: contain;
    background: #f8fafc; border: 1px solid var(--border); padding: 4px; flex-shrink: 0;
}
.img-placeholder {
    width: 52px; height: 52px; border-radius: 10px; background: #f1f5f9;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-muted); font-size: 18px; flex-shrink: 0;
}
.img-upload-info strong { display: block; font-size: 13px; font-weight: 600; color: var(--text-main); }
.img-upload-info span   { font-size: 12px; color: var(--text-muted); }

/* ── Buttons ──────────────────────────────────────────────────── */
.btn-save {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px; border-radius: 10px;
    background: linear-gradient(135deg, #0ea5e9, var(--accent));
    color: #fff; font-size: 14px; font-weight: 600; border: none;
    cursor: pointer; transition: opacity .18s, transform .1s;
    box-shadow: 0 4px 14px rgba(99,102,241,.3); width: 100%;
    justify-content: center;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }

/* ── Quick info card ──────────────────────────────────────────── */
.as-info-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 0; border-bottom: 1px solid #f8fafc;
}
.as-info-item:last-child { border-bottom: none; padding-bottom: 0; }
.as-info-item .ii-icon {
    width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 12px;
}
.as-info-item .ii-label { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.as-info-item .ii-val   { font-size: 13px; color: var(--text-main); font-weight: 500; margin-top: 1px; word-break: break-all; }
</style>

<div class="as-wrap">

    {{-- ── Page header ── --}}
    <div class="as-page-header">
        <div class="as-page-header-left">
            <div class="as-page-header-icon">
                <i class="fas fa-cog" style="color:#fff;font-size:18px;"></i>
            </div>
            <div>
                <h1>Thông tin Ứng dụng</h1>
                <p>Cấu hình tên, liên hệ, logo và thông tin hiển thị</p>
            </div>
        </div>
        <button type="submit" form="appSettingForm" class="btn-save" style="width:auto;padding:10px 24px;">
            <i class="fas fa-save"></i> Lưu thay đổi
        </button>
    </div>

    {{-- Flash --}}
    @if(session('success'))
        <div class="as-alert success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="as-alert error"><i class="fas fa-exclamation-circle"></i> {{ $errors->first() }}</div>
    @endif

    <form id="appSettingForm" action="{{ route('admin.app-setting.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="as-grid">

            {{-- ── Cột trái ── --}}
            <div>

                {{-- Card: Thông tin cơ bản --}}
                <div class="as-card">
                    <div class="as-card-header">
                        <div class="ch-icon" style="background:#eff6ff;">
                            <i class="fas fa-info-circle" style="color:#3b82f6;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Thông tin cơ bản</h2>
                            <p>Tên, địa chỉ và mô tả ứng dụng</p>
                        </div>
                    </div>
                    <div class="as-card-body">
                        <div class="as-field">
                            <label>Tên ứng dụng</label>
                            <input type="text" name="app_name" class="as-input"
                                value="{{ old('app_name', $setting->app_name) }}"
                                placeholder="VD: Ecoluck">
                        </div>
                        <div class="as-field">
                            <label>Địa chỉ</label>
                            <input type="text" name="address" class="as-input"
                                value="{{ old('address', $setting->address) }}"
                                placeholder="Số nhà, đường, quận, thành phố...">
                        </div>
                        <div class="as-field">
                            <label>Mô tả ứng dụng</label>
                            <textarea name="description" class="as-input"
                                placeholder="Giới thiệu ngắn về ứng dụng...">{{ old('description', $setting->description) }}</textarea>
                        </div>
                    </div>
                </div>

                {{-- Card: Liên hệ --}}
                <div class="as-card">
                    <div class="as-card-header">
                        <div class="ch-icon" style="background:#f0fdf4;">
                            <i class="fas fa-phone-alt" style="color:#16a34a;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Thông tin liên hệ</h2>
                            <p>Số điện thoại và email hỗ trợ</p>
                        </div>
                    </div>
                    <div class="as-card-body">
                        <div class="as-row-2">
                            <div class="as-field" style="margin-bottom:0;">
                                <label>Số điện thoại</label>
                                <div class="as-input-wrap">
                                    <span class="prefix"><i class="fas fa-phone"></i></span>
                                    <input type="text" name="phone" class="as-input has-prefix"
                                        value="{{ old('phone', $setting->phone) }}"
                                        placeholder="0901 234 567">
                                </div>
                            </div>
                            <div class="as-field" style="margin-bottom:0;">
                                <label>Email</label>
                                <div class="as-input-wrap">
                                    <span class="prefix"><i class="fas fa-envelope"></i></span>
                                    <input type="email" name="email" class="as-input has-prefix"
                                        value="{{ old('email', $setting->email) }}"
                                        placeholder="support@example.com">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Card: Logo & Favicon --}}
                <div class="as-card">
                    <div class="as-card-header">
                        <div class="ch-icon" style="background:#fdf4ff;">
                            <i class="fas fa-image" style="color:#a855f7;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Logo & Favicon</h2>
                            <p>Hình ảnh đại diện trên web và trình duyệt</p>
                        </div>
                    </div>
                    <div class="as-card-body">
                        <div class="as-row-2">
                            {{-- Logo --}}
                            <div class="as-field" style="margin-bottom:0;">
                                <label>Logo ứng dụng</label>
                                <label class="img-upload">
                                    <input type="file" accept="image/*" name="logo_path"
                                        onchange="previewImg(this,'logoThumb','logoPlaceholder')">
                                    <div class="img-upload-inner">
                                        @if($setting->logo_path)
                                            <img id="logoThumb" src="{{ asset('storage/'.$setting->logo_path) }}" class="img-thumb" alt="Logo">
                                            <div id="logoPlaceholder" class="img-placeholder" style="display:none;"><i class="fas fa-image"></i></div>
                                        @else
                                            <img id="logoThumb" src="" class="img-thumb" style="display:none;" alt="">
                                            <div id="logoPlaceholder" class="img-placeholder"><i class="fas fa-image"></i></div>
                                        @endif
                                        <div class="img-upload-info">
                                            <strong>Chọn logo</strong>
                                            <span>PNG, JPG, SVG ≤ 2MB</span>
                                        </div>
                                    </div>
                                </label>
                            </div>
                            {{-- Favicon --}}
                            <div class="as-field" style="margin-bottom:0;">
                                <label>Favicon</label>
                                <label class="img-upload">
                                    <input type="file" accept="image/*" name="favicon_path"
                                        onchange="previewImg(this,'faviconThumb','faviconPlaceholder')">
                                    <div class="img-upload-inner">
                                        @if($setting->favicon_path)
                                            <img id="faviconThumb" src="{{ asset('storage/'.$setting->favicon_path) }}" class="img-thumb" alt="Favicon">
                                            <div id="faviconPlaceholder" class="img-placeholder" style="display:none;"><i class="fas fa-globe"></i></div>
                                        @else
                                            <img id="faviconThumb" src="" class="img-thumb" style="display:none;" alt="">
                                            <div id="faviconPlaceholder" class="img-placeholder"><i class="fas fa-globe"></i></div>
                                        @endif
                                        <div class="img-upload-info">
                                            <strong>Chọn favicon</strong>
                                            <span>ICO, PNG ≤ 1MB</span>
                                        </div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

            </div>{{-- /cột trái --}}

            {{-- ── Cột phải ── --}}
            <div>

                {{-- Card: Thông tin hiện tại --}}
                <div class="as-card">
                    <div class="as-card-header">
                        <div class="ch-icon" style="background:#f0fdf4;">
                            <i class="fas fa-eye" style="color:#16a34a;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Thông tin hiện tại</h2>
                            <p>Đang hiển thị trên hệ thống</p>
                        </div>
                    </div>
                    <div class="as-card-body" style="padding-top:12px;padding-bottom:12px;">
                        <div class="as-info-item">
                            <div class="ii-icon" style="background:#eff6ff;">
                                <i class="fas fa-tag" style="color:#3b82f6;"></i>
                            </div>
                            <div>
                                <div class="ii-label">Tên ứng dụng</div>
                                <div class="ii-val">{{ $setting->app_name ?: '—' }}</div>
                            </div>
                        </div>
                        <div class="as-info-item">
                            <div class="ii-icon" style="background:#f0fdf4;">
                                <i class="fas fa-phone" style="color:#16a34a;"></i>
                            </div>
                            <div>
                                <div class="ii-label">Điện thoại</div>
                                <div class="ii-val">{{ $setting->phone ?: '—' }}</div>
                            </div>
                        </div>
                        <div class="as-info-item">
                            <div class="ii-icon" style="background:#fef9c3;">
                                <i class="fas fa-envelope" style="color:#ca8a04;"></i>
                            </div>
                            <div>
                                <div class="ii-label">Email</div>
                                <div class="ii-val">{{ $setting->email ?: '—' }}</div>
                            </div>
                        </div>
                        @if($setting->logo_path)
                        <div class="as-info-item">
                            <div class="ii-icon" style="background:#fdf4ff;">
                                <i class="fas fa-image" style="color:#a855f7;"></i>
                            </div>
                            <div>
                                <div class="ii-label">Logo hiện tại</div>
                                <img src="{{ asset('storage/'.$setting->logo_path) }}"
                                    style="height:32px;margin-top:4px;border-radius:6px;border:1px solid var(--border);" alt="Logo">
                            </div>
                        </div>
                        @endif
                    </div>
                </div>

                {{-- Nút lưu --}}
                <div style="margin-top:16px;">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Lưu thay đổi
                    </button>
                </div>

                {{-- Note --}}
                <div style="margin-top:12px;padding:12px 14px;background:#f8fafc;border-radius:10px;border:1px solid var(--border);">
                    <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:flex-start;gap:7px;">
                        <i class="fas fa-info-circle" style="color:var(--accent);margin-top:1px;flex-shrink:0;"></i>
                        <span>Logo và favicon sẽ được cập nhật ngay sau khi lưu. Xóa cache trình duyệt nếu không thấy thay đổi.</span>
                    </div>
                </div>

            </div>{{-- /cột phải --}}

        </div>{{-- /as-grid --}}
    </form>
</div>

<script>
function previewImg(input, thumbId, placeholderId) {
    const thumb = document.getElementById(thumbId);
    const ph    = document.getElementById(placeholderId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            thumb.src = e.target.result;
            thumb.style.display = 'block';
            ph.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
@endsection
