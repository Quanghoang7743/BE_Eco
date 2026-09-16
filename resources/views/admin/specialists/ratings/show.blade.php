@extends('admin.layout')

@section('content')
    @php
        $scoreFormat = fn ($value) => number_format((float) $value, 2, ',', '.');
        $stars = fn ($level) => str_repeat('★', max(0, (int) $level)) . str_repeat('☆', max(0, 5 - (int) $level));
        $statusBadge = fn ($status) => match ((string) $status) {
            'APPROVED', 'NOTIFIED' => 'bg-success',
            'WAITING_REVIEW' => 'bg-warning text-dark',
            'CALCULATING' => 'bg-info text-dark',
            default => 'bg-secondary',
        };
    @endphp

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="mb-1">Chi tiết đánh giá sao</h1>
                <div class="text-muted">
                    {{ $rating->specialist?->full_name ?: ($rating->specialist?->name ?: 'Chuyên viên') }}
                    · {{ optional($rating->period_from)->format('d/m/Y') }} - {{ optional($rating->period_to)->format('d/m/Y') }}
                </div>
            </div>
            <a href="{{ route('admin.specialist-ratings.index') }}" class="btn btn-outline-secondary">Quay lại</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted mb-2">Số sao</div>
                        <div class="h2 text-warning">{{ $stars($rating->star_level) }}</div>
                        <div>{{ (int) $rating->star_level }}/5 sao</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted mb-2">Điểm tổng</div>
                        <div class="h2">{{ $scoreFormat($rating->total_score) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted mb-2">Xu hướng sao</div>
                        @if ($rating->previous_star_level)
                            <div class="h3 {{ (int) $rating->star_level >= (int) $rating->previous_star_level ? 'text-success' : 'text-danger' }}">
                                {{ (int) $rating->star_level - (int) $rating->previous_star_level >= 0 ? '+' : '' }}{{ (int) $rating->star_level - (int) $rating->previous_star_level }}
                            </div>
                            <div class="text-muted">Kỳ trước: {{ (int) $rating->previous_star_level }} sao</div>
                        @else
                            <div class="h4">N/A</div>
                            <div class="text-muted">Chưa có kỳ trước.</div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted mb-2">Trạng thái</div>
                        <span class="badge {{ $statusBadge($rating->status) }}">{{ $statusLabels[$rating->status] ?? $rating->status }}</span>
                        <div class="text-muted mt-2">Tính lúc: {{ optional($rating->calculated_at)->format('d/m/Y H:i') ?: 'N/A' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header bg-white fw-semibold">Điểm từng tiêu chí</div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Tiêu chí</th>
                                    <th class="text-end">Điểm thô</th>
                                    <th class="text-end">Trọng số</th>
                                    <th class="text-end">Điểm quy đổi</th>
                                    <th>Nguồn dữ liệu</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rating->criteriaScores as $criteria)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $criteriaLabels[$criteria->criteria_code] ?? $criteria->criteria_code }}</div>
                                            <small class="text-muted">{{ $criteria->criteria_code }}</small>
                                        </td>
                                        <td class="text-end">{{ $scoreFormat($criteria->raw_score) }}</td>
                                        <td class="text-end">{{ $scoreFormat($criteria->weight_percent) }}%</td>
                                        <td class="text-end fw-semibold">{{ $scoreFormat($criteria->weighted_score) }}</td>
                                        <td>
                                            <small class="text-muted">
                                                {{ collect($criteria->data_source_json ?? [])->map(fn ($value, $key) => $key . ': ' . $value)->implode(' | ') }}
                                            </small>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header bg-white fw-semibold">Lịch sử thay đổi</div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Thời gian</th>
                                    <th>Hành động</th>
                                    <th>Người thao tác</th>
                                    <th>Ghi chú</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rating->auditLogs as $log)
                                    <tr>
                                        <td>{{ optional($log->created_at)->format('d/m/Y H:i') }}</td>
                                        <td>{{ $log->action }}</td>
                                        <td>{{ $log->actor_id ?: 'Hệ thống' }}</td>
                                        <td>{{ $log->notes ?: 'N/A' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Chưa có lịch sử thay đổi.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                @if (! $isSaleUser)
                    <div class="card mb-3">
                        <div class="card-header bg-white fw-semibold">Thao tác admin</div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.specialist-ratings.approve', $rating->id) }}" class="mb-3">
                                @csrf
                                @method('PATCH')
                                <label class="form-label">Ghi chú duyệt</label>
                                <textarea class="form-control mb-2" name="notes" rows="2"></textarea>
                                <button type="submit" class="btn btn-success w-100">Duyệt kết quả</button>
                            </form>
                            <hr>
                            <form method="POST" action="{{ route('admin.specialist-ratings.adjust', $rating->id) }}">
                                @csrf
                                @method('PATCH')
                                <label class="form-label">Điểm điều chỉnh</label>
                                <input type="number" min="0" max="100" step="0.01" name="total_score" class="form-control mb-2" value="{{ $rating->total_score }}" required>
                                <label class="form-label">Lý do điều chỉnh</label>
                                <textarea class="form-control mb-2" name="adjustment_reason" rows="3" required></textarea>
                                <button type="submit" class="btn btn-primary w-100">Điều chỉnh điểm</button>
                            </form>
                        </div>
                    </div>
                @else
                    <div class="card mb-3">
                        <div class="card-header bg-white fw-semibold">Yêu cầu xem xét lại</div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('admin.specialist-ratings.review-requests.store', $rating->id) }}">
                                @csrf
                                <textarea class="form-control mb-3" name="request_reason" rows="5" placeholder="Nhập lý do cần xem xét lại..." required></textarea>
                                <button type="submit" class="btn btn-primary w-100">Gửi yêu cầu</button>
                            </form>
                        </div>
                    </div>
                @endif

                <div class="card">
                    <div class="card-header bg-white fw-semibold">Yêu cầu xem xét lại</div>
                    <div class="card-body">
                        @forelse ($rating->reviewRequests as $reviewRequest)
                            <div class="border rounded p-2 mb-2">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <span class="fw-semibold">{{ $reviewStatusLabels[$reviewRequest->status] ?? $reviewRequest->status }}</span>
                                    <small class="text-muted">{{ optional($reviewRequest->created_at)->format('d/m/Y H:i') }}</small>
                                </div>
                                <div class="text-muted mb-2">{{ $reviewRequest->request_reason }}</div>
                                @if (! $isSaleUser && in_array($reviewRequest->status, ['SUBMITTED', 'UNDER_REVIEW'], true))
                                    <form method="POST" action="{{ route('admin.specialist-ratings.review-requests.process', $reviewRequest->id) }}">
                                        @csrf
                                        @method('PATCH')
                                        <select class="form-control mb-2" name="status" required>
                                            <option value="ACCEPTED">Chấp nhận</option>
                                            <option value="REJECTED">Từ chối</option>
                                        </select>
                                        <input type="number" min="0" max="100" step="0.01" class="form-control mb-2" name="new_score" placeholder="Điểm mới nếu chấp nhận">
                                        <textarea class="form-control mb-2" name="admin_response" rows="2" placeholder="Phản hồi admin"></textarea>
                                        <button type="submit" class="btn btn-sm btn-primary">Xử lý</button>
                                    </form>
                                @elseif ($reviewRequest->admin_response)
                                    <small class="text-muted">Phản hồi: {{ $reviewRequest->admin_response }}</small>
                                @endif
                            </div>
                        @empty
                            <div class="text-muted">Chưa có yêu cầu xem xét lại.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
