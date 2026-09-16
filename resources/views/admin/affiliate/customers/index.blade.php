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
    @endphp
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Khách hàng bên dưới Affiliate</h2>
                <div class="text-muted">Theo dõi quan hệ Affiliate - khách hàng, doanh số, ticket và hoa hồng phát sinh.</div>
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

        @if (!empty($isLegacyMode))
            <div class="alert alert-warning">
                Đang chạy ở chế độ tương thích từ <code>users.referrer_id</code> vì DB chưa có bảng <code>affiliate_customers</code>.
                Tab này vẫn xem được danh sách khách hàng, nhưng chưa mở được màn chi tiết và các thao tác chuyển/khóa/loại cho đến khi chạy migration mới.
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.affiliate.customers.index') }}" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Affiliate</label>
                        <select name="affiliate_id" class="form-select">
                            <option value="">Tất cả Affiliate</option>
                            @foreach ($affiliateOptions as $option)
                                <option value="{{ $option['user_id'] }}" @selected((int) ($filters['affiliate_id'] ?? 0) === (int) $option['user_id'])>
                                    {{ $option['name'] }} (#{{ $option['user_id'] }}){{ $option['affiliate_code'] ? ' - ' . $option['affiliate_code'] : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="">Tất cả</option>
                            @foreach ($statusLabels as $code => $label)
                                <option value="{{ $code }}" @selected(($filters['status'] ?? '') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Nguồn</label>
                        <input type="text" name="source_type" value="{{ $filters['source_type'] ?? '' }}" class="form-control" placeholder="admin_transfer">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Cờ rủi ro</label>
                        <select name="risk_flag" class="form-select">
                            <option value="">Tất cả</option>
                            <option value="1" @selected((string) ($filters['risk_flag'] ?? '') === '1')>Có</option>
                            <option value="0" @selected((string) ($filters['risk_flag'] ?? '') === '0')>Không</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Từ ngày gán</label>
                        <input type="date" name="assigned_from" value="{{ $filters['assigned_from'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Đến ngày gán</label>
                        <input type="date" name="assigned_to" value="{{ $filters['assigned_to'] ?? '' }}" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Từ khóa</label>
                        <input type="text" name="keyword" value="{{ $filters['keyword'] ?? '' }}" class="form-control" placeholder="ID khách / tên / SĐT / mã đơn đầu tiên">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Số dòng</label>
                        <select name="per_page" class="form-select">
                            @foreach ([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 20) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">Lọc</button>
                        <a href="{{ route('admin.affiliate.customers.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead style="text-transform: none;">
                            <tr>
                                <th>Affiliate</th>
                                <th>Khách hàng</th>
                                <th>Ngày gán</th>
                                <th>Nguồn</th>
                                <th>Đơn đầu tiên</th>
                                <th>Tổng đơn</th>
                                <th>GMV</th>
                                <th>Ticket</th>
                                <th>Hoa hồng</th>
                                <th>Trạng thái</th>
                                <th>Rủi ro</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($assignments as $item)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $item['affiliate_name'] ?? '—' }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $item['customer_name'] ?? '—' }}</div>
                                        <div class="text-muted">{{ $item['raw_phone'] ?? '—' }}</div>
                                    </td>
                                    <td>{{ !empty($item['assigned_at']) ? \Carbon\Carbon::parse($item['assigned_at'])->format('d/m/Y H:i') : '—' }}</td>
                                    <td>
                                        <div>{{ $item['source_type'] ?? '—' }}</div>
                                        <div class="text-muted">{{ $item['source_id'] ?? '' }}</div>
                                    </td>
                                    <td>
                                        <div>{{ $item['first_product_name'] ?? '—' }}</div>
                                    </td>
                                    <td>{{ number_format($item['total_valid_orders'] ?? 0) }}</td>
                                    <td>{{ number_format((float) ($item['total_gmv'] ?? 0), 0, ',', '.') }}</td>
                                    <td>{{ number_format($item['total_lucky_tickets'] ?? 0) }}</td>
                                    <td>{{ number_format((float) ($item['total_commission_generated'] ?? 0), 0, ',', '.') }}</td>
                                    <td><span class="badge bg-{{ match($item['status']) { 'ACTIVE' => 'success', 'LOCKED' => 'warning', 'TRANSFERRED' => 'info', 'REMOVED' => 'secondary', default => 'primary' } }}">{{ $statusLabels[$item['status']] ?? $item['status'] }}</span></td>
                                    <td>{!! !empty($item['risk_flag']) ? '<span class="badge bg-danger">Có</span>' : '<span class="badge bg-success">Không</span>' !!}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted py-4">Chưa có khách hàng Affiliate phù hợp bộ lọc.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if ($assignments instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <div class="card-footer">
                    {{ $assignments->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
