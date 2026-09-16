@extends('admin.layout')

@section('title', 'Chi tiết đối soát')

@section('content')
<style>
    .card-custom {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        background: #ffffff;
    }
    .stat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px 16px;
        height: 100%;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: all 0.2s;
    }
    .stat-card:hover {
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        border-color: #cbd5e1;
    }
    .stat-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
    }
    .icon-blue {
        background-color: #eff6ff;
        color: #3b82f6;
    }
    .icon-green {
        background-color: #ecfdf5;
        color: #10b981;
    }
    .icon-purple {
        background-color: #f5f3ff;
        color: #8b5cf6;
    }
    .icon-red {
        background-color: #fef2f2;
        color: #ef4444;
    }
    .stat-info {
        display: flex;
        flex-direction: column;
    }
    .stat-label {
        font-size: 13px;
        color: #64748b;
        margin-bottom: 4px;
        font-weight: 500;
    }
    .stat-value {
        font-size: 16px;
        font-weight: 700;
        color: #0f172a;
    }
    .stat-card-net {
        background: #f0fdf4;
        border: 1px solid #10b981;
    }
    .stat-card-net:hover {
        border-color: #059669;
    }
    .stat-card-net .stat-value {
        color: #10b981;
        font-size: 20px;
    }
    .stat-card-net .stat-label {
        color: #047857;
        font-weight: 600;
    }
    .action-card {
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        background: #ffffff;
        padding: 24px;
    }
    .btn-approve-custom {
        background-color: #10b981;
        border-color: #10b981;
        color: #ffffff;
        font-weight: 600;
        padding: 10px;
        border-radius: 8px;
        transition: all 0.2s;
    }
    .btn-approve-custom:hover {
        background-color: #059669;
        border-color: #059669;
        color: #ffffff;
    }
    .btn-reject-custom {
        background-color: #ef4444;
        border-color: #ef4444;
        color: #ffffff;
        font-weight: 600;
        padding: 10px;
        border-radius: 8px;
        transition: all 0.2s;
    }
    .btn-reject-custom:hover {
        background-color: #dc2626;
        border-color: #dc2626;
        color: #ffffff;
    }
    .btn-back-custom {
        background-color: #ffffff;
        border: 1px solid #cbd5e1;
        color: #475569;
        font-weight: 600;
        padding: 10px;
        border-radius: 8px;
        transition: all 0.2s;
    }
    .btn-back-custom:hover {
        background-color: #f8fafc;
        border-color: #cbd5e1;
        color: #1e293b;
    }
</style>

