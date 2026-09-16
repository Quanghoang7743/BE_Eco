@extends('admin.layout')

@section('title', 'Chi tiết Ví – ' . ($wallet['store_name'] ?? ''))

@section('styles')
<style>
:root {
    --sw-primary:     #2563eb;
    --sw-primary-lt: #eff6ff;
    --sw-success:     #16a34a;
    --sw-success-lt:  #dcfce7;
    --sw-danger:      #dc2626;
    --sw-danger-lt:   #fee2e2;
    --sw-warning:     #d97706;
    --sw-warning-lt:  #fef3c7;
    --sw-info:        #0891b2;
    --sw-info-lt:     #cffafe;
    --sw-border:      #e5e7eb;
    --sw-text:        #111827;
    --sw-muted:       #6b7280;
    --sw-card-shadow: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
}
.sw-breadcrumb { font-size: 13px; color: var(--sw-muted); margin-bottom: 14px; }
.sw-breadcrumb a { color: var(--sw-primary); text-decoration: none; }
.sw-breadcrumb a:hover { text-decoration: underline; }

.sw-card {
    background: #fff;
    border: 1px solid var(--sw-border);
    border-radius: 12px;
    box-shadow: var(--sw-card-shadow);
    margin-bottom: 20px;
    overflow: hidden;
}
.sw-card-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--sw-border);
    background: #fafafa;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 700;
    font-size: 15px;
    color: var(--sw-text);
}
.sw-card-header i { color: var(--sw-primary); margin-right: 8px; }
.sw-card-body { padding: 20px; }

/* KPI row */
.sw-kpi-row {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.sw-kpi-item {
    background: #f9fafb;
    border: 1px solid var(--sw-border);
    border-radius: 10px;
    padding: 14px 16px;
}
.sw-kpi-item .label { font-size: 11.5px; font-weight: 600; color: var(--sw-muted); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 6px; }
.sw-kpi-item .value { font-size: 20px; font-weight: 700; color: var(--sw-text); }
.sw-kpi-item .value.success { color: var(--sw-success); }
.sw-kpi-item .value.danger  { color: var(--sw-danger);  }
.sw-kpi-item .value.warning { color: var(--sw-warning); }
.sw-kpi-item .value.primary { color: var(--sw-primary); }

/* Info grid */
.sw-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 14px;
}
.sw-info-row { display: flex; flex-direction: column; gap: 3px; }
.sw-info-row .info-label { font-size: 12px; font-weight: 600; color: var(--sw-muted); }
.sw-info-row .info-value { font-size: 14px; color: var(--sw-text); font-weight: 500; }

/* Status badges */
.sw-badge { display: inline-flex; align-items: center; padding: 4px 12px; border-radius: 20px; font-size: 12.5px; font-weight: 600; gap: 5px; }
.sw-badge-open   { background: var(--sw-success-lt); color: var(--sw-success); }
.sw-badge-locked { background: var(--sw-danger-lt);  color: var(--sw-danger);  }
.sw-badge-level  { background: var(--sw-primary-lt); color: var(--sw-primary); }

/* Transaction filter bar */
.tx-filter-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid var(--sw-border);
    background: #fafafa;
}
.tx-filter-bar select,
.tx-filter-bar input { font-size: 13px !important; }

/* Transaction table */
.sw-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.sw-table thead th {
    background: #f8fafc;
    border-bottom: 2px solid var(--sw-border);
    padding: 10px 14px;
    font-weight: 700;
    color: var(--sw-muted);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}
