@extends('admin.layout')

@section('content')
    @php
        $badgeClasses = [
            'pending' => 'bg-warning text-dark',
            'approved' => 'bg-success',
            'rejected' => 'bg-danger',
        ];
    @endphp

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Danh sách duyệt</h2>
                <div class="text-muted">Duyệt hồ sơ xác thực tài khoản người dùng.</div>
            </div>
            <a href="{{ route('admin.users.index', ['tab' => 'customers']) }}" class="btn btn-outline-primary">
                Danh sách người dùng
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

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.user-verifications.index') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="all" @selected(($filters['status'] ?? '') === 'all')>Tất cả</option>
                            @foreach ($statusLabels as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['status'] ?? 'pending') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Từ khóa</label>
                        <input type="text" name="keyword" value="{{ $filters['keyword'] ?? '' }}" class="form-control" placeholder="Tên, SĐT, email, CCCD">
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
                        <a href="{{ route('admin.user-verifications.index') }}" class="btn btn-outline-secondary">Reset</a>
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
                                <th>Thông tin xác thực</th>
                                <th>Trạng thái</th>
                                <th>Ngày nộp</th>
                                <th>Người duyệt</th>
                                <th>Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($verifications as $verification)
                                @php
                                    $user = $verification->user;
                                    $displayName = $user?->full_name ?: ($user?->name ?: '—');
                                @endphp
                                <tr>
                                    <td>{{ $verification->id }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $displayName }}</div>
                                        <div class="text-muted">User ID: {{ $verification->user_id }}</div>
                                        <div class="text-muted">{{ $user?->phone ?? $user?->email ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $verification->full_name }}</div>
                                        <div class="text-muted">CCCD: {{ $verification->id_number }}</div>
                                        <div class="text-muted">Ngày sinh: {{ optional($verification->birth_date)->format('d/m/Y') }}</div>
                                    </td>
                                    <td>
                                        <span class="badge {{ $badgeClasses[$verification->status] ?? 'bg-secondary' }}">
                                            {{ $statusLabels[$verification->status] ?? strtoupper($verification->status) }}
                                        </span>
                                    </td>
                                    <td>{{ optional($verification->submitted_at ?? $verification->created_at)->format('d/m/Y H:i') }}</td>
                                    <td>
                                        @if ($verification->reviewer)
                                            <div>{{ $verification->reviewer->full_name ?: $verification->reviewer->name }}</div>
                                            <div class="text-muted">{{ optional($verification->reviewed_at)->format('d/m/Y H:i') }}</div>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.user-verifications.show', $verification->id) }}" class="btn btn-outline-primary btn-sm">
                                            Mở hồ sơ
                                        </a>
                                        <form method="POST" action="{{ route('admin.user-verifications.destroy', $verification->id) }}" class="d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Xóa hồ sơ xác thực này? Tài khoản người dùng vẫn được giữ nguyên.')">
                                                Xóa
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Chưa có hồ sơ xác thực phù hợp.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($verifications->hasPages())
                <div class="card-footer">
                    {{ $verifications->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            var reloadTimer = null;

            function handleRealtimeUpdate() {
                window.clearTimeout(reloadTimer);
                reloadTimer = window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            }

            window.addEventListener('client-user-verification-updated', handleRealtimeUpdate);
        })();
    </script>
@endsection
