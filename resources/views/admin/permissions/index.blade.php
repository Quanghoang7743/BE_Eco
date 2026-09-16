@extends('admin.layout')

@section('title', 'Phân quyền')

@section('content')
    <style>
        .permission-code {
            color: #6c757d;
            display: block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
            font-size: 11px;
            margin-top: 2px;
            word-break: break-word;
        }

        .permission-group {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 14px;
            height: 100%;
        }

        .permission-group-title {
            align-items: center;
            display: flex;
            font-size: 13px;
            font-weight: 700;
            gap: 8px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .permission-checkbox-list {
            display: grid;
            gap: 10px;
        }

        .role-permission-grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .user-role-select {
            min-height: 90px;
            min-width: 260px;
        }

        @media (max-width: 1199.98px) {
            .role-permission-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767.98px) {
            .role-permission-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="page-inner">
        <div class="page-header">
            <h3 class="fw-bold mb-3">Phân quyền</h3>
            <ul class="breadcrumbs mb-3">
                <li class="nav-home">
                    <a href="{{ route('admin') }}">
                        <i class="icon-home"></i>
                    </a>
                </li>
                <li class="separator">
                    <i class="icon-arrow-right"></i>
                </li>
                <li class="nav-item">
                    <a href="{{ route('admin.users.index') }}">Tài khoản</a>
                </li>
                <li class="separator">
                    <i class="icon-arrow-right"></i>
                </li>
                <li class="nav-item">
                    <span>Phân quyền</span>
                </li>
            </ul>
        </div>

        @if(session('success'))
            <div class="alert alert-success" role="alert">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @if($setupRequired ?? false)
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Cần chạy migration Spatie</div>
                </div>
                <div class="card-body">
                    <p>Các bảng phân quyền mới chưa có nên trang tạm dừng để tránh lỗi dữ liệu.</p>
                    <pre class="bg-light border rounded p-3 mb-3"><code>php artisan migrate</code></pre>
                    <p class="mb-0">Bảng đang thiếu: <code>{{ implode(', ', $missingTables ?? []) }}</code></p>
                </div>
            </div>
        @else
            <div class="row">
                <div class="col-sm-6 col-md-3">
                    <div class="card card-stats card-round">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-icon">
                                    <div class="icon-big text-center icon-primary bubble-shadow-small">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                </div>
                                <div class="col col-stats ms-3 ms-sm-0">
                                    <div class="numbers">
                                        <p class="card-category">Vai trò</p>
                                        <h4 class="card-title">{{ $stats['roles'] }}</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="card card-stats card-round">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-icon">
                                    <div class="icon-big text-center icon-info bubble-shadow-small">
                                        <i class="fas fa-key"></i>
                                    </div>
                                </div>
                                <div class="col col-stats ms-3 ms-sm-0">
                                    <div class="numbers">
                                        <p class="card-category">Quyền</p>
                                        <h4 class="card-title">{{ $stats['permissions'] }}</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="card card-stats card-round">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-icon">
                                    <div class="icon-big text-center icon-success bubble-shadow-small">
                                        <i class="fas fa-users"></i>
                                    </div>
                                </div>
                                <div class="col col-stats ms-3 ms-sm-0">
                                    <div class="numbers">
                                        <p class="card-category">Tài khoản đã gán</p>
                                        <h4 class="card-title">{{ $stats['users'] }}</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-md-3">
                    <div class="card card-stats card-round">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-icon">
                                    <div class="icon-big text-center icon-secondary bubble-shadow-small">
                                        <i class="fas fa-link"></i>
                                    </div>
                                </div>
                                <div class="col col-stats ms-3 ms-sm-0">
                                    <div class="numbers">
                                        <p class="card-category">Lượt gán quyền</p>
                                        <h4 class="card-title">{{ $stats['assigned'] }}</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="permissionTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="roles-tab" data-bs-toggle="tab" data-bs-target="#roles-pane" type="button" role="tab">
                                Vai trò & quyền
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="create-role-tab" data-bs-toggle="tab" data-bs-target="#create-role-pane" type="button" role="tab">
                                Tạo vai trò
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="permissionTabsContent">
                        <div class="tab-pane fade show active" id="roles-pane" role="tabpanel" aria-labelledby="roles-tab" tabindex="0">
                            <div class="accordion" id="rolesAccordion">
                                @foreach($roles as $role)
                                    @php
                                        $definition = $roleDefinitions[$role->name] ?? null;
                                        $selected = $role->permissions->pluck('name')->all();
                                        $collapseId = 'role-collapse-' . \Illuminate\Support\Str::slug($role->name);
                                        $headingId = 'role-heading-' . \Illuminate\Support\Str::slug($role->name);
                                    @endphp

                                    <div class="accordion-item" id="role-{{ $role->name }}">
                                        <h2 class="accordion-header" id="{{ $headingId }}">
                                            <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}" aria-expanded="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
                                                <span class="fw-bold me-2">{{ $role->display_name ?: ($definition['label'] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $role->name))) }}</span>
                                                <span class="badge bg-light text-dark border me-2">{{ $role->name }}</span>
                                                <span class="badge bg-primary me-2">{{ count($selected) }} quyền</span>
                                                <span class="badge bg-secondary">{{ $role->users_count }} tài khoản</span>
                                            </button>
                                        </h2>
                                        <div id="{{ $collapseId }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" aria-labelledby="{{ $headingId }}" data-bs-parent="#rolesAccordion">
                                            <div class="accordion-body">
                                                <p class="text-muted mb-3">
                                                    {{ $role->description ?: ($definition['description'] ?? 'Vai trò tùy chỉnh.') }}
                                                </p>

                                                <form method="POST" action="{{ route('admin.permissions.roles.update', ['role' => $role->name]) }}">
                                                    @csrf
                                                    @method('PUT')

                                                    <div class="role-permission-grid">
                                                        @foreach($permissionGroups as $group)
                                                            <div class="permission-group">
                                                                <div class="permission-group-title justify-content-between">
                                                                    <span>
                                                                        <i class="fas fa-{{ $group['icon'] }}"></i>
                                                                        <span>{{ $group['label'] }}</span>
                                                                    </span>
                                                                    <label class="form-check mb-0 small text-capitalize">
                                                                        <input class="form-check-input permission-group-check" type="checkbox">
                                                                        <span class="form-check-label">Chọn tất cả</span>
                                                                    </label>
                                                                </div>
                                                                <div class="permission-checkbox-list">
                                                                    @foreach($group['permissions'] as $permission)
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $permission['name'] }}" id="perm-{{ $role->id }}-{{ $permission['name'] }}" @checked(in_array($permission['name'], old('permissions', $selected), true))>
                                                                            <label class="form-check-label" for="perm-{{ $role->id }}-{{ $permission['name'] }}">
                                                                                {{ $permission['label'] }}
                                                                                <span class="permission-code">{{ $permission['name'] }}</span>
                                                                            </label>
                                                                        </div>
                                                                    @endforeach
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>

                                                    <div class="d-flex flex-wrap justify-content-between gap-2 mt-4">
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-check-all="#role-{{ $role->name }}">
                                                                Chọn tất cả
                                                            </button>
                                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-clear-all="#role-{{ $role->name }}">
                                                                Bỏ chọn
                                                            </button>
                                                        </div>
                                                        <button type="submit" class="btn btn-primary">
                                                            <i class="fas fa-save me-1"></i> Lưu quyền
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="tab-pane fade" id="create-role-pane" role="tabpanel" aria-labelledby="create-role-tab" tabindex="0">
                            <form method="POST" action="{{ route('admin.permissions.roles.store') }}" class="row g-3">
                                @csrf
                                <div class="col-md-6">
                                    <label for="role-name" class="form-label">Mã vai trò</label>
                                    <input id="role-name" class="form-control" name="name" value="{{ old('name') }}" placeholder="support_lead">
                                    <div class="form-text">Chỉ dùng chữ cái, số, dấu gạch ngang và gạch dưới.</div>
                                </div>
                                <div class="col-md-6">
                                    <label for="role-display-name" class="form-label">Tên hiển thị</label>
                                    <input id="role-display-name" class="form-control" name="display_name" value="{{ old('display_name') }}" placeholder="Trưởng nhóm hỗ trợ">
                                </div>
                                <div class="col-12">
                                    <label for="role-description" class="form-label">Mô tả</label>
                                    <textarea id="role-description" class="form-control" name="description" rows="3" placeholder="Mô tả nhanh nhiệm vụ và giới hạn của vai trò">{{ old('description') }}</textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i> Tạo vai trò
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@section('scripts')
    <script>
        document.querySelectorAll('[data-check-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll(button.dataset.checkAll + ' input[type="checkbox"]').forEach(function (input) {
                    input.checked = true;
                    input.indeterminate = false;
                });
            });
        });

        document.querySelectorAll('[data-clear-all]').forEach(function (button) {
            button.addEventListener('click', function () {
                document.querySelectorAll(button.dataset.clearAll + ' input[type="checkbox"]').forEach(function (input) {
                    input.checked = false;
                    input.indeterminate = false;
                });
            });
        });

        document.querySelectorAll('.permission-group').forEach(function (group) {
            var groupCheck = group.querySelector('.permission-group-check');
            var permissionChecks = Array.prototype.slice.call(group.querySelectorAll('input[name="permissions[]"]'));

            if (!groupCheck || permissionChecks.length === 0) {
                return;
            }

            function syncGroupCheck() {
                var checkedCount = permissionChecks.filter(function (input) {
                    return input.checked;
                }).length;

                groupCheck.checked = checkedCount === permissionChecks.length;
                groupCheck.indeterminate = checkedCount > 0 && checkedCount < permissionChecks.length;
            }

            groupCheck.addEventListener('change', function () {
                permissionChecks.forEach(function (input) {
                    input.checked = groupCheck.checked;
                });
                groupCheck.indeterminate = false;
            });

            permissionChecks.forEach(function (input) {
                input.addEventListener('change', syncGroupCheck);
            });

            syncGroupCheck();
        });
    </script>
@endsection
