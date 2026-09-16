@extends('admin.layout')
@section('content')
    @php
        $activeTab = request('tab') === 'customers' ? 'customers' : (request('tab') === 'roles' ? 'roles' : (request('tab') === 'verifications' ? 'verifications' : 'accounts'));
        
        $titleLabels = [
            'accounts' => 'Danh sách tài khoản',
            'customers' => 'Danh sách khách hàng',
            'verifications' => 'Danh sách hồ sơ xác thực',
        ];
        $pageTitle = $titleLabels[$activeTab] ?? 'Danh sách tài khoản';

        $roleRows = [];
        $totalRoles = count($roleRows);
        $totalMembers = array_sum(array_column($roleRows, 'members'));
        $rolesWithPermissions = count(array_filter($roleRows, fn ($row) => (int) $row['permissions'] > 0));
        $totalAssignedPermissions = array_sum(array_column($roleRows, 'permissions'));
    @endphp

    <style>
        /* Global card and layout styling */
        .card {
            border: 1px solid #e2e8f0 !important;
            border-radius: 12px !important;
            box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;
        }
        .table thead th {
            background-color: #f8fafc !important;
            color: #475569 !important;
            font-weight: 600 !important;
            text-transform: none !important; /* Ensure no uppercase */
            border-bottom: 1px solid #e2e8f0 !important;
            font-size: 0.85rem !important;
            padding: 12px 16px !important;
        }
        .table tbody td {
            padding: 12px 16px !important;
            vertical-align: middle !important;
            color: #334155 !important;
            font-size: 0.9rem !important;
        }
        .avatar-wrapper img {
            width: 36px !important;
            height: 36px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 1px solid #e2e8f0 !important;
        }
        .user-detail-link {
            color: #2563eb !important;
            font-weight: 600 !important;
            text-decoration: none !important;
        }
        .user-detail-link:hover {
            text-decoration: underline !important;
        }
        .badge {
            font-size: 0.75rem !important;
            font-weight: 600 !important;
            padding: 0.35em 0.65em !important;
            border-radius: 6px !important;
        }
        .badge-role-user {
            background-color: #eff6ff !important;
            color: #1d4ed8 !important;
            border: 1px solid #bfdbfe !important;
        }
        .badge-role-admin-store {
            background-color: #1e3a8a !important;
            color: #ffffff !important;
        }
        .table td .btn {
            font-size: 0.8rem !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            padding: 0.35rem 0.75rem !important;
        }
        .table td .btn.btn-action {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0 !important;
            font-size: 0.85rem !important;
        }
        .table td .btn.btn-action i {
            font-size: 0.85rem !important;
            margin: 0 !important;
        }
        .table td .user-ledger-link {
            background-color: #eff6ff !important;
            color: #2563eb !important;
            border: 1px solid #bfdbfe !important;
        }
        .table td .user-ledger-link:hover {
            background-color: #dbeafe !important;
        }
        .table td a.btn-warning {
            background-color: #fffbeb !important;
            color: #d97706 !important;
            border: 1px solid #fde68a !important;
        }
        .table td a.btn-warning:hover {
            background-color: #fef3c7 !important;
        }
        .table td button.btn-danger, .table td form button.btn-danger {
            background-color: #dc2626 !important;
            border-color: #dc2626 !important;
            color: #ffffff !important;
        }
        .table td button.btn-danger:hover {
            background-color: #b91c1c !important;
        }
        .btn-outline-danger {
            background-color: #fef2f2 !important;
            border-color: #fca5a5 !important;
            color: #dc2626 !important;
        }
        .btn-outline-danger:hover:not(:disabled) {
            background-color: #fee2e2 !important;
            border-color: #f87171 !important;
            color: #b91c1c !important;
        }
        .btn-outline-danger:disabled {
            background-color: #fbfbfb !important;
            border-color: #e5e7eb !important;
            color: #9ca3af !important;
            opacity: 0.6;
        }
    </style>

    @unless($activeTab === 'roles')
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background-color: #eff6ff; color: #2563eb;">
                    <i class="fa-solid fa-users fs-4"></i>
                </div>
                <div>
                    <h1 class="h3 mb-1 fw-bold text-dark">{{ $pageTitle }}</h1>
                    <p class="text-muted small mb-0">
                        @if($activeTab === 'customers')
                            Quản lý thông tin và tài khoản khách hàng
                        @elseif($activeTab === 'verifications')
                            Quản lý thông tin và hồ sơ xác thực tài khoản
                        @else
                            Quản lý thông tin và tài khoản nhân viên
                        @endif
                    </p>
                </div>
            </div>
            @if($activeTab !== 'verifications')
                <a href="{{ $createUrl ?? route('admin.users.create') }}" class="btn btn-primary px-4 py-2 d-flex align-items-center gap-2" style="background-color: #0061f2; border-color: #0061f2; border-radius: 8px;">
                    <i class="fa-solid fa-plus"></i>
                    <span>{{ $createLabel ?? 'Thêm tài khoản' }}</span>
                </a>
            @endif
        </div>
    @endunless

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div id="accountsSection" class="user-list-section {{ $activeTab === 'accounts' ? '' : 'd-none' }}">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-4">
                <div class="row mb-4 gy-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Tìm kiếm</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted border-1" style="border-radius: 8px 0 0 8px; border-color: #cbd5e1; border-right: 0;">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </span>
                            <input id="accountsSearch" type="text" class="form-control border-1 ps-0" placeholder="Tên, số điện thoại hoặc ID" style="border-radius: 0 8px 8px 0; border-color: #cbd5e1; border-left: 0; padding: 0.6rem 1rem 0.6rem 0; font-size: 0.9rem;">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Loại tài khoản</label>
                        <select id="accountsRoleFilter" class="form-select" style="border-color: #cbd5e1; border-radius: 8px; padding: 0.6rem 1rem; font-size: 0.9rem;">
                            <option value="">Tất cả</option>
                            <option value="admin">Admin</option>
                            <option value="sale">Sale</option>
                        </select>
                    </div>
                    <div class="col-md-5 d-flex justify-content-end align-items-end gap-2">
                        <button id="deleteSelectedAccounts" class="btn btn-outline-danger d-flex align-items-center gap-2 fw-semibold" style="border-radius: 8px; padding: 0.6rem 1.2rem; font-size: 0.9rem;" disabled>
                            <i class="fa-regular fa-trash-can"></i>
                            <span>Xóa đã chọn</span>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="accountsTable" class="table table-striped table-hover align-middle mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAllAccounts"></th>
                                <th>Tài khoản</th>
                                <th>Điện thoại</th>
                                <th>Vai trò</th>
                                <th>Giới tính</th>
                                <th>Ngày tạo</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="customersSection" class="user-list-section {{ $activeTab === 'customers' ? '' : 'd-none' }}">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-4">
                <div class="row mb-4 gy-3 align-items-end">
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Tìm kiếm</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted border-1" style="border-radius: 8px 0 0 8px; border-color: #cbd5e1; border-right: 0;">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </span>
                            <input id="customersSearch" type="text" class="form-control border-1 ps-0" placeholder="Tên, số điện thoại hoặc ID" style="border-radius: 0 8px 8px 0; border-color: #cbd5e1; border-left: 0; padding: 0.6rem 1rem 0.6rem 0; font-size: 0.9rem;">
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Loại tài khoản</label>
                        <select id="customersRoleFilter" class="form-select" style="border-color: #cbd5e1; border-radius: 8px; padding: 0.6rem 1rem; font-size: 0.9rem;">
                            <option value="">Tất cả</option>
                            <option value="user">User</option>
                            <option value="admin_store">Admin_Store</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Trạng thái xác thực</label>
                        <select id="customersVerificationFilter" class="form-select" style="border-color: #cbd5e1; border-radius: 8px; padding: 0.6rem 1rem; font-size: 0.9rem;">
                            <option value="">Tất cả</option>
                            <option value="pending">Chờ duyệt</option>
                            <option value="approved">Đã duyệt</option>
                            <option value="rejected">Từ chối</option>
                            <option value="unverified">Chưa xác thực</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6 d-flex justify-content-end align-items-end gap-2">
                        <button id="deleteSelectedCustomers" class="btn btn-outline-danger d-flex align-items-center gap-2 fw-semibold w-100 justify-content-center" style="border-radius: 8px; padding: 0.6rem 1.2rem; font-size: 0.9rem;" disabled>
                            <i class="fa-regular fa-trash-can"></i>
                            <span>Xóa đã chọn</span>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="customersTable" class="table table-striped table-hover align-middle mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAllCustomers"></th>
                                <th>Khách hàng</th>
                                <th>Điện thoại</th>
                                <th>Loại tài khoản</th>
                                <th>Xác thực</th>
                                <th>Giới tính</th>
                                <th>Ngày tạo</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="verificationsSection" class="user-list-section {{ $activeTab === 'verifications' ? '' : 'd-none' }}">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-body p-4">
                <div class="row mb-4 gy-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Tìm kiếm</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted border-1" style="border-radius: 8px 0 0 8px; border-color: #cbd5e1; border-right: 0;">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </span>
                            <input id="verificationsSearch" type="text" class="form-control border-1 ps-0" placeholder="Tên, số điện thoại hoặc ID" style="border-radius: 0 8px 8px 0; border-color: #cbd5e1; border-left: 0; padding: 0.6rem 1rem 0.6rem 0; font-size: 0.9rem;">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Trạng thái xác thực</label>
                        <select id="verificationsStatusFilter" class="form-select" style="border-color: #cbd5e1; border-radius: 8px; padding: 0.6rem 1rem; font-size: 0.9rem;">
                            <option value="">Tất cả</option>
                            <option value="pending">Chờ duyệt</option>
                            <option value="approved">Đã duyệt</option>
                            <option value="rejected">Từ chối</option>
                        </select>
                    </div>
                    <div class="col-md-5 d-flex justify-content-end align-items-end gap-2">
                        <button id="deleteSelectedVerifications" class="btn btn-outline-danger d-flex align-items-center gap-2 fw-semibold" style="border-radius: 8px; padding: 0.6rem 1.2rem; font-size: 0.9rem;" disabled>
                            <i class="fa-regular fa-trash-can"></i>
                            <span>Xóa đã chọn</span>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="verificationsTable" class="table table-striped table-hover align-middle mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width: 40px;"><input type="checkbox" id="selectAllVerifications"></th>
                                <th>Tài khoản</th>
                                <th>Điện thoại</th>
                                <th>Họ tên xác thực</th>
                                <th>Số CCCD/CMND</th>
                                <th>Trạng thái</th>
                                <th>Ngày nộp</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    @if($activeTab === 'roles')
        <style>
            .rp-page { padding: 16px 0 4px; }
            .rp-header { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
            .rp-title { margin:0; font-size:30px; line-height:1.2; font-weight:800; color:#12211a; letter-spacing:-0.02em; }
            .rp-subtitle { margin:8px 0 0; color:#6f817a; font-size:14px; line-height:1.55; }
            .rp-header-right { min-width:240px; display:flex; justify-content:flex-end; }
            .rp-search-wrap { display:flex; align-items:center; gap:10px; width:100%; max-width:320px; border:1px solid #e4ece7; background:#fff; border-radius:14px; padding:10px 14px; box-shadow:0 8px 24px -20px rgba(0,92,63,0.18); }
            .rp-search-wrap i { color:#8ba095; }
            .rp-search { border:0; outline:0; width:100%; font-size:14px; color:#173127; background:transparent; }
            .rp-search::placeholder { color:#95a8a0; }
            .rp-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:14px; }
            .rp-stat-card { border:1px solid #e4ece7; background:#fff; border-radius:16px; padding:18px 18px 16px; min-height:78px; box-shadow:0 14px 32px -28px rgba(0,92,63,0.18); }
            .rp-stat-card.accent { background:linear-gradient(180deg,#ddf8e4 0%,#cef3d8 100%); border-color:#b9e8c9; }
            .rp-stat-label { text-transform:uppercase; letter-spacing:0.08em; font-size:12px; font-weight:800; color:#6f817a; }
            .rp-stat-card.accent .rp-stat-label { color:#148a52; }
            .rp-stat-value { margin-top:6px; font-size:33px; line-height:1; font-weight:800; color:#12211a; }
            .rp-table-card { border:1px solid #e4ece7; border-radius:16px; overflow:hidden; background:#fff; box-shadow:0 16px 34px -30px rgba(0,92,63,0.22); }
            .rp-table { width:100%; border-collapse:separate; border-spacing:0; }
            .rp-table thead th { background:#edf9ef; color:#6f817a; font-size:12px; text-transform:uppercase; letter-spacing:0.08em; font-weight:800; padding:16px 18px; border-bottom:1px solid #e3efe6; }
            .rp-table tbody td { padding:16px 18px; border-bottom:1px solid #eef3ef; vertical-align:middle; }
            .rp-table tbody tr:last-child td { border-bottom:0; }
            .rp-role-icon { width:38px; height:38px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; }
            .rp-role-icon.amber { background:rgba(216,157,18,.14); color:#b8860b; }
            .rp-role-icon.blue { background:rgba(47,116,255,.12); color:#2f74ff; }
            .rp-role-icon.green { background:rgba(22,163,106,.12); color:#0d965f; }
            .rp-role-icon.purple { background:rgba(131,88,255,.12); color:#8358ff; }
            .rp-role-icon.gray { background:rgba(148,163,184,.18); color:#64748b; }
            .rp-role-name { font-size:14px; font-weight:800; color:#12211a; line-height:1.35; }
            .rp-role-description { margin-top:4px; font-size:12px; line-height:1.45; color:#6f817a; max-width:420px; }
            .rp-role-code { font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:12px; color:#5f766f; }
            .rp-badge-number { min-width:38px; display:inline-flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:999px; background:#f3f6f4; color:#334155; font-size:13px; font-weight:800; }
            .rp-badge-note { display:inline-flex; align-items:center; min-height:30px; padding:6px 12px; border-radius:999px; background:#f7faf8; color:#4b635b; font-size:12px; font-weight:700; line-height:1.35; text-align:center; }
            .rp-btn-edit, .rp-btn-delete { display:inline-flex; align-items:center; justify-content:center; gap:6px; border-radius:12px; font-size:13px; font-weight:700; padding:9px 14px; transition:all .2s ease; border:1px solid transparent; }
            .rp-btn-edit { color:#0d965f; background:#fff; border-color:#18b26e; }
            .rp-btn-edit:hover { background:#f0fbf5; color:#0a7b4d; text-decoration:none; }
            .rp-btn-delete { width:36px; height:36px; color:#ef4444; background:#fff; border-color:#fecaca; }
            .rp-btn-delete:hover { background:#fff5f5; }
            @media (max-width: 991.98px) {
                .rp-header { flex-direction:column; align-items:stretch; }
                .rp-header-right { justify-content:flex-start; }
                .rp-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
                .rp-table { min-width:760px; }
                .table-responsive { overflow-x:auto; }
            }
            @media (max-width: 575.98px) {
                .rp-stats { grid-template-columns:1fr; }
                .rp-title { font-size:26px; }
            }
        </style>

        <div class="rp-page">
            <div class="rp-header">
                <div>
                    <h1 class="rp-title">Vai trò &amp; Phân quyền</h1>
                    <p class="rp-subtitle">Kiểm soát quyền truy cập của từng nhóm nhân viên trong hệ thống</p>
                </div>
                <div class="rp-header-right">
                    <div class="rp-search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" class="rp-search" id="roleSearchInput" placeholder="Tìm kiếm vai trò...">
                    </div>
                </div>
            </div>

            <div class="rp-stats">
                <div class="rp-stat-card accent">
                    <div class="rp-stat-label">Tổng vai trò</div>
                    <div class="rp-stat-value">{{ $totalRoles }}</div>
                </div>
                <div class="rp-stat-card">
                    <div class="rp-stat-label">Tổng thành viên</div>
                    <div class="rp-stat-value">{{ $totalMembers }}</div>
                </div>
                <div class="rp-stat-card">
                    <div class="rp-stat-label">Vai trò có quyền</div>
                    <div class="rp-stat-value">{{ $rolesWithPermissions }}</div>
                </div>
                <div class="rp-stat-card">
                    <div class="rp-stat-label">Tổng quyền đã gán</div>
                    <div class="rp-stat-value">{{ $totalAssignedPermissions }}</div>
                </div>
            </div>

            <div class="rp-table-card" id="roles-table-shell">
                <div class="table-responsive">
                    <table class="rp-table" id="rolesTable">
                        <thead>
                            <tr>
                                <th style="width:48px;">#</th>
                                <th>Tên vai trò</th>
                                <th>Tên hệ thống</th>
                                <th style="width:130px;">Sá»‘ quyá»n</th>
                                <th style="width:180px;">Pháº¡m vi</th>
                                <th style="width:180px;" id="roles-action-col">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($roleRows as $role)
                                <tr class="role-row">
                                    <td style="color:#6f817a; font-size:12px; font-weight:700;">{{ $role['rank'] }}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rp-role-icon {{ $role['tone'] }}">
                                                <i class="fas fa-{{ $role['icon'] }}"></i>
                                            </div>
                                            <div>
                                                <div class="rp-role-name">{{ $role['name'] }}</div>
                                                <div class="rp-role-description">{{ $role['description'] }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="rp-role-code">{{ $role['code'] }}</span></td>
                                    <td><span class="rp-badge-number">{{ $role['permissions'] }}</span></td>
                                    <td><span class="rp-badge-note">{{ $role['scope'] }}</span></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <a href="{{ route('admin.permissions.index') }}#role-{{ $role['code'] }}" class="rp-btn-edit">
                                                <i class="fas fa-pen" style="font-size:10px;"></i> Sá»­a quyá»n
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <!-- Modal hiển thị thông tin chi tiết -->
    <div class="modal fade" id="userDetailModal" tabindex="-1" role="dialog" aria-labelledby="userDetailModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Thông tin khách hàng</h5>
                </div>
                <div class="modal-body">
                    <!-- Nội dung được load động -->
                    <div id="userDetailContent">
                        <p class="text-muted">Đang tải...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('scripts')
    <script>
        $(function () {
            var tables = {};
            var tabParam = new URLSearchParams(window.location.search).get('tab');
            var activeTab = tabParam === 'customers' ? 'customers' : (tabParam === 'roles' ? 'roles' : (tabParam === 'verifications' ? 'verifications' : 'accounts'));

            if ($('#roleSearchInput').length) {
                $('#roleSearchInput').on('input', function () {
                    var query = String($(this).val() || '').trim().toLowerCase();

                    $('.role-row').each(function () {
                        var text = $(this).text().toLowerCase();
                        $(this).toggle(text.indexOf(query) !== -1);
                    });
                });

                $(document).on('click', '.rp-btn-delete', function () {
                    var roleCode = $(this).data('role-code');
                    if (window.showAppError) {
                        window.showAppError('Chức năng xóa vai trò "' + roleCode + '" sẽ được nối sau.', 'Lưu ý');
                    }
                });
            }

            function createTable(config) {
                var table = $(config.tableSelector).DataTable({
                dom: 'rt<"row mb-3"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                pageLength: 20,
                searchDelay: 300,
                order: config.order || [[6, 'desc']],
                ajax: {
                    url: '{{ route('admin.users.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                            d.role_filter = $(config.filterSelector).val();
                            d.list_type = config.listType;
                            if (typeof config.extraData === 'function') {
                                config.extraData(d);
                            }
                    }
                },
                language: {
                    processing: 'Đang xử lý...',
                    search: 'Tìm kiếm:',
                    lengthMenu: '_MENU_ mục',
                    info: 'Hiển thị _START_ đến _END_ của _TOTAL_ mục',
                    infoEmpty: 'Hiển thị 0 đến 0 của 0 mục',
                    infoFiltered: '(lọc từ _MAX_ mục)',
                    loadingRecords: 'Đang tải...',
                    zeroRecords: 'Không tìm thấy dữ liệu phù hợp',
                    emptyTable: 'Chưa có dữ liệu',
                    paginate: {
                        first: 'Đầu',
                        previous: 'Trước',
                        next: 'Tiếp',
                        last: 'Cuối'
                    }
                },
                lengthMenu: [[20, 50, 100], [20, 50, 100]],
                columns: config.columns || [
                    { data: 'checkbox', orderable: false, searchable: false },
                    { 
                        data: 'name',
                        render: function(data, type, row) {
                            return '<div class="d-flex align-items-center gap-3">' +
                                       '<div class="avatar-wrapper">' + row.avatar + '</div>' +
                                       '<div class="fw-semibold">' + data + '</div>' +
                                   '</div>';
                        }
                    },
                    { data: 'phone' },
                    { 
                        data: 'role',
                        render: function(data, type, row) {
                            var text = $(data).text().trim().toLowerCase();
                            if (text === 'user') {
                                return '<span class="badge badge-role-user">User</span>';
                            } else if (text === 'admin_store') {
                                return '<span class="badge badge-role-admin-store">admin_store</span>';
                            } else if (text === 'admin') {
                                return '<span class="badge bg-danger text-white">Admin</span>';
                            } else if (text === 'sale') {
                                return '<span class="badge bg-warning text-dark">Sale</span>';
                            }
                            return data;
                        }
                    },
                    { data: 'gender' },
                    { data: 'created_at' },
                    { data: 'action', orderable: false, searchable: false },
                ],
                columnDefs: config.columnDefs || [
                    { targets: [0, 3, 4, 5, 6], className: 'text-center' },
                ],
            });

                function updateDeleteSelectedButton() {
                    var selectedCount = $(config.tableSelector + ' .user-row-checkbox:checked').length;
                    $(config.deleteSelector).prop('disabled', selectedCount === 0);
                }

                $(config.searchSelector).on('input', function () {
                    table.search(this.value).draw();
                });

                $(config.filterSelector).on('change', function () {
                    table.ajax.reload(null, false);
                });

                table.on('draw', function () {
                    $(config.selectAllSelector).prop('checked', false);
                    updateDeleteSelectedButton();
                });

                $(config.tableSelector).on('change', '.user-row-checkbox', function () {
                    updateDeleteSelectedButton();
                    var totalCheckboxes = $(config.tableSelector + ' .user-row-checkbox').length;
                    var checkedCheckboxes = $(config.tableSelector + ' .user-row-checkbox:checked').length;
                    $(config.selectAllSelector).prop('checked', totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes);
                });

                $(config.selectAllSelector).on('change', function () {
                    $(config.tableSelector + ' .user-row-checkbox').prop('checked', $(this).is(':checked'));
                    updateDeleteSelectedButton();
                });

                $(config.deleteSelector).on('click', function () {
                    var selectedIds = $(config.tableSelector + ' .user-row-checkbox:checked').map(function () {
                    return $(this).val();
                }).get();

                    if (!selectedIds.length) {
                        return;
                    }

                    var entityLabel = config.entityLabel || 'tài khoản';
                    if (!confirm('Xác nhận xóa ' + selectedIds.length + ' ' + entityLabel + ' đã chọn?')) {
                        return;
                    }

                    var button = $(this);
                    button.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Đang xóa...');

                    axios.post(config.deleteUrl || '{{ route('admin.users.destroyMultiple') }}', {
                        ids: selectedIds,
                    }, {
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    })
                    .then(function (response) {
                        table.ajax.reload(null, false);
                        $(config.selectAllSelector).prop('checked', false);
                        updateDeleteSelectedButton();
                        window.showAppSuccess(response.data.message || config.successMessage || 'Tài khoản đã được xóa.', 'Thành công');
                    })
                    .catch(function (error) {
                        console.error(error);
                        window.showAppError(config.errorMessage || 'Xóa tài khoản thất bại. Vui lòng thử lại.', 'Lỗi');
                    })
                    .finally(function () {
                        button.prop('disabled', false).html('<i class="fa-regular fa-trash-can"></i> <span>Xóa đã chọn</span>');
                    });
                });

                return table;
            }

            tables.accounts = createTable({
                listType: 'account',
                tableSelector: '#accountsTable',
                searchSelector: '#accountsSearch',
                filterSelector: '#accountsRoleFilter',
                selectAllSelector: '#selectAllAccounts',
                deleteSelector: '#deleteSelectedAccounts',
            });

            tables.customers = createTable({
                listType: 'customer',
                tableSelector: '#customersTable',
                searchSelector: '#customersSearch',
                filterSelector: '#customersRoleFilter',
                selectAllSelector: '#selectAllCustomers',
                deleteSelector: '#deleteSelectedCustomers',
                order: [[6, 'desc']],
                extraData: function(d) {
                    d.verification_filter = $('#customersVerificationFilter').val();
                },
                columns: [
                    { data: 'checkbox', orderable: false, searchable: false },
                    { 
                        data: 'name',
                        render: function(data, type, row) {
                            return '<div class="d-flex align-items-center gap-3">' +
                                       '<div class="avatar-wrapper">' + row.avatar + '</div>' +
                                       '<div class="fw-semibold">' + data + '</div>' +
                                   '</div>';
                        }
                    },
                    { data: 'phone' },
                    { 
                        data: 'role',
                        render: function(data, type, row) {
                            var text = $(data).text().trim().toLowerCase();
                            if (text === 'user' || text === 'account_user' || text === 'account user' || text === 'affiliate') {
                                return '<span class="badge badge-role-user">User</span>';
                            } else if (text === 'admin_store' || text === 'admin store' || text === 'admin_store_pending' || text === 'admin store pending') {
                                return '<span class="badge badge-role-admin-store">Admin_Store</span>';
                            }
                            return data;
                        }
                    },
                    { data: 'verification_status', orderable: false, searchable: false },
                    { data: 'gender' },
                    { data: 'created_at' },
                    { data: 'action', orderable: false, searchable: false },
                ],
                columnDefs: [
                    { targets: [0, 3, 4, 5, 6, 7], className: 'text-center' },
                ]
            });

            $('#customersVerificationFilter').on('change', function () {
                tables.customers.ajax.reload(null, false);
            });

            tables.verifications = createTable({
                listType: 'verification',
                tableSelector: '#verificationsTable',
                searchSelector: '#verificationsSearch',
                filterSelector: '#verificationsStatusFilter',
                selectAllSelector: '#selectAllVerifications',
                deleteSelector: '#deleteSelectedVerifications',
                deleteUrl: '{{ route('admin.user-verifications.destroyMultiple') }}',
                entityLabel: 'hồ sơ xác thực',
                successMessage: 'Hồ sơ xác thực đã được xóa. Tài khoản người dùng vẫn được giữ nguyên.',
                errorMessage: 'Xóa hồ sơ xác thực thất bại. Vui lòng thử lại.',
                order: [[6, 'desc']],
                columns: [
                    { data: 'checkbox', orderable: false, searchable: false },
                    { 
                        data: 'name',
                        render: function(data, type, row) {
                            return '<div class="d-flex align-items-center gap-3">' +
                                       '<div class="avatar-wrapper">' + row.avatar + '</div>' +
                                       '<div class="fw-semibold">' + data + '</div>' +
                                   '</div>';
                        }
                    },
                    { data: 'phone' },
                    { data: 'verified_name', orderable: false },
                    { data: 'id_number', orderable: false },
                    { data: 'status', orderable: false },
                    { data: 'submitted_at' },
                    { data: 'action', orderable: false, searchable: false },
                ],
                columnDefs: [
                    { targets: [0, 5, 6, 7], className: 'text-center' },
                ]
            });

            function setActiveTab(tab) {
                activeTab = tab === 'customers' ? 'customers' : (tab === 'roles' ? 'roles' : (tab === 'verifications' ? 'verifications' : 'accounts'));
                $('#accountsSection').toggleClass('d-none', activeTab !== 'accounts');
                $('#customersSection').toggleClass('d-none', activeTab !== 'customers');
                $('#verificationsSection').toggleClass('d-none', activeTab !== 'verifications');

                if (tables[activeTab] && tables[activeTab].columns) {
                    tables[activeTab].columns.adjust().draw(false);
                }
            }

            setActiveTab(activeTab);

            function openUserDetailModal(userId, initialTab, detailUrl, keepModalOpen) {
                var targetTab = initialTab || '#info';
                var requestUrl = detailUrl || ('/admin/users/' + userId + '/detail');

                $('#userDetailModal').data('user-id', userId);

                $('#userDetailContent').html('<p class="text-muted">Đang tải...</p>');

                if (!keepModalOpen) {
                    $('#userDetailModal').modal('show');
                }

                $.ajax({
                    url: requestUrl,
                    type: 'GET',
                    success: function(data) {
                        $('#userDetailContent').html(data);

                        if (targetTab) {
                            setTimeout(function() {
                                var $tab = $('#userDetailTabs a[href="' + targetTab + '"]');
                                if ($tab.length) {
                                    $tab.tab('show');
                                }
                            }, 10);
                        }
                    },
                    error: function() {
                        $('#userDetailContent').html(
                            '<p class="text-danger">Không thể tải dữ liệu khách hàng.</p>');
                    }
                });
            }

            $(document).on('click', '.user-detail-link', function (e) {
                e.preventDefault();
                var userId = $(this).data('id');
                openUserDetailModal(userId, '#info');
            });


            $(document).on('click', '.user-ledger-link', function (e) {
                e.preventDefault();
                var userId = $(this).data('id');
                openUserDetailModal(userId, '#ticket-ledger');
            });

            $(document).on('click', '#userDetailModal #ticket-ledger .pagination a', function (e) {
                e.preventDefault();

                var userId = $('#userDetailModal').data('user-id');
                var pageUrl = $(this).attr('href');

                if (!userId || !pageUrl) {
                    return;
                }

                openUserDetailModal(userId, '#ticket-ledger', pageUrl, true);
            });

            // AJAX pagination for Affiliate tab (F1/F2 networks)
            $(document).on('click', '#userDetailModal #affiliate .pagination a', function (e) {
                e.preventDefault();

                var userId = $('#userDetailModal').data('user-id');
                var pageUrl = $(this).attr('href');

                if (!userId || !pageUrl) {
                    return;
                }

                openUserDetailModal(userId, '#affiliate', pageUrl, true);
            });

            // AJAX pagination for Commission History tab
            $(document).on('click', '#userDetailModal #commission-history .pagination a', function (e) {
                e.preventDefault();

                var userId = $('#userDetailModal').data('user-id');
                var pageUrl = $(this).attr('href');

                if (!userId || !pageUrl) {
                    return;
                }

                openUserDetailModal(userId, '#commission-history', pageUrl, true);
            });

            // AJAX filter form submit for Commission History
            $(document).on('submit', '#userDetailModal #commissionFilterForm', function (e) {
                e.preventDefault();

                var userId = $('#userDetailModal').data('user-id');
                if (!userId) {
                    return;
                }

                var formData = $(this).serialize();
                var baseUrl = '/admin/users/' + userId + '/detail';
                var requestUrl = baseUrl + '?' + formData;

                openUserDetailModal(userId, '#commission-history', requestUrl, true);
            });

            // AJAX clear filter button for Commission History
            $(document).on('click', '#userDetailModal #clearCommissionFilter', function (e) {
                e.preventDefault();

                var userId = $('#userDetailModal').data('user-id');
                if (!userId) {
                    return;
                }

                openUserDetailModal(userId, '#commission-history', null, true);
            });

            // AJAX submission for Affiliate profile approval/rejection forms
            $(document).on('submit', '#userDetailModal .affiliate-action-form', function (e) {
                e.preventDefault();

                var form = $(this);
                var url = form.attr('action');
                var method = form.find('input[name="_method"]').val() || form.attr('method') || 'POST';
                var data = form.serialize();

                $.ajax({
                    url: url,
                    type: method,
                    data: data,
                    headers: {
                        'Accept': 'application/json'
                    },
                    success: function (response) {
                        alert(response.message || 'Đã cập nhật hồ sơ thành công.');
                        var userId = $('#userDetailModal').data('user-id');
                        openUserDetailModal(userId, '#affiliate', null, true);
                    },
                    error: function (xhr) {
                        var errorMsg = 'Có lỗi xảy ra, không thể thực hiện thao tác.';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        alert(errorMsg);
                    }
                });
            });
        });
    </script>
@endsection
