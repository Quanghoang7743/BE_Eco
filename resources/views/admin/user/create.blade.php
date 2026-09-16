@extends('admin.layout')

@section('content')
    @php
        $defaultRole = old('role', request('role', 'user'));
        $defaultCmsRole = old('cms_role', $defaultRole === 'admin' ? 'admin' : '');
    @endphp
    <h1 class="mb-4">Thêm mới người dùng</h1>

    <form action="{{ route('admin.users.store') }}" method="post" enctype="multipart/form-data">
        @csrf
        <div class="row">
            <div class="col-md-6">
                <!-- Họ tên -->
                <div class="mb-3">
                    <label for="name" class="form-label">Tên đăng nhập</label>
                    <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                        value="{{ old('name') }}">
                    @error('name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Họ và tên đầy đủ -->
                <div class="mb-3">
                    <label for="full_name" class="form-label">Họ và tên</label>
                    <input type="text" class="form-control @error('full_name') is-invalid @enderror" id="full_name"
                        name="full_name" value="{{ old('full_name') }}">
                    @error('full_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Email -->
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email"
                        value="{{ old('email') }}">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Password -->
                <div class="mb-3">
                    <label for="password" class="form-label">Mật khẩu</label>
                    <input type="password" class="form-control @error('password') is-invalid @enderror" id="password"
                        name="password">
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Zalo ID -->
                {{-- <div class="mb-3">
                    <label for="zalo_id" class="form-label">Zalo ID</label>
                    <input type="text" class="form-control @error('zalo_id') is-invalid @enderror" id="zalo_id"
                        name="zalo_id" value="{{ old('zalo_id') }}">
                    @error('zalo_id')
                    <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div> --}}

                <!-- Số điện thoại -->
                <div class="mb-3">
                    <label for="phone" class="form-label">Số điện thoại</label>
                    <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" required
                        name="phone" value="{{ old('phone') }}">
                    @error('phone')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-md-6">
                <!-- Giới tính -->
                <div class="mb-3">
                    <label for="gender" class="form-label">Giới tính</label>
                    <select name="gender" id="gender" class="form-select @error('gender') is-invalid @enderror">
                        <option value="">-- Chọn giới tính --</option>
                        <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>Nam</option>
                        <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>Nữ</option>
                        <option value="other" {{ old('gender') == 'other' ? 'selected' : '' }}>Khác</option>
                    </select>
                    @error('gender')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Ngày sinh -->
                <div class="mb-3">
                    <label for="birthday" class="form-label">Ngày sinh</label>
                    <input type="date" class="form-control @error('birthday') is-invalid @enderror" id="birthday"
                        name="birthday" value="{{ old('birthday') }}">
                    @error('birthday')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Địa chỉ -->
                <div class="mb-3">
                    <label for="address" class="form-label">Địa chỉ</label>
                    <input type="text" class="form-control @error('address') is-invalid @enderror" id="address"
                        name="address" value="{{ old('address') }}">
                    @error('address')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Số dư -->
                <div class="mb-3">
                    <label for="balance" class="form-label">Số dư tài khoản</label>
                    <input type="number" class="form-control @error('balance') is-invalid @enderror" id="balance"
                        name="balance" value="{{ old('balance', 0) }}">
                    @error('balance')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Role -->
                <div class="mb-3">
                    <label for="role" class="form-label">Vai trò</label>
                    <select name="role" id="role" class="form-select @error('role') is-invalid @enderror">
                        <option value="user" {{ $defaultRole == 'user' ? 'selected' : '' }}>Người dùng</option>
                        <option value="sale" {{ $defaultRole == 'sale' ? 'selected' : '' }}>Chuyên viên cửa hàng</option>
                        <option value="admin" {{ $defaultRole == 'admin' ? 'selected' : '' }}>Quản trị viên</option>
                    </select>
                    @error('role')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3 {{ $defaultRole === 'admin' ? '' : 'd-none' }}" id="cms-role-wrapper">
                    <label for="cms_role" class="form-label">Vai trò CMS</label>
                    @if(($spatieRoles ?? collect())->isEmpty())
                        <div class="alert alert-warning mb-0">
                            Chưa có vai trò CMS. Vui lòng tạo vai trò ở trang Vai trò & Phân quyền trước.
                        </div>
                    @else
                        <select name="cms_role" id="cms_role" class="form-select @error('cms_role') is-invalid @enderror" {{ $defaultRole === 'admin' ? '' : 'disabled' }}>
                            @foreach($spatieRoles as $role)
                                @php
                                    $roleLabel = $role->display_name ?: \Illuminate\Support\Str::headline(str_replace('_', ' ', $role->name));
                                @endphp
                                <option value="{{ $role->name }}" {{ $defaultCmsRole === $role->name ? 'selected' : '' }}>
                                    {{ $roleLabel }} ({{ $role->name }})
                                </option>
                            @endforeach
                        </select>
                        @error('cms_role')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Mỗi tài khoản quản trị chỉ chọn một vai trò.</div>
                    @endif
                </div>

                <div class="mb-3">
                    <label for="status" class="form-label">Trang thai tai khoan</label>
                    <select name="status" id="status" class="form-select @error('status') is-invalid @enderror">
                        <option value="active" {{ old('status', 'active') === 'active' ? 'selected' : '' }}>Dang hoat dong
                        </option>
                        <option value="locked" {{ old('status') === 'locked' ? 'selected' : '' }}>Da khoa</option>
                        <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Tam dung</option>
                    </select>
                    @error('status')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Avatar -->
                <div class="mb-3">
                    <label for="avatar" class="form-label">Ảnh đại diện</label>
                    <input type="file" class="form-control @error('avatar') is-invalid @enderror" id="avatar" name="avatar">
                    @error('avatar')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
        @include('admin.user._specialist_documents')

        <button type="submit" class="btn btn-success">Lưu người dùng</button>
        <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">Quay lại</a>

    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const roleSelect = document.getElementById('role');
            const cmsRoleWrapper = document.getElementById('cms-role-wrapper');
            const cmsRoleSelect = document.getElementById('cms_role');
            const specialistDocumentsWrapper = document.getElementById('specialist-documents-wrapper');

            function toggleCmsRole() {
                const isAdmin = roleSelect && roleSelect.value === 'admin';

                if (cmsRoleWrapper) {
                    cmsRoleWrapper.classList.toggle('d-none', !isAdmin);
                }

                if (cmsRoleSelect) {
                    cmsRoleSelect.disabled = !isAdmin;

                    if (isAdmin && !cmsRoleSelect.value) {
                        const adminOption = cmsRoleSelect.querySelector('option[value="admin"]');
                        cmsRoleSelect.value = adminOption ? 'admin' : '';
                    }
                }

                const isSale = roleSelect && roleSelect.value === 'sale';
                if (specialistDocumentsWrapper) {
                    specialistDocumentsWrapper.classList.toggle('d-none', !isSale);
                    specialistDocumentsWrapper.querySelectorAll('input').forEach(function (input) {
                        input.disabled = !isSale;
                    });
                }
            }

            if (roleSelect) {
                roleSelect.addEventListener('change', toggleCmsRole);
                toggleCmsRole();
            }
        });
    </script>
@endsection