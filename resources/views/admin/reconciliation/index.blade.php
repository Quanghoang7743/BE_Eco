@extends('admin.layout')

@section('title', 'Quản lý đối soát')

@section('content')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
    .filter-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        background: #ffffff;
    }
    .table-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        background: #ffffff;
        overflow: hidden;
    }
    .badge-status {
        border-radius: 9999px;
        padding: 4px 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        text-align: center;
        width: 100px;
    }
    .badge-pending {
        background-color: #ffedd5;
        color: #9a3412;
    }
    .badge-approved {
        background-color: #d1fae5;
        color: #065f46;
    }
    .badge-rejected {
        background-color: #fee2e2;
        color: #991b1b;
    }
    .badge-disputed {
        background-color: #fef3c7;
        color: #92400e;
    }
    .badge-secondary {
        background-color: #f1f5f9;
        color: #475569;
    }
    .action-detail-btn {
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
        border: 1px solid #e2e8f0;
        background: #ffffff;
        color: #0f172a;
        transition: all 0.2s;
        padding: 4px 12px;
    }
    .action-detail-btn:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #0f172a;
        text-decoration: none;
    }
    .action-check-btn {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        background-color: #10b981;
        border: none;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .action-check-btn:hover {
        background-color: #059669;
        color: #ffffff;
    }
    .action-check-btn:disabled {
        background-color: #cbd5e1;
        opacity: 0.6;
        cursor: not-allowed;
    }
    .pagination .page-item.active .page-link {
        background-color: #10b981;
        border-color: #10b981;
    }
    .pagination .page-link {
        color: #10b981;
    }
</style>

<div class="page-inner">
    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="page-title fw-bold">Quản lý đối soát</h4>
            <ul class="breadcrumbs">
                <li class="nav-home">
                    <a href="{{ route('admin') }}" class="text-muted"><i class="fas fa-home"></i></a>
                </li>
                <li class="separator"><i class="fas fa-chevron-right text-muted" style="font-size: 10px;"></i></li>
                <li class="nav-item"><a href="#" class="text-muted">Đối soát</a></li>
                <li class="separator"><i class="fas fa-chevron-right text-muted" style="font-size: 10px;"></i></li>
                <li class="nav-item"><a href="#" class="text-dark fw-bold">Quản lý đối soát</a></li>
            </ul>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 8px; background-color: #f0fdf4; color: #166534;">
            <i class="fas fa-check-circle me-2"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" role="alert" style="border-radius: 8px; background-color: #fef2f2; color: #991b1b;">
            <i class="fas fa-exclamation-circle me-2"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-md-12">
            <!-- Filter Section -->
            <div class="card filter-card mb-4">
                <div class="card-body p-4">
                    <form method="GET" action="{{ route('admin.reconciliation.index') }}" id="filter-form" class="row g-3 align-items-end">
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-bold text-muted mb-1">Cửa hàng</label>
                            <select name="store_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Tất cả cửa hàng</option>
                                @foreach($stores as $s)
                                    <option value="{{ $s->id }}" {{ request()->query('store_id') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <label class="form-label small fw-bold text-muted mb-1">Trạng thái</label>
                            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Tất cả trạng thái</option>
                                <option value="pending" {{ request()->query('status') === 'pending' ? 'selected' : '' }}>Chờ duyệt</option>
                                <option value="approved" {{ request()->query('status') === 'approved' ? 'selected' : '' }}>Đã duyệt</option>
                                <option value="rejected" {{ request()->query('status') === 'rejected' ? 'selected' : '' }}>Bị từ chối</option>
                                <option value="paid" {{ request()->query('status') === 'paid' ? 'selected' : '' }}>Đã thanh toán</option>
                            </select>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-bold text-muted mb-1">Khoảng ngày</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="far fa-calendar-alt text-muted"></i></span>
                                <input type="text" name="date_range" id="date-range-picker" class="form-control form-control-sm border-start-0" placeholder="Chọn khoảng ngày" value="{{ request()->query('date_range') }}">
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <label class="form-label small fw-bold text-muted mb-1">Tìm kiếm cửa hàng</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                                <input type="text" name="keyword" class="form-control form-control-sm border-start-0" placeholder="Tìm kiếm cửa hàng..." value="{{ request()->query('keyword') }}">
                            </div>
                        </div>
                        <div class="col-md-1 col-sm-6">
                            <a href="{{ route('admin.reconciliation.index') }}" class="btn btn-sm btn-outline-success w-100 d-flex align-items-center justify-content-center gap-1" style="height: 31px; border-radius: 6px;">
                                <i class="fas fa-undo-alt"></i> Làm mới
                            </a>
                        </div>
                        <input type="hidden" name="per_page" id="filter-per-page" value="{{ request()->query('per_page', 10) }}">
                    </form>
                </div>
            </div>

            <!-- Table Section -->
            <div class="card table-card">
                <div class="card-body p-0" id="reconciliation-card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="min-width: 1000px;">
                            <thead class="table-light text-muted uppercase small">
                                <tr>
                                    <th class="ps-4" style="width: 60px;">#</th>
                                    <th>Cửa hàng</th>
                                    <th>Ngày đối soát</th>
                                    <th class="text-end">Doanh thu gộp</th>
                                    <th class="text-end">Tổng trừ</th>
                                    <th class="text-end">Thực nhận</th>
                                    <th class="text-center" style="width: 140px;">Trạng thái</th>
                                    <th>Ngày xác nhận</th>
                                    <th class="text-center" style="width: 180px;">Hành động</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reconciliations as $reconciliation)
                                    @php
                                        $deduction = (float)$reconciliation->gross_revenue - (float)$reconciliation->net_revenue;
                                    @endphp
                                    <tr>
                                        <td class="ps-4 text-muted">{{ $reconciliation->id }}</td>
                                        <td class="fw-semibold text-dark">
                                            {{ $reconciliation->store->name ?? 'N/A' }}
                                        </td>
                                        <td>{{ $reconciliation->reconciliation_date?->format('d/m/Y') ?? 'N/A' }}</td>
                                        <td class="text-end fw-semibold text-dark">
                                            {{ number_format($reconciliation->gross_revenue, 0, ',', '.') }} VND
                                        </td>
                                        <td class="text-end text-danger">
                                            @if($deduction > 0)
                                                -{{ number_format($deduction, 0, ',', '.') }} VND
                                            @elseif($deduction < 0)
                                                +{{ number_format(abs($deduction), 0, ',', '.') }} VND
                                            @else
                                                0 VND
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold {{ $reconciliation->net_revenue >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ number_format($reconciliation->net_revenue, 0, ',', '.') }} VND
                                        </td>
                                        <td class="text-center">
                                            @php $status = $reconciliation->status; @endphp
                                            @if($status === 'pending' || $status === 'pending_shop' || $status === 'waiting_payment')
                                                <span class="badge-status badge-pending">Chờ duyệt</span>
                                            @elseif(in_array($status, ['approved', 'paid', 'closed', 'matched']))
                                                <span class="badge-status badge-approved">Đã duyệt</span>
                                            @elseif(in_array($status, ['rejected', 'rejected_shop']))
                                                <span class="badge-status badge-rejected">Bị từ chối</span>
                                            @elseif($status === 'disputed')
                                                <span class="badge-status badge-disputed">Lệch</span>
                                            @else
                                                <span class="badge-status badge-secondary">{{ ucfirst($status) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $reconciliation->date_confirmed?->format('d/m/Y H:i') ?? 'N/A' }}</td>
                                        <td class="text-center">
                                            <div class="d-flex align-items-center justify-content-center gap-1">
                                                <a href="{{ route('admin.reconciliation.show', $reconciliation) }}" 
                                                   class="action-detail-btn">
                                                    Xem chi tiết
                                                </a>
                                                @if($reconciliation->status === 'pending')
                                                    @if($reconciliation->is_latest_unapproved)
                                                        <button type="button" class="action-check-btn" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#approveModal{{ $reconciliation->id }}"
                                                                title="Duyệt nhanh">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    @else
                                                        <button type="button" class="action-check-btn" disabled title="Chỉ được duyệt đơn mới nhất của cửa hàng">
                                                            <i class="fas fa-lock"></i>
                                                        </button>
                                                    @endif
                                                @else
                                                    <button type="button" class="action-check-btn" disabled title="Đã xử lý">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Approve Modal -->
                                    @if($reconciliation->status === 'pending' && $reconciliation->is_latest_unapproved)
                                        <div class="modal fade" id="approveModal{{ $reconciliation->id }}" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
                                                    <div class="modal-header border-0 pb-0">
                                                        <h5 class="modal-title d-none">Duyệt đối soát</h5>
                                                        <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <form action="{{ route('admin.reconciliation.approve', $reconciliation) }}" method="POST">
                                                        @csrf
                                                        @method('PATCH')
                                                        <div class="modal-body pt-0 px-4">
                                                            <div class="text-center mb-3">
                                                                <i class="fas fa-check-circle text-success" style="font-size: 48px;"></i>
                                                                <h4 class="mt-3 fw-bold text-dark">Duyệt đối soát</h4>
                                                                <p class="text-muted small">Bạn có chắc chắn muốn duyệt đối soát này?</p>
                                                            </div>
                                                            
                                                            <div class="p-3 mb-3" style="background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 6px;">
                                                                <div class="d-flex justify-content-between mb-1">
                                                                    <span class="text-muted small">Cửa hàng:</span>
                                                                    <span class="fw-bold text-dark">{{ $reconciliation->store?->name ?? 'N/A' }}</span>
                                                                </div>
                                                                <div class="d-flex justify-content-between mb-1">
                                                                    <span class="text-muted small">Ngày đối soát:</span>
                                                                    <span class="fw-bold text-dark">{{ $reconciliation->reconciliation_date?->format('d/m/Y') ?? 'N/A' }}</span>
                                                                </div>
                                                                <div class="d-flex justify-content-between">
                                                                    <span class="text-muted small">Thực nhận:</span>
                                                                    <span class="fw-bold text-success">{{ number_format($reconciliation->net_revenue, 0, ',', '.') }} VND</span>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-3">
                                                                <label class="form-label small fw-bold text-muted mb-1">Ghi chú (không bắt buộc)</label>
                                                                <textarea name="notes" id="notes-approve-{{ $reconciliation->id }}" class="form-control" rows="3" placeholder="Nhập ghi chú nếu cần..." style="border-radius: 6px; resize: none;" maxlength="300" oninput="updateCharCount({{ $reconciliation->id }})"></textarea>
                                                                <div class="text-end text-muted small mt-1"><span id="char-count-{{ $reconciliation->id }}">0</span>/300</div>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0 pt-0 px-4 pb-4 d-flex justify-content-end gap-2">
                                                            <button type="button" class="btn btn-sm btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 6px;">Hủy</button>
                                                            <button type="submit" class="btn btn-sm btn-success px-4 d-flex align-items-center gap-1" style="border-radius: 6px; background-color: #10b981; border-color: #10b981;">
                                                                <i class="fas fa-check"></i> Duyệt
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center text-muted py-5">
                                            <i class="fas fa-inbox fa-2x mb-2 d-block text-muted" style="opacity: 0.5;"></i>
                                            Không có dữ liệu đối soát phù hợp
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-4 border-top">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="text-muted small">
                                Hiển thị {{ $reconciliations->firstItem() ?? 0 }} - {{ $reconciliations->lastItem() ?? 0 }} của {{ $reconciliations->total() }} kết quả
                            </div>
                            
                            <div>
                                {{ $reconciliations->appends(request()->query())->links('pagination::bootstrap-4') }}
                            </div>
                            
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small text-nowrap">Số dòng:</span>
                                <select id="per-page-select" class="form-select form-select-sm" style="width: auto;">
                                    <option value="5" {{ request()->query('per_page') == 5 ? 'selected' : '' }}>5 / trang</option>
                                    <option value="10" {{ request()->query('per_page', 10) == 10 ? 'selected' : '' }}>10 / trang</option>
                                    <option value="20" {{ request()->query('per_page') == 20 ? 'selected' : '' }}>20 / trang</option>
                                    <option value="50" {{ request()->query('per_page') == 50 ? 'selected' : '' }}>50 / trang</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        flatpickr("#date-range-picker", {
            mode: "range",
            dateFormat: "d/m/Y",
            locale: {
                rangeSeparator: " - "
            },
            onClose: function(selectedDates, dateStr, instance) {
                if (selectedDates.length === 2) {
                    document.getElementById('filter-form').submit();
                }
            }
        });

        document.getElementById('per-page-select').addEventListener('change', function() {
            document.getElementById('filter-per-page').value = this.value;
            document.getElementById('filter-form').submit();
        });
    });

    function updateCharCount(id) {
        const textarea = document.getElementById('notes-approve-' + id);
        const countSpan = document.getElementById('char-count-' + id);
        countSpan.textContent = textarea.value.length;
    }
