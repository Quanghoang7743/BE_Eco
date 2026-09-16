@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <h2 class="mb-4">Tất cả đơn hàng hệ thống (S13)</h2>

        @php
            $statusLabels = [
                'pending' => 'Chờ xử lý',
                'confirmed' => 'Đã xác nhận',
                'approved' => 'Đã xác nhận',
                'preparing' => 'Đang chuẩn bị',
                'ready' => 'Sẵn sàng giao',
                'delivered' => 'Đã giao',
                'completed' => 'Hoàn tất',
                'cancelled' => 'Đã hủy',
            ];

            $statusBadges = [
                'pending' => 'bg-warning text-dark',
                'confirmed' => 'bg-info text-dark',
                'approved' => 'bg-info text-dark',
                'preparing' => 'bg-primary',
                'ready' => 'bg-secondary',
                'delivered' => 'bg-success',
                'completed' => 'bg-success',
                'cancelled' => 'bg-danger',
            ];
        @endphp
        @php
            $appSetting = $appSetting ?? null;
            $timeoutSeconds = (int) ($appSetting->order_sla_timeout_seconds ?? config('ecoluck.order_sla_timeout_seconds', 150));
            $warningSeconds = (int) ($appSetting->order_sla_warning_seconds ?? max(0, $timeoutSeconds - 30));
            $timeoutTime = sprintf('%02d:%02d', intdiv($timeoutSeconds, 60), $timeoutSeconds % 60);
            $warningTime = sprintf('%02d:%02d', intdiv($warningSeconds, 60), $warningSeconds % 60);
        @endphp

        <div class="row g-3 mb-3 row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-xl-5">
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background-color: #eff6ff; color: #2563eb; flex-shrink: 0;">
                            <i class="fa-regular fa-file-lines fs-5"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.85rem; font-weight: 500;">Tổng đơn</small>
                            <span class="h4 fw-bold mb-0 text-dark">{{ number_format($summary['total_orders'] ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background-color: #fff7ed; color: #ea580c; flex-shrink: 0;">
                            <i class="fa-regular fa-clock fs-5"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.85rem; font-weight: 500;">Chờ xử lý</small>
                            <span class="h4 fw-bold mb-0" style="color: #ea580c;">{{ number_format($summary['pending_orders'] ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background-color: #eff6ff; color: #2563eb; flex-shrink: 0;">
                            <i class="fa-solid fa-box-open fs-5"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.85rem; font-weight: 500;">Đang thực hiện</small>
                            <span class="h4 fw-bold mb-0" style="color: #2563eb;">{{ number_format($summary['confirmed_orders'] ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background-color: #f0fdf4; color: #16a34a; flex-shrink: 0;">
                            <i class="fa-regular fa-circle-check fs-5"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.85rem; font-weight: 500;">Đã giao/hoàn tất</small>
                            <span class="h4 fw-bold mb-0" style="color: #16a34a;">{{ number_format($summary['delivered_orders'] ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card border-0 shadow-sm rounded-3 h-100" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;">
                    <div class="card-body d-flex align-items-center gap-3 py-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px; background-color: #fef2f2; color: #dc2626; flex-shrink: 0;">
                            <i class="fa-regular fa-circle-xmark fs-5"></i>
                        </div>
                        <div>
                            <small class="text-muted d-block" style="font-size: 0.85rem; font-weight: 500;">Đã hủy</small>
                            <span class="h4 fw-bold mb-0" style="color: #dc2626;">{{ number_format($summary['cancelled_orders'] ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3 border-0 shadow-sm rounded-3" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important;">
            <div class="card-body p-4">
                <form method="GET" action="{{ route('admin.system-orders.index') }}" class="row g-3">
                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Trạng thái</label>
                        <select name="status" class="form-select border-1" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                            <option value="">Tất cả</option>
                            @foreach ($statusLabels as $value => $label)
                                <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Cửa hàng</label>
                        <select name="store_id" class="form-select border-1" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                            <option value="">Tất cả</option>
                            @foreach ($stores as $store)
                                <option value="{{ $store->id }}" {{ request('store_id') === (string) $store->id ? 'selected' : '' }}>
                                    {{ $store->store_code }} - {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-xl-3 col-md-4 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Chuyên viên cửa hàng</label>
                        <select name="sale_id" class="form-select border-1" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                            <option value="">Tất cả</option>
                            @foreach ($saleUsers as $sale)
                                <option value="{{ $sale->id }}" {{ (string) request('sale_id') === (string) $sale->id ? 'selected' : '' }}>
                                    {{ $sale->full_name ?: $sale->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Từ ngày</label>
                        <input type="date" class="form-control border-1" name="from_date" value="{{ request('from_date') }}" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                    </div>

                    <div class="col-xl-2 col-md-4 col-sm-6">
                        <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Đến ngày</label>
                        <input type="date" class="form-control border-1" name="to_date" value="{{ request('to_date') }}" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                    </div>

                    <!-- Row 2: Search and Action Buttons -->
                    <div class="col-12 mt-3">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label text-dark fw-semibold" style="font-size: 0.9rem;">Tìm kiếm</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted border-1" style="border-radius: 8px 0 0 8px; border-color: #d1d5db; border-right: 0;">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                    </span>
                                    <input type="text" class="form-control border-1 ps-0" name="keyword" value="{{ request('keyword') }}"
                                        placeholder="Mã đơn, số điện thoại hoặc tên khách" style="border-radius: 0 8px 8px 0; border-color: #d1d5db; border-left: 0; padding: 0.6rem 1rem 0.6rem 0;">
                                </div>
                            </div>
                            <div class="col-md-4 d-flex justify-content-end gap-2">
                                <a href="{{ route('admin.system-orders.index') }}" class="btn btn-outline-secondary px-4 py-2 bg-white fw-semibold" style="border-color: #d1d5db; color: #4b5563; border-radius: 8px; font-size: 0.9rem;">Đặt lại</a>
                                <button class="btn btn-primary px-4 py-2 d-flex align-items-center gap-2 fw-semibold" type="submit" style="background-color: #0061f2; border-color: #0061f2; border-radius: 8px; font-size: 0.9rem;">
                                    Lọc dữ liệu
                                    <i class="fa-solid fa-filter"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Divider line -->
                <hr class="my-4 text-muted opacity-25">

                <!-- SLA configuration section -->
                <div class="sla-section mt-3">
                    <style>
                        .toggle-chevron {
                            transition: transform 0.2s ease-in-out;
                        }
                        [data-bs-toggle="collapse"][aria-expanded="true"] .toggle-chevron {
                            transform: rotate(180deg);
                        }
                        .order-code-link {
                            text-decoration: none !important;
                        }
                        .order-code-link:hover {
                            text-decoration: underline !important;
                        }
                    </style>
                    <div class="d-flex align-items-center justify-content-between" style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#slaCollapse" aria-expanded="false" aria-controls="slaCollapse">
                        <div class="d-flex align-items-center gap-2">
                            <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; background-color: #eff6ff; color: #2563eb;">
                                <i class="fa-regular fa-clock fs-5"></i>
                            </div>
                            <h5 class="mb-0 fw-semibold text-dark fs-6">Cấu hình SLA thời gian xác nhận đơn</h5>
                        </div>
                        <i class="fa-solid fa-chevron-down text-muted toggle-chevron"></i>
                    </div>

                    <div class="collapse mt-3" id="slaCollapse">
                        <form method="POST" action="{{ url('/admin/app-setting') }}" id="sla-config-form" class="row g-3 align-items-end">
                            @csrf
                            @method('PUT')

                            <div class="col-md-3">
                                <label class="form-label text-muted small mb-1">Thời gian timeout (MM:SS)</label>
                                <input type="text" inputmode="numeric" pattern="^\d{1,4}:[0-5][0-9]$" placeholder="MM:SS" class="form-control" id="order_sla_timeout_time" value="{{ $timeoutTime }}" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                                <input type="hidden" name="order_sla_timeout_seconds" id="order_sla_timeout_seconds" value="{{ $timeoutSeconds }}">
                            </div>

                            <div class="col-md-3">
                                <button class="btn btn-primary w-100 fw-semibold" type="submit" style="background-color: #0061f2; border-color: #0061f2; border-radius: 8px; padding: 0.6rem 1rem;">Lưu cấu hình SLA</button>
                            </div>

                            <div class="col-md-6">
                                <div class="d-flex align-items-start gap-2 p-3 rounded border-0" style="background-color: #f8fafc !important;">
                                    <i class="fa-solid fa-circle-info text-secondary mt-1 fs-5"></i>
                                    <div class="text-muted small">
                                        Hệ thống sẽ cảnh báo khi đơn hàng chưa được xác nhận trong thời gian SLA đã cấu hình.
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-3 mt-4" style="border: 1px solid #e2e8f0 !important; border-radius: 12px !important; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.05) !important; overflow: hidden;">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Định danh đơn</th>
                                <th>Khách hàng</th>
                                <th>Cửa hàng</th>
                                <th>Thanh toán</th>
                                <th>Logistics</th>
                                <th>SLA</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($orders as $order)
                                @php
                                    $status = (string) ($order->status ?? 'pending');
                                    $customerName = optional($order->user)->full_name ?? optional($order->user)->name ?? ($order->name ?? 'N/A');
                                    $customerPhone = optional($order->user)->phone ?? ($order->phone ?? $order->delivery_phone ?? 'N/A');
                                    $storeName = optional($order->store)->name ?? 'N/A';
                                    $storeCode = optional($order->store)->store_code ?? 'N/A';
                                    $slaMinutes = (int) ($order->sla_timeout_minutes ?? 0);
                                    $isPending = $status === 'pending';
                                    $isOverdue = false;
                                    if ($isPending && $slaMinutes > 0 && $order->created_at) {
                                        $isOverdue = now()->greaterThan($order->created_at->copy()->addMinutes($slaMinutes));
                                    }
                                @endphp
                                <tr>
                                    <td style="min-width: 240px;">
                                        <div>
                                            <a href="{{ route('admin.orders.show', $order->id) }}" class="fw-semibold text-primary order-code-link" style="font-size: 1.05rem;">
                                                {{ $order->order_code ?? ('#' . $order->id) }}
                                            </a>
                                        </div>
                                        <small class="text-muted">Tạo lúc: {{ optional($order->created_at)->format('Y-m-d H:i:s') ?? 'N/A' }}</small>
                                        <div class="mt-1">
                                            <span class="badge {{ $statusBadges[$status] ?? 'bg-light text-dark' }}">{{ $statusLabels[$status] ?? strtoupper($status) }}</span>
                                        </div>
                                    </td>
                                    <td style="min-width: 230px;">
                                        <div>{{ $customerName }}</div>
                                        <small class="text-muted">SĐT: {{ $customerPhone }}</small>
                                    </td>
                                    <td style="min-width: 220px;">
                                        <div>{{ $storeName }}</div>
                                    </td>
                                    <td style="min-width: 150px;">
                                        <div>{{ number_format((float) ($order->final_amount ?? $order->total ?? 0), 0, ',', '.') }} đ</div>
                                    </td>
                                    <td style="min-width: 260px;">
                                        <div><small class="text-muted">Địa chỉ: {{ $order->delivery_address ?? $order->address ?? 'N/A' }}</small></div>
                                    </td>
                                    <td style="min-width: 150px;">
                                        @if ($status === 'cancelled')
                                            <span class="badge bg-secondary">Đã hủy</span>
                                        @elseif ($isPending)
                                            @if ($isOverdue)
                                                <span class="badge bg-danger">Quá hạn</span>
                                            @else
                                                <span class="badge bg-primary">Đang xử lý</span>
                                            @endif
                                        @else
                                            <span class="badge bg-success">Hoàn thành</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center py-4">Không có đơn hàng phù hợp bộ lọc.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card-footer">
                {{ $orders->onEachSide(1)->links('pagination::bootstrap-5') }}
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            function timeToSeconds(t) {
                if (!t) return 0;
                var parts = t.split(':').map(function (p) { return parseInt(p, 10) || 0; });
                // If format MM:SS (2 parts), treat as minutes:seconds
                if (parts.length === 2) {
                    return parts[0] * 60 + parts[1];
                }
                // If format HH:MM:SS (3 parts), support but prefer MM:SS
                return (parts[0] || 0) * 3600 + (parts[1] || 0) * 60 + (parts[2] || 0);
            }

            var form = document.getElementById('sla-config-form');
            if (!form) return;
            form.addEventListener('submit', function () {
                var tInput = document.getElementById('order_sla_timeout_time');
                var wInput = document.getElementById('order_sla_warning_time');
                var hiddenT = document.getElementById('order_sla_timeout_seconds');
                var hiddenW = document.getElementById('order_sla_warning_seconds');
                var t = tInput ? tInput.value : '';
                var w = wInput ? wInput.value : '';
                if (hiddenT) hiddenT.value = timeToSeconds(t);
                if (hiddenW) hiddenW.value = timeToSeconds(w);
            });
        });
    </script>
@endsection
