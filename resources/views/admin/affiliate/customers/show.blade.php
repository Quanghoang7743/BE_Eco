@extends('admin.layout')

@section('content')
    @php
        $statusLabels = [
            'ACTIVE' => 'Đang thuộc Affiliate',
            'TRANSFER_PENDING' => 'Chờ chuyển',
            'TRANSFERRED' => 'Đã chuyển đi',
            'REMOVED' => 'Đã loại',
            'LOCKED' => 'Đang khóa',
        ];
        $currentAffiliateId = (int) ($assignment['affiliate_id'] ?? 0);
        $currentCustomerId = (int) ($assignment['customer_id'] ?? 0);
    @endphp
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <a href="{{ route('admin.affiliate.customers.index', ['affiliate_id' => $currentAffiliateId]) }}" class="btn btn-link px-0 text-decoration-none">&larr; Quay lại danh sách khách hàng</a>
                <h2 class="mb-1">Chi tiết khách hàng Affiliate #{{ $assignment['assignment_id'] }}</h2>
                <div class="text-muted">
                    {{ $assignment['customer_name'] ?? '—' }} • Affiliate {{ $assignment['affiliate_name'] ?? '—' }}
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">Có lỗi xảy ra:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Thông tin quan hệ Affiliate - khách hàng</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><strong>Affiliate:</strong> {{ $assignment['affiliate_name'] ?? '—' }} (#{{ $assignment['affiliate_id'] ?? '—' }})</div>
                            <div class="col-md-6"><strong>Khách hàng:</strong> {{ $assignment['customer_name'] ?? '—' }} (#{{ $assignment['customer_id'] ?? '—' }})</div>
                            <div class="col-md-6"><strong>Số điện thoại:</strong> {{ $assignment['phone'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Email:</strong> {{ $assignment['email'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Ngày gán:</strong> {{ !empty($assignment['assigned_at']) ? \Carbon\Carbon::parse($assignment['assigned_at'])->format('d/m/Y H:i') : '—' }}</div>
                            <div class="col-md-6"><strong>Trạng thái:</strong> {{ $statusLabels[$assignment['status']] ?? $assignment['status'] }}</div>
                            <div class="col-md-6"><strong>Nguồn ghi nhận:</strong> {{ $assignment['source_type'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Source ID:</strong> {{ $assignment['source_id'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Đơn đầu tiên:</strong> {{ $assignment['first_order_id'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Sản phẩm đầu tiên:</strong> {{ $assignment['first_product_name'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Tổng đơn hợp lệ:</strong> {{ number_format($assignment['total_valid_orders'] ?? 0) }}</div>
                            <div class="col-md-6"><strong>Tổng GMV:</strong> {{ number_format((float) ($assignment['total_gmv'] ?? 0), 0, ',', '.') }}</div>
                            <div class="col-md-6"><strong>Tổng Lucky Ticket:</strong> {{ number_format($assignment['total_lucky_tickets'] ?? 0) }}</div>
                            <div class="col-md-6"><strong>Tổng hoa hồng tạo ra:</strong> {{ number_format((float) ($assignment['total_commission_generated'] ?? 0), 0, ',', '.') }}</div>
                            <div class="col-md-6"><strong>Cờ rủi ro:</strong> {{ !empty($assignment['risk_flag']) ? 'Có' : 'Không' }}</div>
                            <div class="col-md-6"><strong>Affiliate nhận chuyển đến:</strong> {{ $assignment['transferred_to_affiliate_name'] ?? '—' }}</div>
                            <div class="col-12"><strong>Lý do thay đổi:</strong> {{ $assignment['transfer_reason'] ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Hành động</div>
                    <div class="card-body d-grid gap-3">
                        @if (in_array($assignment['status'], ['ACTIVE', 'LOCKED'], true))
                            <form method="POST" action="{{ route('admin.affiliate.customers.transfer', $assignment['assignment_id']) }}">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label">Affiliate đích</label>
                                    <select name="new_affiliate_id" class="form-select" required>
                                        <option value="">Chọn Affiliate</option>
                                        @foreach ($affiliateOptions as $option)
                                            @if ((int) $option['user_id'] !== $currentAffiliateId)
                                                <option value="{{ $option['user_id'] }}">{{ $option['name'] }} (#{{ $option['user_id'] }})</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                                <textarea name="transfer_reason" rows="3" class="form-control mb-2" placeholder="Lý do chuyển khách" required></textarea>
                                <button type="submit" class="btn btn-primary w-100">Chuyển Affiliate</button>
                            </form>
                        @endif

                        @if (in_array($assignment['status'], ['ACTIVE', 'TRANSFER_PENDING', 'LOCKED'], true))
                            <form method="POST" action="{{ route('admin.affiliate.customers.remove', $assignment['assignment_id']) }}">
                                @csrf
                                <textarea name="transfer_reason" rows="3" class="form-control mb-2" placeholder="Lý do loại khách khỏi Affiliate" required></textarea>
                                <button type="submit" class="btn btn-outline-danger w-100">Loại khỏi Affiliate</button>
                            </form>
                        @endif

                        @if ($assignment['status'] === 'ACTIVE')
                            <form method="POST" action="{{ route('admin.affiliate.customers.lock', $assignment['assignment_id']) }}">
                                @csrf
                                <textarea name="transfer_reason" rows="3" class="form-control mb-2" placeholder="Lý do khóa khách" required></textarea>
                                <button type="submit" class="btn btn-outline-warning w-100">Khóa khách</button>
                            </form>
                        @endif

                        @if ($assignment['status'] === 'LOCKED')
                            <form method="POST" action="{{ route('admin.affiliate.customers.unlock', $assignment['assignment_id']) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline-success w-100">Mở khóa khách</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
