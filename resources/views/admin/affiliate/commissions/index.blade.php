@extends('admin.layout')

@section('content')
    @php
        $statusLabels = [
            'PENDING' => 'PENDING',
            'WAITING_ORDER_COMPLETE' => 'WAITING_ORDER_COMPLETE',
            'WAITING_RETURN_PERIOD' => 'WAITING_RETURN_PERIOD',
            'ELIGIBLE' => 'ELIGIBLE',
            'APPROVED' => 'APPROVED',
            'REJECTED' => 'REJECTED',
            'CANCELLED' => 'CANCELLED',
            'HELD' => 'HELD',
            'PAYOUT_PENDING' => 'PAYOUT_PENDING',
            'PAID' => 'PAID',
            'CLAWBACK' => 'CLAWBACK',
        ];
    @endphp
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Quản lý hoa hồng Affiliate</h2>
                <div class="text-muted">Theo dõi vòng đời hoa hồng, hold, duyệt và clawback.</div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-3 mb-3">
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Tổng</div><div class="fs-4 fw-bold">{{ number_format($summary['total'] ?? 0) }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Eligible</div><div class="fs-4 fw-bold">{{ number_format($summary['eligible'] ?? 0) }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Approved</div><div class="fs-4 fw-bold">{{ number_format($summary['approved'] ?? 0) }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Paid</div><div class="fs-4 fw-bold">{{ number_format($summary['paid'] ?? 0) }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Held</div><div class="fs-4 fw-bold">{{ number_format($summary['held'] ?? 0) }}</div></div></div></div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.affiliate.commissions.index') }}" class="row g-3">
                    <div class="col-md-2"><input type="number" name="affiliate_id" value="{{ $filters['affiliate_id'] ?? '' }}" class="form-control" placeholder="Affiliate ID"></div>
                    <div class="col-md-2"><input type="number" name="customer_id" value="{{ $filters['customer_id'] ?? '' }}" class="form-control" placeholder="Customer ID"></div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">Tất cả trạng thái</option>
                            @foreach ($statusLabels as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['status'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2"><input type="text" name="order_keyword" value="{{ $filters['order_keyword'] ?? '' }}" class="form-control" placeholder="Order ID / code"></div>
                    <div class="col-md-2"><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
                    <div class="col-md-2"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
                    <div class="col-md-2">
                        <select name="per_page" class="form-select">
                            @foreach ([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 20) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Lọc</button>
                        <a href="{{ route('admin.affiliate.commissions.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Affiliate</th>
                            <th>Khách</th>
                            <th>Đơn</th>
                            <th>Sản phẩm</th>
                            <th>Số tiền</th>
                            <th>Trạng thái</th>
                            <th>Batch</th>
                            <th>Tạo lúc</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($commissions as $item)
                            <tr>
                                <td>#{{ $item['id'] }}</td>
                                <td>{{ $item['affiliate_name'] ?? '—' }}<div class="text-muted small">#{{ $item['affiliate_id'] }}</div></td>
                                <td>{{ $item['customer_name'] ?? '—' }}<div class="text-muted small">#{{ $item['customer_id'] ?? '—' }}</div></td>
                                <td>{{ $item['order_code'] ?? $item['order_id'] ?? '—' }}</td>
                                <td>{{ $item['product_name'] ?? '—' }}</td>
                                <td>{{ number_format((float) ($item['approved_amount'] > 0 ? $item['approved_amount'] : $item['pending_amount']), 0, ',', '.') }}</td>
                                <td><span class="badge bg-secondary">{{ $item['status'] }}</span></td>
                                <td>{{ $item['payout_batch_code'] ?? '—' }}</td>
                                <td>{{ !empty($item['created_at']) ? \Carbon\Carbon::parse($item['created_at'])->format('d/m/Y H:i') : '—' }}</td>
                                <td><a href="{{ route('admin.affiliate.commissions.show', $item['id']) }}" class="btn btn-outline-primary btn-sm">Chi tiết</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="text-center py-4 text-muted">Chưa có hoa hồng phù hợp bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $commissions->links() }}</div>
        </div>
    </div>
@endsection