.sw-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .15s; }
.sw-table tbody tr:hover { background: #f9fafb; }
.sw-table tbody td { padding: 11px 14px; color: var(--sw-text); vertical-align: middle; }
.sw-table tbody td.muted { color: var(--sw-muted); font-size: 12.5px; }

.tx-type-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.tx-topup      { background: var(--sw-success-lt); color: var(--sw-success); }
.tx-withdraw   { background: var(--sw-danger-lt);  color: var(--sw-danger);  }
.tx-adjustment { background: var(--sw-info-lt);    color: var(--sw-info);    }

/* Pagination bar */
.sw-pagination-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px 20px;
    border-top: 1px solid var(--sw-border);
    background: #fafafa;
    font-size: 13px;
    color: var(--sw-muted);
}
.sw-page-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px;
    border: 1px solid var(--sw-border); border-radius: 6px;
    background: #fff; color: var(--sw-text); font-size: 13px;
    cursor: pointer; transition: all .15s; text-decoration: none;
}
.sw-page-btn:hover { background: #f3f4f6; }
.sw-page-btn.active { background: var(--sw-primary); border-color: var(--sw-primary); color: #fff; }
.sw-page-btn.disabled { opacity: .4; pointer-events: none; }
</style>
@endsection

@section('content')
<div class="container-fluid py-3">

    {{-- BREADCRUMB --}}
    <div class="sw-breadcrumb">
        <a href="{{ route('admin.store-wallets.index') }}"><i class="fas fa-wallet me-1"></i>Quản lý Ví</a>
        <span class="mx-2">/</span>
        <span>{{ $wallet['store_name'] }}</span>
    </div>

    {{-- KPI ROW --}}
    <div class="sw-kpi-row">
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-coins me-1"></i>Số dư ví</div>
            <div class="value success">{{ number_format((float)$wallet['wallet_balance'], 0, ',', '.') }}đ</div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-arrow-down me-1"></i>Tổng nạp vào</div>
            <div class="value success">{{ number_format((float)$totalDeposit, 0, ',', '.') }}đ</div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-arrow-up me-1"></i>Tổng chi ra</div>
            <div class="value danger">{{ number_format((float)$totalSpent, 0, ',', '.') }}đ</div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-file-invoice-dollar me-1"></i>Công nợ</div>
            <div class="value {{ (float)$wallet['ticket_debt'] > 0 ? 'warning' : '' }}">
                {{ number_format((float)$wallet['ticket_debt'], 0, ',', '.') }}đ
            </div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-credit-card me-1"></i>Hạn mức</div>
            <div class="value primary">{{ number_format((float)$wallet['credit_limit'], 0, ',', '.') }}đ</div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-check-circle me-1"></i>Còn khả dụng</div>
            <div class="value {{ (float)$wallet['available_credit'] < 0 ? 'danger' : 'success' }}">
                {{ number_format((float)$wallet['available_credit'], 0, ',', '.') }}đ
            </div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-star me-1"></i>Cấp độ / Điểm</div>
            <div class="value primary">Cấp {{ $wallet['credit_level'] }}</div>
            <div style="font-size:12px;color:var(--sw-muted);margin-top:2px;">Điểm tín dụng: {{ $wallet['credit_score'] }}</div>
        </div>
        <div class="sw-kpi-item">
            <div class="label"><i class="fas fa-shield-alt me-1"></i>Trạng thái ví</div>
            <div style="margin-top:6px;">
                @if($wallet['is_debt_locked'])
                    <span class="sw-badge sw-badge-locked"><i class="fas fa-lock" style="font-size:11px"></i> Đang khóa</span>
                @else
                    <span class="sw-badge sw-badge-open"><i class="fas fa-lock-open" style="font-size:11px"></i> Đang mở</span>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- LEFT: STORE INFO --}}
        <div class="col-12 col-lg-5">
            <div class="sw-card">
                <div class="sw-card-header">
                    <span><i class="fas fa-store"></i>Thông tin cửa hàng</span>
                    <span class="sw-badge sw-badge-level"><i class="fas fa-star" style="font-size:11px"></i> Cấp {{ $wallet['credit_level'] }}</span>
                </div>
                <div class="sw-card-body">
                    <div class="sw-info-grid">
                        <div class="sw-info-row">
                            <span class="info-label">Mã cửa hàng</span>
                            <span class="info-value">{{ $wallet['store_code'] }}</span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Tên cửa hàng</span>
                            <span class="info-value">{{ $wallet['store_name'] }}</span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Số điện thoại</span>
                            <span class="info-value">{{ $wallet['phone'] ?: '—' }}</span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Email</span>
                            <span class="info-value">{{ $wallet['email'] ?: '—' }}</span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Trạng thái hồ sơ</span>
                            <span class="info-value">{{ $wallet['status'] ?: '—' }}</span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Đang hoạt động</span>
                            <span class="info-value">{{ $wallet['is_open'] ? 'Đang mở' : 'Đang đóng' }}</span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Ngày tạo</span>
                            <span class="info-value">
                                {{ $wallet['created_at'] ? \Carbon\Carbon::parse($wallet['created_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : '—' }}
                            </span>
                        </div>
                        <div class="sw-info-row">
                            <span class="info-label">Cập nhật lần cuối</span>
                            <span class="info-value">
                                {{ $wallet['updated_at'] ? \Carbon\Carbon::parse($wallet['updated_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : '—' }}
                            </span>
                        </div>
                    </div>

                    @if($wallet['is_debt_locked'])
                    <div class="mt-3 p-3" style="background:var(--sw-danger-lt);border-radius:8px;border:1px solid #fca5a5;">
                        <div style="font-weight:700;color:var(--sw-danger);font-size:13px;margin-bottom:6px;">
                            <i class="fas fa-lock me-1"></i>Thông tin khóa ví
                        </div>
                        <div style="font-size:13px;color:#7f1d1d;">
                            <div><strong>Thời gian khóa:</strong>
                                {{ $wallet['debt_locked_at'] ? \Carbon\Carbon::parse($wallet['debt_locked_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : '—' }}
                            </div>
                            <div class="mt-1"><strong>Lý do:</strong> {{ $wallet['debt_lock_reason'] ?: 'Không có ghi chú' }}</div>
                        </div>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- RIGHT: RECENT TRANSACTIONS --}}
        <div class="col-12 col-lg-7">
            <div class="sw-card">
                <div class="sw-card-header">
                    <span><i class="fas fa-history"></i>10 giao dịch gần nhất</span>
                    <button class="btn btn-sm btn-outline-primary" id="btn-load-all-tx"
                            onclick="loadAllTransactions()">
                        <i class="fas fa-list me-1"></i>Xem tất cả
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="sw-table">
                        <thead>
                            <tr>
                                <th>Thời gian</th>
                                <th>Loại</th>
                                <th style="text-align:right">Số tiền</th>
                                <th style="text-align:right">Sau GD</th>
                                <th>Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($recentTransactions as $tx)
                            <tr>
                                <td class="muted" style="white-space:nowrap">
                                    {{ $tx['created_at'] ? \Carbon\Carbon::parse($tx['created_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') : '—' }}
                                </td>
                                <td>
                                    <span class="tx-type-badge tx-{{ $tx['type'] }}">
                                        @if($tx['type'] === 'topup')    <i class="fas fa-arrow-down" style="font-size:10px"></i>
                                        @elseif($tx['type'] === 'withdraw') <i class="fas fa-arrow-up" style="font-size:10px"></i>
                                        @else <i class="fas fa-sliders-h" style="font-size:10px"></i>
                                        @endif
                                        {{ $tx['type_label'] }}
                                    </span>
                                </td>
                                <td style="text-align:right;font-weight:700;color:{{ $tx['type']==='topup' ? 'var(--sw-success)' : ($tx['type']==='withdraw' ? 'var(--sw-danger)' : 'var(--sw-info)') }}">
                                    {{ $tx['type'] === 'withdraw' ? '-' : '+' }}{{ number_format((float)$tx['amount'], 0, ',', '.') }}đ
                                </td>
                                <td style="text-align:right" class="muted">
                                    {{ number_format((float)$tx['wallet_balance_after'], 0, ',', '.') }}đ
                                </td>
                                <td class="muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                    title="{{ $tx['note'] }}">
                                    {{ $tx['note'] ?: ($tx['reference_code'] ?: '—') }}
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="text-center py-4 muted">
                                    <i class="fas fa-inbox fa-lg mb-1 d-block"></i>Chưa có giao dịch nào.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- FULL TRANSACTION HISTORY (hidden, loaded via AJAX) --}}
    <div class="sw-card" id="tx-full-panel" style="display:none;">
        <div class="sw-card-header">
            <span><i class="fas fa-list-alt"></i>Lịch sử nạp/rút đầy đủ</span>
        </div>

        {{-- Filter bar --}}
        <div class="tx-filter-bar">
            <select class="form-select form-select-sm" id="tx-type-filter" style="width:180px;" onchange="fetchTransactions(1)">
                <option value="">-- Tất cả loại --</option>
                <option value="topup">Tất cả nạp tiền</option>
                <option value="withdraw">Tất cả chi ra</option>
                <option value="WALLET_DEPOSIT">Nạp tiền vào ví</option>
                <option value="DEBT_REPAYMENT">Khấu trừ trả nợ ticket</option>
                <option value="ORDER_ACCEPTED">Phí ticket đơn hàng</option>
                <option value="ORDER_CANCELLED_REVERSAL">Hoàn phí hủy đơn</option>
            </select>
            <input type="date" class="form-control form-control-sm" id="tx-from-date" style="width:150px;" onchange="fetchTransactions(1)" placeholder="Từ ngày">
            <input type="date" class="form-control form-control-sm" id="tx-to-date"   style="width:150px;" onchange="fetchTransactions(1)" placeholder="Đến ngày">
            <input type="text" class="form-control form-control-sm" id="tx-keyword"   style="width:200px;" placeholder="Ghi chú / mã giao dịch…"
                   onkeydown="if(event.key==='Enter') fetchTransactions(1)">
            <button class="btn btn-sm btn-primary" onclick="fetchTransactions(1)"><i class="fas fa-search"></i></button>
        </div>

        <div class="table-responsive">
            <table class="sw-table">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Loại</th>
                        <th style="text-align:right">Số tiền</th>
                        <th style="text-align:right">Trước GD</th>
                        <th style="text-align:right">Sau GD</th>
                        <th>Mã tham chiếu</th>
                        <th>Ghi chú</th>
                    </tr>
                </thead>
                <tbody id="tx-tbody">
                    <tr><td colspan="7" class="text-center py-4 muted"><i class="fas fa-spinner fa-spin"></i> Đang tải…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="sw-pagination-bar" id="tx-pagination-bar"></div>
    </div>

</div>
@endsection

@section('scripts')
<script>
const STORE_ID  = '{{ $storeId }}';
const TX_URL    = '{{ route("admin.store-wallets.transactions", $storeId) }}';
let txCurrentPage = 1;

function loadAllTransactions() {
    document.getElementById('tx-full-panel').style.display = 'block';
    document.getElementById('btn-load-all-tx').style.display = 'none';
    document.getElementById('tx-full-panel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    fetchTransactions(1);
}

function fetchTransactions(page) {
    txCurrentPage = page;
    const tbody = document.getElementById('tx-tbody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 muted"><i class="fas fa-spinner fa-spin"></i> Đang tải…</td></tr>';

    const params = new URLSearchParams({
        page,
        per_page: 20,
        type:      document.getElementById('tx-type-filter').value,
        from_date: document.getElementById('tx-from-date').value,
        to_date:   document.getElementById('tx-to-date').value,
        keyword:   document.getElementById('tx-keyword').value,
    });

    fetch(TX_URL + '?' + params.toString(), {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 muted">Lỗi tải dữ liệu.</td></tr>'; return; }
        const items = res.data.items;
        const pg    = res.data.pagination;

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 muted"><i class="fas fa-inbox fa-lg mb-1 d-block"></i>Không có giao dịch.</td></tr>';
            document.getElementById('tx-pagination-bar').innerHTML = '';
            return;
        }

        let rows = '';
        items.forEach(tx => {
            const sign  = tx.type === 'withdraw' ? '-' : '+';
            const color = tx.type === 'topup' ? '#16a34a' : (tx.type === 'withdraw' ? '#dc2626' : '#0891b2');
            const icon  = tx.type === 'topup' ? 'arrow-down' : (tx.type === 'withdraw' ? 'arrow-up' : 'sliders-h');
            const cls   = 'tx-' + tx.type;
            const dt    = tx.created_at ? new Date(tx.created_at).toLocaleString('vi-VN') : '—';
            rows += `
            <tr>
                <td class="muted" style="white-space:nowrap">${dt}</td>
                <td><span class="tx-type-badge ${cls}"><i class="fas fa-${icon}" style="font-size:10px"></i> ${tx.type_label}</span></td>
                <td style="text-align:right;font-weight:700;color:${color}">${sign}${parseFloat(tx.amount).toLocaleString('vi-VN')}đ</td>
                <td style="text-align:right" class="muted">${parseFloat(tx.wallet_balance_before).toLocaleString('vi-VN')}đ</td>
                <td style="text-align:right" class="muted">${parseFloat(tx.wallet_balance_after).toLocaleString('vi-VN')}đ</td>
                <td class="muted">${tx.reference_code || '—'}</td>
                <td class="muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${tx.note || ''}">${tx.note || '—'}</td>
            </tr>`;
        });
        tbody.innerHTML = rows;

        // Build pagination
        buildPagination(pg);
    })
    .catch(() => {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 muted">Không thể kết nối. Vui lòng thử lại.</td></tr>';
    });
}

function buildPagination(pg) {
    const bar = document.getElementById('tx-pagination-bar');
    if (pg.last_page <= 1) { bar.innerHTML = `<span>Tổng: ${pg.total} giao dịch</span>`; return; }

    let btns = '';
    // Prev
    btns += `<a class="sw-page-btn ${pg.current_page <= 1 ? 'disabled' : ''}" onclick="fetchTransactions(${pg.current_page - 1})"><i class="fas fa-chevron-left"></i></a>`;
    // Pages
    for (let p = Math.max(1, pg.current_page - 2); p <= Math.min(pg.last_page, pg.current_page + 2); p++) {
        btns += `<a class="sw-page-btn ${p === pg.current_page ? 'active' : ''}" onclick="fetchTransactions(${p})">${p}</a>`;
    }
    // Next
    btns += `<a class="sw-page-btn ${pg.current_page >= pg.last_page ? 'disabled' : ''}" onclick="fetchTransactions(${pg.current_page + 1})"><i class="fas fa-chevron-right"></i></a>`;

    bar.innerHTML = `
        <span>Hiển thị ${pg.from || 0}–${pg.to || 0} trong ${pg.total} giao dịch</span>
        <div style="display:flex;gap:4px;">${btns}</div>`;
}
</script>
@endsection