</script>

<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
<script>
    (function () {
        if (typeof Pusher === 'undefined') {
            return;
        }

        const channelName = 'finance.reconciliations';
        const eventName = 'finance.reconciliation.updated';
        const refreshTarget = document.getElementById('reconciliation-card-body');

        if (!refreshTarget) {
            return;
        }

        const appKey = @json(config('broadcasting.connections.pusher.key'));
        const appCluster = @json(config('broadcasting.connections.pusher.options.cluster'));

        if (!appKey) {
            return;
        }

        Pusher.logToConsole = false;

        const pusher = new Pusher(appKey, {
            cluster: appCluster || undefined,
            forceTLS: true,
            disableStats: true,
        });

        let refreshing = false;
        let refreshQueued = false;

        const replaceCardBody = async () => {
            if (refreshing) {
                refreshQueued = true;
                return;
            }

            refreshing = true;

            try {
                const response = await fetch(window.location.href, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Cache-Control': 'no-cache',
                    },
                    cache: 'no-store',
                });

                if (!response.ok) {
                    return;
                }

                const html = await response.text();
                const parser = new DOMParser();
                const nextDocument = parser.parseFromString(html, 'text/html');
                const nextCardBody = nextDocument.getElementById('reconciliation-card-body');

                if (nextCardBody) {
                    refreshTarget.innerHTML = nextCardBody.innerHTML;
                    // Re-bind pagination selector event listeners
                    const newSelect = document.getElementById('per-page-select');
                    if (newSelect) {
                        newSelect.addEventListener('change', function() {
                            document.getElementById('filter-per-page').value = this.value;
                            document.getElementById('filter-form').submit();
                        });
                    }
                }
            } catch (error) {
                console.error('Unable to refresh reconciliation list', error);
            } finally {
                refreshing = false;

                if (refreshQueued) {
                    refreshQueued = false;
                    replaceCardBody();
                }
            }
        };

        const channel = pusher.subscribe(channelName);
        channel.bind(eventName, function () {
            replaceCardBody();
        });

        window.addEventListener('beforeunload', function () {
            channel.unbind(eventName);
            pusher.unsubscribe(channelName);
            pusher.disconnect();
        });
    })();
</script>
@endsection
