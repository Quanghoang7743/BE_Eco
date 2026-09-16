@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h1 class="mb-1">Yêu cầu xem xét lại đánh giá</h1>
                <div class="text-muted">Xử lý khi chuyên viên đề nghị rà soát lại điểm/sao.</div>
            </div>
            <a href="{{ route('admin.specialist-ratings.index') }}" class="btn btn-outline-secondary">Về danh sách đánh giá</a>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.specialist-ratings.review-requests') }}" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Chuyên viên</label>
                        <select class="form-control" name="specialist_id">
                            <option value="">Tất cả</option>
                            @foreach ($specialistOptions as $specialist)
                                <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>
                                    {{ $specialist->full_name ?: ($specialist->name ?: $specialist->email) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-control" name="status">
                            <option value="all">Tất cả</option>
                            @foreach ($reviewStatusLabels as $status => $label)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary">Lọc</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body table-responsive p-0">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Chuyên viên</th>
                            <th>Kỳ đánh giá</th>
                            <th>Lý do</th>
                            <th>Trạng thái</th>
                            <th style="width: 320px;">Xử lý</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($requests as $reviewRequest)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $reviewRequest->specialist?->full_name ?: ($reviewRequest->specialist?->name ?: 'N/A') }}</div>
                                    <small class="text-muted">{{ $reviewRequest->specialist?->email }}</small>
                                </td>
                                <td>
                                    {{ optional($reviewRequest->rating?->period_from)->format('d/m/Y') }}
                                    -
                                    {{ optional($reviewRequest->rating?->period_to)->format('d/m/Y') }}
                                    <div><a href="{{ route('admin.specialist-ratings.show', $reviewRequest->rating_id) }}">Xem điểm</a></div>
                                </td>
                                <td>{{ $reviewRequest->request_reason }}</td>
                                <td>{{ $reviewStatusLabels[$reviewRequest->status] ?? $reviewRequest->status }}</td>
                                <td>
                                    @if (in_array($reviewRequest->status, ['SUBMITTED', 'UNDER_REVIEW'], true))
                                        <form method="POST" action="{{ route('admin.specialist-ratings.review-requests.process', $reviewRequest->id) }}">
                                            @csrf
                                            @method('PATCH')
                                            <div class="d-flex gap-2 mb-2">
                                                <select class="form-control" name="status" required>
                                                    <option value="ACCEPTED">Chấp nhận</option>
                                                    <option value="REJECTED">Từ chối</option>
                                                </select>
                                                <input type="number" min="0" max="100" step="0.01" class="form-control" name="new_score" placeholder="Điểm mới">
                                            </div>
                                            <textarea class="form-control mb-2" name="admin_response" rows="2" placeholder="Phản hồi admin"></textarea>
                                            <button type="submit" class="btn btn-sm btn-primary">Cập nhật</button>
                                        </form>
                                    @else
                                        <div class="text-muted">{{ $reviewRequest->admin_response ?: 'Đã xử lý' }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Chưa có yêu cầu xem xét lại.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $requests->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        </div>
    </div>
@endsection
