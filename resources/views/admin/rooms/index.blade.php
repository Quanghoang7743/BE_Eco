@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Danh sách phòng quay (S04)</h2>
            <div class="d-flex align-items-center gap-2">
                {{-- Toggle view --}}
                <div class="btn-group" role="group" id="viewToggle">
                    <button type="button" class="btn btn-outline-secondary btn-sm active" id="btnTableView" title="Dạng bảng">
                        <i class="fas fa-table me-1"></i> Bảng
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnCardView" title="Dạng card">
                        <i class="fas fa-th-large me-1"></i> Card
                    </button>
                </div>
                <a href="{{ route('admin.rooms.index', ['room_date' => $roomDateFilter, 'status' => request('status'), 'trash' => 'only']) }}"
                    class="btn btn-sm {{ $trashFilter === 'only' ? 'btn-danger' : 'btn-outline-danger' }}">
                    <i class="fas fa-trash-can me-1"></i> Thùng rác
                </a>
                <a href="{{ route('admin.rooms.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Tạo phòng riêng lẻ
                </a>
            </div>
        </div>

        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.rooms.index') }}" class="row g-3">
                    @if ($trashFilter === 'only')
                        <input type="hidden" name="trash" value="only">
                    @endif
                    <div class="col-md-3">
                        <label class="form-label">Ngày quay</label>
                        <input type="date" name="room_date" class="form-control" value="{{ $roomDateFilter }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-control">
                            <option value="">Tất cả</option>
                            <option value="WAITING" {{ request('status') === 'WAITING' ? 'selected' : '' }}>WAITING - Chờ đủ Ticket</option>
                            <option value="FILLING" {{ request('status') === 'FILLING' ? 'selected' : '' }}>FILLING - Đang nhận Ticket</option>
                            <option value="FULL" {{ request('status') === 'FULL' ? 'selected' : '' }}>FULL - Đủ Ticket chờ quay</option>
                            <option value="DRAWING" {{ request('status') === 'DRAWING' ? 'selected' : '' }}>DRAWING - Đang quay</option>
                            <option value="COMPLETED" {{ request('status') === 'COMPLETED' ? 'selected' : '' }}>COMPLETED - Đã quay</option>
                            <option value="CANCELED" {{ request('status') === 'CANCELED' ? 'selected' : '' }}>CANCELED - Đã hủy</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Hiển thị</label>
                        @if ($trashFilter === 'only')
                            <select class="form-control" disabled>
                                <option selected>Đang xem thùng rác</option>
                            </select>
                        @else
                            <select name="trash" class="form-control">
                                <option value="active" {{ $trashFilter === 'active' ? 'selected' : '' }}>Đang hoạt động</option>
                                <option value="with" {{ $trashFilter === 'with' ? 'selected' : '' }}>Tất cả</option>
                            </select>
                        @endif
                    </div>

                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Lọc</button>
                        <a href="{{ route('admin.rooms.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        {{-- ===================== TABLE VIEW ===================== --}}
        <div id="tableView">
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive room-table-wrap">
                        <table class="table table-striped table-hover mb-0 room-table">
                            <thead>
                                <tr>
                                    <th>Room Code</th>
                                    <th>Tên phòng</th>
                                    <th>Ngày quay</th>
                                    <th>Trạng thái</th>
                                    <th>Tiến độ</th>
                                    <th>Loại Ticket</th>
                                    <th>Khung giờ</th>
                                    <th>Giải thưởng</th>
                                    <th>Kết quả quay</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($rooms as $room)
                                    @php
                                        $requiredVotes = max(1, (int) $room->required_votes);
                                        $currentVotes = (int) $room->current_votes;
                                        $fillRate = min(100, (int) round(($currentVotes / $requiredVotes) * 100));
                                        $isTrashed = $room->trashed();
                                        $prizeDescription = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($room->prize_description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                                        $statusLabel = match ($room->status) {
                                            'WAITING' => 'Đang chờ Ticket',
                                            'FILLING' => 'Đang nhận Ticket',
                                            'FULL' => 'Đã đủ Ticket',
                                            'DRAWING' => 'Đang quay',
                                            'COMPLETED' => 'Đã quay',
                                            'CANCELED' => 'Đã hủy',
                                            default => 'Không xác định',
                                        };
                                        if ($isTrashed) {
                                            $statusLabel = 'Đã xóa mềm';
                                        }
                                        $roomDate = optional($room->room_date)->format('Y-m-d') ?? 'N/A';
                                        $openAt = optional($room->open_at)->format('Y-m-d H:i') ?? 'N/A';
                                        $closeAt = optional($room->close_at)->format('Y-m-d H:i') ?? 'N/A';
                                        $prizeName = $room->prizeTemplate?->prize_name ?? ($room->prize_type ?? 'N/A');
                                        $prizeValue = $room->prize_value ? number_format($room->prize_value, 0, ',', '.') . ' VND' : 'N/A';
                                        $createdAt = optional($room->created_at)->format('Y-m-d H:i:s') ?? 'N/A';
                                        $drawCompletedAt = optional($room->draw_completed_at)->format('Y-m-d H:i:s') ?? 'N/A';
                                    @endphp
                                    <tr>
                                        <td>
                                            <button type="button"
                                                class="btn btn-link p-0 room-detail-trigger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#roomDetailModal"
                                                data-room-id="{{ $room->id }}"
                                                data-room-code="{{ e($room->room_code) }}"
                                                data-room-number="{{ e($room->room_number) }}"
                                                data-room-name="{{ e($room->room_name ?: '') }}"
                                                data-room-date="{{ e($roomDate) }}"
                                                data-room-status="{{ e($room->status) }}"
                                                data-room-status-label="{{ e($statusLabel) }}"
                                                data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                                data-room-open-at="{{ e($openAt) }}"
                                                data-room-close-at="{{ e($closeAt) }}"
                                                data-room-required-votes="{{ number_format($requiredVotes) }}"
                                                data-room-current-votes="{{ number_format($currentVotes) }}"
                                                data-room-fill-rate="{{ $fillRate }}%"
                                                data-room-prize-name="{{ e($prizeName) }}"
                                                data-room-prize-value="{{ e($prizeValue) }}"
                                                data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                                data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                                data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                                data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                                data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                                data-room-created-at="{{ e($createdAt) }}">
                                                {{ $room->room_code }}
                                            </button>
                                        </td>
                                        <td>{{ $room->room_name ?? '...' }}</td>
                                        <td>{{ $roomDate }}</td>
                                        <td>
                                            @if ($isTrashed)
                                                <span class="badge bg-dark">Đã xóa mềm</span>
                                            @elseif ($room->status === 'WAITING')
                                                <span class="badge bg-warning text-dark">Đang chờ Ticket</span>
                                            @elseif ($room->status === 'FILLING')
                                                <span class="badge" style="background-color:#6f42c1; color:#fff;">Đang nhận Ticket</span>
                                            @elseif ($room->status === 'FULL')
                                                <span class="badge bg-primary">Đã đủ Ticket</span>
                                            @elseif ($room->status === 'DRAWING')
                                                <span class="badge bg-info text-dark">Đang quay</span>
                                            @elseif ($room->status === 'COMPLETED')
                                                <span class="badge bg-success">Đã quay</span>
                                            @elseif ($room->status === 'CANCELED')
                                                <span class="badge bg-danger">Đã hủy</span>
                                            @else
                                                <span class="badge bg-secondary">Không xác định</span>
                                            @endif
                                        </td>
                                        <td style="min-width: 220px;">
                                            <div class="fw-semibold">{{ number_format($currentVotes) }} / {{ number_format($requiredVotes) }}</div>
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar" role="progressbar" style="width: {{ $fillRate }}%;"
                                                    aria-valuenow="{{ $fillRate }}" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <small class="text-muted">{{ $fillRate }}%</small>
                                        </td>
                                        <td>
                                            <div>
                                                <button type="button"
                                                    class="btn btn-link p-0 room-detail-trigger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#roomDetailModal"
                                                    data-room-id="{{ $room->id }}"
                                                    data-room-code="{{ e($room->room_code) }}"
                                                    data-room-number="{{ e($room->room_number) }}"
                                                    data-room-name="{{ e($room->room_name ?: '') }}"
                                                    data-room-name="{{ e($room->room_name ?: '') }}"
                                                    data-room-date="{{ e($roomDate) }}"
                                                    data-room-status="{{ e($room->status) }}"
                                                    data-room-status-label="{{ e($statusLabel) }}"
                                                    data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                                    data-room-open-at="{{ e($openAt) }}"
                                                    data-room-close-at="{{ e($closeAt) }}"
                                                    data-room-required-votes="{{ number_format($requiredVotes) }}"
                                                    data-room-current-votes="{{ number_format($currentVotes) }}"
                                                    data-room-fill-rate="{{ $fillRate }}%"
                                                    data-room-prize-name="{{ e($prizeName) }}"
                                                    data-room-prize-value="{{ e($prizeValue) }}"
                                                    data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                                    data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                                    data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                                    data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                                    data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                                    data-room-created-at="{{ e($createdAt) }}">
                                                    {{ $room->ticket_type_code ?: 'N/A' }}
                                                </button>
                                            </div>
                                            @if (!empty($room->is_template))
                                                <small class="badge bg-dark">Template</small>
                                            @endif
                                        </td>
                                        <td>
                                            <div><small class="text-muted">Mở: {{ $openAt }}</small></div>
                                            <div><small class="text-muted">Đóng: {{ $closeAt }}</small></div>
                                        </td>
                                        <td>
                                            <div>
                                                <button type="button"
                                                    class="btn btn-link p-0 room-detail-trigger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#roomDetailModal"
                                                    data-room-id="{{ $room->id }}"
                                                    data-room-code="{{ e($room->room_code) }}"
                                                    data-room-number="{{ e($room->room_number) }}"
                                                    data-room-name="{{ e($room->room_name ?: '') }}"
                                                    data-room-name="{{ e($room->room_name ?: '') }}"
                                                    data-room-date="{{ e($roomDate) }}"
                                                    data-room-status="{{ e($room->status) }}"
                                                    data-room-status-label="{{ e($statusLabel) }}"
                                                    data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                                    data-room-open-at="{{ e($openAt) }}"
                                                    data-room-close-at="{{ e($closeAt) }}"
                                                    data-room-required-votes="{{ number_format($requiredVotes) }}"
                                                    data-room-current-votes="{{ number_format($currentVotes) }}"
                                                    data-room-fill-rate="{{ $fillRate }}%"
                                                    data-room-prize-name="{{ e($prizeName) }}"
                                                    data-room-prize-value="{{ e($prizeValue) }}"
                                                    data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                                    data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                                    data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                                    data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                                    data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                                    data-room-created-at="{{ e($createdAt) }}">
                                                    {{ $prizeName }}
                                                </button>
                                            </div>
                                            <small class="text-muted">{{ $prizeValue }}</small>
                                        </td>
                                        <td class="room-table__result">
                                            @if ($room->status === 'COMPLETED')
                                                <div><strong>{{ $room->winner_name ?? 'N/A' }}</strong></div>
                                                <div><small class="text-muted">Completed: {{ $drawCompletedAt }}</small></div>
                                            @else
                                                <small class="text-muted">Chưa có kết quả</small>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex flex-column gap-2 mb-2">
                                                <button type="button"
                                                    class="btn btn-sm btn-outline-secondary room-detail-trigger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#roomDetailModal"
                                                    data-room-id="{{ $room->id }}"
                                                    data-room-code="{{ e($room->room_code) }}"
                                                    data-room-number="{{ e($room->room_number) }}"
                                                    data-room-date="{{ e($roomDate) }}"
                                                    data-room-status="{{ e($room->status) }}"
                                                    data-room-status-label="{{ e($statusLabel) }}"
                                                    data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                                    data-room-open-at="{{ e($openAt) }}"
                                                    data-room-close-at="{{ e($closeAt) }}"
                                                    data-room-required-votes="{{ number_format($requiredVotes) }}"
                                                    data-room-current-votes="{{ number_format($currentVotes) }}"
                                                    data-room-fill-rate="{{ $fillRate }}%"
                                                    data-room-prize-name="{{ e($prizeName) }}"
                                                    data-room-prize-value="{{ e($prizeValue) }}"
                                                    data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                                    data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                                    data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                                    data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                                    data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                                data-room-created-at="{{ e($createdAt) }}">
                                                <i class="fas fa-circle-info me-1"></i> Chi tiết
                                            </button>
                                            @if ($isTrashed)
                                                <form method="POST" action="{{ route('admin.rooms.restore', $room->id) }}" class="d-inline"
                                                    onsubmit="return confirm('Khôi phục phòng {{ $room->room_code }}? Phòng sẽ trống và sẵn sàng nhận vé mới.');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                                        <i class="fas fa-rotate-left me-1"></i> Khôi phục
                                                    </button>
                                                </form>
                                            @else
                                                <a href="{{ route('admin.rooms.edit', $room->id) }}" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-pen-to-square me-1"></i> Sửa giải thưởng
                                                </a>
                                            @endif
                                            </div>

                                            @if (!$isTrashed && in_array($room->status, ['WAITING', 'FILLING', 'FULL'], true) && empty($room->draw_started_at))
                                                <div class="d-flex flex-column gap-2">
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#cancelRoomModal"
                                                        data-room-id="{{ $room->id }}"
                                                        data-action="{{ route('admin.rooms.emergency-cancel', $room->id) }}"
                                                        data-room-code="{{ $room->room_code }}"
                                                        data-room-tickets="{{ (int) ($room->room_tickets_count ?? 0) }}">
                                                        <i class="fas fa-triangle-exclamation me-1"></i> Hủy khẩn cấp
                                                    </button>
                                                    <button type="button"
                                                        class="btn btn-sm btn-outline-dark"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deleteRoomModal"
                                                        data-room-id="{{ $room->id }}"
                                                        data-action="{{ route('admin.rooms.destroy', $room->id) }}"
                                                        data-room-code="{{ $room->room_code }}"
                                                        data-room-tickets="{{ (int) ($room->room_tickets_count ?? 0) }}">
                                                        <i class="fas fa-trash-can me-1"></i> Xóa mềm
                                                    </button>

                                                    @if ((int) $room->current_votes >= (int) $room->required_votes)
                                                        <form method="POST" action="{{ route('admin.rooms.force-draw', $room->id) }}"
                                                            onsubmit="return confirm('Xác nhận force quay cho phòng {{ $room->room_code }}?');">
                                                            @csrf
                                                            <button type="submit" class="btn btn-sm btn-danger">Force quay</button>
                                                        </form>
                                                    @else
                                                        <span class="text-muted small">Phòng chưa đủ Ticket để force quay</span>
                                                    @endif
                                                </div>
                                            @elseif (!$isTrashed && in_array($room->status, ['WAITING', 'FILLING', 'FULL'], true) && (int) $room->current_votes >= (int) $room->required_votes)
                                                <form method="POST" action="{{ route('admin.rooms.force-draw', $room->id) }}"
                                                    onsubmit="return confirm('Xác nhận force quay cho phòng {{ $room->room_code }}?');">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-danger">Force quay</button>
                                                </form>
                                            @elseif(!$isTrashed && $room->status === 'COMPLETED')
                                                <span class="badge bg-success">Đã quay</span>
                                            @elseif($isTrashed)
                                                <span class="text-muted small">Phòng đã ở thùng rác</span>
                                            @else
                                                <span class="text-muted">Chưa đủ điều kiện</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="text-center py-4">Chưa có dữ liệu phòng quay.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    {{ $rooms->onEachSide(1)->links('pagination::bootstrap-5') }}
                </div>
            </div>
        </div>

        {{-- ===================== CARD VIEW ===================== --}}
        <div id="cardView" style="display: none;">

            @if($rooms->isEmpty())
                <div class="text-center py-5 text-muted">Chưa có dữ liệu phòng quay.</div>
            @else
                <div class="row g-3" id="roomCardGrid">
                    @foreach($rooms as $room)
                        @php
                            $requiredVotes = max(1, (int) $room->required_votes);
                            $currentVotes = (int) $room->current_votes;
                            $fillRate = min(100, (int) round(($currentVotes / $requiredVotes) * 100));
                            $isTrashed = $room->trashed();
                            $prizeDescription = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($room->prize_description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));

                            $badgeClass = match($room->status) {
                                'WAITING'   => 'bg-warning text-dark',
                                'FILLING'   => 'text-white',
                                'FULL'      => 'bg-primary',
                                'DRAWING'   => 'bg-info text-dark',
                                'COMPLETED' => 'bg-success',
                                'CANCELED'  => 'bg-danger',
                                default     => 'bg-secondary',
                            };
                            $badgeStyle = $room->status === 'FILLING' ? 'background-color:#6f42c1;' : '';
                            if ($isTrashed) {
                                $badgeClass = 'bg-dark';
                                $badgeStyle = '';
                            }
                            $statusLabel = match ($room->status) {
                                'WAITING' => 'Đang chờ Ticket',
                                'FILLING' => 'Đang nhận Ticket',
                                'FULL' => 'Đã đủ Ticket',
                                'DRAWING' => 'Đang quay',
                                'COMPLETED' => 'Đã quay',
                                'CANCELED' => 'Đã hủy',
                                default => 'Không xác định',
                            };
                            if ($isTrashed) {
                                $statusLabel = 'Đã xóa mềm';
                            }
                            $roomDate = optional($room->room_date)->format('Y-m-d') ?? 'N/A';
                            $openAt = optional($room->open_at)->format('H:i d/m') ?? 'N/A';
                            $closeAt = optional($room->close_at)->format('H:i d/m') ?? 'N/A';
                            $prizeName = $room->prizeTemplate?->prize_name ?? ($room->prize_type ?? 'N/A');
                            $prizeValue = $room->prize_value ? number_format($room->prize_value, 0, ',', '.') . ' VND' : 'N/A';
                            $createdAt = optional($room->created_at)->format('Y-m-d H:i:s') ?? 'N/A';
                            $drawCompletedAt = optional($room->draw_completed_at)->format('Y-m-d H:i:s') ?? 'N/A';
                        @endphp

                        <div class="col-12 col-sm-6 col-xl-4">
                            <div class="room-card h-100">

                                {{-- Card header: room code + badge --}}
                                <div class="room-card__header">
                                    <button type="button"
                                        class="btn btn-link p-0 room-detail-trigger room-card__code"
                                        data-bs-toggle="modal"
                                        data-bs-target="#roomDetailModal"
                                        data-room-id="{{ $room->id }}"
                                        data-room-code="{{ e($room->room_code) }}"
                                        data-room-number="{{ e($room->room_number) }}"
                                        data-room-name="{{ e($room->room_name ?: '') }}"
                                        data-room-date="{{ e($roomDate) }}"
                                        data-room-status="{{ e($room->status) }}"
                                        data-room-status-label="{{ e($statusLabel) }}"
                                        data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                        data-room-open-at="{{ e($openAt) }}"
                                        data-room-close-at="{{ e($closeAt) }}"
                                        data-room-required-votes="{{ number_format($requiredVotes) }}"
                                        data-room-current-votes="{{ number_format($currentVotes) }}"
                                        data-room-fill-rate="{{ $fillRate }}%"
                                        data-room-prize-name="{{ e($prizeName) }}"
                                        data-room-prize-value="{{ e($prizeValue) }}"
                                        data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                        data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                        data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                        data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                        data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                        data-room-created-at="{{ e($createdAt) }}">
                                        {{ $room->room_code }}
                                    </button>
                                        <span class="badge {{ $badgeClass }}" style="{{ $badgeStyle }}">
                                            {{ $statusLabel }}
                                        </span>
                                </div>

                                {{-- Thumbnail placeholder (swap src khi có ảnh thật) --}}
                                <div class="room-card__thumb">
                                    @if(!empty($room->prize_image))
                                        <img src="{{ $room->prize_image }}" alt="{{ $room->prizeTemplate?->prize_name ?? '' }}">
                                    @else
                                        <div class="room-card__thumb-placeholder">
                                            <i class="fas fa-desktop"></i>
                                        </div>
                                    @endif
                                </div>

                                <div class="room-card__body">

                                    {{-- Title row --}}
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <div>
                                            <div class="room-card__title">{{ $room->room_name ?: ('Phòng #' . $room->room_number) }}</div>
                                            <div class="room-card__date">Ngày quay: {{ $roomDate }}</div>
                                        </div>
                                        <button type="button"
                                            class="btn btn-link p-0 room-detail-trigger room-card__ticket-type"
                                            data-bs-toggle="modal"
                                            data-bs-target="#roomDetailModal"
                                            data-room-id="{{ $room->id }}"
                                            data-room-code="{{ e($room->room_code) }}"
                                            data-room-number="{{ e($room->room_number) }}"
                                            data-room-name="{{ e($room->room_name ?: '') }}"
                                            data-room-date="{{ e($roomDate) }}"
                                            data-room-status="{{ e($room->status) }}"
                                            data-room-status-label="{{ e($statusLabel) }}"
                                            data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                            data-room-open-at="{{ e($openAt) }}"
                                            data-room-close-at="{{ e($closeAt) }}"
                                            data-room-required-votes="{{ number_format($requiredVotes) }}"
                                            data-room-current-votes="{{ number_format($currentVotes) }}"
                                            data-room-fill-rate="{{ $fillRate }}%"
                                            data-room-prize-name="{{ e($prizeName) }}"
                                            data-room-prize-value="{{ e($prizeValue) }}"
                                            data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                            data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                            data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                            data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                            data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                            data-room-created-at="{{ e($createdAt) }}">
                                            {{ $room->ticket_type_code ?: 'N/A' }}
                                            @if(!empty($room->is_template))
                                                <span class="badge bg-dark ms-1" style="font-size:9px;">TPL</span>
                                            @endif
                                        </button>
                                    </div>

                                    {{-- Khung giờ --}}
                                    <div class="room-card__meta">
                                        <span>Mở: {{ $openAt }}</span>
                                        <span>Đóng: {{ $closeAt }}</span>
                                    </div>

                                    {{-- Tiến độ --}}
                                    <div class="room-card__progress-label">
                                        <span>Tiến độ</span>
                                        <span>{{ number_format($currentVotes) }} / {{ number_format($requiredVotes) }} ({{ $fillRate }}%)</span>
                                    </div>
                                    <div class="progress mb-2" style="height:5px;">
                                        <div class="progress-bar" role="progressbar"
                                            style="width:{{ $fillRate }}%;"
                                            aria-valuenow="{{ $fillRate }}" aria-valuemin="0" aria-valuemax="100">
                                        </div>
                                    </div>

                                    {{-- Giải thưởng box --}}
                                    <div class="room-card__prize">
                                        <div class="room-card__prize-info">
                                            <button type="button"
                                                class="btn btn-link p-0 room-detail-trigger room-card__prize-name"
                                                data-bs-toggle="modal"
                                                data-bs-target="#roomDetailModal"
                                                data-room-id="{{ $room->id }}"
                                                data-room-code="{{ e($room->room_code) }}"
                                                data-room-number="{{ e($room->room_number) }}"
                                                data-room-name="{{ e($room->room_name ?: '') }}"
                                                data-room-date="{{ e($roomDate) }}"
                                                data-room-status="{{ e($room->status) }}"
                                                data-room-status-label="{{ e($statusLabel) }}"
                                                data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                                data-room-open-at="{{ e($openAt) }}"
                                                data-room-close-at="{{ e($closeAt) }}"
                                                data-room-required-votes="{{ number_format($requiredVotes) }}"
                                                data-room-current-votes="{{ number_format($currentVotes) }}"
                                                data-room-fill-rate="{{ $fillRate }}%"
                                                data-room-prize-name="{{ e($prizeName) }}"
                                                data-room-prize-value="{{ e($prizeValue) }}"
                                                data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                                data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                                data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                                data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                                data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                                data-room-created-at="{{ e($createdAt) }}">
                                                {{ $prizeName }}
                                            </button>
                                            <div class="room-card__prize-price">
                                                {{ $prizeValue }}
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Kết quả quay --}}
                                    <div class="room-card__result">
                                        @if ($room->status === 'COMPLETED')
                                            <button type="button"
                                                class="btn btn-link p-0 room-detail-trigger room-card__result--win"
                                                data-bs-toggle="modal"
                                                data-bs-target="#roomDetailModal"
                                                data-room-id="{{ $room->id }}"
                                                data-room-code="{{ e($room->room_code) }}"
                                                data-room-number="{{ e($room->room_number) }}"
                                                data-room-name="{{ e($room->room_name ?: '') }}"
                                                data-room-date="{{ e($roomDate) }}"
                                                data-room-status="{{ e($room->status) }}"
                                                data-room-status-label="{{ e($statusLabel) }}"
                                                data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                                data-room-open-at="{{ e($openAt) }}"
                                                data-room-close-at="{{ e($closeAt) }}"
                                                data-room-required-votes="{{ number_format($requiredVotes) }}"
                                                data-room-current-votes="{{ number_format($currentVotes) }}"
                                                data-room-fill-rate="{{ $fillRate }}%"
                                                data-room-prize-name="{{ e($prizeName) }}"
                                                data-room-prize-value="{{ e($prizeValue) }}"
                                                data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                                data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                                data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                                data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                                data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                                data-room-created-at="{{ e($createdAt) }}">
                                                {{ $room->winner_name ?? 'N/A' }}
                                            </button>
                                        @elseif($room->status === 'DRAWING')
                                            <span class="room-card__result--drawing">
                                                Đang quay
                                            </span>
                                        @else
                                            <span class="room-card__result--empty">
                                                Chưa có kết quả
                                            </span>
                                        @endif
                                    </div>

                                </div>{{-- end card body --}}

                                {{-- Card footer: time + actions --}}
                                <div class="room-card__footer">
                                    <div class="room-card__actions">
                                        <button type="button"
                                            class="btn btn-sm btn-outline-secondary room-detail-trigger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#roomDetailModal"
                                            data-room-id="{{ $room->id }}"
                                            data-room-code="{{ e($room->room_code) }}"
                                            data-room-number="{{ e($room->room_number) }}"
                                            data-room-date="{{ e($roomDate) }}"
                                            data-room-status="{{ e($room->status) }}"
                                            data-room-status-label="{{ e($statusLabel) }}"
                                            data-room-ticket-type="{{ e($room->ticket_type_code ?: 'N/A') }}"
                                            data-room-open-at="{{ e($openAt) }}"
                                            data-room-close-at="{{ e($closeAt) }}"
                                            data-room-required-votes="{{ number_format($requiredVotes) }}"
                                            data-room-current-votes="{{ number_format($currentVotes) }}"
                                            data-room-fill-rate="{{ $fillRate }}%"
                                            data-room-prize-name="{{ e($prizeName) }}"
                                            data-room-prize-value="{{ e($prizeValue) }}"
                                            data-room-prize-desc="{{ e($prizeDescription ?: 'N/A') }}"
                                            data-room-prize-image="{{ e($room->prize_image ?? '') }}"
                                            data-room-winner-name="{{ e($room->winner_name ?? 'N/A') }}"
                                            data-room-winner-id="{{ e($room->winner_id ?? 'N/A') }}"
                                            data-room-draw-completed-at="{{ e($drawCompletedAt) }}"
                                            data-room-created-at="{{ e($createdAt) }}">
                                            Chi tiết
                                        </button>
                                        @if ($isTrashed)
                                            <form method="POST" action="{{ route('admin.rooms.restore', $room->id) }}"
                                                onsubmit="return confirm('Khôi phục phòng {{ $room->room_code }}? Phòng sẽ trống và sẵn sàng nhận vé mới.');"
                                                class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success">
                                                    Khôi phục
                                                </button>
                                            </form>
                                        @else
                                            <a href="{{ route('admin.rooms.edit', $room->id) }}" class="btn btn-sm btn-outline-primary">
                                                Sửa giải thưởng
                                            </a>
                                        @endif

                                        @if (!$isTrashed && in_array($room->status, ['WAITING', 'FILLING', 'FULL'], true) && empty($room->draw_started_at))
                                            <button type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                data-bs-toggle="modal"
                                                data-bs-target="#cancelRoomModal"
                                                data-room-id="{{ $room->id }}"
                                                data-action="{{ route('admin.rooms.emergency-cancel', $room->id) }}"
                                                data-room-code="{{ $room->room_code }}"
                                                data-room-tickets="{{ (int) ($room->room_tickets_count ?? 0) }}">
                                                Hủy khẩn
                                            </button>
                                            <button type="button"
                                                class="btn btn-sm btn-outline-dark"
                                                data-bs-toggle="modal"
                                                data-bs-target="#deleteRoomModal"
                                                data-room-id="{{ $room->id }}"
                                                data-action="{{ route('admin.rooms.destroy', $room->id) }}"
                                                data-room-code="{{ $room->room_code }}"
                                                data-room-tickets="{{ (int) ($room->room_tickets_count ?? 0) }}">
                                                Xóa mềm
                                            </button>

                                            @if ((int) $room->current_votes >= (int) $room->required_votes)
                                                <form method="POST" action="{{ route('admin.rooms.force-draw', $room->id) }}"
                                                    onsubmit="return confirm('Xác nhận force quay cho phòng {{ $room->room_code }}?');"
                                                    class="d-inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        Force quay
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-muted small d-block mt-1">Chưa đủ Ticket để force quay</span>
                                            @endif

                                        @elseif (!$isTrashed && in_array($room->status, ['WAITING', 'FILLING', 'FULL'], true) && (int) $room->current_votes >= (int) $room->required_votes)
                                            <form method="POST" action="{{ route('admin.rooms.force-draw', $room->id) }}"
                                                onsubmit="return confirm('Xác nhận force quay cho phòng {{ $room->room_code }}?');"
                                                class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-danger">
                                                    Force quay
                                                </button>
                                            </form>

                                        @elseif(!$isTrashed && $room->status === 'COMPLETED')
                                            <span class="badge bg-success">Đã quay xong</span>

                                        @elseif($isTrashed)
                                            <span class="text-muted small">Phòng đã ở thùng rác</span>

                                        @else
                                            <span class="text-muted small">Chưa đủ điều kiện</span>
                                        @endif
                                    </div>
                                </div>

                            </div>{{-- end .room-card --}}
                        </div>
                    @endforeach
                </div>{{-- end .row --}}
            @endif

            {{-- Pagination giữ nguyên --}}
            <div class="mt-3">
                {{ $rooms->onEachSide(1)->links('pagination::bootstrap-5') }}
            </div>
        </div>{{-- end #cardView --}}

    </div>

    {{-- ===================== MODAL HỦY KHẨN CẤP (giữ nguyên) ===================== --}}
    <div class="modal fade" id="cancelRoomModal" tabindex="-1" aria-labelledby="cancelRoomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="cancelRoomForm">
                    @csrf
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="cancelRoomModalLabel">Xác nhận hủy phòng khẩn cấp</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-3">
                            Hành động này không thể hoàn tác. Hệ thống sẽ hoàn toàn bộ Ticket tham gia về ví người dùng.
                        </div>
                        <p class="mb-2">Phòng: <strong id="cancelRoomCode">-</strong></p>
                        <p class="mb-3">Số Ticket tham gia dự kiến hoàn: <strong id="cancelRoomTickets">0</strong></p>

                        <label for="cancelReason" class="form-label">Lý do hủy <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancelReason" name="reason" rows="4" required minlength="10"
                            placeholder="Nhập lý do chi tiết để phục vụ đối soát và thông báo cho người dùng..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                        <button type="submit" class="btn btn-danger">Xác nhận hủy phòng</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteRoomModal" tabindex="-1" aria-labelledby="deleteRoomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="deleteRoomForm">
                    @csrf
                    @method('DELETE')
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="deleteRoomModalLabel">Xác nhận xóa mềm phòng</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            Phòng sẽ được đưa vào thùng rác. Toàn bộ Ticket đang tham gia sẽ được hoàn lại ví người dùng và phòng vẫn có thể khôi phục sau.
                        </div>
                        <p class="mb-2">Phòng: <strong id="deleteRoomCode">-</strong></p>
                        <p class="mb-3">Số Ticket dự kiến hoàn: <strong id="deleteRoomTickets">0</strong></p>

                        <label for="deleteReason" class="form-label">Lý do xóa mềm</label>
                        <textarea class="form-control" id="deleteReason" name="reason" rows="4" maxlength="1000"
                            placeholder="Nhập lý do để lưu vào lịch sử hoàn vé và thông báo cho người dùng..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                        <button type="submit" class="btn btn-dark">Xác nhận xóa mềm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ===================== MODAL CHI TIẾT PHÒNG ===================== --}}
    <div class="modal fade" id="roomDetailModal" tabindex="-1" aria-labelledby="roomDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="roomDetailModalLabel">Chi tiết phòng</h5>
                        <div class="text-muted small" id="detailRoomCode">-</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="ratio ratio-4x3 bg-light rounded overflow-hidden">
                                <img id="detailPrizeImage" src="" alt="Prize" class="w-100 h-100" style="object-fit: cover; display: none;">
                                <div id="detailPrizePlaceholder" class="d-flex align-items-center justify-content-center text-muted">
                                    Không có ảnh
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-2"><span class="text-muted">Mã phòng:</span> <strong id="detailRoomCodeText">-</strong></div>
                            <div class="mb-2"><span class="text-muted">Tên phòng:</span> <span id="detailRoomName">-</span></div>
                            <div class="mb-2"><span class="text-muted">Ngày quay:</span> <span id="detailRoomDate">-</span></div>
                            <div class="mb-2"><span class="text-muted">Trạng thái:</span> <span id="detailRoomStatus">-</span></div>
                            <div class="mb-2"><span class="text-muted">Tiến độ:</span> <span id="detailRoomProgress">-</span></div>
                            <div class="mb-2"><span class="text-muted">Loại Ticket:</span> <span id="detailTicketType">-</span></div>
                            <div class="mb-2"><span class="text-muted">Khung giờ:</span> <span id="detailRoomWindow">-</span></div>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="fw-semibold mb-1">Giải thưởng</div>
                            <div class="mb-1"><span class="text-muted">Tên:</span> <span id="detailPrizeName">-</span></div>
                            <div class="mb-1"><span class="text-muted">Giá trị:</span> <span id="detailPrizeValue">-</span></div>
                            <div><span class="text-muted">Mô tả:</span> <span id="detailPrizeDesc">-</span></div>
                        </div>
                        <div class="col-md-6">
                            <div class="fw-semibold mb-1">Kết quả quay</div>
                            <div class="mb-1"><span class="text-muted">Người thắng:</span> <span id="detailWinnerName">-</span></div>
                            <div class="mb-1"><span class="text-muted">Winner ID:</span> <span id="detailWinnerId">-</span></div>
                            <div class="mb-1"><span class="text-muted">Hoàn tất:</span> <span id="detailDrawCompletedAt">-</span></div>
                            <div><span class="text-muted">Tạo lúc:</span> <span id="detailCreatedAt">-</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== STYLES ===================== --}}
    <style>
        .room-table {
            table-layout: fixed;
            width: 100%;
        }

        .room-table th,
        .room-table td {
            vertical-align: top;
            white-space: normal;
            word-break: break-word;
        }

        .room-table th:nth-child(1),
        .room-table td:nth-child(1) {
            width: 140px;
        }

        .room-table th:nth-child(2),
        .room-table td:nth-child(2) {
            width: 140px;
        }

        .room-table th:nth-child(3),
        .room-table td:nth-child(3) {
            width: 120px;
        }

        .room-table th:nth-child(4),
        .room-table td:nth-child(4) {
            width: 110px;
        }

        .room-table th:nth-child(5),
        .room-table td:nth-child(5) {
            width: 120px;
        }

        .room-table th:nth-child(6),
        .room-table td:nth-child(6) {
            width: 220px;
        }

        .room-table th:nth-child(7),
        .room-table td:nth-child(7) {
            width: 120px;
        }

        .room-table th:nth-child(8),
        .room-table td:nth-child(8) {
            width: 160px;
        }

        .room-table th:nth-child(9),
        .room-table td:nth-child(9) {
            width: 220px;
        }

        .room-table th:nth-child(10),
        .room-table td:nth-child(10) {
            width: 180px;
        }

        .room-table__result small {
            display: block;
        }
        /* --- Toggle button active state --- */
        #viewToggle .btn.active {
            background-color: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }

        /* ---- ROOM CARD BASE ---- */
        .room-card {
            background: #fff;
            border: 1px solid #ced4da;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: box-shadow .2s;
        }
        .room-card:hover {
            box-shadow: 0 4px 18px rgba(0,0,0,.09);
        }

        /* Header strip */
        .room-card__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 14px 6px;
            background: #f8f9fa;
            border-bottom: 1px solid #f0f0f0;
        }
        .room-card__code {
            font-size: 12px;
            font-weight: 600;
            color: #495057;
            font-family: monospace;
        }

        /* Thumbnail */
        .room-card__thumb {
            width: 100%;
            height: 120px;
            overflow: hidden;
            background: #f1f5f9;
        }
        .room-card__thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .room-card__thumb-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            font-size: 36px;
        }

        /* Body */
        .room-card__body {
            padding: 12px 14px;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .room-card__title {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
        }
        .room-card__date {
            font-size: 12px;
            color: #64748b;
        }
        .room-card__ticket-type {
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0369a1;
            white-space: nowrap;
            align-self: flex-start;
        }
        .room-card__meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            color: #64748b;
        }
        .room-card__progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #64748b;
            margin-bottom: 2px;
        }

        /* Prize box */
        .room-card__prize {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            margin-top: 2px;
        }
        .room-card__prize-icon {
            font-size: 18px;
            flex-shrink: 0;
            line-height: 1;
        }
        .room-card__prize-info {
            flex: 1;
            min-width: 0;
        }
        .room-card__prize-name {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .room-card__prize-price {
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 2px;
        }
        .room-card__prize-desc {
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
        }
        .room-card__expand-btn {
            background: none;
            border: none;
            padding: 2px 0;
            font-size: 11px;
            color: #2563eb;
            cursor: pointer;
        }
        .room-card__expand-btn:hover { text-decoration: underline; }

        /* Result */
        .room-card__result {
            font-size: 12px;
            margin-top: 2px;
        }
        .room-card__result--win    { color: #15803d; font-weight: 500; }
        .room-card__result--drawing { color: #0284c7; font-weight: 500; }
        .room-card__result--empty  { color: #94a3b8; }

        /* Footer */
        .room-card__footer {
            border-top: 1px solid #f0f0f0;
            padding: 8px 14px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 6px;
            background: #fafafa;
        }
        .room-card__actions {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            align-items: center;
        }
        .room-card__actions .btn-sm {
            font-size: 12px;
            padding: 3px 8px;
        }
    </style>

    {{-- ===================== SCRIPTS ===================== --}}
    <script>
        /* ---- Modal hủy (giữ nguyên) ---- */
        document.addEventListener('DOMContentLoaded', function () {
            var modal = document.getElementById('cancelRoomModal');
            var form  = document.getElementById('cancelRoomForm');
            var roomCodeEl    = document.getElementById('cancelRoomCode');
            var roomTicketsEl = document.getElementById('cancelRoomTickets');
            var deleteModal = document.getElementById('deleteRoomModal');
            var deleteForm = document.getElementById('deleteRoomForm');
            var deleteRoomCodeEl = document.getElementById('deleteRoomCode');
            var deleteRoomTicketsEl = document.getElementById('deleteRoomTickets');
            var deleteReasonEl = document.getElementById('deleteReason');

            modal.addEventListener('show.bs.modal', function (event) {
                var button     = event.relatedTarget;
                var roomId     = button.getAttribute('data-room-id');
                var actionUrl  = button.getAttribute('data-action');
                var roomCode   = button.getAttribute('data-room-code');
                var roomTickets = button.getAttribute('data-room-tickets') || '0';

                form.action = actionUrl || ("{{ url('admin/rooms') }}/" + roomId + "/emergency-cancel");
                roomCodeEl.textContent    = roomCode;
                roomTicketsEl.textContent = roomTickets;
            });

            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    var roomId = button.getAttribute('data-room-id');
                    var actionUrl = button.getAttribute('data-action');
                    var roomCode = button.getAttribute('data-room-code');
                    var roomTickets = button.getAttribute('data-room-tickets') || '0';

                    deleteForm.action = actionUrl || ("{{ url('admin/rooms') }}/" + roomId);
                    deleteRoomCodeEl.textContent = roomCode;
                    deleteRoomTicketsEl.textContent = roomTickets;
                    if (deleteReasonEl) {
                        deleteReasonEl.value = 'Xóa mềm phòng ' + roomCode + ' bởi quản trị viên.';
                    }
                });
            }

            /* ---- View toggle ---- */
            var tableView   = document.getElementById('tableView');
            var cardView    = document.getElementById('cardView');
            var btnTable    = document.getElementById('btnTableView');
            var btnCard     = document.getElementById('btnCardView');
            var STORAGE_KEY = 'roomsViewMode';

            function switchView(mode) {
                if (mode === 'card') {
                    tableView.style.display = 'none';
                    cardView.style.display  = 'block';
                    btnCard.classList.add('active');
                    btnTable.classList.remove('active');
                } else {
                    tableView.style.display = 'block';
                    cardView.style.display  = 'none';
                    btnTable.classList.add('active');
                    btnCard.classList.remove('active');
                }
                localStorage.setItem(STORAGE_KEY, mode);
            }

            /* Restore từ lần trước */
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'card') switchView('card');

            btnTable.addEventListener('click', function () { switchView('table'); });
            btnCard.addEventListener('click',  function () { switchView('card');  });
        });
        var detailModal = document.getElementById('roomDetailModal');
        if (detailModal) {
            detailModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                if (!button) return;

                var data = button.dataset;
                var roomCodeText = data.roomCode || '-';
                var detailPrizeImage = document.getElementById('detailPrizeImage');
                var detailPrizePlaceholder = document.getElementById('detailPrizePlaceholder');

                document.getElementById('detailRoomCode').textContent = roomCodeText;
                document.getElementById('detailRoomCodeText').textContent = roomCodeText;
                document.getElementById('detailRoomName').textContent = data.roomName || data.roomNumber || '-';
                document.getElementById('detailRoomDate').textContent = data.roomDate || '-';
                document.getElementById('detailRoomStatus').textContent = data.roomStatusLabel || '-';
                document.getElementById('detailRoomProgress').textContent = (data.roomCurrentVotes || '-') + ' / ' + (data.roomRequiredVotes || '-') + ' (' + (data.roomFillRate || '-') + ')';
                document.getElementById('detailTicketType').textContent = data.roomTicketType || '-';
                document.getElementById('detailRoomWindow').textContent = (data.roomOpenAt || '-') + ' → ' + (data.roomCloseAt || '-');
                document.getElementById('detailPrizeName').textContent = data.roomPrizeName || '-';
                document.getElementById('detailPrizeValue').textContent = data.roomPrizeValue || '-';
                document.getElementById('detailPrizeDesc').textContent = data.roomPrizeDesc || '-';
                document.getElementById('detailWinnerName').textContent = data.roomWinnerName || '-';
                document.getElementById('detailWinnerId').textContent = data.roomWinnerId || '-';
                document.getElementById('detailDrawCompletedAt').textContent = data.roomDrawCompletedAt || '-';
                document.getElementById('detailCreatedAt').textContent = data.roomCreatedAt || '-';

                if (data.roomPrizeImage) {
                    detailPrizeImage.src = data.roomPrizeImage;
                    detailPrizeImage.style.display = 'block';
                    detailPrizePlaceholder.style.display = 'none';
                } else {
                    detailPrizeImage.src = '';
                    detailPrizeImage.style.display = 'none';
                    detailPrizePlaceholder.style.display = 'flex';
                }
            });
        }
    </script>
@endsection
