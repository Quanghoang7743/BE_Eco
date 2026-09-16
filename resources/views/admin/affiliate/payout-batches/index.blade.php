@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Đối soát và thanh toán hoa hồng</h2>
                <div class="text-muted">Tạo kỳ thanh toán từ các commission đã APPROVED.</div>
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

        <div class="card mb-3">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.affiliate.payout-batches.store') }}" class="row g-3">
                    @csrf
                    <div class="col-md-3"><label class="form-label">Từ ngày</label><input type="date" name="payout_period_from" class="form-control" required></div>
                    <div class="col-md-3"><label class="form-label">Đến ngày</label><input type="date" name="payout_period_to" class="form-control" required></div>
                    <div class="col-md-4"><label class="form-label">Ghi chú</label><input type="text" name="note" class="form-control" placeholder="Ghi chú batch"></div>
                    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Tạo batch</button></div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.affiliate.payout-batches.index') }}" class="row g-3">
                    <div class="col-md-3">
                        <select name="status" class="form-select">
                            <option value="">Tất cả trạng thái</option>
                            @foreach (['DRAFT','WAITING_APPROVAL','APPROVED','PAID','CANCELLED'] as $status)
                                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3"><input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="form-control"></div>
                    <div class="col-md-3"><input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="form-control"></div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-outline-primary">Lọc</button>
                        <a href="{{ route('admin.affiliate.payout-batches.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Batch</th>
                            <th>Kỳ</th>
                            <th>Tổng affiliate</th>
                            <th>Tổng gross</th>
                            <th>Tổng tax</th>
                            <th>Tổng payout</th>
                            <th>Trạng thái</th>
                            <th>Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($batches as $batch)
                            <tr>
                                <td>{{ $batch['batch_code'] }}</td>
                                <td>{{ $batch['payout_period_from'] }} → {{ $batch['payout_period_to'] }}</td>
                                <td>{{ number_format($batch['total_affiliates']) }}</td>
                                <td>{{ number_format((float) $batch['total_commission'], 0, ',', '.') }}</td>
                                <td>{{ number_format((float) $batch['total_tax_withholding'], 0, ',', '.') }}</td>
                                <td>{{ number_format((float) $batch['total_payout'], 0, ',', '.') }}</td>
                                <td><span class="badge bg-secondary">{{ $batch['status'] }}</span></td>
                                <td><a href="{{ route('admin.affiliate.payout-batches.show', $batch['id']) }}" class="btn btn-outline-primary btn-sm">Chi tiết</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center py-4 text-muted">Chưa có batch thanh toán nào.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $batches->links() }}</div>
        </div>
    </div>
@endsection
