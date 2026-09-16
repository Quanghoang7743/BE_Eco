@extends('admin.layout')

@section('styles')
    <style>
        .commission-detail-page {
            color: #1f2937;
        }

        .commission-detail-page .page-title {
            font-weight: 700;
            line-height: 1.15;
            margin-bottom: 8px;
        }

        .commission-heading-block {
            align-items: flex-start;
            display: flex;
            flex-direction: column;
            text-align: left;
            width: 100%;
        }

        .commission-detail-page .commission-status-line {
            align-items: center !important;
            align-self: flex-start;
            display: inline-flex !important;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-start !important;
            margin: 0;
            width: auto !important;
        }

        .commission-detail-page .commission-badge {
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            margin: 0 !important;
            padding: 7px 10px;
            white-space: nowrap;
        }

        .commission-card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.07);
        }

        .commission-card .card-body {
            padding: 22px;
        }

        .commission-card-title {
            color: #111827;
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .commission-metric {
            background: #ffffff;
            border: 1px solid #e8edf5;
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            min-height: 104px;
            padding: 18px;
        }

        .commission-metric-label {
            color: #64748b;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .commission-metric-value {
            color: #0f172a;
            font-size: 26px;
            font-variant-numeric: tabular-nums;
            font-weight: 800;
            line-height: 1.1;
        }

        .commission-metric.pending {
            border-left: 4px solid #f59e0b;
        }

        .commission-metric.approved {
            border-left: 4px solid #16a34a;
        }

        .commission-metric.payout {
            border-left: 4px solid #2563eb;
        }

        .commission-kv-grid {
            display: grid;
            gap: 14px 22px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .commission-kv-item {
            min-width: 0;
        }

        .commission-kv-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .commission-kv-value {
            color: #1f2937;
            font-size: 14px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .commission-timeline {
            border-left: 2px solid #e5e7eb;
            margin-left: 7px;
            padding-left: 18px;
        }

        .commission-timeline-item {
            margin-bottom: 16px;
            position: relative;
        }

        .commission-timeline-item:last-child {
            margin-bottom: 0;
        }

        .commission-timeline-item::before {
            background: #ffffff;
            border: 3px solid #2563eb;
            border-radius: 999px;
            content: "";
            height: 13px;
            left: -25px;
            position: absolute;
            top: 4px;
            width: 13px;
        }

        .commission-action-form {
            align-items: center;
            display: grid;
            gap: 8px;
            grid-template-columns: minmax(0, 1fr) auto;
        }

        .commission-action-form .form-control {
            height: 44px;
            min-width: 210px;
        }

        .commission-action-form .btn {
            font-size: 13px;
            font-weight: 700;
            height: 44px;
            min-width: 84px;
            padding: 0 14px;
            white-space: nowrap;
        }

        .commission-technical-summary {
            cursor: pointer;
            display: inline-flex;
            list-style: none;
        }

        .commission-technical-summary::-webkit-details-marker {
            display: none;
        }

        .commission-technical-json {
            background: #0f172a;
            border-radius: 8px;
            color: #dbeafe;
            margin-top: 14px;
            max-height: 360px;
            overflow: auto;
            padding: 16px;
            white-space: pre-wrap;
        }

        @media (max-width: 767.98px) {
            .commission-kv-grid {
                grid-template-columns: 1fr;
            }

            .commission-action-form {
                align-items: stretch;
                display: grid;
                grid-template-columns: 1fr;
            }

            .commission-action-form .form-control {
                min-width: 0;
                width: 100%;
            }

            .commission-action-form .btn {
                width: 100%;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $policy = is_array($commission['policy_snapshot_json'] ?? null) ? $commission['policy_snapshot_json'] : [];
        $currentStatus = (string) ($commission['status'] ?? '');
        $orderStatusRaw = (string) ($commission['order_status'] ?? ($policy['order_status'] ?? ''));
        $paymentStatusRaw = (string) ($policy['payment_status'] ?? '');
        $orderStatusKey = strtolower(trim($orderStatusRaw));
        $paymentStatusKey = strtolower(trim($paymentStatusRaw));

        $formatMoney = static fn ($value): string => number_format((float) ($value ?? 0), 0, ',', '.') . 'đ';
        $formatNumber = static fn ($value): string => number_format((float) ($value ?? 0), 0, ',', '.');
        $formatDate = static fn ($value): string => !empty($value)
            ? \Carbon\Carbon::parse($value)->format('d/m/Y H:i')
            : '—';

        $statusMeta = [
            'PENDING' => [
                'label' => 'Chờ thanh toán',
                'badge' => 'bg-secondary',
                'description' => 'Hoa hồng mới ghi nhận, đang chờ đơn thanh toán thành công.',
            ],
            'WAITING_ORDER_COMPLETE' => [
                'label' => 'Chờ hoàn tất đơn',
                'badge' => 'bg-info text-dark',
                'description' => 'Hoa hồng sẽ chỉ được duyệt sau khi đơn hoàn tất và hết thời gian đổi trả.',
            ],
            'WAITING_RETURN_PERIOD' => [
                'label' => 'Chờ hết hạn đổi trả',
                'badge' => 'bg-warning text-dark',
                'description' => 'Hoa hồng sẽ chỉ được duyệt sau khi đơn hoàn tất và hết thời gian đổi trả.',
            ],
            'ELIGIBLE' => [
                'label' => 'Đủ điều kiện duyệt',
                'badge' => 'bg-primary',
                'description' => 'Khoản này đã qua các bước chờ và có thể được admin duyệt.',
            ],
            'APPROVED' => [
                'label' => 'Đã duyệt',
                'badge' => 'bg-success',
                'description' => 'Khoản hoa hồng đã được duyệt và đang chờ đưa vào đợt chi trả.',
            ],
            'REJECTED' => [
                'label' => 'Đã từ chối',
                'badge' => 'bg-danger',
                'description' => 'Khoản hoa hồng đã bị từ chối.',
            ],
            'CANCELLED' => [
                'label' => 'Đã hủy',
                'badge' => 'bg-danger',
                'description' => 'Khoản hoa hồng đã bị hủy, thường do đơn hàng hủy hoặc hoàn tiền.',
            ],
            'HELD' => [
                'label' => 'Đang tạm giữ',
                'badge' => 'bg-warning text-dark',
                'description' => 'Khoản hoa hồng đang bị giữ để kiểm tra khi có khiếu nại hoặc điều kiện chưa rõ.',
            ],
            'PAYOUT_PENDING' => [
                'label' => 'Chờ chi trả',
                'badge' => 'bg-info text-dark',
                'description' => 'Khoản hoa hồng đã nằm trong quy trình chi trả.',
            ],
            'PAID' => [
                'label' => 'Đã chi trả',
                'badge' => 'bg-success',
                'description' => 'Khoản hoa hồng đã được thanh toán cho affiliate.',
            ],
            'CLAWBACK' => [
                'label' => 'Đã thu hồi',
                'badge' => 'bg-dark',
                'description' => 'Khoản đã chi trả trước đó bị thu hồi do phát sinh hoàn/hủy hoặc sai lệch.',
            ],
        ];

        $orderStatusLabels = [
            'completed' => 'Đã hoàn tất',
            'delivered' => 'Đã giao hàng',
            'call_completed' => 'Đã hoàn tất',
            'refunded' => 'Đã hoàn tiền',
            'returned' => 'Đã trả hàng',
            'cancelled' => 'Đã hủy',
            'canceled' => 'Đã hủy',
            'pending' => 'Đang chờ',
            'processing' => 'Đang xử lý',
        ];

        $paymentStatusLabels = [
            'paid' => 'Đã thanh toán',
            'success' => 'Đã thanh toán',
            'successful' => 'Đã thanh toán',
            'completed' => 'Đã thanh toán',
            'pending' => 'Chờ thanh toán',
            'unpaid' => 'Chưa thanh toán',
            'refunded' => 'Đã hoàn tiền',
            'chargeback' => 'Đã hoàn/thu hồi',
        ];

        $sourceTypeLabels = [
            'customer_upline' => 'Tuyến trên của khách hàng',
            'product_link' => 'Link giới thiệu sản phẩm',
            'unknown' => 'Không xác định',
        ];

        $commissionTypeLabels = [
            'percent' => 'Theo phần trăm',
            'fixed' => 'Số tiền cố định',
        ];

        $status = $statusMeta[$currentStatus] ?? [
            'label' => $currentStatus !== '' ? $currentStatus : 'Không xác định',
            'badge' => 'bg-secondary',
            'description' => 'Trạng thái hoa hồng chưa có mô tả hiển thị.',
        ];

        $isRefundedOrder = in_array($orderStatusKey, ['refunded', 'returned'], true)
            || in_array($paymentStatusKey, ['refunded', 'chargeback'], true);
        $needsRefundReview = $isRefundedOrder && !in_array($currentStatus, ['CANCELLED', 'CLAWBACK', 'REJECTED'], true);

        $commissionType = (string) ($commission['commission_type'] ?? ($policy['commission_type'] ?? ''));
        $commissionValue = $commission['commission_value'] ?? ($policy['commission_value'] ?? 0);
        $commissionValueDisplay = $commissionType === 'percent'
            ? $formatNumber($commissionValue) . '%'
            : $formatMoney($commissionValue);

        $policyRows = [
            'Nguồn hoa hồng' => $sourceTypeLabels[(string) ($policy['source_type'] ?? 'unknown')] ?? (string) ($policy['source_type'] ?? '—'),
            'Mã gán khách affiliate' => $policy['source_assignment_id'] ?? '—',
            'Phương thức thanh toán' => $policy['payment_method'] ?? '—',
            'Trạng thái thanh toán' => $paymentStatusLabels[$paymentStatusKey] ?? ($paymentStatusRaw !== '' ? $paymentStatusRaw : '—'),
            'Kiểu hoa hồng' => $commissionTypeLabels[$commissionType] ?? ($commissionType !== '' ? $commissionType : '—'),
            'Giá trị hoa hồng' => $commissionValueDisplay,
            'Tiền nền' => $formatMoney($policy['commission_base_amount'] ?? $commission['commission_base_amount'] ?? 0),
            'Tiền hoa hồng' => $formatMoney($policy['commission_amount'] ?? $commission['pending_amount'] ?? 0),
            'Ghi nhận lúc' => $formatDate($policy['captured_at'] ?? null),
        ];

        $hasActions = $currentStatus === 'ELIGIBLE'
            || in_array($currentStatus, ['ELIGIBLE', 'APPROVED'], true)
            || $currentStatus === 'HELD'
            || in_array($currentStatus, ['PENDING', 'WAITING_ORDER_COMPLETE', 'WAITING_RETURN_PERIOD', 'ELIGIBLE', 'APPROVED', 'HELD'], true)
            || $currentStatus === 'PAID';
    @endphp

    <div class="container-fluid commission-detail-page">
        <div class="mb-3">
            <a href="{{ route('admin.affiliate.commissions.index') }}" class="btn btn-link px-0 text-decoration-none">
                &larr; Quay lại danh sách hoa hồng
            </a>
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

        @if ($needsRefundReview)
            <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
                <i class="fas fa-triangle-exclamation mt-1"></i>
                <div>
                    <strong>Đơn hàng đã hoàn tiền.</strong>
                    Cần kiểm tra và hủy hoa hồng nếu khoản này chưa được đồng bộ.
                </div>
            </div>
        @endif

        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
            <div class="commission-heading-block">
                <h2 class="page-title">Chi tiết hoa hồng #{{ $commission['id'] }}</h2>
                <div class="commission-status-line">
                    <span class="badge commission-badge {{ $status['badge'] }}">{{ $status['label'] }}</span>
                    @if ($orderStatusRaw !== '')
                        <span class="badge commission-badge {{ $isRefundedOrder ? 'bg-danger' : 'bg-light text-dark border' }}">
                            Đơn hàng: {{ $orderStatusLabels[$orderStatusKey] ?? $orderStatusRaw }}
                        </span>
                    @endif
                </div>
                <div class="text-muted mt-2">{{ $status['description'] }}</div>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="commission-metric pending">
                    <div class="commission-metric-label">Hoa hồng đang chờ</div>
                    <div class="commission-metric-value">{{ $formatMoney($commission['pending_amount'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="commission-metric approved">
                    <div class="commission-metric-label">Hoa hồng đã duyệt</div>
                    <div class="commission-metric-value">{{ $formatMoney($commission['approved_amount'] ?? 0) }}</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="commission-metric payout">
                    <div class="commission-metric-label">Số tiền chi trả</div>
                    <div class="commission-metric-value">{{ $formatMoney($commission['payout_amount'] ?? 0) }}</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card commission-card">
                    <div class="card-body">
                        <h5 class="commission-card-title">Thông tin chính</h5>
                        <div class="commission-kv-grid">
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Affiliate nhận hoa hồng</div>
                                <div class="commission-kv-value">{{ $commission['affiliate_name'] ?? '—' }} (#{{ $commission['affiliate_id'] }})</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Khách hàng mua hàng</div>
                                <div class="commission-kv-value">{{ $commission['customer_name'] ?? '—' }} (#{{ $commission['customer_id'] ?? '—' }})</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Đơn hàng</div>
                                <div class="commission-kv-value">{{ $commission['order_code'] ?? $commission['order_id'] ?? '—' }}</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Trạng thái đơn</div>
                                <div class="commission-kv-value">{{ $orderStatusLabels[$orderStatusKey] ?? ($orderStatusRaw !== '' ? $orderStatusRaw : '—') }}</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Sản phẩm</div>
                                <div class="commission-kv-value">{{ $commission['product_name'] ?? '—' }}</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Seller</div>
                                <div class="commission-kv-value">{{ $commission['seller_name'] ?? $commission['seller_id'] ?? '—' }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card commission-card mt-3">
                    <div class="card-body">
                        <h5 class="commission-card-title">Số liệu hoa hồng</h5>
                        <div class="commission-kv-grid">
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Giá trị tính hoa hồng</div>
                                <div class="commission-kv-value">{{ $formatMoney($commission['commission_base_amount'] ?? 0) }}</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Giá trị cấu hình</div>
                                <div class="commission-kv-value">{{ $commissionValueDisplay }}</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Thuế khấu trừ</div>
                                <div class="commission-kv-value">{{ $formatMoney($commission['tax_withholding_amount'] ?? 0) }}</div>
                            </div>
                            <div class="commission-kv-item">
                                <div class="commission-kv-label">Đợt chi trả</div>
                                <div class="commission-kv-value">{{ $commission['payout_batch_code'] ?? '—' }}</div>
                            </div>
                        </div>

                        @if (!empty($commission['reason_cancelled']))
                            <div class="alert alert-danger mt-3 mb-0">
                                <strong>Lý do hủy:</strong> {{ $commission['reason_cancelled'] }}
                            </div>
                        @endif
                        @if (!empty($commission['held_reason']))
                            <div class="alert alert-warning mt-3 mb-0">
                                <strong>Lý do tạm giữ:</strong> {{ $commission['held_reason'] }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                @if ($hasActions)
                    <div class="card commission-card mb-3">
                        <div class="card-body">
                            <h5 class="commission-card-title">Thao tác</h5>
                            <div class="d-flex flex-column gap-2">
                                @if ($currentStatus === 'ELIGIBLE')
                                    <form method="POST" action="{{ route('admin.affiliate.commissions.approve', $commission['id']) }}">
                                        @csrf
                                        <button class="btn btn-success w-100" type="submit">Duyệt hoa hồng</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.affiliate.commissions.reject', $commission['id']) }}" class="commission-action-form">
                                        @csrf
                                        <input type="text" name="reason" class="form-control" placeholder="Lý do từ chối" required>
                                        <button class="btn btn-outline-danger" type="submit">Từ chối</button>
                                    </form>
                                @endif

                                @if (in_array($currentStatus, ['ELIGIBLE', 'APPROVED'], true))
                                    <form method="POST" action="{{ route('admin.affiliate.commissions.hold', $commission['id']) }}" class="commission-action-form">
                                        @csrf
                                        <input type="text" name="reason" class="form-control" placeholder="Lý do tạm giữ" required>
                                        <button class="btn btn-warning" type="submit">Tạm giữ</button>
                                    </form>
                                @endif

                                @if ($currentStatus === 'HELD')
                                    <form method="POST" action="{{ route('admin.affiliate.commissions.release', $commission['id']) }}">
                                        @csrf
                                        <button class="btn btn-outline-success w-100" type="submit">Mở tạm giữ</button>
                                    </form>
                                @endif

                                @if (in_array($currentStatus, ['PENDING', 'WAITING_ORDER_COMPLETE', 'WAITING_RETURN_PERIOD', 'ELIGIBLE', 'APPROVED', 'HELD'], true))
                                    <form method="POST" action="{{ route('admin.affiliate.commissions.cancel', $commission['id']) }}" class="commission-action-form">
                                        @csrf
                                        <input type="text" name="reason" class="form-control" placeholder="Lý do hủy hoa hồng" required>
                                        <button class="btn btn-outline-danger" type="submit" title="Hủy hoa hồng">Hủy</button>
                                    </form>
                                @endif

                                @if ($currentStatus === 'PAID')
                                    <form method="POST" action="{{ route('admin.affiliate.commissions.clawback', $commission['id']) }}" class="commission-action-form">
                                        @csrf
                                        <input type="text" name="reason" class="form-control" placeholder="Lý do thu hồi" required>
                                        <button class="btn btn-danger" type="submit">Thu hồi</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                <div class="card commission-card mb-3">
                    <div class="card-body">
                        <h5 class="commission-card-title">Mốc thời gian</h5>
                        <div class="commission-timeline">
                            <div class="commission-timeline-item">
                                <div class="commission-kv-label">Tạo lúc</div>
                                <div class="commission-kv-value">{{ $formatDate($commission['created_at'] ?? null) }}</div>
                            </div>
                            <div class="commission-timeline-item">
                                <div class="commission-kv-label">Đủ điều kiện lúc</div>
                                <div class="commission-kv-value">{{ $formatDate($commission['eligible_at'] ?? null) }}</div>
                            </div>
                            <div class="commission-timeline-item">
                                <div class="commission-kv-label">Duyệt lúc</div>
                                <div class="commission-kv-value">{{ $formatDate($commission['approved_at'] ?? null) }}</div>
                            </div>
                            <div class="commission-timeline-item">
                                <div class="commission-kv-label">Đã chi trả lúc</div>
                                <div class="commission-kv-value">{{ $formatDate($commission['paid_at'] ?? null) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card commission-card">
                    <div class="card-body">
                        <h5 class="commission-card-title">Công thức tính tại thời điểm ghi nhận</h5>
                        <div class="commission-kv-grid">
                            @foreach ($policyRows as $label => $value)
                                <div class="commission-kv-item">
                                    <div class="commission-kv-label">{{ $label }}</div>
                                    <div class="commission-kv-value">{{ $value }}</div>
                                </div>
                            @endforeach
                        </div>

                        <details class="mt-3">
                            <summary class="commission-technical-summary btn btn-outline-secondary btn-sm">
                                Xem dữ liệu kỹ thuật
                            </summary>
                            <pre class="commission-technical-json small mb-0">{{ json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
