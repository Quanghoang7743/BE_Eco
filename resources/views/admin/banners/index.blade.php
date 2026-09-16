@extends('admin.layout')

@section('styles')
<style>
.page-inner { padding: 20px 28px !important; max-width: 100% !important; }

/* tokens */
:root {
    --ac: #6366f1; --ac-lt: #ede9fe; --bd: #e2e8f0;
    --tx: #1e293b; --ts: #64748b; --tm: #94a3b8;
}

/* layout */
.bx-wrap { padding: 4px 0 48px; }
.bx-ph { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.bx-ph-left { display:flex; align-items:center; gap:12px; }
.bx-ph-icon { width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(99,102,241,.3); flex-shrink:0; }
.bx-ph h1 { font-size:20px; font-weight:700; color:var(--tx); margin:0; }
.bx-ph p  { font-size:13px; color:var(--ts); margin:2px 0 0; }

/* alert */
.bx-alert { display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:9px; margin-bottom:18px; font-size:13px; font-weight:500; }
.bx-alert.ok  { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
.bx-alert.err { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }

/* card */
.bx-card { background:#fff; border-radius:14px; border:1px solid var(--bd); box-shadow:0 1px 3px rgba(0,0,0,.06); overflow:hidden; margin-bottom:18px; }
.bx-ch { padding:14px 18px 12px; border-bottom:1px solid #f8fafc; display:flex; align-items:center; justify-content:space-between; gap:10px; }
.bx-ch-left { display:flex; align-items:center; gap:10px; }
.bx-chi { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.bx-ch h2 { font-size:14px; font-weight:600; color:var(--tx); margin:0; }
.bx-ch p  { font-size:12px; color:var(--tm); margin:2px 0 0; }
.bx-cb { padding:16px 18px; }

/* switch cards */
.bx-sw-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
@media(max-width:700px){ .bx-sw-grid { grid-template-columns:1fr; } }
.bx-sw-btn {
    width:100%; padding:14px 12px; border-radius:12px; border:2px solid var(--bd);
    background:#fff; cursor:pointer; text-align:center; transition:all .18s; font-family:inherit;
}
.bx-sw-btn:hover { border-color:#a5b4fc; background:#fafbff; }
.bx-sw-btn.on { border-color:var(--ac); background:var(--ac-lt); }
.bx-sw-icon { margin-bottom:8px; }
.bx-sw-icon span { width:38px; height:38px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; }
.bx-sw-label { font-size:13px; font-weight:600; color:var(--tx); }
.bx-sw-btn.on .bx-sw-label { color:var(--ac); }
.bx-sw-stats { font-size:12px; color:var(--tm); margin-top:3px; }
.bx-sw-badge { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; background:var(--ac); color:#fff; padding:2px 8px; border-radius:20px; margin-top:6px; }

/* badge */
.bx-badge { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; }
.bx-badge.on  { background:#dcfce7; color:#16a34a; }
.bx-badge.off { background:#f1f5f9; color:#64748b; }

/* banner grid */
.bx-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; }
.bx-item { background:#fff; border-radius:12px; border:1px solid var(--bd); box-shadow:0 1px 3px rgba(0,0,0,.05); overflow:hidden; display:flex; flex-direction:column; transition:box-shadow .2s,transform .15s; }
.bx-item:hover { box-shadow:0 6px 20px rgba(0,0,0,.09); transform:translateY(-2px); }
.bx-item-img { width:100%; height:140px; object-fit:cover; display:block; background:#f1f5f9; flex-shrink:0; }
.bx-item-no-img { width:100%; height:140px; background:linear-gradient(135deg,#f1f5f9,#e2e8f0); display:flex; align-items:center; justify-content:center; color:var(--tm); font-size:28px; flex-shrink:0; }
.bx-item-body { padding:12px 14px; flex:1; }
.bx-item-type { display:inline-flex; align-items:center; gap:4px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:2px 8px; border-radius:20px; margin-bottom:6px; }
.bx-item-title { font-size:13px; font-weight:600; color:var(--tx); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px; }
.bx-item-meta  { font-size:12px; color:var(--tm); }
.bx-item-foot  { padding:9px 14px; border-top:1px solid #f8fafc; display:flex; align-items:center; justify-content:space-between; gap:6px; flex-shrink:0; }

/* buttons */
.bx-btn-add { display:inline-flex; align-items:center; gap:6px; padding:9px 18px; border-radius:10px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-size:13px; font-weight:600; border:none; cursor:pointer; box-shadow:0 4px 12px rgba(99,102,241,.3); text-decoration:none; }
.bx-btn-add:hover, .bx-btn-add:focus { opacity:.9; color:#fff; text-decoration:none; }

.bx-btn { display:inline-flex; align-items:center; gap:4px; padding:5px 11px; border-radius:7px; font-size:12px; font-weight:600; border:1px solid; cursor:pointer; text-decoration:none; transition:background .15s; white-space:nowrap; }
.bx-btn:hover { text-decoration:none; }
.bx-btn.edit   { background:#eff6ff; color:#3b82f6; border-color:#bfdbfe; }
.bx-btn.edit:hover { background:#dbeafe; color:#2563eb; }
.bx-btn.del    { background:#fef2f2; color:#ef4444; border-color:#fecaca; }
.bx-btn.del:hover  { background:#fee2e2; color:#dc2626; }
.bx-btn.tog-on  { background:#f0fdf4; color:#16a34a; border-color:#bbf7d0; }
.bx-btn.tog-on:hover  { background:#dcfce7; color:#15803d; }
.bx-btn.tog-off { background:#f8fafc; color:#64748b; border-color:#e2e8f0; }
.bx-btn.tog-off:hover { background:#f1f5f9; color:#475569; }
</style>
@endsection

@section('content')
@php
$typeIcons   = ['room_link'=>'fas fa-dice','image_only'=>'fas fa-images','promo'=>'fas fa-bullhorn'];
$typeColors  = ['room_link'=>['bg'=>'#ede9fe','c'=>'#7c3aed'],'image_only'=>['bg'=>'#eff6ff','c'=>'#3b82f6'],'promo'=>['bg'=>'#fef9c3','c'=>'#ca8a04']];
$typeLabels  = ['room_link'=>'Phòng cố định','image_only'=>'Slider ảnh','promo'=>'Slider nội dung'];
@endphp

<div class="bx-wrap">

    {{-- Header --}}
    <div class="bx-ph">
        <div class="bx-ph-left">
            <div class="bx-ph-icon"><i class="far fa-images" style="color:#fff;font-size:17px;"></i></div>
            <div><h1>Quản lý Banner</h1><p>Cấu hình banner trang chủ ứng dụng</p></div>
        </div>
        <a href="{{ route('admin.banners.create') }}" class="bx-btn-add">
            <i class="fas fa-plus"></i> Thêm banner
        </a>
    </div>

    @if(session('success'))
        <div class="bx-alert ok"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="bx-alert err"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
    @endif

    {{-- Switch type --}}
    <div class="bx-card">
        <div class="bx-ch">
            <div class="bx-ch-left">
                <div class="bx-chi" style="background:#fef9c3;"><i class="fas fa-sliders-h" style="color:#ca8a04;font-size:11px;"></i></div>
                <div><h2>Loại hiển thị trang chủ</h2><p>Chọn 1 loại — các loại còn lại tự tắt</p></div>
            </div>
        </div>
        <div class="bx-cb">
            <div class="bx-sw-grid">
                @foreach($bannerTypeOptions as $type => $label)
                @php
                    if ($type === 'room_link' || $type === 'promo') continue;
                    $isOn  = $activeBannerType === $type;
                    $stats = $typeStats[$type] ?? ['total'=>0,'active'=>0];
                    $ic    = $typeIcons[$type] ?? 'fas fa-image';
                    $cl    = $typeColors[$type] ?? ['bg'=>'#f1f5f9','c'=>'#64748b'];
                @endphp
                <form action="{{ route('admin.banners.switchDisplayType') }}" method="POST">
                    @csrf @method('PATCH')
                    <input type="hidden" name="banner_type" value="{{ $type }}">
                    <button type="submit" class="bx-sw-btn {{ $isOn ? 'on' : '' }}">
                        <div class="bx-sw-icon">
                            <span style="background:{{ $cl['bg'] }};"><i class="{{ $ic }}" style="color:{{ $cl['c'] }};font-size:15px;"></i></span>
                        </div>
                        <div class="bx-sw-label">{{ $typeLabels[$type] ?? $label }}</div>
                        <div class="bx-sw-stats">{{ $stats['active'] }} bật / {{ $stats['total'] }} banner</div>
                        @if($isOn)<div><span class="bx-sw-badge"><i class="fas fa-check" style="font-size:8px;"></i> Đang dùng</span></div>@endif
                    </button>
                </form>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Banner list grouped by type --}}
    @php $grouped = $banners->groupBy('banner_type'); @endphp
    @foreach(['image_only'] as $type)
    @php
        $grp   = $grouped->get($type, collect());
        $ic    = $typeIcons[$type] ?? 'fas fa-image';
        $cl    = $typeColors[$type] ?? ['bg'=>'#f1f5f9','c'=>'#64748b'];
        $lbl   = $typeLabels[$type] ?? $type;
        $isOn  = $activeBannerType === $type;
    @endphp
    @if($grp->count() > 0)
    <div class="bx-card">
        <div class="bx-ch">
            <div class="bx-ch-left">
                <div class="bx-chi" style="background:{{ $cl['bg'] }};"><i class="{{ $ic }}" style="color:{{ $cl['c'] }};font-size:11px;"></i></div>
                <div>
                    <h2>{{ $lbl }}</h2>
                    <p>{{ $grp->count() }} banner · {{ $grp->where('status',true)->count() }} đang bật</p>
                </div>
            </div>
            <span class="bx-badge {{ $isOn ? 'on' : 'off' }}">
                <i class="fas fa-circle" style="font-size:6px;"></i>
                {{ $isOn ? 'Đang hiển thị' : 'Đang tắt' }}
            </span>
        </div>
        <div class="bx-cb">
            <div class="bx-list">
                @foreach($grp as $banner)
                <div class="bx-item">
                    @if($banner->image)
                        <img src="{{ asset('storage/'.$banner->image) }}" class="bx-item-img" alt="">
                    @else
                        <div class="bx-item-no-img"><i class="far fa-image"></i></div>
                    @endif
                    <div class="bx-item-body">
                        <span class="bx-item-type" style="background:{{ $cl['bg'] }};color:{{ $cl['c'] }};">
                            <i class="{{ $ic }}" style="font-size:8px;"></i> {{ $lbl }}
                        </span>
                        <div class="bx-item-title">{{ $banner->title ?: 'Banner #'.$banner->id }}</div>
                        <div class="bx-item-meta">Thứ tự: {{ $banner->order ?? '—' }}</div>
                    </div>
                    <div class="bx-item-foot">
                        <form action="{{ route('admin.banners.toggleStatus', $banner->id) }}" method="POST" style="margin:0;">
                            @csrf @method('PATCH')
                            <button type="submit" class="bx-btn {{ $banner->status ? 'tog-on' : 'tog-off' }}">
                                <i class="fas fa-circle" style="font-size:6px;"></i>
                                {{ $banner->status ? 'Bật' : 'Tắt' }}
                            </button>
                        </form>
                        <div style="display:flex;gap:6px;">
                            <a href="{{ route('admin.banners.edit', $banner->id) }}" class="bx-btn edit">
                                <i class="fas fa-pen"></i> Sửa
                            </a>
                            <form action="{{ route('admin.banners.destroy', $banner->id) }}" method="POST" style="margin:0;"
                                onsubmit="return confirm('Xóa banner này?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="bx-btn del"><i class="fas fa-trash-alt"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif
    @endforeach

    @if($banners->isEmpty())
    <div class="bx-card">
        <div class="bx-cb" style="text-align:center;padding:48px 20px;">
            <div style="width:60px;height:60px;border-radius:14px;background:var(--ac-lt);display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
                <i class="far fa-images" style="font-size:22px;color:var(--ac);"></i>
            </div>
            <div style="font-size:15px;font-weight:600;color:var(--tx);margin-bottom:6px;">Chưa có banner nào</div>
            <div style="font-size:13px;color:var(--tm);margin-bottom:18px;">Thêm banner đầu tiên để hiển thị trên trang chủ</div>
            <a href="{{ route('admin.banners.create') }}" class="bx-btn-add"><i class="fas fa-plus"></i> Thêm banner</a>
        </div>
    </div>
    @endif

</div>
@endsection
