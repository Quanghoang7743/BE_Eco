@extends('admin.layout')

@section('content')
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-md-12">
            <h1 class="h3">Cài Đặt Thanh Toán VietQR</h1>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Thông Tin Tài Khoản Ngân Hàng</h5>
                </div>
                <form method="POST" action="{{ route('admin.payment-settings.update') }}" class="card-body">
                    @csrf
                    @method('PUT')

                    <div class="mb-4">
                        <h6 class="text-uppercase fw-bold text-muted mb-3">VietQR Configuration</h6>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Client ID <span class="text-danger">*</span></label>
                            <input type="text" name="vietqr_client_id" class="form-control @error('vietqr_client_id') is-invalid @enderror"
                                   value="{{ old('vietqr_client_id', $setting->vietqr_client_id) }}" 
                                   placeholder="VietQR Client ID">
                            @error('vietqr_client_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">API Key <span class="text-danger">*</span></label>
                            <input type="password" name="vietqr_api_key" class="form-control @error('vietqr_api_key') is-invalid @enderror"
                                   value="{{ old('vietqr_api_key', $setting->vietqr_api_key) }}"
                                   placeholder="VietQR API Key">
                            @error('vietqr_api_key')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="text-muted">Để bảo mật, hãy không chia sẻ API key này với bất kỳ ai</small>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-4">
                        <h6 class="text-uppercase fw-bold text-muted mb-3">Thông Tin Tài Khoản Ngân Hàng</h6>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Chọn Ngân Hàng <span class="text-danger">*</span></label>
                            <select name="vietqr_bank_id" class="form-select @error('vietqr_bank_id') is-invalid @enderror">
                                <option value="">-- Chọn Ngân Hàng --</option>
                                @foreach($banks as $code => $name)
                                    <option value="{{ $code }}" 
                                            {{ old('vietqr_bank_id', $setting->vietqr_bank_id) === $code ? 'selected' : '' }}>
                                        {{ $name }} ({{ $code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('vietqr_bank_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Số Tài Khoản <span class="text-danger">*</span></label>
                            <input type="text" name="vietqr_account_no" class="form-control @error('vietqr_account_no') is-invalid @enderror"
                                   value="{{ old('vietqr_account_no', $setting->vietqr_account_no) }}"
                                   placeholder="Ví dụ: 1234567890">
                            @error('vietqr_account_no')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Chủ Tài Khoản <span class="text-danger">*</span></label>
                            <input type="text" name="vietqr_account_name" class="form-control @error('vietqr_account_name') is-invalid @enderror"
                                   value="{{ old('vietqr_account_name', $setting->vietqr_account_name) }}"
                                   placeholder="Ví dụ: LE DINH THANG">
                            @error('vietqr_account_name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <small class="text-muted">Tên sẽ hiển thị trên mã QR thanh toán</small>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            Lưu Cài Đặt
                        </button>
                        <a href="{{ route('admin.app-setting.index') }}" class="btn btn-secondary">
                            Quay Lại
                        </a>
                    </div>
                </form>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">ℹ️ Hướng Dẫn VietQR</h5>
                </div>
                <div class="card-body">
                    <ol>
                        <li>Đăng ký tài khoản trên <a href="https://my.vietqr.io/login" target="_blank">https://my.vietqr.io/login</a></li>
                        <li>Lấy Client ID và API Key từ trang cài đặt VietQR</li>
                        <li>Chọn ngân hàng và nhập thông tin tài khoản của bạn</li>
                        <li>Lưu cài đặt để bắt đầu nhận thanh toán qua mã QR</li>
                    </ol>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Trạng Thái Kết Nối</h5>
                </div>
                <div class="card-body">
                    @if($setting->vietqr_client_id && $setting->vietqr_api_key && $setting->vietqr_bank_id && $setting->vietqr_account_no)
                        <div class="alert alert-success mb-0">
                            <strong>✓ Đã Cấu Hình</strong><br>
                            <small>VietQR đã được cấu hình hoàn chỉnh và sẵn sàng sử dụng</small>
                        </div>
                    @else
                        <div class="alert alert-warning mb-0">
                            <strong>⚠ Chưa Hoàn Chỉnh</strong><br>
                            <small>Vui lòng cấu hình đầy đủ thông tin VietQR</small>
                        </div>
                    @endif

                    <hr>

                    <h6 class="fw-bold mt-3">Thông Tin Hiện Tại</h6>
                    <small class="text-muted d-block">
                        <strong>Ngân Hàng:</strong> {{ $setting->vietqr_bank_id ?? 'Chưa cấu hình' }}<br>
                        <strong>Tài Khoản:</strong> {{ $setting->vietqr_account_no ?? 'Chưa cấu hình' }}<br>
                        <strong>Tên Chủ TK:</strong> {{ $setting->vietqr_account_name ?? 'Chưa cấu hình' }}
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
