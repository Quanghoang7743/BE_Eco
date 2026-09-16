@extends('admin.layout')

@section('content')
    <!-- CSS Tùy chỉnh để đạt độ hoàn thiện cao giống Mockup -->
    <style>
        .profile-container {
            font-family: 'Public Sans', sans-serif;
            color: #3f4254;
        }
        .breadcrumb-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 25px;
        }
        .breadcrumb-container h2 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: #1e1e2d;
        }
        .custom-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #7e8299;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .custom-breadcrumb a {
            color: #7e8299;
            text-decoration: none;
            transition: color 0.2s;
        }
        .custom-breadcrumb a:hover {
            color: #0056b3;
        }
        .custom-breadcrumb .separator {
            color: #b5b5c3;
        }
        .custom-breadcrumb .active {
            color: #464e5f;
            font-weight: 500;
        }

        /* Profile Summary Card */
        .card-profile-summary {
            border: none;
            border-radius: 12px;
            box-shadow: 0 0 20px 0 rgba(76, 87, 125, 0.05);
            background: #ffffff;
            overflow: hidden;
        }
        .profile-banner {
            /* Đồng bộ hoàn toàn với màu nền tối của sidebar (#1a2035) */
            background: linear-gradient(135deg, #1a2035, #151a2d);
            height: 110px;
            position: relative;
        }
        .profile-avatar-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
            margin: -60px auto 0 auto;
            z-index: 2;
        }
        .profile-avatar-img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid #ffffff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            object-fit: cover;
        }
        .avatar-upload-trigger {
            position: absolute;
            bottom: 3px;
            right: 3px;
            width: 30px;
            height: 30px;
            /* Đồng bộ màu nút camera với sidebar */
            background-color: #1a2035;
            border: 2px solid #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            cursor: pointer;
            box-shadow: 0 2px 4px rgba(0,0,0,0.15);
            transition: background-color 0.2s;
        }
        .avatar-upload-trigger:hover {
            background-color: #151a2d;
        }
        
        .profile-info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .profile-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f3f3f5;
            font-size: 13.5px;
        }
        .profile-info-item:last-child {
            border-bottom: none;
        }
        .profile-info-label {
            color: #7e8299;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .profile-info-label i {
            color: #a1a5b7;
            font-size: 14px;
        }
        .profile-info-value {
            font-weight: 500;
            color: #3f4254;
            text-align: right;
        }
        
        /* Badges */
        .badge-role-user {
            background-color: #e8f1fd;
            color: #1f78d1;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
        }
        .badge-status-active {
            background-color: #e1fcef;
            color: #0bb783;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
        }

        /* Detail Cards */
        .profile-detail-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 0 20px 0 rgba(76, 87, 125, 0.05);
            background: #ffffff;
            margin-bottom: 25px;
        }
        .profile-detail-card .card-body {
            padding: 30px;
        }
        .section-title {
            font-size: 16px;
            font-weight: 700;
            margin-bottom: 25px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f1f4;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        /* Đổi tên class để tránh xung đột với class avatar của Bootstrap gây vỡ layout */
        .section-title.title-personal {
            color: #1880f9;
        }
        .section-title.title-avatar {
            color: #6f42c1;
        }
        .section-title.title-password {
            color: #28a745;
        }
        
        /* Inputs & Labels styling */
        .form-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #3f4254;
            margin-bottom: 6px;
        }
        .input-group-custom {
            display: flex;
            align-items: center;
            border: 1px solid #e4e6ef;
            border-radius: 8px;
            background-color: #f5f8fa;
            transition: border-color 0.2s, background-color 0.2s;
            overflow: hidden;
        }
        .input-group-custom:focus-within {
            border-color: #b5b5c3;
            background-color: #ffffff;
        }
        .input-group-custom .input-icon {
            padding: 10px 15px;
            color: #a1a5b7;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .input-group-custom .form-control, 
        .input-group-custom .form-select {
            border: none;
            background-color: transparent;
            padding: 10px 15px 10px 0;
            font-size: 14px;
            color: #3f4254;
            height: auto;
            box-shadow: none !important;
        }
        .input-group-custom .form-control:focus,
        .input-group-custom .form-select:focus {
            background-color: transparent;
        }
        
        /* Eye toggle for password */
        .password-toggle {
            padding: 10px 15px;
            color: #a1a5b7;
            cursor: pointer;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #3f4254;
        }

        /* Drag & drop upload area */
        .upload-dashed-box {
            border: 2px dashed #e4e6ef;
            border-radius: 8px;
            background-color: #f5f8fa;
            padding: 25px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background-color 0.2s;
            position: relative;
        }
        .upload-dashed-box:hover {
            border-color: #1880f9;
            background-color: #f1f8ff;
        }
        .upload-dashed-box i {
            font-size: 32px;
            color: #1880f9;
            margin-bottom: 12px;
        }
        .upload-dashed-box p {
            font-size: 13px;
            color: #5e6278;
            margin-bottom: 0;
        }
        .upload-dashed-box .btn-select-file {
            font-size: 12px;
            font-weight: 600;
            padding: 5px 12px;
            border-radius: 6px;
            pointer-events: none;
        }

        /* Current avatar preview box */
        .current-avatar-box {
            border: 1px solid #eff2f5;
            border-radius: 8px;
            background-color: #f9fafb;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            min-height: 110px;
        }
        .current-avatar-preview {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ffffff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        .current-avatar-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
            overflow: hidden;
        }
        .current-avatar-title {
            font-size: 13px;
            font-weight: 700;
            color: #1e1e2d;
        }
        .current-avatar-filename {
            font-size: 12px;
            color: #7e8299;
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
            max-width: 150px;
        }
        .btn-delete-avatar-custom {
            font-size: 11px;
            font-weight: 600;
            color: #f1416c;
            border: 1px solid #f1416c;
            background-color: #fff5f8;
            padding: 3px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            width: fit-content;
        }
        .btn-delete-avatar-custom:hover {
            color: #ffffff;
            background-color: #f1416c;
        }

        /* Save Button */
        .btn-save-profile {
            background-color: #1880f9;
            border-color: #1880f9;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        .btn-save-profile:hover {
            background-color: #116dd5;
            border-color: #116dd5;
            color: #ffffff;
        }
    </style>

    <div class="profile-container">
        <!-- Breadcrumb Header -->
        <div class="breadcrumb-container">
            <h2>Hồ sơ cá nhân</h2>
            <ul class="custom-breadcrumb">
                <li>
                    <a href="{{ route('admin') }}">
                        <i class="fas fa-home"></i> Trang chủ
                    </a>
                </li>
                <li class="separator">></li>
                <li class="active">Hồ sơ cá nhân</li>
            </ul>
        </div>

        <!-- Alerts -->
        @if (session('success'))
            <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert" style="border-radius: 8px; background-color: #e1fcef; color: #0bb783;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle me-2" style="font-size: 18px;"></i>
                    <div>{{ session('success') }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="color: #0bb783; opacity: 0.8;"></button>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" role="alert" style="border-radius: 8px; background-color: #fff5f8; color: #f1416c;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle me-2" style="font-size: 18px;"></i>
                    <div>Vui lòng kiểm tra lại và sửa các lỗi bên dưới.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close" style="color: #f1416c; opacity: 0.8;"></button>
            </div>
        @endif

        <form action="{{ route('admin.profile.update') }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            
            <!-- Trường ẩn để lưu trạng thái xóa ảnh -->
            <input type="hidden" name="delete_avatar" id="delete_avatar" value="0">

            <div class="row">
                <!-- Cột trái: Tóm tắt Profile (Tỷ lệ giống mockup) -->
                <div class="col-lg-4 mb-4">
                    <div class="card-profile-summary">
                        <!-- Banner nền trên -->
                        <div class="profile-banner"></div>
                        
                        <div class="card-body p-4">
                            <!-- Avatar tròn chồng lên banner -->
                            <div class="profile-avatar-wrapper">
                                @if ($user->avatar)
                                    <img id="avatar-preview-summary" src="{{ Str::startsWith($user->avatar, ['http://', 'https://']) ? $user->avatar : asset('storage/' . $user->avatar) }}"
                                        alt="Avatar" class="profile-avatar-img">
                                @else
                                    <img id="avatar-preview-summary" src="https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg"
                                        alt="Avatar" class="profile-avatar-img">
                                @endif
                                <label for="avatar_input" class="avatar-upload-trigger">
                                    <i class="fas fa-camera" style="font-size: 12px;"></i>
                                </label>
                            </div>

                            <div class="text-center mt-3 mb-4">
                                <h4 class="fw-bold mb-1" style="font-size: 18px; color: #1e1e2d;">{{ $user->full_name }}</h4>
                                <div class="text-muted mb-3" style="font-size: 13px;">{{ $user->email }}</div>
                                <span class="badge bg-light text-primary border border-primary px-3 py-1.5 rounded-pill fw-bold" style="font-size: 11.5px; letter-spacing: 0.2px;">System Admin</span>
                            </div>

                            <!-- List thông tin chi tiết -->
                            <ul class="profile-info-list pt-2 border-top">
                                <li class="profile-info-item">
                                    <span class="profile-info-label">
                                        <i class="far fa-user"></i> Tên đăng nhập
                                    </span>
                                    <span class="profile-info-value">{{ $user->name }}</span>
                                </li>
                                <li class="profile-info-item">
                                    <span class="profile-info-label">
                                        <i class="fas fa-user-tag"></i> Vai trò
                                    </span>
                                    <span class="profile-info-value">
                                        <span class="badge-role-user">Người dùng</span>
                                    </span>
                                </li>
                                <li class="profile-info-item">
                                    <span class="profile-info-label">
                                        <i class="fas fa-toggle-on"></i> Trạng thái
                                    </span>
                                    <span class="profile-info-value">
                                        <span class="badge-status-active">Active</span>
                                    </span>
                                </li>
                                <li class="profile-info-item">
                                    <span class="profile-info-label">
                                        <i class="far fa-calendar-alt"></i> Ngày tham gia
                                    </span>
                                    <span class="profile-info-value">{{ $user->created_at ? $user->created_at->format('d/m/Y') : '28/05/2026' }}</span>
                                </li>
                                <li class="profile-info-item">
                                    <span class="profile-info-label">
                                        <i class="fas fa-phone-alt"></i> Số điện thoại
                                    </span>
                                    <span class="profile-info-value">{{ $user->phone }}</span>
                                </li>
                                <li class="profile-info-item">
                                    <span class="profile-info-label">
                                        <i class="far fa-envelope"></i> Email
                                    </span>
                                    <span class="profile-info-value text-truncate" style="max-width: 160px;" title="{{ $user->email }}">{{ $user->email }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Cột phải: Form chi tiết & Đổi mật khẩu -->
                <div class="col-lg-8">
                    <!-- Section 1: Thông tin cá nhân -->
                    <div class="profile-detail-card">
                        <div class="card-body">
                            <!-- Đổi class title-personal để không bị xung đột -->
                            <h5 class="section-title title-personal">
                                <i class="fas fa-user-circle"></i> Thông tin cá nhân
                            </h5>
                            
                            <div class="row">
                                <!-- Họ và tên -->
                                <div class="col-md-6 mb-4">
                                    <label for="full_name" class="form-label">Họ và tên <span class="text-danger">*</span></label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="far fa-user"></i></span>
                                        <input type="text" class="form-control @error('full_name') is-invalid @enderror" id="full_name" name="full_name" value="{{ old('full_name', $user->full_name) }}" required>
                                    </div>
                                    @error('full_name')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Tên đăng nhập -->
                                <div class="col-md-6 mb-4">
                                    <label for="name" class="form-label">Tên đăng nhập <span class="text-danger">*</span></label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="far fa-user"></i></span>
                                        <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                                    </div>
                                    @error('name')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Email -->
                                <div class="col-md-6 mb-4">
                                    <label for="email" class="form-label">Email</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="far fa-envelope"></i></span>
                                        <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}">
                                    </div>
                                    @error('email')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Số điện thoại -->
                                <div class="col-md-6 mb-4">
                                    <label for="phone" class="form-label">Số điện thoại <span class="text-danger">*</span></label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="fas fa-phone-alt" style="font-size: 12px;"></i></span>
                                        <input type="text" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" required>
                                    </div>
                                    @error('phone')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Giới tính -->
                                <div class="col-md-6 mb-4">
                                    <label for="gender" class="form-label">Giới tính</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="fas fa-venus-mars" style="font-size: 12px;"></i></span>
                                        <select class="form-select @error('gender') is-invalid @enderror" id="gender" name="gender">
                                            <option value="">-- Chọn giới tính --</option>
                                            <option value="male" {{ old('gender', $user->gender) == 'male' ? 'selected' : '' }}>Nam</option>
                                            <option value="female" {{ old('gender', $user->gender) == 'female' ? 'selected' : '' }}>Nữ</option>
                                            <option value="other" {{ old('gender', $user->gender) == 'other' ? 'selected' : '' }}>Khác</option>
                                        </select>
                                    </div>
                                    @error('gender')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Ngày sinh -->
                                <div class="col-md-6 mb-4">
                                    <label for="birthday" class="form-label">Ngày sinh</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="far fa-calendar-alt"></i></span>
                                        <input type="date" class="form-control @error('birthday') is-invalid @enderror" id="birthday" name="birthday" value="{{ old('birthday', $user->birthday ? $user->birthday->format('Y-m-d') : '') }}">
                                    </div>
                                    @error('birthday')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Địa chỉ -->
                                <div class="col-md-12 mb-2">
                                    <label for="address" class="form-label">Địa chỉ</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="fas fa-map-marker-alt" style="font-size: 13px;"></i></span>
                                        <input type="text" class="form-control @error('address') is-invalid @enderror" id="address" name="address" value="{{ old('address', $user->address) }}" placeholder="Nhập địa chỉ">
                                    </div>
                                    @error('address')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Thay đổi ảnh đại diện -->
                    <div class="profile-detail-card">
                        <div class="card-body">
                            <!-- Đổi class title-avatar để tránh xung đột với class avatar của Bootstrap gây vỡ layout -->
                            <h5 class="section-title title-avatar">
                                <i class="far fa-image"></i> Thay đổi ảnh đại diện
                            </h5>
                            
                            <div class="row align-items-center">
                                <!-- Hộp kéo thả -->
                                <div class="col-md-7 mb-3 mb-md-0">
                                    <div class="upload-dashed-box" id="upload-box-container">
                                        <!-- Viết style opacity: 0 tường minh để ẩn hoàn toàn text chọn tệp mặc định của trình duyệt -->
                                        <input type="file" name="avatar" id="avatar_input" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 5;" accept="image/*">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <p>Kéo & thả ảnh vào đây hoặc <span class="btn btn-outline-primary btn-select-file ms-2">Chọn tệp</span></p>
                                    </div>
                                    <div class="form-text text-muted small mt-2" style="font-size: 12px;">Định dạng: JPG, PNG, GIF. Kích thước tối đa 2MB.</div>
                                </div>

                                <!-- Preview ảnh hiện tại -->
                                <div class="col-md-5">
                                    <div class="current-avatar-box">
                                        @if ($user->avatar)
                                            <img id="avatar-preview-upload" src="{{ Str::startsWith($user->avatar, ['http://', 'https://']) ? $user->avatar : asset('storage/' . $user->avatar) }}"
                                                alt="Avatar" class="current-avatar-preview">
                                        @else
                                            <img id="avatar-preview-upload" src="https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg"
                                                alt="Avatar" class="current-avatar-preview">
                                        @endif
                                        <div class="current-avatar-info">
                                            <div class="current-avatar-title">Ảnh hiện tại</div>
                                            <div class="current-avatar-filename text-muted" id="avatar-filename">
                                                {{ $user->avatar ? basename($user->avatar) : 'avatar-default.png' }}
                                            </div>
                                            @if ($user->avatar)
                                                <button type="button" class="btn-delete-avatar-custom" id="btn-delete-avatar">
                                                    Xóa ảnh
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Đổi mật khẩu -->
                    <div class="profile-detail-card">
                        <div class="card-body">
                            <!-- Đổi class title-password để không bị xung đột -->
                            <h5 class="section-title title-password">
                                <i class="fas fa-shield-alt"></i> Đổi mật khẩu
                            </h5>
                            
                            <div class="row">
                                <!-- Mật khẩu hiện tại -->
                                <div class="col-md-12 mb-4">
                                    <label for="current_password" class="form-label">Mật khẩu hiện tại</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" placeholder="Nhập mật khẩu hiện tại">
                                        <span class="password-toggle"><i class="far fa-eye-slash"></i></span>
                                    </div>
                                    @error('current_password')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Mật khẩu mới -->
                                <div class="col-md-6 mb-4">
                                    <label for="new_password" class="form-label">Mật khẩu mới</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control @error('new_password') is-invalid @enderror" id="new_password" name="new_password" placeholder="Nhập mật khẩu mới">
                                        <span class="password-toggle"><i class="far fa-eye-slash"></i></span>
                                    </div>
                                    <div class="form-text text-muted small mt-1" style="font-size: 11.5px;">Mật khẩu tối thiểu 6 ký tự.</div>
                                    @error('new_password')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>

                                <!-- Xác nhận mật khẩu mới -->
                                <div class="col-md-6 mb-4">
                                    <label for="new_password_confirmation" class="form-label">Xác nhận mật khẩu mới</label>
                                    <div class="input-group-custom">
                                        <span class="input-icon"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control" id="new_password_confirmation" name="new_password_confirmation" placeholder="Xác nhận mật khẩu mới">
                                        <span class="password-toggle"><i class="far fa-eye-slash"></i></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Nút Lưu thay đổi (đặt ở góc phải bên trong Card giống Mockup) -->
                            <div class="d-flex justify-content-end mt-2">
                                <button type="submit" class="btn-save-profile">
                                    <i class="fas fa-save" style="font-size: 13px;"></i> Lưu thay đổi
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- JS Tương tác & Preview động -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const avatarInput = document.getElementById('avatar_input');
            const avatarPreviewSummary = document.getElementById('avatar-preview-summary');
            const avatarPreviewUpload = document.getElementById('avatar-preview-upload');
            const avatarFilename = document.getElementById('avatar-filename');
            const deleteAvatarInput = document.getElementById('delete_avatar');
            const btnDeleteAvatar = document.getElementById('btn-delete-avatar');
            const uploadBoxContainer = document.getElementById('upload-box-container');

            const defaultAvatarUrl = 'https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg';

            // Preview ảnh khi chọn file mới
            if (avatarInput) {
                avatarInput.addEventListener('change', function () {
                    const file = this.files[0];
                    if (file) {
                        // Kiểm tra dung lượng (2MB)
                        if (file.size > 2 * 1024 * 1024) {
                            alert('Kích thước ảnh tối đa 2MB. Vui lòng chọn ảnh khác.');
                            this.value = '';
                            return;
                        }
                        
                        const reader = new FileReader();
                        reader.onload = function (e) {
                            if (avatarPreviewSummary) avatarPreviewSummary.src = e.target.result;
                            if (avatarPreviewUpload) avatarPreviewUpload.src = e.target.result;
                        };
                        reader.readAsDataURL(file);
                        
                        if (avatarFilename) avatarFilename.textContent = file.name;
                        if (deleteAvatarInput) deleteAvatarInput.value = '0';
                        
                        // Đảm bảo hiển thị nút xóa nếu có ảnh mới
                        if (!document.getElementById('btn-delete-avatar') && btnDeleteAvatar) {
                            btnDeleteAvatar.style.display = 'block';
                        }
                    }
                });
            }

            // Xử lý nút xóa ảnh hiện tại
            if (btnDeleteAvatar) {
                btnDeleteAvatar.addEventListener('click', function () {
                    if (confirm('Bạn có chắc chắn muốn xóa ảnh đại diện hiện tại?')) {
                        if (avatarPreviewSummary) avatarPreviewSummary.src = defaultAvatarUrl;
                        if (avatarPreviewUpload) avatarPreviewUpload.src = defaultAvatarUrl;
                        if (avatarFilename) avatarFilename.textContent = 'avatar-default.png';
                        if (deleteAvatarInput) deleteAvatarInput.value = '1';
                        if (avatarInput) avatarInput.value = ''; // Xóa file đang chọn trong input
                        this.style.display = 'none'; // Ẩn nút xóa ảnh
                    }
                });
            }

            // Kéo thả file
            if (uploadBoxContainer && avatarInput) {
                ['dragenter', 'dragover'].forEach(eventName => {
                    uploadBoxContainer.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        uploadBoxContainer.style.borderColor = '#1880f9';
                        uploadBoxContainer.style.backgroundColor = '#f1f8ff';
                    }, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    uploadBoxContainer.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        uploadBoxContainer.style.borderColor = '#e4e6ef';
                        uploadBoxContainer.style.backgroundColor = '#f5f8fa';
                    }, false);
                });

                uploadBoxContainer.addEventListener('drop', (e) => {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    if (files.length > 0) {
                        avatarInput.files = files;
                        // Kích hoạt sự kiện change của file input
                        const event = new Event('change');
                        avatarInput.dispatchEvent(event);
                    }
                }, false);
            }

            // Toggle ẩn hiện mật khẩu (Eye icon)
            const passwordToggles = document.querySelectorAll('.password-toggle');
            passwordToggles.forEach(toggle => {
                toggle.addEventListener('click', function () {
                    const input = this.closest('.input-group-custom').querySelector('input');
                    const icon = this.querySelector('i');
                    
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    } else {
                        input.type = 'password';
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                });
            });
        });
    </script>
@endsection
