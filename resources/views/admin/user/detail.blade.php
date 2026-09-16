<!-- Tabs -->
<ul class="nav nav-tabs" id="userDetailTabs" role="tablist">
    <li class="nav-item">
        <a class="nav-link active" id="info-tab" data-toggle="tab" href="#info" role="tab">Thông tin</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="orders-tab" data-toggle="tab" href="#order-history" role="tab">Lịch sử mua hàng</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="ledger-tab" data-toggle="tab" href="#ticket-ledger" role="tab">Ledger Ticket</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="reward-logs-tab" data-toggle="tab" href="#reward-logs" role="tab">Lịch sử quay thưởng</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="affiliate-tab" data-toggle="tab" href="#affiliate" role="tab">Affiliate</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="commission-history-tab" data-toggle="tab" href="#commission-history" role="tab">Lịch sử hoa hồng</a>
    </li>
    {{-- <li class="nav-item">
        <a class="nav-link" id="care-tab" data-toggle="tab" href="#care" role="tab">Chăm sóc</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="warranty-tab" data-toggle="tab" href="#warranty" role="tab">Bảo hành</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" id="repair-tab" data-toggle="tab" href="#repair" role="tab">Lịch sử sửa</a>
    </li> --}}
</ul>

<!-- Tab content -->
<div class="tab-content p-3 border border-top-0" id="userDetailTabsContent">
    <div class="tab-pane fade show active" id="info" role="tabpanel">
        <div class="row">
            <!-- Thông tin chính -->
            <div class="col-md-8">
                <div class="border p-3 mb-3 rounded">
                    <h5 class="mb-3">Thông tin</h5>
                    <p><i class="fas fa-user me-2 text-secondary"></i><strong>Tên:</strong>
                        {{ $user->full_name ?? 'Không rõ' }}</p>
                    <p><i class="fas fa-phone me-2 text-secondary"></i><strong>SĐT:</strong> <a
                            href="tel:{{ $user->phone }}">{{ $user->phone ?? 'Không rõ' }}</a></p>
                    <p><i class="fas fa-map-marker-alt me-2 text-secondary"></i><strong>Địa chỉ:</strong>
                        {{ $user->address ?? 'Không rõ' }}</p>
                    <p><i class="fas fa-user-tag me-2 text-secondary"></i><strong>Loại:</strong> Khách lẻ</p>
                    {{-- <p><i class="fas fa-tags me-2 text-secondary"></i><strong>Nhóm, Mã thẻ, Cấp độ:</strong> (chưa có)</p> --}}
                </div>

                <div class="border p-3 mb-3 rounded">
                    <h5 class="mb-3"><i class="fas fa-history me-2 text-secondary"></i>Lịch sử giao dịch</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Tổng số hóa đơn:</strong> {{ $totalOrders }}</p>
                            <p><strong>Tổng số ngày mua:</strong> {{ $totalDays }}</p>
                            <p><strong>Tổng sản phẩm đã mua:</strong> {{ $totalProducts }}</p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Điểm tích lũy:</strong> <span
                                    class="text-primary font-weight-bold">{{ number_format($totalPoints, 0) }}</span>
                            </p>
                            <p><strong>Tổng tiền tích lũy:</strong> {{ number_format($totalAmount, 0, ',', '.') }}</p>
                        </div>
                        <div class="col-md-12">
                            @if ($firstOrder)
                                <p>
                                    <strong>Ngày bắt đầu mua hàng:</strong>
                                    {{ $firstOrder->created_at->format('d/m/Y') }}
                                </p>
                            @endif

                            @if ($lastOrder)
                                <p>
                                    <strong>Ngày mua cuối cùng:</strong> {{ $lastOrder->created_at->format('d/m/Y') }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cột phụ -->
            <div class="col-md-4">
                <div class="border p-3 mb-3 rounded">
                    <h5 class="mb-3"><i class="fas fa-credit-card me-2 text-secondary"></i>Thanh toán</h5>
                    <p><strong>Tên công ty:</strong></p>
                    <p><strong>Mã số thuế:</strong></p>
                </div>

                {{-- <div class="border p-3 rounded">
                    <h5 class="mb-3 d-flex justify-content-between">
                        <span><i class="fas fa-tags me-2 text-secondary"></i>Nhãn</span>
                        <button class="btn btn-outline-success btn-sm"><i class="fas fa-plus"></i></button>
                    </h5>
                    <p class="text-muted">Chưa có nhãn</p>
                </div> --}}

                <div class="border p-3 rounded">
                    <h5 class="mb-3"><i class="fas fa-paperclip me-2 text-secondary"></i>File đính kèm</h5>
                    <button class="btn btn-success btn-sm"><i class="fas fa-upload"></i> Upload</button>
                </div>
            </div>
            {{-- <div class="col-md-12">
                <div class="border p-3 rounded">
                    <h5 class="mb-3"><i class="fas fa-paperclip me-2 text-secondary"></i>Gợi ý</h5>
                    <ul class="mt-3 small text-muted">
                        <li><i class="fas fa-info-circle mr-1"></i> Tổng tiền tích lũy = Tổng tiền khách đã mua – trả
                            hàng – điểm sử dụng</li>
                        <li><i class="fas fa-info-circle mr-1"></i> Hóa đơn gồm cả mua và trả hàng</li>
                        <li><i class="fas fa-info-circle mr-1"></i> Sản phẩm = mua – trả</li>
                        <li><i class="fas fa-info-circle mr-1"></i> Ngày đầu/cuối chỉ tính đơn mua</li>
                    </ul>
                </div>
            </div> --}}
        </div>
    </div>

    <!-- Lịch sử mua hàng -->
    <div class="tab-pane fade" id="order-history" role="tabpanel">

        @if ($orders->isEmpty())
            <p class="text-muted">Không có đơn hàng nào.</p>
        @else
            <table class="table table-bordered table-hover">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>Ngày mua</th>
                        <th>Sản phẩm</th>
                        <th>Tổng tiền</th>
                        <th>Trạng thái đơn</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td>
                                <a href="{{ route('admin.orders.show', $order->id) }}" target="_blank" class="text-primary font-weight-bold">
                                    {{ $order->id }}
                                </a>
                            </td>
                            <td>{{ $order->created_at->format('d/m/Y H:i') }}</td>
                            <td>
                                <ul>
                                    @foreach ($order->items as $item)
                                        <li>{{ $item->product_name ?? 'SP' }} × {{ $item->quantity }}</li>
                                    @endforeach
                                </ul>
                            </td>
                            <td>{{ number_format($order->total, 0, ',', '.') }}đ</td>
                            <td>{{ ucfirst($order->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="tab-pane fade" id="ticket-ledger" role="tabpanel">
        @php
            $actionLabels = [
                'PURCHASE' => 'Mua Ticket',
                'ISSUED' => 'Cộng Ticket',
                'ORDER_USE' => 'Dùng Ticket đổi đơn',
                'ROOM_USE' => 'Dùng Ticket vào phòng',
                'ROOM_WITHDRAW' => 'Rút Ticket khỏi phòng',
                'REFUND' => 'Hoàn Ticket',
                'EXPIRE' => 'Ticket hết hạn',
                'LOCK' => 'Khóa Ticket',
                'LOCKED' => 'Khóa Ticket',
            ];
        @endphp

        <div class="mb-3">
            <div class="row">
                <div class="col-md-3 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="text-muted small">Số bản ghi</div>
                        <div class="font-weight-bold">{{ number_format($ledgerSummary['total_entries'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="text-muted small">Tổng cộng</div>
                        <div class="font-weight-bold text-success">+{{ number_format($ledgerSummary['total_in'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="text-muted small">Tổng trừ</div>
                        <div class="font-weight-bold text-danger">-{{ number_format($ledgerSummary['total_out'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-md-3 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="text-muted small">Biến động ròng</div>
                        @php
                            $net = (int) ($ledgerSummary['net_change'] ?? 0);
                        @endphp
                        <div class="font-weight-bold {{ $net >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ $net >= 0 ? '+' : '' }}{{ number_format($net) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @if ($ledgerEntries->isEmpty())
            <p class="text-muted">User chưa có dữ liệu ledger Ticket.</p>
        @else
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Thời gian</th>
                            <th>Action</th>
                            <th>Biến động</th>
                            <th>Trước</th>
                            <th>Sau</th>
                            <th>Mã Ticket</th>
                            <th>Ref</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ledgerEntries as $entry)
                            <tr>
                                <td>{{ optional($entry->created_at)->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    {{ $actionLabels[strtoupper((string) ($entry->action_type ?? ''))] ?? 'Khác' }}
                                </td>
                                <td class="font-weight-bold {{ (int) $entry->amount_change >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ (int) $entry->amount_change >= 0 ? '+' : '' }}{{ number_format((int) $entry->amount_change) }}
                                </td>
                                <td>{{ is_null($entry->balance_before) ? '-' : number_format((int) $entry->balance_before) }}</td>
                                <td>{{ is_null($entry->balance_after) ? '-' : number_format((int) $entry->balance_after) }}</td>
                                <td>{{ optional($entry->ticket)->ticket_code ?? '-' }}</td>
                                <td>
                                    @if (!empty($entry->reference_type) || !empty($entry->reference_id))
                                        <div><strong>{{ $entry->reference_type ?? '-' }}</strong></div>
                                        <small class="text-muted">{{ $entry->reference_id ?? '-' }}</small>
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($ledgerEntries->lastPage() > 1)
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mt-3">
                    <div class="small text-muted mb-2 mb-md-0">
                        Hiển thị {{ number_format((int) ($ledgerEntries->firstItem() ?? 0)) }}-
                        {{ number_format((int) ($ledgerEntries->lastItem() ?? 0)) }} / {{ number_format((int) $ledgerEntries->total()) }} bản ghi
                    </div>
                    <div>
                        {{ $ledgerEntries->onEachSide(1)->links('pagination::bootstrap-4') }}
                    </div>
                </div>
            @endif
        @endif
    </div>

    <!-- Lịch sử quay thưởng -->
    <div class="tab-pane fade" id="reward-logs" role="tabpanel">
        @if ($rewardLogs->isEmpty())
            <p class="text-muted">Không có lịch sử quay thưởng nào.</p>
        @else
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>Thời gian quay</th>
                            <th>Tên phần thưởng</th>
                            <th>Loại</th>
                            <th>Giá trị</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rewardLogs as $index => $log)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ optional($log->created_at)->format('d/m/Y H:i:s') ?? '-' }}</td>
                                <td>{{ $log->reward_name }}</td>
                                <td>
                                    @if($log->reward)
                                        @switch($log->reward->type)
                                            @case('point')
                                                <span class="badge badge-info text-white" style="background-color: #17a2b8;">Điểm</span>
                                                @break
                                            @case('voucher')
                                                <span class="badge badge-warning text-dark" style="background-color: #ffc107;">Voucher</span>
                                                @break
                                            @case('product')
                                                <span class="badge badge-success text-white" style="background-color: #28a745;">Sản phẩm</span>
                                                @break
                                            @case('extra_spin')
                                                <span class="badge badge-primary text-white" style="background-color: #007bff;">Lượt quay</span>
                                                @break
                                            @case('none')
                                                <span class="badge badge-secondary text-white" style="background-color: #6c757d;">Không trúng</span>
                                                @break
                                            @default
                                                <span class="badge badge-dark text-white">{{ $log->reward->type }}</span>
                                        @endswitch
                                    @else
                                        <span class="badge badge-secondary text-white" style="background-color: #6c757d;">Không trúng</span>
                                    @endif
                                </td>
                                <td>
                                    @if($log->reward && $log->reward->value)
                                        {{ number_format($log->reward->value) }}
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- <!-- Chăm sóc -->
    <div class="tab-pane fade" id="care" role="tabpanel">
        <p>Chưa có lịch sử chăm sóc khách hàng...</p>
    </div>

    <!-- Bảo hành -->
    <div class="tab-pane fade" id="warranty" role="tabpanel">
        <p>Chưa có thông tin bảo hành...</p>
    </div>

    <!-- Lịch sử sửa -->
    <div class="tab-pane fade" id="repair" role="tabpanel">
        <p>Chưa có lịch sử sửa chữa...</p>
    </div> --}}

    <!-- Affiliate Tab -->
    <div class="tab-pane fade" id="affiliate" role="tabpanel">
        @if ($affiliateProfile)
            <div class="card mb-4 border-light shadow-sm">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 font-weight-bold text-dark"><i class="fas fa-id-card me-2"></i>Hồ sơ đối tác Affiliate</h6>
                    @php
                        $statusBadge = match($affiliateProfile->status) {
                            'approved' => 'success',
                            'rejected', 'locked', 'terminated' => 'danger',
                            'need_more_info', 'suspended' => 'warning',
                            'under_review' => 'info',
                            default => 'secondary',
                        };
                        $statusLabel = match($affiliateProfile->status) {
                            'approved' => 'Đã duyệt',
                            'rejected' => 'Bị từ chối',
                            'locked' => 'Bị khóa',
                            'terminated' => 'Chấm dứt',
                            'need_more_info' => 'Cần bổ sung',
                            'suspended' => 'Tạm khóa',
                            'under_review' => 'Đang kiểm tra',
                            default => 'Chờ duyệt',
                        };
                    @endphp
                    <span class="badge bg-{{ $statusBadge }} text-white px-3 py-2">{{ $statusLabel }}</span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <span class="text-muted d-block small">Số CCCD</span>
                            <strong>{{ $affiliateProfile->id_number ?? 'Chưa có' }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block small">Mã số thuế cá nhân</span>
                            <strong>{{ $affiliateProfile->personal_tax_code ?? 'Chưa có' }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block small">Mã đối tác</span>
                            <strong>{{ $affiliateProfile->affiliate_code ?? 'Chưa có' }}</strong>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4">
                            <span class="text-muted d-block small mb-1">Ảnh CCCD mặt trước</span>
                            @if ($affiliateProfile->id_front_image)
                                <a href="{{ $affiliateProfile->id_front_image }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-image me-1"></i> Xem ảnh trước</a>
                            @else
                                <span class="text-muted small">Không có</span>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block small mb-1">Ảnh CCCD mặt sau</span>
                            @if ($affiliateProfile->id_back_image)
                                <a href="{{ $affiliateProfile->id_back_image }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-image me-1"></i> Xem ảnh sau</a>
                            @else
                                <span class="text-muted small">Không có</span>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted d-block small mb-1">Ảnh chân dung</span>
                            @if ($affiliateProfile->id_selfie_image)
                                <a href="{{ $affiliateProfile->id_selfie_image }}" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-image me-1"></i> Xem ảnh chân dung</a>
                            @else
                                <span class="text-muted small">Không có</span>
                            @endif
                        </div>
                    </div>

                    @if ($affiliateProfile->rejection_reason)
                        <div class="alert alert-danger mb-0 mt-3 py-2 small">
                            <strong>Lý do từ chối trước đó:</strong> {{ $affiliateProfile->rejection_reason }}
                        </div>
                    @endif

                    @if (in_array($affiliateProfile->status, ['pending', 'submitted', 'under_review', 'need_more_info'], true))
                        <div class="border-top pt-3 mt-3 d-flex flex-wrap gap-2 align-items-center">
                            <span class="text-muted font-weight-bold me-2"><i class="fas fa-check-circle me-1"></i>Thao tác duyệt đối tác:</span>
                            <form method="POST" class="affiliate-action-form d-inline-block" action="{{ route('admin.affiliate.requests.approve', $affiliateProfile->id) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn btn-success btn-sm px-3"><i class="fas fa-check me-1"></i> Duyệt hồ sơ</button>
                            </form>
                            
                            <button type="button" class="btn btn-danger btn-sm px-3" data-toggle="collapse" data-target="#rejectProfileCollapse"><i class="fas fa-times me-1"></i> Từ chối</button>
                        </div>
                        
                        <div class="collapse mt-3" id="rejectProfileCollapse">
                            <div class="card card-body bg-light border-danger shadow-sm">
                                <form method="POST" class="affiliate-action-form" action="{{ route('admin.affiliate.requests.reject', $affiliateProfile->id) }}">
                                    @csrf
                                    <div class="mb-3">
                                        <label class="form-label font-weight-bold text-danger">Nhập lý do từ chối hồ sơ:</label>
                                        <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Nhập lý do từ chối để gửi thông báo cho khách hàng..." required></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-paper-plane me-1"></i> Xác nhận từ chối</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="alert alert-warning mb-4 shadow-sm">
                <i class="fas fa-exclamation-triangle me-2"></i> Khách hàng chưa đăng ký đối tác (Chưa có hồ sơ đối tác Affiliate).
            </div>
        @endif

        <div class="row">
            <!-- F1 List -->
            <div class="col-md-6">
                <div class="card mb-3 border-light shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 font-weight-bold text-primary"><i class="fas fa-users me-2"></i>Mạng lưới F1 (Cấp dưới trực tiếp)</h6>
                    </div>
                    <div class="card-body p-0">
                        @if ($f1Users->isEmpty())
                            <div class="p-3 text-muted text-center">Chưa có thành viên F1.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="bg-light small">
                                        <tr>
                                            <th>ID</th>
                                            <th>Họ tên / Tên</th>
                                            <th>Số điện thoại</th>
                                            <th>Ngày tham gia</th>
                                        </tr>
                                    </thead>
                                    <tbody class="small">
                                        @foreach ($f1Users as $f1)
                                            <tr>
                                                <td>{{ $f1->id }}</td>
                                                <td>
                                                    <strong>{{ $f1->full_name ?? $f1->name }}</strong>
                                                    @if ($f1->status === 'active')
                                                        <span class="badge bg-success text-white px-1">Active</span>
                                                    @endif
                                                </td>
                                                <td>{{ $f1->phone ?? '—' }}</td>
                                                <td>{{ $f1->created_at->format('d/m/Y') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($f1Users->lastPage() > 1)
                                <div class="p-2 border-top d-flex justify-content-end">
                                    {{ $f1Users->onEachSide(1)->links('pagination::bootstrap-4') }}
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>

            <!-- F2 List -->
            <div class="col-md-6">
                <div class="card mb-3 border-light shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="mb-0 font-weight-bold text-info"><i class="fas fa-users-cog me-2"></i>Mạng lưới F2 (Cấp dưới gián tiếp)</h6>
                    </div>
                    <div class="card-body p-0">
                        @if ($f2Users->isEmpty())
                            <div class="p-3 text-muted text-center">Chưa có thành viên F2.</div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover table-sm mb-0">
                                    <thead class="bg-light small">
                                        <tr>
                                            <th>ID</th>
                                            <th>Họ tên / Tên</th>
                                            <th>Số điện thoại</th>
                                            <th>Ngày tham gia</th>
                                        </tr>
                                    </thead>
                                    <tbody class="small">
                                        @foreach ($f2Users as $f2)
                                            <tr>
                                                <td>{{ $f2->id }}</td>
                                                <td>
                                                    <strong>{{ $f2->full_name ?? $f2->name }}</strong>
                                                    @if ($f2->status === 'active')
                                                        <span class="badge bg-success text-white px-1">Active</span>
                                                    @endif
                                                </td>
                                                <td>{{ $f2->phone ?? '—' }}</td>
                                                <td>{{ $f2->created_at->format('d/m/Y') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($f2Users->lastPage() > 1)
                                <div class="p-2 border-top d-flex justify-content-end">
                                    {{ $f2Users->onEachSide(1)->links('pagination::bootstrap-4') }}
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Commission History Tab -->
    <div class="tab-pane fade" id="commission-history" role="tabpanel">
        <!-- Quick Stats -->
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="border rounded p-3 bg-light text-center h-100 shadow-sm">
                    <div class="text-success mb-1" style="font-size: 1.5rem;"><i class="fas fa-wallet"></i></div>
                    <div class="text-muted small">Đã rút</div>
                    <h5 class="font-weight-bold text-success mb-0">{{ number_format($commissionStats['withdrawn'], 0, ',', '.') }}đ</h5>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 bg-light text-center h-100 shadow-sm">
                    <div class="text-warning mb-1" style="font-size: 1.5rem;"><i class="fas fa-hourglass-half"></i></div>
                    <div class="text-muted small">Đang chờ hoàn thành</div>
                    <h5 class="font-weight-bold text-warning mb-0">{{ number_format($commissionStats['pending'], 0, ',', '.') }}đ</h5>
                </div>
            </div>
            <div class="col-md-4">
                <div class="border rounded p-3 bg-light text-center h-100 shadow-sm">
                    <div class="text-danger mb-1" style="font-size: 1.5rem;"><i class="fas fa-times-circle"></i></div>
                    <div class="text-muted small">Không thành công do đơn hủy</div>
                    <h5 class="font-weight-bold text-danger mb-0">{{ number_format($commissionStats['cancelled'], 0, ',', '.') }}đ</h5>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card mb-3 border-light shadow-sm">
            <div class="card-body bg-light p-3">
                <form id="commissionFilterForm" class="row g-2 align-items-end small">
                    <div class="col-md-3">
                        <label class="form-label font-weight-bold text-dark">Trạng thái hoa hồng</label>
                        <select name="commission_status" class="form-select form-select-sm">
                            <option value="">Tất cả</option>
                            <option value="PENDING" {{ request('commission_status') === 'PENDING' ? 'selected' : '' }}>Chờ xử lý</option>
                            <option value="WAITING_ORDER_COMPLETE" {{ request('commission_status') === 'WAITING_ORDER_COMPLETE' ? 'selected' : '' }}>Chờ hoàn thành đơn</option>
                            <option value="WAITING_RETURN_PERIOD" {{ request('commission_status') === 'WAITING_RETURN_PERIOD' ? 'selected' : '' }}>Chờ hết hạn đổi trả</option>
                            <option value="ELIGIBLE" {{ request('commission_status') === 'ELIGIBLE' ? 'selected' : '' }}>Đủ điều kiện rút</option>
                            <option value="APPROVED" {{ request('commission_status') === 'APPROVED' ? 'selected' : '' }}>Đã duyệt</option>
                            <option value="PAID" {{ request('commission_status') === 'PAID' ? 'selected' : '' }}>Đã thanh toán</option>
                            <option value="CANCELLED" {{ request('commission_status') === 'CANCELLED' ? 'selected' : '' }}>Bị hủy</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label font-weight-bold text-dark">Từ ngày</label>
                        <input type="date" name="commission_start_date" class="form-control form-control-sm" value="{{ request('commission_start_date') }}">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label font-weight-bold text-dark">Đến ngày</label>
                        <input type="date" name="commission_end_date" class="form-control form-control-sm" value="{{ request('commission_end_date') }}">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i> Lọc</button>
                        <button type="button" id="clearCommissionFilter" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync-alt"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Commissions List Table -->
        <div class="card border-light shadow-sm">
            <div class="card-body p-0">
                @if ($commissions->isEmpty())
                    <div class="p-3 text-muted text-center">Chưa có lịch sử nhận hoa hồng nào.</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered mb-0 table-sm">
                            <thead class="bg-light small">
                                <tr>
                                    <th>Mã đơn</th>
                                    <th>Giá trị đơn hàng</th>
                                    <th>% Hoa hồng</th>
                                    <th>Tiền hoa hồng</th>
                                    <th>Trạng thái</th>
                                    <th>Ngày tạo</th>
                                </tr>
                            </thead>
                            <tbody class="small">
                                @foreach ($commissions as $commission)
                                    <tr>
                                        <td>
                                            @if ($commission->order_id)
                                                <a href="{{ route('admin.orders.show', $commission->order_id) }}" target="_blank" class="text-primary font-weight-bold">
                                                    #{{ $commission->order_id }}
                                                </a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ number_format((float) $commission->commission_base_amount, 0, ',', '.') }}đ</td>
                                        <td>
                                            @if (is_numeric($commission->commission_value))
                                                {{ (float) $commission->commission_value }}%
                                            @else
                                                {{ $commission->commission_value }}
                                            @endif
                                        </td>
                                        <td class="font-weight-bold text-success">{{ number_format((float) $commission->pending_amount, 0, ',', '.') }}đ</td>
                                        <td>
                                            @php
                                                $commBadge = match(strtoupper((string) $commission->status)) {
                                                    'PAID', 'APPROVED' => 'success',
                                                    'CANCELLED', 'REJECTED' => 'danger',
                                                    'PENDING', 'PAYOUT_PENDING' => 'warning',
                                                    default => 'info',
                                                };
                                                $commStatusLabel = match(strtoupper((string) $commission->status)) {
                                                    'PENDING' => 'Chờ xử lý',
                                                    'WAITING_ORDER_COMPLETE' => 'Chờ hoàn thành đơn',
                                                    'WAITING_RETURN_PERIOD' => 'Chờ hết hạn đổi trả',
                                                    'ELIGIBLE' => 'Đủ điều kiện rút',
                                                    'APPROVED' => 'Đã duyệt',
                                                    'PAID' => 'Đã thanh toán',
                                                    'CANCELLED' => 'Bị hủy',
                                                    'REJECTED' => 'Bị từ chối',
                                                    'HELD' => 'Tạm giữ',
                                                    'PAYOUT_PENDING' => 'Chờ thanh toán',
                                                    'CLAWBACK' => 'Thu hồi',
                                                    default => $commission->status,
                                                };
                                            @endphp
                                            <span class="badge bg-{{ $commBadge }} text-white">{{ $commStatusLabel }}</span>
                                            @if ($commission->reason_cancelled)
                                                <small class="text-muted d-block" style="font-size: 10px;">Lý do hủy: {{ $commission->reason_cancelled }}</small>
                                            @endif
                                        </td>
                                        <td>{{ $commission->created_at->format('d/m/Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($commissions->lastPage() > 1)
                        <div class="p-2 border-top d-flex justify-content-end">
                            {{ $commissions->onEachSide(1)->links('pagination::bootstrap-4') }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        function bindUserDetailTabs(root) {
            if (!window.jQuery) {
                return;
            }

            var $root = root ? window.jQuery(root) : window.jQuery(document);
            $root.find('#userDetailTabs a').off('.userDetailTabs').on('click.userDetailTabs', function (e) {
                e.preventDefault();
                window.jQuery(this).tab('show');
            });
        }

        window.initUserDetailTabs = bindUserDetailTabs;

        function start() {
            bindUserDetailTabs(document);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start, { once: true });
            return;
        }

        start();
    })();
</script>
