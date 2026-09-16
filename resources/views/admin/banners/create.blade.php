@extends('admin.layout')

@section('styles')
<style>
.page-inner { padding: 20px 28px !important; max-width: 100% !important; }
:root { --ac:#6366f1; --ac-lt:#ede9fe; --bd:#e2e8f0; --tx:#1e293b; --ts:#64748b; --tm:#94a3b8; }

.bf-wrap { padding: 4px 0 48px; }
.bf-ph { display:flex; align-items:center; justify-content:space-between; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.bf-ph-left { display:flex; align-items:center; gap:12px; }
.bf-ph-icon { width:44px; height:44px; border-radius:12px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(99,102,241,.3); flex-shrink:0; }
.bf-ph h1 { font-size:20px; font-weight:700; color:var(--tx); margin:0; }
.bf-ph p  { font-size:13px; color:var(--ts); margin:2px 0 0; }

.bf-alert { display:flex; align-items:center; gap:8px; padding:10px 14px; border-radius:9px; margin-bottom:18px; font-size:13px; font-weight:500; }
.bf-alert.ok  { background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d; }
.bf-alert.err { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }

.bf-grid { display:grid; grid-template-columns:1fr 280px; gap:18px; align-items:start; }
@media(max-width:1200px){ .bf-grid { grid-template-columns:1fr; } }

.bf-card { background:#fff; border-radius:14px; border:1px solid var(--bd); box-shadow:0 1px 3px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
.bf-card:last-child { margin-bottom:0; }
.bf-ch { padding:14px 18px 12px; border-bottom:1px solid #f8fafc; display:flex; align-items:center; gap:10px; }
.bf-chi { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.bf-ch h2 { font-size:14px; font-weight:600; color:var(--tx); margin:0; }
.bf-ch p  { font-size:12px; color:var(--tm); margin:2px 0 0; }
.bf-cb { padding:16px 18px; }

.bf-field { margin-bottom:14px; }
.bf-field:last-child { margin-bottom:0; }
.bf-field > label { display:block; font-size:11px; font-weight:700; color:#475569; margin-bottom:5px; text-transform:uppercase; letter-spacing:.4px; }
.bf-field .hint { font-size:12px; color:var(--tm); margin-top:4px; }
.bf-field .ferr { font-size:12px; color:#dc2626; margin-top:4px; }

.bf-in, .bf-ta, .bf-sel {
    border:1px solid var(--bd); border-radius:8px; padding:8px 11px;
    font-size:13px; color:var(--tx); background:#fff; width:100%; box-sizing:border-box;
    transition:border .15s, box-shadow .15s;
}
.bf-in:focus, .bf-ta:focus, .bf-sel:focus { outline:none; border-color:var(--ac); box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.bf-ta { resize:vertical; min-height:80px; }
.bf-sel { padding-right:30px; appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 9px center; }

.bf-row2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
@media(max-width:600px){ .bf-row2 { grid-template-columns:1fr; } }

/* type radio cards */
.bf-type-list { display:flex; flex-direction:column; gap:8px; }
.bf-tc-in { display:none; }
.bf-tc-lb { display:flex; align-items:flex-start; gap:10px; padding:11px 13px; border-radius:10px; border:1.5px solid var(--bd); background:#fff; cursor:pointer; transition:all .15s; }
.bf-tc-lb:hover { border-color:#a5b4fc; background:#fafbff; }
.bf-tc-in:checked + .bf-tc-lb { border-color:var(--ac); background:var(--ac-lt); }
.bf-tc-icon { width:32px; height:32px; border-radius:8px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:13px; }
.bf-tc-title { font-size:13px; font-weight:600; color:var(--tx); }
.bf-tc-desc  { font-size:11px; color:var(--tm); margin-top:1px; }
.bf-tc-in:checked + .bf-tc-lb .bf-tc-title { color:var(--ac); }

/* status toggle */
.bf-st-grp { display:flex; gap:8px; }
.bf-st-in { display:none; }
.bf-st-lb { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:8px; border-radius:8px; border:1.5px solid var(--bd); background:#fff; font-size:13px; font-weight:500; color:var(--ts); cursor:pointer; transition:all .15s; }
.bf-st-in[value="1"]:checked + .bf-st-lb { border-color:#22c55e; background:#f0fdf4; color:#16a34a; font-weight:600; }
.bf-st-in[value="0"]:checked + .bf-st-lb { border-color:#94a3b8; background:#f8fafc; color:#64748b; font-weight:600; }

/* upload zone */
.bf-upload { border:2px dashed #c7d2fe; border-radius:12px; background:#f8f7ff; padding:28px 16px; display:flex; flex-direction:column; align-items:center; justify-content:center; cursor:pointer; text-align:center; position:relative; min-height:150px; transition:all .2s; }
.bf-upload:hover { border-color:var(--ac); background:var(--ac-lt); }
.bf-upload.has-img { padding:0; border-style:solid; border-color:var(--bd); background:#fff; }
.bf-upload input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
.bf-upload-icon { width:44px; height:44px; border-radius:11px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; margin-bottom:8px; }
.bf-upload-title { font-size:13px; font-weight:600; color:var(--tx); margin-bottom:2px; }
.bf-upload-sub   { font-size:12px; color:var(--tm); }
.bf-img-preview  { width:100%; border-radius:10px; display:block; max-height:220px; object-fit:cover; }
.bf-img-change   { position:absolute; bottom:8px; right:8px; background:rgba(0,0,0,.55); color:#fff; border:none; border-radius:7px; padding:5px 10px; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:4px; }

/* room mode labels */
.bf-rm-lb { display:flex; align-items:center; gap:9px; padding:9px 12px; border-radius:8px; border:1.5px solid var(--bd); background:#fff; cursor:pointer; transition:all .15s; margin-bottom:6px; }
.bf-rm-lb:last-child { margin-bottom:0; }
.bf-rm-lb:hover { border-color:#a5b4fc; background:#fafbff; }
.bf-rm-lb.active { border-color:var(--ac); background:var(--ac-lt); }
.bf-rm-lb input { width:15px; height:15px; flex-shrink:0; accent-color:var(--ac); }
.bf-rm-lb span { font-size:13px; font-weight:500; color:var(--tx); }

/* save button */
.bf-btn-save { display:flex; align-items:center; justify-content:center; gap:7px; width:100%; padding:10px; border-radius:10px; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-size:14px; font-weight:600; border:none; cursor:pointer; box-shadow:0 4px 14px rgba(99,102,241,.3); transition:opacity .15s; }
.bf-btn-save:hover { opacity:.9; }

.bf-btn-back { display:flex; align-items:center; justify-content:center; gap:6px; width:100%; padding:9px; border-radius:10px; border:1px solid var(--bd); background:#fff; color:var(--ts); font-size:13px; font-weight:500; text-decoration:none; transition:all .15s; margin-top:8px; }
.bf-btn-back:hover { border-color:var(--ac); color:var(--ac); background:var(--ac-lt); text-decoration:none; }
</style>
@endsection

@section('content')
@php
$isEdit = false;
$selType = old('banner_type', 'image_only');
$selRoomMode = old('room_selection_mode', 'selected_room');
$typeIcons  = ['room_link'=>'fas fa-dice','image_only'=>'fas fa-images','promo'=>'fas fa-bullhorn'];
$typeColors = ['room_link'=>['bg'=>'#ede9fe','c'=>'#7c3aed'],'image_only'=>['bg'=>'#eff6ff','c'=>'#3b82f6'],'promo'=>['bg'=>'#fef9c3','c'=>'#ca8a04']];
$typeDescs  = ['room_link'=>'Hiển thị cố định 1 phòng quay','image_only'=>'Chỉ ảnh, chạy slider tự động','promo'=>'Ảnh + nội dung text, chạy slider'];
@endphp

<div class="bf-wrap">
    <div class="bf-ph">
        <div class="bf-ph-left">
            <div class="bf-ph-icon"><i class="fas fa-plus" style="color:#fff;font-size:16px;"></i></div>
            <div><h1>Thêm Banner mới</h1><p>Tạo banner hiển thị trên trang chủ</p></div>
        </div>
        <a href="{{ route('admin.banners.index') }}" class="bf-btn-back" style="width:auto;padding:8px 16px;margin-top:0;">
            <i class="fas fa-arrow-left"></i> Quay lại
        </a>
    </div>

    @if($errors->any())
        <div class="bf-alert err"><i class="fas fa-exclamation-circle"></i> {{ $errors->first() }}</div>
    @endif

    <form action="{{ route('admin.banners.store') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="bf-grid">
            @include('admin.banners._form_body', ['isEdit'=>false,'selType'=>$selType,'selRoomMode'=>$selRoomMode,'typeIcons'=>$typeIcons,'typeColors'=>$typeColors,'typeDescs'=>$typeDescs])
        </div>
    </form>
</div>
@endsection
