@extends('admin.layout')

@section('content')
    @php
        $scoreFormat = fn ($value) => number_format((float) $value, 2, ',', '.');
        $stars = function ($level) {
            $level = (int) $level;
            return str_repeat('★', max(0, $level)) . str_repeat('☆', max(0, 5 - $level));
        };
        $statusBadge = function ($status) {
            return match ((string) $status) {
                'APPROVED', 'NOTIFIED' => 'bg-success',
                'WAITING_REVIEW' => 'bg-warning text-dark',
                'CALCULATING' => 'bg-info text-dark',
                default => 'bg-secondary',
            };
        };
    @endphp

    <div class="container-fluid">
        @if ($isSaleUser)
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="mb-1">Đánh giá sao của tôi</h1>
                    <div class="text-muted">Theo dõi sao hiện tại, điểm từng tiêu chí và lịch sử thay đổi.</div>
                </div>
            </div>

            @if ($current)
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="text-muted mb-2">Số sao hiện tại</div>
                                <div class="display-6 text-warning" style="letter-spacing:0;">{{ $stars($current->star_level) }}</div>
                                <div class="h3 mb-1">{{ (int) $current->star_level }}/5 sao</div>
                                <div class="text-muted">
                                    Kỳ {{ optional($current->period_from)->format('d/m/Y') }} - {{ optional($current->period_to)->format('d/m/Y') }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="text-muted mb-2">Điểm tổng</div>
                                <div class="display-6">{{ $scoreFormat($current->total_score) }}</div>
                                <span class="badge {{ $statusBadge($current->status) }}">{{ $statusLabels[$current->status] ?? $current->status }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="text-muted mb-2">Xu hướng</div>
                                @if ($trend['score_delta'] === null)
                                    <div class="h4">Chưa đủ dữ liệu</div>
                                    <div class="text-muted">Cần ít nhất 2 kỳ đánh giá.</div>
                                @else
                                    <div class="h3 {{ $trend['score_delta'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $trend['score_delta'] >= 0 ? '+' : '' }}{{ $scoreFormat($trend['score_delta']) }} điểm
                                    </div>
                                    <div class="{{ $trend['star_delta'] >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $trend['star_delta'] >= 0 ? '+' : '' }}{{ $trend['star_delta'] }} sao so với kỳ trước
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-xl-7">
                        <div class="card h-100">
                            <div class="card-header bg-white fw-semibold">Điểm từng tiêu chí</div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-hover mb-0 align-middle">
                                    <thead>
                                        <tr>
                                            <th>Tiêu chí</th>
                                            <th class="text-end">Điểm thô</th>
                                            <th class="text-end">Trọng số</th>
                                            <th class="text-end">Điểm quy đổi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($current->criteriaScores as $criteria)
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold">{{ $criteriaLabels[$criteria->criteria_code] ?? $criteria->criteria_code }}</div>
                                                    <small class="text-muted">{{ $criteria->criteria_code }}</small>
                                                </td>
                                                <td class="text-end">{{ $scoreFormat($criteria->raw_score) }}</td>
                                                <td class="text-end">{{ $scoreFormat($criteria->weight_percent) }}%</td>
                                                <td class="text-end fw-semibold">{{ $scoreFormat($criteria->weighted_score) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="card h-100">
                            <div class="card-header bg-white fw-semibold">Gửi yêu cầu xem xét lại</div>
                            <div class="card-body">
                                <form method="POST" action="{{ route('admin.specialist-ratings.review-requests.store', $current->id) }}">
                                    @csrf
                                    <textarea class="form-control mb-3" name="request_reason" rows="5" placeholder="Nhập lý do cần xem xét lại điểm/sao..." required></textarea>
                                    <button type="submit" class="btn btn-primary">Gửi yêu cầu</button>
                                </form>
                                <hr>
                                <div class="fw-semibold mb-2">Yêu cầu gần đây</div>
                                @forelse ($current->reviewRequests as $reviewRequest)
                                    <div class="border rounded p-2 mb-2">
                                        <div class="d-flex justify-content-between gap-2">
                                            <span>{{ $reviewStatusLabels[$reviewRequest->status] ?? $reviewRequest->status }}</span>
                                            <small class="text-muted">{{ optional($reviewRequest->created_at)->format('d/m/Y H:i') }}</small>
                                        </div>
                                        <small class="text-muted">{{ $reviewRequest->request_reason }}</small>
                                    </div>
                                @empty
                                    <div class="text-muted">Chưa có yêu cầu xem xét lại.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header bg-white fw-semibold">Lịch sử đánh giá</div>
                    <div class="card-body table-responsive p-0">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Kỳ</th>
                                    <th class="text-end">Điểm</th>
                                    <th class="text-end">Sao</th>
                                    <th>Trạng thái</th>
                                    <th class="text-end">Chi tiết</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($history as $rating)
                                    <tr>
                                        <td>{{ optional($rating->period_from)->format('d/m/Y') }} - {{ optional($rating->period_to)->format('d/m/Y') }}</td>
                                        <td class="text-end">{{ $scoreFormat($rating->total_score) }}</td>
                                        <td class="text-end text-warning">{{ $stars($rating->star_level) }}</td>
                                        <td><span class="badge {{ $statusBadge($rating->status) }}">{{ $statusLabels[$rating->status] ?? $rating->status }}</span></td>
                                        <td class="text-end"><a href="{{ route('admin.specialist-ratings.show', $rating->id) }}" class="btn btn-sm btn-outline-primary">Xem</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="card">
                    <div class="card-body text-center text-muted py-5">Chưa có kỳ đánh giá nào được tạo.</div>
                </div>
            @endif
        @else
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
                <div>
                    <h1 class="mb-1">Đánh giá sao chuyên viên</h1>
                    <div class="text-muted">Chạy tính điểm, duyệt kết quả, điều chỉnh và xử lý yêu cầu xem xét lại.</div>
                </div>
                <a href="{{ route('admin.specialist-ratings.review-requests') }}" class="btn btn-outline-primary">Yêu cầu xem xét lại</a>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.specialist-ratings.calculate') }}" class="row g-3 align-items-end">
                        @csrf
                        <div class="col-md-3">
                            <label class="form-label">Chuyên viên</label>
                            <select class="form-control" name="specialist_id">
                                <option value="">Tất cả chuyên viên sale</option>
                                @foreach ($specialistOptions as $specialist)
                                    <option value="{{ $specialist->id }}">{{ $specialist->full_name ?: ($specialist->name ?: $specialist->email) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Từ ngày</label>
                            <input type="date" class="form-control" name="period_from" value="{{ now('Asia/Ho_Chi_Minh')->startOfMonth()->toDateString() }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Đến ngày</label>
                            <input type="date" class="form-control" name="period_to" value="{{ now('Asia/Ho_Chi_Minh')->endOfMonth()->toDateString() }}">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">Chạy tính điểm</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.specialist-ratings.index') }}" class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label">Chuyên viên</label>
                            <select class="form-control" name="specialist_id">
                                <option value="">Tất cả</option>
                                @foreach ($specialistOptions as $specialist)
                                    <option value="{{ $specialist->id }}" @selected((string) $filters['specialist_id'] === (string) $specialist->id)>{{ $specialist->full_name ?: ($specialist->name ?: $specialist->email) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Sao</label>
                            <select class="form-control" name="star_level">
                                <option value="all">Tất cả</option>
                                @for ($i = 5; $i >= 1; $i--)
                                    <option value="{{ $i }}" @selected((string) $filters['star_level'] === (string) $i)>{{ $i }} sao</option>
                                @endfor
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-control" name="status">
                                <option value="all">Tất cả</option>
                                @foreach ($statusLabels as $status => $label)
                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Từ kỳ</label>
                            <input type="date" class="form-control" name="period_from" value="{{ $filters['period_from'] }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Đến kỳ</label>
                            <input type="date" class="form-control" name="period_to" value="{{ $filters['period_to'] }}">
                        </div>
                        <div class="col-md-1 d-flex gap-2">
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
                                <th>Kỳ</th>
                                <th class="text-end">Điểm</th>
                                <th class="text-end">Sao</th>
                                <th>Xu hướng</th>
                                <th>Trạng thái</th>
                                <th class="text-end">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($ratings as $rating)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $rating->specialist?->full_name ?: ($rating->specialist?->name ?: 'N/A') }}</div>
                                        <small class="text-muted">{{ $rating->specialist?->email }}</small>
                                    </td>
                                    <td>{{ optional($rating->period_from)->format('d/m/Y') }} - {{ optional($rating->period_to)->format('d/m/Y') }}</td>
                                    <td class="text-end">{{ $scoreFormat($rating->total_score) }}</td>
                                    <td class="text-end text-warning">{{ $stars($rating->star_level) }}</td>
                                    <td>
                                        @if ($rating->previous_star_level)
                                            {{ (int) $rating->star_level - (int) $rating->previous_star_level >= 0 ? '+' : '' }}{{ (int) $rating->star_level - (int) $rating->previous_star_level }} sao
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $statusBadge($rating->status) }}">{{ $statusLabels[$rating->status] ?? $rating->status }}</span></td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.specialist-ratings.show', $rating->id) }}" class="btn btn-sm btn-outline-primary">Chi tiết</a>
                                        @if ($rating->status !== 'APPROVED' && $rating->status !== 'NOTIFIED')
                                            <form method="POST" action="{{ route('admin.specialist-ratings.approve', $rating->id) }}" class="d-inline">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-success">Duyệt</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Chưa có dữ liệu đánh giá.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">{{ $ratings->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
            </div>
        @endif
    </div>
@endsection
