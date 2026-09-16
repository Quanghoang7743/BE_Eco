@extends('admin.layout')

@section('content')
    @php
        $statusLabels = [
            'pending' => 'Chờ duyệt',
            'draft' => 'Bản nháp',
            'submitted' => 'Chờ duyệt',
            'under_review' => 'Đang kiểm tra',
            'need_more_info' => 'Cần bổ sung',
            'approved' => 'Đã duyệt',
            'rejected' => 'Từ chối',
            'suspended' => 'Tạm khóa',
            'locked' => 'Khóa',
            'terminated' => 'Chấm dứt',
        ];
    @endphp
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Duyệt hồ sơ Affiliate</h2>
                <div class="text-muted">Danh sách hồ sơ Affiliate theo trạng thái và bộ lọc.</div>
            </div>
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

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.affiliate.requests.index') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="">Tất cả</option>
                            @foreach ([
                                'draft' => 'Bản nháp',
                                'submitted' => 'Chờ duyệt',
                                'under_review' => 'Đang kiểm tra',
                                'need_more_info' => 'Cần bổ sung',
                                'approved' => 'Đã duyệt',
                                'rejected' => 'Từ chối',
                                'suspended' => 'Tạm khóa',
                                'locked' => 'Khóa',
                                'terminated' => 'Chấm dứt',
                            ] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Từ khóa</label>
                        <input type="text" name="keyword" value="{{ $filters['keyword'] ?? '' }}" class="form-control" placeholder="Tên, SĐT, CCCD, MST">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Từ ngày nộp</label>
                        <input type="date" name="submitted_from" value="{{ $filters['submitted_from'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Đến ngày nộp</label>
                        <input type="date" name="submitted_to" value="{{ $filters['submitted_to'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100">Lọc</button>
                        <a href="{{ route('admin.affiliate.requests.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Người dùng</th>
                                <th>Trạng thái</th>
                                <th>CCCD</th>
                                <th>Thuế</th>
                                <th>Ngân hàng</th>
                                <th>Ngày nộp</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse(($profiles ?? collect()) as $profile)
                                @php
                                    $user = $profile->user;
                                    $bank = $user?->bankAccount;
                                    $displayName = $user?->personal_full_name ?? $user?->full_name ?? $user?->name ?? '—';
                                @endphp
                                <tr>
                                    <td>{{ $profile->id }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $displayName }}</div>
                                        <div class="text-muted">ID: {{ $profile->user_id }}</div>
                                        <div class="text-muted">{{ $user?->phone ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ match($profile->status) {
                                            'approved' => 'success',
                                            'rejected', 'locked', 'terminated' => 'danger',
                                            'need_more_info', 'suspended' => 'warning',
                                            'under_review' => 'info',
                                            default => 'secondary',
                                        } }}">
                                            {{ $statusLabels[$profile->status] ?? strtoupper($profile->status) }}
                                        </span>
                                    </td>
                                    <td>{{ $profile->id_number ?: '—' }}</td>
                                    <td>{{ $profile->personal_tax_code ?: '—' }}</td>
                                    <td>
                                        <div>{{ $bank?->account_name ?? '—' }}</div>
                                        <div class="text-muted">{{ $bank?->account_number ? \Illuminate\Support\Str::mask((string) $bank->account_number, '*', 2, max(strlen((string) $bank->account_number) - 4, 1)) : '' }}</div>
                                        <div class="text-muted">{{ $bank?->bank_name ?? $bank?->bank?->name ?? '' }}</div>
                                    </td>
                                    <td>
                                        <div>{{ optional($profile->submitted_at ?? $profile->created_at)->format('d/m/Y H:i') }}</div>
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.affiliate.requests.show', $profile->id) }}" class="btn btn-outline-primary btn-sm">
                                            Mở hồ sơ
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Chưa có hồ sơ Affiliate phù hợp bộ lọc.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
