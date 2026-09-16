@extends('admin.layout')

@section('title', 'Quản lý hạn mức Cửa Hàng')

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
.sw-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--sw-border);
}
.sw-page-header h1 {
    font-size: 22px;
    font-weight: 700;
    color: var(--sw-text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.sw-page-header h1 i { color: var(--sw-primary); }

/* ===== Summary KPI cards ===== */
.sw-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
}
.sw-kpi-card {
    background: #fff;
    border: 1px solid var(--sw-border);
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: var(--sw-card-shadow);
    transition: box-shadow .2s;
}
.sw-kpi-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.sw-kpi-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--sw-muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    margin-bottom: 6px;
}
.sw-kpi-value {
    font-size: 22px;
    font-weight: 700;
    color: var(--sw-text);
    line-height: 1.2;
}
.sw-kpi-value.text-success { color: var(--sw-success) !important; }
.sw-kpi-value.text-danger  { color: var(--sw-danger)  !important; }
.sw-kpi-value.text-primary { color: var(--sw-primary) !important; }
.sw-kpi-icon {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    margin-bottom: 10px;
}
.sw-kpi-icon.bg-primary-lt { background: var(--sw-primary-lt); color: var(--sw-primary); }
.sw-kpi-icon.bg-success-lt { background: var(--sw-success-lt); color: var(--sw-success); }
.sw-kpi-icon.bg-danger-lt  { background: var(--sw-danger-lt);  color: var(--sw-danger);  }
.sw-kpi-icon.bg-warning-lt { background: var(--sw-warning-lt); color: var(--sw-warning); }
.sw-kpi-icon.bg-info-lt    { background: var(--sw-info-lt);    color: var(--sw-info);    }

