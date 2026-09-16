@extends('admin.layout')

@section('content')
    @php
        $selectedCodes = $selectedCodes ?? [];
        $permissionCount = count($selectedCodes);
    @endphp

    <style>
        .role-shell { padding: 10px 0 24px; color:#163027; }
        .role-topbar { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:18px; }
        .role-title { margin:0; font-size:32px; line-height:1.15; font-weight:800; letter-spacing:-0.03em; color:#10251d; }
        .role-subtitle { margin:10px 0 0; color:#6b8278; font-size:14px; line-height:1.55; max-width:760px; }
        .role-top-actions { display:flex; gap:10px; align-items:center; }
        .role-pill { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; background:#eff8f2; color:#17754b; font-weight:700; font-size:13px; }
        .role-meta-card-wrap { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-bottom:16px; }
        .role-meta-card { background:#fff; border:1px solid #e4ece7; border-radius:18px; padding:18px; box-shadow:0 14px 30px -28px rgba(0,92,63,.18); }
        .role-meta-label { color:#7b8f87; text-transform:uppercase; letter-spacing:.08em; font-size:12px; font-weight:800; }
        .role-meta-value { margin-top:8px; font-size:18px; line-height:1.4; font-weight:800; color:#123026; }
        .role-meta-desc { margin-top:6px; color:#6b8278; font-size:13px; line-height:1.55; }
        .role-layout { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:16px; align-items:start; }
        .role-panel { background:#fff; border:1px solid #e4ece7; border-radius:20px; overflow:hidden; box-shadow:0 18px 34px -32px rgba(0,92,63,.22); }
        .role-panel-head { padding:18px 20px; border-bottom:1px solid #ebf1ed; display:flex; justify-content:space-between; align-items:center; gap:12px; background:linear-gradient(180deg,#f4fbf6 0%,#ffffff 100%); }
        .role-panel-head h2 { margin:0; font-size:17px; font-weight:800; color:#13291f; }
        .role-panel-head p { margin:4px 0 0; color:#6b8278; font-size:13px; }
        .role-check-tools { display:flex; gap:8px; flex-wrap:wrap; }
        .role-tool-btn { border:1px solid #dbe7df; background:#fff; color:#355247; border-radius:999px; padding:8px 12px; font-size:12px; font-weight:700; }
        .role-content { padding:18px 20px 6px; }
        .role-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
        .perm-group { border:1px solid #e6eee9; border-radius:18px; padding:14px; background:#fbfdfb; min-height:100%; }
        .perm-group-head { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:12px; }
        .perm-group-title { display:flex; align-items:center; gap:10px; font-size:14px; font-weight:800; color:#143227; margin:0; }
        .perm-group-title i { width:34px; height:34px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; background:#e9f7ef; color:#15945e; }
        .perm-count { display:inline-flex; align-items:center; justify-content:center; min-width:36px; height:28px; padding:0 10px; border-radius:999px; background:#eaf6ee; color:#17754b; font-size:12px; font-weight:800; }
        .perm-list { display:flex; flex-direction:column; gap:10px; }
        .perm-list-scroll { max-height: 270px; overflow-y: auto; padding-right: 4px; }
        .perm-list-scroll::-webkit-scrollbar { width: 8px; }
        .perm-list-scroll::-webkit-scrollbar-thumb { background: #cfded4; border-radius: 999px; }
        .perm-list-scroll::-webkit-scrollbar-track { background: transparent; }
        .perm-item { display:flex; gap:10px; align-items:flex-start; padding:11px 12px; border-radius:14px; background:#fff; border:1px solid #edf2ef; transition:all .2s ease; }
        .perm-item:hover { border-color:#cfe6d8; box-shadow:0 10px 20px -18px rgba(0,92,63,.25); }
        .perm-item input { margin-top:3px; accent-color:#16935d; }
        .perm-text { flex:1; min-width:0; }
        .perm-name { margin:0; font-size:13px; font-weight:700; color:#13291f; line-height:1.4; }
        .perm-code { margin-top:2px; font-size:11px; color:#7b8f87; word-break:break-word; }
        .role-sidebar { position:sticky; top:18px; display:flex; flex-direction:column; gap:14px; }
        .role-side-card { background:#fff; border:1px solid #e4ece7; border-radius:18px; padding:18px; box-shadow:0 14px 30px -28px rgba(0,92,63,.18); }
        .role-side-title { margin:0 0 8px; font-size:15px; font-weight:800; color:#12251e; }
        .role-side-text { color:#6b8278; font-size:13px; line-height:1.55; margin:0; }
        .role-summary { display:grid; gap:10px; margin-top:12px; }
        .role-summary-row { display:flex; justify-content:space-between; gap:12px; font-size:13px; color:#355247; }
        .role-summary-row strong { color:#12251e; }
        .role-form-actions { display:flex; gap:10px; margin-top:16px; }
        .role-submit { border:0; border-radius:14px; padding:12px 18px; background:linear-gradient(135deg,#17754b,#16a34a); color:#fff; font-weight:800; box-shadow:0 16px 28px -22px rgba(22,163,74,.6); }
        .role-cancel { display:inline-flex; align-items:center; justify-content:center; border:1px solid #dbe7df; background:#fff; color:#355247; border-radius:14px; padding:12px 18px; font-weight:800; text-decoration:none; }
        @media (max-width: 1199.98px) {
            .role-layout { grid-template-columns:1fr; }
            .role-sidebar { position:static; }
        }
        @media (max-width: 991.98px) {
            .role-topbar { flex-direction:column; }
            .role-meta-card-wrap, .role-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
        }
        @media (max-width: 575.98px) {
            .role-meta-card-wrap, .role-grid { grid-template-columns:1fr; }
            .role-title { font-size:27px; }
        }
    </style>

    <div class="role-shell">
        <div class="role-topbar">
            <div>
                <h1 class="role-title">{{ $roleMeta['title'] }}</h1>
                <p class="role-subtitle">{{ $roleMeta['description'] }}</p>
            </div>
            <div class="role-top-actions">
                <span class="role-pill"><i class="fas fa-user-shield"></i> {{ $roleRecord->name }}</span>
            </div>
        </div>

        <div class="role-meta-card-wrap">
            <div class="role-meta-card">
                <div class="role-meta-label">Phạm vi</div>
                <div class="role-meta-value">{{ $roleMeta['scope'] }}</div>
                <div class="role-meta-desc">Vai trò này được hiển thị theo phạm vi vận hành đã cấu hình.</div>
            </div>
            <div class="role-meta-card">
                <div class="role-meta-label">Loại vai trò</div>
                <div class="role-meta-value">{{ $roleMeta['badge'] }}</div>
                <div class="role-meta-desc">Dùng để nhận diện nhanh trong danh sách quản trị.</div>
            </div>
            <div class="role-meta-card">
                <div class="role-meta-label">Quyền đã chọn</div>
                <div class="role-meta-value">{{ $permissionCount }}</div>
                <div class="role-meta-desc">Tổng số permission đang được bật cho vai trò này.</div>
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('admin.roles.update', ['role' => $roleRecord->name]) }}">
            @csrf
            @method('PUT')

            <div class="role-layout">
                <div class="role-panel">
                    <div class="role-panel-head">
                        <div>
                            <h2>Danh sách quyền</h2>
                            <p>Chọn các quyền áp dụng cho vai trò này.</p>
                        </div>
                        <div class="role-check-tools">
                            <button type="button" class="role-tool-btn" id="checkAllPermissions">Chọn tất cả</button>
                            <button type="button" class="role-tool-btn" id="clearAllPermissions">Bỏ chọn hết</button>
                        </div>
                    </div>
                    <div class="role-content">
                        <div class="role-grid">
                            @foreach ($permissionGroups as $group)
                                <div class="perm-group" data-group-key="{{ $group['key'] }}">
                                    <div class="perm-group-head">
                                        <div class="perm-group-title">
                                            <i class="fas fa-{{ $group['icon'] }}"></i>
                                            <span>{{ $group['label'] }}</span>
                                        </div>
                                        <span class="perm-count">{{ count($group['permissions']) }}</span>
                                    </div>
                                    <div class="perm-list{{ count($group['permissions']) > 4 ? ' perm-list-scroll' : '' }}">
                                        @foreach ($group['permissions'] as $permission)
                                            <label class="perm-item">
                                                <input
                                                    type="checkbox"
                                                    name="permissions[]"
                                                    value="{{ $permission['code'] }}"
                                                    {{ in_array($permission['code'], old('permissions', $selectedCodes), true) ? 'checked' : '' }}
                                                >
                                                <span class="perm-text">
                                                    <span class="perm-name">{{ $permission['name'] }}</span>
                                                    <span class="perm-code">{{ $permission['code'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <aside class="role-sidebar">
                    <div class="role-side-card">
                        <h3 class="role-side-title">Thông tin vai trò</h3>
                        <p class="role-side-text">{{ $roleMeta['description'] }}</p>
                        <div class="role-summary">
                            <div class="role-summary-row"><span>Mã vai trò</span><strong>{{ $roleRecord->name }}</strong></div>
                            <div class="role-summary-row"><span>Phạm vi</span><strong>{{ $roleMeta['scope'] }}</strong></div>
                            <div class="role-summary-row"><span>Số quyền</span><strong>{{ $permissionCount }}</strong></div>
                        </div>
                    </div>

                    <div class="role-side-card">
                        <h3 class="role-side-title">Lưu thay đổi</h3>
                        <p class="role-side-text">Khi xác nhận, hệ thống sẽ cập nhật lại quyền của vai trò này.</p>
                        <div class="role-form-actions">
                            <button type="submit" class="role-submit">Lưu thay đổi</button>
                            <a href="{{ route('admin.users.index', ['tab' => 'roles']) }}" class="role-cancel">Hủy</a>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
@endsection

@section('scripts')
    <script>
        $(function () {
            $('#checkAllPermissions').on('click', function () {
                $('input[name="permissions[]"]').prop('checked', true);
            });

            $('#clearAllPermissions').on('click', function () {
                $('input[name="permissions[]"]').prop('checked', false);
            });
        });
    </script>
@endsection