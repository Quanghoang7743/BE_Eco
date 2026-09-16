@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <div class="mb-3">
            <a href="{{ route('admin.affiliate.payout-batches.index') }}" class="btn btn-link px-0 text-decoration-none">&larr; Quay lại danh sách batch</a>
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

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Batch {{ $batch['batch_code'] }}</h2>
                <div class="text-muted">Trạng thái hiện tại: <strong>{{ $batch['status'] }}</strong></div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                @if ($batch['status'] === 'DRAFT')
                    <form method="POST" action="{{ route('admin.affiliate.payout-batches.submit', $batch['id']) }}">@csrf<button class="btn btn-outline-primary">Gửi duyệt</button></form>
                @endif
                @if (in_array($batch['status'], ['DRAFT','WAITING_APPROVAL'], true))
                    <form method="POST" action="{{ route('admin.affiliate.payout-batches.approve', $batch['id']) }}">@csrf<button class="btn btn-success">Duyệt batch</button></form>
                @endif
                @if ($batch['status'] === 'APPROVED')
                    <form method="POST" action="{{ route('admin.affiliate.payout-batches.mark-paid', $batch['id']) }}" class="d-flex gap-2">
                        @csrf
                        <input type="text" name="payment_reference" class="form-control" placeholder="Mã giao dịch">
                        <input type="url" name="payment_proof_url" class="form-control" placeholder="Link chứng từ">
                        <button class="btn btn-primary">Mark paid</button>
                    </form>
                @endif
                @if ($batch['status'] !== 'PAID' && $batch['status'] !== 'CANCELLED')
                    <form method="POST" action="{{ route('admin.affiliate.payout-batches.cancel', $batch['id']) }}" class="d-flex gap-2">@csrf<input type="text" name="reason" class="form-control" placeholder="Lý do hủy"><button class="btn btn-outline-danger">Hủy batch</button></form>
                @endif
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Kỳ</div><div class="fw-bold">{{ $batch['payout_period_from'] }} → {{ $batch['payout_period_to'] }}</div></div></div></div>
            <div class="col-md-3"><div class="card"><div class="card-body"><div class="text-muted">Affiliate</div><div class="fw-bold">{{ number_format($batch['total_affiliates']) }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Gross</div><div class="fw-bold">{{ number_format((float) $batch['total_commission'], 0, ',', '.') }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Tax</div><div class="fw-bold">{{ number_format((float) $batch['total_tax_withholding'], 0, ',', '.') }}</div></div></div></div>
            <div class="col-md-2"><div class="card"><div class="card-body"><div class="text-muted">Net</div><div class="fw-bold">{{ number_format((float) $batch['total_payout'], 0, ',', '.') }}</div></div></div></div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Affiliate</th>
                            <th>Số commission</th>
                            <th>Gross</th>
                            <th>Tax</th>
                            <th>Net</th>
                            <th>Ngân hàng</th>
                            <th>Trạng thái</th>
                            <th>Tham chiếu</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($batch['items'] as $item)
                            <tr>
                                <td>#{{ $item['affiliate_id'] }}</td>
                                <td>{{ number_format($item['commission_count']) }}</td>
                                <td>{{ number_format((float) $item['gross_amount'], 0, ',', '.') }}</td>
                                <td>{{ number_format((float) $item['tax_withholding_amount'], 0, ',', '.') }}</td>
                                <td>{{ number_format((float) $item['net_payout_amount'], 0, ',', '.') }}</td>
                                <td>{{ $item['bank_account']['bank']['name'] ?? '—' }}<div class="text-muted small">{{ $item['bank_account']['account_number'] ?? '' }}</div></td>
                                <td><span class="badge bg-secondary">{{ $item['status'] }}</span></td>
                                <td>{{ $item['payment_reference'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
