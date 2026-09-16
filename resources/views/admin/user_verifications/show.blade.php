@extends('admin.layout')

@section('content')
    @php
        $badgeClasses = [
            'pending' => 'bg-warning text-dark',
            'approved' => 'bg-success',
            'rejected' => 'bg-danger',
        ];
        $imageUrl = function (?string $value): string {
            $path = trim((string) $value);
            if ($path === '') {
                return '';
            }
            if (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://'])) {
                return $path;
            }
            if (\Illuminate\Support\Str::startsWith($path, ['/storage/', 'storage/'])) {
                return asset(ltrim($path, '/'));
            }
            return asset('storage/' . ltrim($path, '/'));
        };
        $user = $verification->user;
    @endphp

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Chi tiết hồ sơ xác thực</h2>
                <div class="text-muted">Đối chiếu giấy tờ, selfie và thông tin người dùng khai báo.</div>
            </div>
            <a href="{{ route('admin.user-verifications.index') }}" class="btn btn-outline-secondary">
                Quay lại danh sách
            </a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">Có lỗi xảy ra:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Thông tin tài khoản</div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-5">User ID</dt>
                            <dd class="col-7">{{ $verification->user_id }}</dd>
                            <dt class="col-5">Tên tài khoản</dt>
                            <dd class="col-7">{{ $user?->full_name ?: ($user?->name ?: '—') }}</dd>
                            <dt class="col-5">Điện thoại</dt>
                            <dd class="col-7">{{ $user?->phone ?? '—' }}</dd>
                            <dt class="col-5">Email</dt>
                            <dd class="col-7">{{ $user?->email ?? '—' }}</dd>
                            <dt class="col-5">Trạng thái</dt>
                            <dd class="col-7">
                                <span class="badge {{ $badgeClasses[$verification->status] ?? 'bg-secondary' }}">
                                    {{ $statusLabels[$verification->status] ?? strtoupper($verification->status) }}
                                </span>
                            </dd>
                            <dt class="col-5">Ngày nộp</dt>
                            <dd class="col-7">{{ optional($verification->submitted_at ?? $verification->created_at)->format('d/m/Y H:i') }}</dd>
                            <dt class="col-5">Người duyệt</dt>
                            <dd class="col-7">
                                @if ($verification->reviewer)
                                    {{ $verification->reviewer->full_name ?: $verification->reviewer->name }}
                                    <div class="text-muted">{{ optional($verification->reviewed_at)->format('d/m/Y H:i') }}</div>
                                @else
                                    —
                                @endif
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header fw-semibold">Thông tin khai báo</div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-md-3">Họ và tên</dt>
                            <dd class="col-md-9">{{ $verification->full_name }}</dd>
                            <dt class="col-md-3">Số CCCD/CMND</dt>
                            <dd class="col-md-9">{{ $verification->id_number }}</dd>
                            <dt class="col-md-3">Ngày sinh</dt>
                            <dd class="col-md-9">{{ optional($verification->birth_date)->format('d/m/Y') }}</dd>
                            <dt class="col-md-3">Địa chỉ thường trú</dt>
                            <dd class="col-md-9">{{ $verification->address }}</dd>
                            @if ($verification->rejection_reason)
                                <dt class="col-md-3">Lý do từ chối</dt>
                                <dd class="col-md-9 text-danger fw-semibold">{{ $verification->rejection_reason }}</dd>
                            @endif
                        </dl>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mt-1">
            @foreach ([
                'id_front_image' => 'CCCD mặt trước',
                'id_back_image' => 'CCCD mặt sau',
                'id_selfie_image' => 'Ảnh selfie',
            ] as $field => $label)
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header fw-semibold">{{ $label }}</div>
                        <div class="card-body">
                            @php $src = $imageUrl($verification->{$field}); @endphp
                            @if ($src)
                                <a href="{{ $src }}" target="_blank" rel="noopener">
                                    <img src="{{ $src }}" alt="{{ $label }}" class="img-fluid rounded border" style="width:100%; max-height:320px; object-fit:contain; background:#f8fafc;">
                                </a>
                            @else
                                <div class="text-muted">Chưa có ảnh.</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold">Thao tác duyệt</div>
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row gap-3">
                    <form method="POST" action="{{ route('admin.user-verifications.approve', $verification->id) }}" onsubmit="return confirm('Duyệt hồ sơ xác thực này?')">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-success" @disabled($verification->status === 'approved')>
                            Duyệt hồ sơ
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.user-verifications.reject', $verification->id) }}" class="flex-grow-1">
                        @csrf
                        @method('PATCH')
                        <div class="input-group">
                            <input type="text" name="rejection_reason" class="form-control" placeholder="Nhập lý do từ chối" required @disabled($verification->status === 'approved')>
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Từ chối hồ sơ xác thực này?')" @disabled($verification->status === 'approved')>
                                Từ chối
                            </button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('admin.user-verifications.destroy', $verification->id) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Xóa hồ sơ xác thực này? Tài khoản người dùng vẫn được giữ nguyên.')">
                            Xóa hồ sơ xác thực
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
