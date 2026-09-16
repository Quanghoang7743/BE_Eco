@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <h2 class="mb-4">Danh sách người trúng thưởng (S08)</h2>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.winners.index') }}" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Người trúng (tên/SĐT)</label>
                        <input type="text" class="form-control" name="winner_keyword" value="{{ request('winner_keyword') }}"
                            placeholder="Nhập tên hoặc số điện thoại">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Mã phòng</label>
                        <input type="text" class="form-control" name="room_code" value="{{ request('room_code') }}"
                            placeholder="VD: R20260406-001">
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Mã Ticket / Ticket ID</label>
                        <input type="text" class="form-control" name="ticket_keyword" value="{{ request('ticket_keyword') }}"
                            placeholder="ticket_code hoặc winner_ticket_id">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.winners.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" id="winnerViewTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="table-view-tab" data-bs-toggle="tab" data-bs-target="#table-view-pane"
                    type="button" role="tab" aria-controls="table-view-pane" aria-selected="true">
                    Giao diện bảng
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="card-view-tab" data-bs-toggle="tab" data-bs-target="#card-view-pane"
                    type="button" role="tab" aria-controls="card-view-pane" aria-selected="false">
                    Giao diện thẻ
                </button>
            </li>
        </ul>

        <div class="tab-content" id="winnerViewTabsContent">
            <div class="tab-pane fade show active" id="table-view-pane" role="tabpanel" aria-labelledby="table-view-tab"
                tabindex="0">
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                                    <th style="min-width:240px">Người trúng</th>
                                                    <th style="min-width:160px">Phòng quay</th>
                                                    <th style="min-width:110px">Ngày quay</th>
                                                    <th style="min-width:150px">Thời điểm hoàn tất</th>
                                                    <th style="min-width:220px">Giải thưởng</th>
                                                    <th style="min-width:300px">Bằng chứng minh bạch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($winners as $winner)
                                        <tr>
                                            <td style="min-width: 240px;">
                                                <div class="fw-semibold">{{ $winner->winner_display_name ?? 'N/A' }}</div>
                                                <div><small class="text-muted">Mã người trúng: {{ $winner->winner_id ?? 'N/A' }}</small></div>
                                                <div><small class="text-muted">SĐT: {{ $winner->winner_phone ?? 'N/A' }}</small></div>
                                                <div><small class="text-muted">Email: {{ $winner->winner_email ?? 'N/A' }}</small></div>
                                                @if(!empty($winner->winner_avatar))
                                                    <div class="mt-1"><img src="{{ $winner->winner_avatar }}" alt="Avatar" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid #e9ecef"></div>
                                                @endif
                                            </td>

                                            <td style="min-width: 180px;">
                                                <div class="fw-semibold">{{ $winner->room_code }}</div>
                                                <div><small class="text-muted">Room #: {{ $winner->room_number }}</small></div>
                                            </td>

                                            <td>{{ optional($winner->room_date)->format('Y-m-d') }}</td>
                                            <td>{{ optional($winner->draw_completed_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</td>

                                            <td style="min-width: 220px;">
                                                <div><strong>{{ $winner->prize_type ?? 'N/A' }}</strong></div>
                                                <div><small class="text-muted">Giá trị: {{ $winner->prize_value !== null ? number_format((float) $winner->prize_value, 0, ',', '.') . ' VND' : 'N/A' }}</small></div>
                                                @php
                                                    $rawDesc = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($winner->prize_description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                                                    $shortDesc = mb_strlen($rawDesc) > 120 ? mb_substr($rawDesc, 0, 120) . '…' : $rawDesc;
                                                @endphp
                                                @if($rawDesc === '')
                                                    <div><small class="text-muted">Mô tả: N/A</small></div>
                                                @else
                                                    <div class="mt-1">
                                                        <small class="text-muted d-block" id="winner-desc-short-{{ $winner->id }}">Mô tả: {{ $shortDesc }}</small>
                                                        @if(mb_strlen($rawDesc) > 120)
                                                            <small class="text-muted d-none d-block" id="winner-desc-full-{{ $winner->id }}">Mô tả: {{ $rawDesc }}</small>
                                                            <button type="button" class="btn btn-link p-0 small" onclick="toggleWinnerDesc({{ $winner->id }}, this)">Xem thêm</button>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>

                                            <td style="min-width: 300px;">
                                                <div><small class="text-muted">Ticket ID: {{ $winner->winner_ticket_id ?? 'N/A' }}</small></div>
                                                <div><small class="text-muted">Mã Ticket: {{ $winner->winner_ticket_code ?? 'N/A' }}</small></div>
                                                @if(!empty($winner->tx_hash))
                                                    @php $txShort = substr($winner->tx_hash, 0, 8) . '…' . substr($winner->tx_hash, -8); @endphp
                                                    <div><small class="text-muted" title="{{ $winner->tx_hash }}">Tx: {{ $txShort }}</small></div>
                                                @endif
                                                @if(!empty($winner->block_hash))
                                                    @php $blkShort = substr($winner->block_hash, 0, 8) . '…' . substr($winner->block_hash, -8); @endphp
                                                    <div><small class="text-muted" title="{{ $winner->block_hash }}">Block: {{ $blkShort }}</small></div>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center py-4">Chưa có dữ liệu người trúng thưởng.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer">
                        {{ $winners->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="card-view-pane" role="tabpanel" aria-labelledby="card-view-tab" tabindex="0">
                <div class="row g-3">
                    @forelse ($winners as $winner)
                        <div class="col-xl-4 col-lg-6">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0 fw-bold">{{ $winner->winner_display_name ?? 'N/A' }}</h6>
                                        <span class="badge bg-primary">{{ optional($winner->room_date)->format('Y-m-d') ?? 'N/A' }}</span>
                                    </div>

                                    <div class="mb-2">
                                        <div><small class="text-muted">SĐT: {{ $winner->winner_phone ?? 'N/A' }}</small></div>
                                        <div><small class="text-muted">Email: {{ $winner->winner_email ?? 'N/A' }}</small></div>
                                        <div><small class="text-muted">Mã người trúng: {{ $winner->winner_id ?? 'N/A' }}</small></div>
                                    </div>

                                    <hr class="my-2">

                                    <div class="mb-2">
                                        <div class="fw-semibold">{{ $winner->room_code }}</div>
                                        <div><small class="text-muted">Phòng số: {{ $winner->room_number }}</small></div>
                                        <div><small class="text-muted">Hoàn tất: {{ optional($winner->draw_completed_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</small></div>
                                    </div>

                                    <div class="mb-2">
                                        <div><strong>{{ $winner->prize_type ?? 'N/A' }}</strong></div>
                                        <div><small class="text-muted">Giá trị: {{ $winner->prize_value !== null ? number_format((float) $winner->prize_value, 0, ',', '.') . ' VND' : 'N/A' }}</small></div>
                                        @php
                                            $rawCardDesc = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($winner->prize_description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                                            $shortCardDesc = mb_strlen($rawCardDesc) > 120 ? mb_substr($rawCardDesc, 0, 120) . '…' : $rawCardDesc;
                                        @endphp
                                        @if($rawCardDesc === '')
                                            <div><small class="text-muted">Mô tả: N/A</small></div>
                                        @else
                                            <div>
                                                <small class="text-muted d-block" id="winner-card-desc-short-{{ $winner->id }}">Mô tả: {{ $shortCardDesc }}</small>
                                                @if(mb_strlen($rawCardDesc) > 120)
                                                    <small class="text-muted d-none" id="winner-card-desc-full-{{ $winner->id }}">Mô tả: {{ $rawCardDesc }}</small>
                                                    <button type="button" class="btn btn-link p-0" onclick="toggleWinnerCardDesc({{ $winner->id }}, this)">Xem thêm</button>
                                                @endif
                                            </div>
                                        @endif
                                    </div>

                                    <hr class="my-2">

                                    <div>
                                        <div><small class="text-muted">Ticket ID: {{ $winner->winner_ticket_id ?? 'N/A' }}</small></div>
                                        <div><small class="text-muted">Mã Ticket: {{ $winner->winner_ticket_code ?? 'N/A' }}</small></div>
                                        @if(!empty($winner->tx_hash))
                                            @php $txShort = substr($winner->tx_hash, 0, 8) . '…' . substr($winner->tx_hash, -8); @endphp
                                            <div><small class="text-muted" title="{{ $winner->tx_hash }}">Tx: {{ $txShort }}</small></div>
                                        @endif
                                        @if(!empty($winner->block_hash))
                                            @php $blkShort = substr($winner->block_hash, 0, 8) . '…' . substr($winner->block_hash, -8); @endphp
                                            <div><small class="text-muted" title="{{ $winner->block_hash }}">Block: {{ $blkShort }}</small></div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body text-center py-4">Chưa có dữ liệu người trúng thưởng.</div>
                            </div>
                        </div>
                    @endforelse
                </div>

                <div class="card mt-3">
                    <div class="card-footer">
                        {{ $winners->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
    function toggleWinnerDesc(id, btn) {
        var shortEl = document.getElementById('winner-desc-short-' + id);
        var fullEl = document.getElementById('winner-desc-full-' + id);
        if (!fullEl) return;
        if (fullEl.classList.contains('d-none')) {
            fullEl.classList.remove('d-none');
            shortEl.classList.add('d-none');
            btn.innerHTML = 'Thu gọn';
        } else {
            fullEl.classList.add('d-none');
            shortEl.classList.remove('d-none');
            btn.innerHTML = 'Xem thêm';
        }
    }

    function toggleWinnerCardDesc(id, btn) {
        var shortEl = document.getElementById('winner-card-desc-short-' + id);
        var fullEl = document.getElementById('winner-card-desc-full-' + id);
        if (!fullEl) return;
        if (fullEl.classList.contains('d-none')) {
            fullEl.classList.remove('d-none');
            shortEl.classList.add('d-none');
            btn.innerHTML = 'Thu gọn';
        } else {
            fullEl.classList.add('d-none');
            shortEl.classList.remove('d-none');
            btn.innerHTML = 'Xem thêm';
        }
    }
</script>

@endsection