<div class="page-inner">
    @php
        $detail = $financialRecon ?? null;
        $revenueTotal = $detail ? (float) $detail->revenue_total : (float) $reconciliation->gross_revenue;
        $revenueTransfer = $detail ? (float) $detail->revenue_transfer : 0;
        $revenueCash = $detail ? (float) $detail->revenue_cash : 0;
        $revenueCod = $detail ? (float) $detail->revenue_cod : 0;
        $affiliateCommission = $detail ? (float) $detail->affiliate_commission : 0;
        $ticketReward = $detail ? (float) $detail->ticket_reward : 0;
        $refundAmount = $detail ? (float) $detail->refund_amount : 0;
        $totalDeduction = $affiliateCommission + $ticketReward;
        $netAmount = $detail ? (float) $detail->net_amount : (float) $reconciliation->net_revenue;
    @endphp

    <div class="page-header d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
            <a href="{{ route('admin.reconciliation.index') }}" class="btn btn-icon btn-round btn-outline-secondary btn-sm" style="width: 32px; height: 32px; border: 1px solid #cbd5e1; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; color: #475569; background: #ffffff;">
                <i class="fas fa-chevron-left" style="font-size: 12px;"></i>
            </a>
            <div>
                <h4 class="page-title fw-bold mb-0">Chi tiết đối soát</h4>
                <ul class="breadcrumbs mb-0" style="padding-left: 0;">
                    <li class="nav-home">
                        <a href="{{ route('admin') }}" class="text-muted"><i class="fas fa-home"></i></a>
                    </li>
                    <li class="separator"><i class="fas fa-chevron-right text-muted" style="font-size: 10px;"></i></li>
                    <li class="nav-item"><a href="#" class="text-muted">Đối soát</a></li>
                    <li class="separator"><i class="fas fa-chevron-right text-muted" style="font-size: 10px;"></i></li>
                    <li class="nav-item"><a href="{{ route('admin.reconciliation.index') }}" class="text-muted">Quản lý đối soát</a></li>
                    <li class="separator"><i class="fas fa-chevron-right text-muted" style="font-size: 10px;"></i></li>
                    <li class="nav-item"><a href="#" class="text-dark fw-bold">Chi tiết</a></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column -->
        <div class="col-md-9">
            <!-- Tổng quan đối soát card -->
            <div class="card card-custom mb-4">
                <div class="card-header border-0 pb-0">
                    <h5 class="card-title fw-bold mb-0 text-dark">Tổng quan đối soát</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <!-- Chuyển khoản -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-blue">
                                    <i class="fas fa-credit-card"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Chuyển khoản</span>
                                    <span class="stat-value">{{ number_format($revenueTransfer, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>
                        <!-- Tiền mặt -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-green">
                                    <i class="fas fa-money-bill-wave"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Tiền mặt</span>
                                    <span class="stat-value">{{ number_format($revenueCash, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>
                        <!-- COD -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-purple">
                                    <i class="fas fa-box"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">COD</span>
                                    <span class="stat-value">{{ number_format($revenueCod, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tiền nạp vào -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-green">
                                    <i class="fas fa-arrow-alt-circle-down"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Tiền nạp vào</span>
                                    <span class="stat-value">{{ number_format($topupAmount, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>
                        <!-- Phí hoàn hàng -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-red">
                                    <i class="fas fa-shopping-cart"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Phí hoàn hàng</span>
                                    <span class="stat-value">{{ number_format($refundAmount, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>
                        <!-- Tổng doanh thu -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-blue">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Tổng doanh thu</span>
                                    <span class="stat-value">{{ number_format($revenueTotal, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>

                        <!-- Tổng trừ -->
                        <div class="col-md-4">
                            <div class="stat-card">
                                <div class="stat-icon icon-red">
                                    <i class="fas fa-minus-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Tổng trừ</span>
                                    <span class="stat-value">{{ number_format($totalDeduction, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>
                        <!-- Thực nhận -->
                        <div class="col-md-8">
                            <div class="stat-card stat-card-net">
                                <div class="stat-icon" style="background-color: #d1fae5; color: #10b981;">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <div class="stat-info">
                                    <span class="stat-label">Thực nhận</span>
                                    <span class="stat-value">{{ number_format($netAmount, 0, ',', '.') }} VND</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Khoản trừ và Ghi chú rows -->
            <div class="row">
                <!-- Khoản trừ card -->
                <div class="col-md-6">
                    <div class="card card-custom h-100 mb-4">
                        <div class="card-header border-0 pb-0">
                            <h5 class="card-title fw-bold mb-0 text-dark">Khoản trừ</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light text-muted uppercase small">
                                        <tr>
                                            <th>Loại</th>
                                            <th class="text-end" style="width: 150px;">Số tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="text-dark">Hoa hồng Affiliate</td>
                                            <td class="text-end text-muted">-{{ number_format($affiliateCommission, 0, ',', '.') }} VND</td>
                                        </tr>
                                        <tr>
                                            <td class="text-dark">Thưởng Ticket số</td>
                                            <td class="text-end text-muted">-{{ number_format($ticketReward, 0, ',', '.') }} VND</td>
                                        </tr>
                                        <tr class="fw-bold">
                                            <td class="text-dark">Tổng trừ</td>
                                            <td class="text-end text-danger">-{{ number_format($totalDeduction, 0, ',', '.') }} VND</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Ghi chú card -->
                <div class="col-md-6">
                    <div class="card card-custom h-100 mb-4">
                        <div class="card-body p-4 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="fw-bold mb-0 text-dark">Ghi chú</h5>
                                    <i class="fas fa-pencil-alt text-success" style="font-size: 14px; cursor: pointer;" title="Ghi chú"></i>
                                </div>
                                <div class="p-3 bg-light rounded text-muted" style="min-height: 100px; font-size: 14px; border: 1px solid #f1f5f9;">
                                    {{ $reconciliation->notes ?? 'Không có ghi chú' }}
                                </div>
                            </div>

                            @if($reconciliation->approved_by || $reconciliation->email_sent_at)
                                <div class="mt-3 pt-3 border-top text-muted small">
                                    @if($reconciliation->approved_by)
                                        <div class="mb-1">
                                            <i class="fas fa-user-check me-1"></i> <strong>Duyệt bởi:</strong> {{ $reconciliation->approvedBy?->name ?? 'N/A' }}
                                            ({{ $reconciliation->approved_at?->format('d/m/Y H:i') ?? 'N/A' }})
                                        </div>
                                    @endif
                                    @if($reconciliation->email_sent_at)
                                        <div>
                                            <i class="fas fa-paper-plane me-1"></i> <strong>Email gửi lúc:</strong> {{ $reconciliation->email_sent_at->format('d/m/Y H:i') }}
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column (Hành động) -->
        <div class="col-md-3">
            <div class="card action-card">
                <h5 class="fw-bold mb-4 text-dark">Hành động</h5>
                @if($reconciliation->status === 'pending')
                    @if($reconciliation->is_latest_unapproved)
                        <button type="button" class="btn btn-approve-custom w-100 mb-3 d-flex align-items-center justify-content-center gap-1" 
                                data-bs-toggle="modal" data-bs-target="#approveModal">
                            <i class="fas fa-check"></i> Duyệt
                        </button>
                        <button type="button" class="btn btn-reject-custom w-100 mb-3 d-flex align-items-center justify-content-center gap-1" 
                                data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="fas fa-times"></i> Từ chối
                        </button>
                    @else
                        <div class="alert alert-warning small border-0 p-2 text-center mb-3" style="border-radius: 8px; background-color: #fffbeb; color: #b45309;">
                            <i class="fas fa-lock me-1"></i> Bị khóa<br>
                            <span style="font-size: 11px;">(Chỉ được xử lý đơn mới nhất)</span>
                        </div>
                    @endif
                @endif
                <a href="{{ route('admin.reconciliation.index') }}" class="btn btn-back-custom w-100 d-flex align-items-center justify-content-center gap-1">
                    <i class="fas fa-arrow-left"></i> Quay lại
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header d-flex align-items-center pb-2 border-bottom">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0">
                    <i class="fas fa-check-circle text-success" style="font-size: 20px;"></i> Duyệt đối soát
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('admin.reconciliation.approve', $reconciliation) }}" method="POST">
                @csrf
                @method('PATCH')
                <div class="modal-body py-3">
                    <p class="text-muted small mb-3">Bạn có chắc chắn muốn duyệt đối soát này?</p>
                    
                    <div class="p-3 mb-3" style="background: #f0fdf4; border-left: 4px solid #10b981; border-radius: 6px;">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Cửa hàng:</span>
                            <span class="fw-bold text-dark">{{ $reconciliation->store?->name ?? 'N/A' }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Ngày đối soát:</span>
                            <span class="fw-bold text-dark">{{ $reconciliation->reconciliation_date?->format('d/m/Y') ?? 'N/A' }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Thực nhận:</span>
                            <span class="fw-bold text-success">{{ number_format($netAmount, 0, ',', '.') }} VND</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted mb-1">Ghi chú (không bắt buộc)</label>
                        <textarea name="notes" id="notes-approve" class="form-control" rows="3" placeholder="Nhập ghi chú nếu cần..." style="border-radius: 6px; resize: none;" maxlength="300" oninput="updateCharCount()"></textarea>
                        <div class="text-end text-muted small mt-1"><span id="char-count">0</span>/300</div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 6px;">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-success px-4 d-flex align-items-center gap-1" style="border-radius: 6px; background-color: #10b981; border-color: #10b981;">
                        <i class="fas fa-check"></i> Duyệt
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 12px;">
            <div class="modal-header d-flex align-items-center pb-2 border-bottom">
                <h5 class="modal-title fw-bold text-dark d-flex align-items-center gap-2 mb-0">
                    <i class="fas fa-times-circle text-danger" style="font-size: 20px;"></i> Từ chối đối soát
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="{{ route('admin.reconciliation.reject', $reconciliation) }}" method="POST">
                @csrf
                @method('PATCH')
                <div class="modal-body py-3">
                    <p class="text-muted small mb-3">Vui lòng nhập lý do từ chối.</p>
                    
                    <div class="p-3 mb-3" style="background: #fef2f2; border-left: 4px solid #ef4444; border-radius: 6px;">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Cửa hàng:</span>
                            <span class="fw-bold text-dark">{{ $reconciliation->store?->name ?? 'N/A' }}</span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted small">Ngày đối soát:</span>
                            <span class="fw-bold text-dark">{{ $reconciliation->reconciliation_date?->format('d/m/Y') ?? 'N/A' }}</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted small">Thực nhận:</span>
                            <span class="fw-bold text-danger">{{ number_format($netAmount, 0, ',', '.') }} VND</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted mb-1">Lý do từ chối <span class="text-danger">*</span></label>
                        <textarea name="notes" id="notes-reject" class="form-control" rows="3" placeholder="Nhập lý do từ chối..." style="border-radius: 6px; resize: none;" maxlength="300" oninput="updateRejectCharCount()" required></textarea>
                        <div class="text-end text-muted small mt-1"><span id="reject-char-count">0</span>/300</div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 pb-4 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 6px;">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-danger px-4 d-flex align-items-center gap-1" style="border-radius: 6px;">
                        <i class="fas fa-times"></i> Từ chối
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function updateCharCount() {
        const textarea = document.getElementById('notes-approve');
        const countSpan = document.getElementById('char-count');
        if (textarea && countSpan) {
            countSpan.textContent = textarea.value.length;
        }
    }
    
    function updateRejectCharCount() {
        const textarea = document.getElementById('notes-reject');
        const countSpan = document.getElementById('reject-char-count');
        if (textarea && countSpan) {
            countSpan.textContent = textarea.value.length;
        }
    }
</script>
@endsection
