@extends('admin.layout')

@section('title', 'Điểm tín dụng chuyên viên')

@section('content')
    @php
        $displayName = fn ($specialist): string => $specialist?->user
            ? ($specialist->user->full_name ?: ($specialist->user->name ?: ($specialist->user->email ?: 'N/A')))
            : 'N/A';
        $changeText = fn ($value): string => ((int) $value > 0 ? '+' : '') . (int) $value;
        $actionClass = fn ($action): string => match ((string) $action) {
            'PLUS' => 'text-success',
            'MINUS' => 'text-danger',
            'ADJUST' => 'text-primary',
            'CANCEL' => 'text-warning',
            default => 'text-muted',
        };
    @endphp

    <div class="container-fluid">
        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($isSaleUser)
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="mb-1">Điểm tín dụng của tôi</h1>
                    <div class="text-muted">Theo dõi điểm hiện tại, xếp loại và lịch sử cộng/trừ điểm.</div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted mb-2">Điểm tín dụng hiện tại</div>
                            <div class="display-5 fw-bold">{{ (int) $profile->credit_points }}/100</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted mb-2">Xếp loại</div>
                            <span class="badge {{ $rankBadgeClass((int) $profile->credit_points) }} fs-6">
                                {{ $profile->credit_rank_label }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="text-muted mb-2">Quy tắc điểm</div>
                            <div>100: Tốt</div>
                            <div>80-99: Khá</div>
                            <div>60-79: Trung bình</div>
                            <div>Dưới 60: Cần cải thiện</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-semibold">Lịch sử điểm tín dụng</div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Thời gian</th>
                                <th>Loại thay đổi</th>
                                <th class="text-end">Thay đổi</th>
                                <th class="text-end">Trước</th>
                                <th class="text-end">Sau</th>
                                <th>Lý do</th>
                                <th>Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($history as $log)
                                <tr>
                                    <td>{{ optional($log->created_at)->format('d/m/Y H:i') }}</td>
                                    <td>{{ $actionLabels[$log->action_type] ?? $log->action_type }}</td>
                                    <td class="text-end fw-semibold {{ $actionClass($log->action_type) }}">{{ $changeText($log->points_change) }}</td>
                                    <td class="text-end">{{ (int) $log->points_before }}</td>
                                    <td class="text-end">{{ (int) $log->points_after }}</td>
                                    <td style="min-width: 240px;">{{ $log->reason }}</td>
                                    <td><span class="badge {{ $log->status === 'ACTIVE' ? 'bg-success' : 'bg-secondary' }}">{{ $statusLabels[$log->status] ?? $log->status }}</span></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Chưa có lịch sử thay đổi điểm.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($history, 'links'))
                    <div class="card-footer">{{ $history->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
                @endif
            </div>
        @else
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="mb-1">Điểm tín dụng chuyên viên</h1>
                    <div class="text-muted">Quản lý điểm tín dụng hiện tại và lịch sử đánh giá của đội ngũ chuyên viên.</div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.specialist-credit.index') }}" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Chuyên viên</label>
                            <select name="specialist_id" class="form-control">
                                <option value="">Tất cả</option>
                                @foreach ($specialistOptions as $specialist)
                                    <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                        {{ $displayName($specialist) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Khu vực</label>
                            <select name="region_id" class="form-control">
                                <option value="">Tất cả</option>
                                @foreach ($regionOptions as $region)
                                    <option value="{{ $region['id'] }}" @selected((string) $filters['region_id'] === (string) $region['id'])>{{ $region['label'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Trạng thái tài khoản</label>
                            <select name="user_status" class="form-control">
                                <option value="all" @selected($filters['user_status'] === 'all')>Tất cả</option>
                                <option value="active" @selected($filters['user_status'] === 'active')>Đang hoạt động</option>
                                <option value="locked" @selected($filters['user_status'] === 'locked')>Đã khóa</option>
                                <option value="inactive" @selected($filters['user_status'] === 'inactive')>Không hoạt động</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Trạng thái đánh giá</label>
                            <select name="log_status" class="form-control">
                                <option value="all" @selected($filters['log_status'] === 'all')>Tất cả</option>
                                @foreach ($statusLabels as $status => $label)
                                    <option value="{{ $status }}" @selected($filters['log_status'] === $status)>{{ $label }}</option>
                                @endforeach
                                <option value="none" @selected($filters['log_status'] === 'none')>Chưa có đánh giá</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Mức điểm</label>
                            <select name="score_level" class="form-control">
                                <option value="all" @selected($filters['score_level'] === 'all')>Tất cả</option>
                                @foreach ($scoreLevelLabels as $level => $label)
                                    <option value="{{ $level }}" @selected($filters['score_level'] === $level)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Lọc</button>
                            <a href="{{ route('admin.specialist-credit.index') }}" class="btn btn-outline-secondary">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-semibold">Danh sách điểm tín dụng</div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Chuyên viên</th>
                                <th>Khu vực</th>
                                <th class="text-end">Điểm</th>
                                <th>Xếp loại</th>
                                <th>Trạng thái</th>
                                <th>Lần thay đổi gần nhất</th>
                                <th class="text-end">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($profiles as $profile)
                                @php
                                    $latestLog = $profile->latestCreditLog ?? null;
                                    $regions = $profile->getAttribute('region_labels') ?: [];
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $displayName($profile) }}</div>
                                        <small class="text-muted">{{ $profile->user?->email ?: $profile->user?->phone }}</small>
                                    </td>
                                    <td>{{ $regions ? implode(', ', $regions) : 'N/A' }}</td>
                                    <td class="text-end fw-semibold">{{ (int) $profile->credit_points }}/100</td>
                                    <td>
                                        <span class="badge {{ $rankBadgeClass((int) $profile->credit_points) }}">{{ $profile->credit_rank_label }}</span>
                                    </td>
                                    <td>{{ $profile->user?->status ?: 'N/A' }}</td>
                                    <td>
                                        @if ($latestLog)
                                            <div class="{{ $actionClass($latestLog->action_type) }}">{{ $changeText($latestLog->points_change) }} điểm</div>
                                            <small class="text-muted">{{ optional($latestLog->created_at)->format('d/m/Y H:i') }}</small>
                                        @else
                                            <span class="text-muted">Chưa có đánh giá</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.specialist-credit.show', $profile->id) }}" class="btn btn-sm btn-outline-primary">Chi tiết</a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Chưa có chuyên viên phù hợp bộ lọc.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $profiles->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            </div>
        @endif
    </div>
@endsection