/* ===== Filter card ===== */
.sw-filter-card {
    background: #fff;
    border: 1px solid var(--sw-border);
    border-radius: 12px;
    padding: 18px 20px;
    margin-bottom: 20px;
    box-shadow: var(--sw-card-shadow);
}
.sw-filter-card .filter-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--sw-muted);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* ===== Table card ===== */
.sw-table-card {
    background: #fff;
    border: 1px solid var(--sw-border);
    border-radius: 12px;
    box-shadow: var(--sw-card-shadow);
    overflow: hidden;
}
.sw-table-card .table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid var(--sw-border);
    background: #fafafa;
}
.sw-table-card .table-header h2 {
    font-size: 15px;
    font-weight: 700;
    color: var(--sw-text);
    margin: 0;
}
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
.sw-table tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: background .15s;
}
.sw-table tbody tr:hover { background: #f9fafb; }
.sw-table tbody td { padding: 12px 14px; color: var(--sw-text); vertical-align: middle; }
.sw-table tbody td.muted { color: var(--sw-muted); font-size: 12.5px; }

/* Badges */
.sw-badge {
    display: inline-flex; align-items: center;
    padding: 3px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600; gap: 4px;
}
.sw-badge-open   { background: var(--sw-success-lt); color: var(--sw-success); }
.sw-badge-locked { background: var(--sw-danger-lt);  color: var(--sw-danger);  }
.sw-badge-level  { background: var(--sw-primary-lt); color: var(--sw-primary); }

/* Sortable th link */
.sort-link { color: inherit; text-decoration: none; }
.sort-link:hover { color: var(--sw-primary); }
.sort-link .sort-icon { margin-left: 4px; font-size: 10px; }
</style>
@endsection

@section('content')
<div class="container-fluid py-3">

    {{-- PAGE HEADER --}}
    <div class="sw-page-header">
        <h1><i class="fas fa-wallet"></i> Quản lý hạn mức Cửa Hàng</h1>
        <span class="text-muted" style="font-size:13px;">
            <i class="fas fa-clock me-1"></i>{{ now()->format('d/m/Y H:i') }}
        </span>
    </div>

    {{-- KPI SUMMARY CARDS --}}
    <div class="sw-kpi-grid">
        <div class="sw-kpi-card">
            <div class="sw-kpi-icon bg-primary-lt"><i class="fas fa-store"></i></div>
            <div class="sw-kpi-label">Tổng cửa hàng</div>
            <div class="sw-kpi-value text-primary">{{ number_format($summary['total_stores']) }}</div>
        </div>
        <div class="sw-kpi-card">
            <div class="sw-kpi-icon bg-success-lt"><i class="fas fa-coins"></i></div>
            <div class="sw-kpi-label">Tổng số dư ví</div>
            <div class="sw-kpi-value text-success">{{ number_format((float)$summary['total_wallet_balance'], 0, ',', '.') }}đ</div>
        </div>
        <div class="sw-kpi-card">
            <div class="sw-kpi-icon bg-warning-lt"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="sw-kpi-label">Tổng công nợ</div>
            <div class="sw-kpi-value" style="color:var(--sw-warning)">{{ number_format((float)$summary['total_ticket_debt'], 0, ',', '.') }}đ</div>
        </div>
        <div class="sw-kpi-card">
            <div class="sw-kpi-icon bg-success-lt"><i class="fas fa-lock-open"></i></div>
            <div class="sw-kpi-label">Đang mở</div>
            <div class="sw-kpi-value text-success">{{ number_format($summary['unlocked_store_count']) }}</div>
        </div>
        <div class="sw-kpi-card">
            <div class="sw-kpi-icon bg-danger-lt"><i class="fas fa-lock"></i></div>
            <div class="sw-kpi-label">Đang khóa</div>
            <div class="sw-kpi-value text-danger">{{ number_format($summary['locked_store_count']) }}</div>
        </div>
    </div>

    {{-- FILTER --}}
    <div class="sw-filter-card">
        <div class="filter-title"><i class="fas fa-filter"></i> Bộ lọc tìm kiếm</div>
        <form method="GET" action="{{ route('admin.store-wallets.index') }}" id="filter-form">
            <div class="row g-2">
                <div class="col-12 col-md-4">
                    <input type="text" name="keyword" class="form-control form-control-sm"
                           placeholder="Tên, mã cửa hàng, SĐT…"
                           value="{{ $filters['keyword'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <select name="is_debt_locked" class="form-select form-select-sm">
                        <option value="">-- Trạng thái khóa --</option>
                        <option value="0" {{ ($filters['is_debt_locked'] ?? '') === '0' ? 'selected' : '' }}>Đang mở</option>
                        <option value="1" {{ ($filters['is_debt_locked'] ?? '') === '1' ? 'selected' : '' }}>Đang khóa</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="credit_level" class="form-select form-select-sm">
                        <option value="">-- Cấp độ --</option>
                        @foreach($summary['level_summary'] as $lvl)
                            <option value="{{ $lvl['credit_level'] }}"
                                {{ ($filters['credit_level'] ?? '') == $lvl['credit_level'] ? 'selected' : '' }}>
                                Cấp {{ $lvl['credit_level'] }} ({{ $lvl['count'] }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        @foreach(['active'=>'Hoạt động','pending'=>'Chờ duyệt','suspended'=>'Tạm ngưng','rejected'=>'Từ chối'] as $val => $label)
                            <option value="{{ $val }}" {{ ($filters['status'] ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="sort_by" class="form-select form-select-sm">
                        <option value="wallet_balance" {{ ($filters['sort_by'] ?? 'wallet_balance') === 'wallet_balance' ? 'selected' : '' }}>Sắp xếp: Số dư</option>
                        <option value="ticket_debt"    {{ ($filters['sort_by'] ?? '') === 'ticket_debt'    ? 'selected' : '' }}>Sắp xếp: Công nợ</option>
                        <option value="credit_level"   {{ ($filters['sort_by'] ?? '') === 'credit_level'   ? 'selected' : '' }}>Sắp xếp: Cấp độ</option>
                        <option value="credit_score"   {{ ($filters['sort_by'] ?? '') === 'credit_score'   ? 'selected' : '' }}>Sắp xếp: Điểm TN</option>
                        <option value="updated_at"     {{ ($filters['sort_by'] ?? '') === 'updated_at'     ? 'selected' : '' }}>Sắp xếp: Cập nhật</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="sort_direction" class="form-select form-select-sm">
                        <option value="desc" {{ ($filters['sort_direction'] ?? 'desc') === 'desc' ? 'selected' : '' }}>Giảm dần</option>
                        <option value="asc"  {{ ($filters['sort_direction'] ?? '') === 'asc'  ? 'selected' : '' }}>Tăng dần</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <input type="number" name="min_wallet_balance" class="form-control form-control-sm"
                           placeholder="Số dư tối thiểu" value="{{ $filters['min_wallet_balance'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <input type="number" name="max_wallet_balance" class="form-control form-control-sm"
                           placeholder="Số dư tối đa" value="{{ $filters['max_wallet_balance'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <input type="number" name="min_ticket_debt" class="form-control form-control-sm"
                           placeholder="Công nợ tối thiểu" value="{{ $filters['min_ticket_debt'] ?? '' }}">
                </div>
                <div class="col-6 col-md-2">
                    <input type="number" name="max_ticket_debt" class="form-control form-control-sm"
                           placeholder="Công nợ tối đa" value="{{ $filters['max_ticket_debt'] ?? '' }}">
                </div>
                <div class="col-12 col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm px-4">
                        <i class="fas fa-search me-1"></i>Tìm kiếm
                    </button>
                    <a href="{{ route('admin.store-wallets.index') }}" class="btn btn-outline-secondary btn-sm px-4">
                        <i class="fas fa-redo me-1"></i>Đặt lại
                    </a>
                </div>
            </div>
        </form>
    </div>

    {{-- TABLE --}}
    <div class="sw-table-card">
        <div class="table-header">
            <h2><i class="fas fa-table me-2" style="color:var(--sw-primary)"></i>
                Danh sách ví ({{ number_format($paginator->total()) }} cửa hàng)
            </h2>
        </div>
        <div class="table-responsive">
            <table class="sw-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cửa hàng</th>
                        <th>SĐT / Email</th>
                        <th style="text-align:right">Số dư ví</th>
                        <th style="text-align:right">Công nợ</th>
                        <th style="text-align:right">Hạn mức</th>
                        <th style="text-align:right">Còn lại</th>
                        <th>Cấp độ</th>
                        <th>Trạng thái</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stores as $i => $s)
                    <tr>
                        <td class="muted">{{ $paginator->firstItem() + $i }}</td>
                        <td>
                            <div style="font-weight:600;font-size:13.5px;">{{ $s['store_name'] }}</div>
                            <div class="muted" style="margin-top:2px;">{{ $s['store_code'] }}</div>
                        </td>
                        <td class="muted">
                            <div>{{ $s['phone'] }}</div>
                            @if($s['email'])<div style="font-size:12px;">{{ $s['email'] }}</div>@endif
                        </td>
                        <td style="text-align:right;font-weight:600;color:var(--sw-success)">
                            {{ number_format((float)$s['wallet_balance'], 0, ',', '.') }}đ
                        </td>
                        <td style="text-align:right;font-weight:600;color:{{ (float)$s['ticket_debt'] > 0 ? 'var(--sw-warning)' : 'inherit' }}">
                            {{ number_format((float)$s['ticket_debt'], 0, ',', '.') }}đ
                        </td>
                        <td style="text-align:right" class="muted">
                            {{ number_format((float)$s['credit_limit'], 0, ',', '.') }}đ
                        </td>
                        <td style="text-align:right;font-weight:600;color:{{ (float)$s['available_credit'] < 0 ? 'var(--sw-danger)' : 'var(--sw-success)' }}">
                            {{ number_format((float)$s['available_credit'], 0, ',', '.') }}đ
                        </td>
                        <td>
                            <span class="sw-badge sw-badge-level">
                                <i class="fas fa-star" style="font-size:10px"></i> Cấp {{ $s['credit_level'] }}
                            </span>
                            <div class="muted" style="font-size:11.5px;margin-top:3px;">
                                Điểm: {{ $s['credit_score'] }}
                            </div>
                        </td>
                        <td>
                            @if($s['is_debt_locked'])
                                <span class="sw-badge sw-badge-locked">
                                    <i class="fas fa-lock" style="font-size:10px"></i> Đang khóa
                                </span>
                                @if($s['debt_locked_at'])
                                <div class="muted" style="font-size:11.5px;margin-top:3px;">
                                    {{ \Carbon\Carbon::parse($s['debt_locked_at'])->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}
                                </div>
                                @endif
                            @else
                                <span class="sw-badge sw-badge-open">
                                    <i class="fas fa-lock-open" style="font-size:10px"></i> Đang mở
                                </span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.store-wallets.show', $s['store_id']) }}"
                               class="btn btn-sm btn-outline-primary" title="Xem chi tiết">
                                <i class="fas fa-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center py-5 muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                            Không tìm thấy cửa hàng nào phù hợp.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- PAGINATION --}}
        @if($paginator->hasPages())
        <div style="padding: 14px 20px; border-top: 1px solid var(--sw-border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <span class="muted" style="font-size:13px;">
                Hiển thị {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}
                trong tổng số {{ number_format($paginator->total()) }} cửa hàng
            </span>
            {{ $paginator->appends(request()->query())->links() }}
        </div>
        @endif
    </div>

</div>
@endsection
