@extends('admin.layout')

@section('title', 'Lệnh rút hạn mức cửa hàng')

@section('content')
@php
    $statusLabels = [
        'pending' => ['label' => 'Chờ chuyển khoản', 'class' => 'bg-warning text-dark'],
        'approved' => ['label' => 'Chờ chuyển khoản', 'class' => 'bg-warning text-dark'],
        'processing' => ['label' => 'Đang xử lý', 'class' => 'bg-info text-dark'],
        'paid' => ['label' => 'Đã chuyển khoản', 'class' => 'bg-success'],
        'rejected' => ['label' => 'Đã từ chối/hoàn ví', 'class' => 'bg-danger'],
    ];
    $currentStatus = $filters['status'] ?? '';
@endphp

<div class="page-inner">
    <div class="page-header">
        <h4 class="page-title">Lệnh rút hạn mức cửa hàng</h4>
        <ul class="breadcrumbs">
            <li class="nav-home">
                <a href="{{ route('admin') }}"><i class="fas fa-home"></i></a>
            </li>
            <li class="separator"><i class="fas fa-chevron-right"></i></li>
            <li class="nav-item"><a href="{{ route('admin.store-wallet-withdrawals.index') }}">Lệnh rút ví</a></li>
        </ul>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card mb-0">
                <div class="card-body">
                    <div class="text-muted small">Chờ chuyển khoản</div>
                    <div class="h3 mb-0">{{ number_format((int) ($summary['pending'] ?? 0), 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card mb-0">
                <div class="card-body">
                    <div class="text-muted small">Đã chuyển khoản</div>
                    <div class="h3 mb-0 text-success">{{ number_format((int) ($summary['paid'] ?? 0), 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card mb-0">
                <div class="card-body">
                    <div class="text-muted small">Từ chối/hoàn ví</div>
                    <div class="h3 mb-0 text-danger">{{ number_format((int) ($summary['rejected'] ?? 0), 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" action="{{ route('admin.store-wallet-withdrawals.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Trạng thái</label>
                    <select name="status" class="form-select">
                        <option value="">Tất cả</option>
                        <option value="pending" {{ $currentStatus === 'pending' ? 'selected' : '' }}>Chờ chuyển khoản</option>
                        <option value="paid" {{ $currentStatus === 'paid' ? 'selected' : '' }}>Đã chuyển khoản</option>
                        <option value="rejected" {{ $currentStatus === 'rejected' ? 'selected' : '' }}>Từ chối/hoàn ví</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Tìm kiếm</label>
                    <input type="text" name="keyword" class="form-control" value="{{ $filters['keyword'] ?? '' }}" placeholder="Tên cửa hàng, mã cửa hàng, ngân hàng, số tài khoản...">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Số dòng</label>
                    <input type="number" name="per_page" class="form-control" min="1" max="100" value="{{ $filters['per_page'] ?? 20 }}">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary w-100" type="submit">Lọc</button>
                    <a class="btn btn-outline-secondary" href="{{ route('admin.store-wallet-withdrawals.index') }}">Xóa</a>
                </div>
            </form>
        </div>

        <div class="card-body">
            <div class="alert alert-info">
                Khi cửa hàng bấm rút, hệ thống đã trừ tiền khỏi ví ngay. Super admin/kế toán chỉ cần chuyển khoản từ tài khoản tổng đến tài khoản ngân hàng bên dưới, rồi bấm "Đã chuyển khoản".
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Thời gian</th>
                            <th>Cửa hàng</th>
                            <th class="text-end">Số tiền</th>
                            <th>Tài khoản nhận</th>
                            <th>Đối soát</th>
                            <th>Trạng thái</th>
                            <th style="width: 300px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($withdrawals as $withdrawal)
                            @php
                                $status = (string) $withdrawal->status;
                                $statusMeta = $statusLabels[$status] ?? ['label' => ucfirst($status), 'class' => 'bg-secondary'];
                                $canProcess = in_array($status, ['pending', 'approved', 'processing'], true);
                            @endphp
                            <tr>
                                <td>
                                    <div>{{ optional($withdrawal->created_at)->format('d/m/Y H:i') }}</div>
                                    <div class="text-muted small">#{{ $withdrawal->id }}</div>
                                </td>
                                <td>
                                    @if($withdrawal->store)
                                        <a href="{{ route('admin.store-wallets.show', $withdrawal->store_id) }}" class="fw-semibold">
                                            {{ $withdrawal->store->name ?? 'N/A' }}
                                        </a>
                                        <div class="text-muted small">{{ $withdrawal->store->store_code ?? $withdrawal->store_id }}</div>
                                    @else
                                        <span class="text-muted">{{ $withdrawal->store_id }}</span>
                                    @endif
                                </td>
                                <td class="text-end fw-bold">{{ number_format((float) $withdrawal->amount, 0, ',', '.') }}đ</td>
                                <td>
                                    <div class="fw-semibold">{{ $withdrawal->account_name }}</div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span>{{ $withdrawal->bank_name ?: 'N/A' }} - {{ $withdrawal->account_number }}</span>
                                        @if($withdrawal->bank_name && $withdrawal->account_number)
                                            <button type="button" class="btn btn-link btn-xs p-0 text-primary show-qr-btn"
                                                data-qr-url="{{ $withdrawal->getVietQrUrl() }}"
                                                data-bank-name="{{ $withdrawal->bank_name }}"
                                                data-bank-code="{{ $withdrawal->getVietQrBankCode() }}"
                                                data-account-number="{{ $withdrawal->account_number }}"
                                                data-account-name="{{ $withdrawal->account_name }}"
                                                data-amount="{{ number_format((float) $withdrawal->amount, 0, ',', '.') }}đ"
                                                data-amount-raw="{{ (int) $withdrawal->amount }}"
                                                data-description="Rut vi Ecoluck {{ substr($withdrawal->id, 0, 8) }}"
                                                title="Quét mã QR chuyển khoản"
                                                style="font-size: 1.1rem; line-height: 1;"
                                            >
                                                <i class="fas fa-qrcode"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td>
                                    @if($withdrawal->financialReconciliation)
                                        <div>{{ $withdrawal->financialReconciliation->period }} / {{ optional($withdrawal->financialReconciliation->period_date)->format('d/m/Y') }}</div>
                                        <div class="text-muted small">{{ $withdrawal->financialReconciliation->status }}</div>
                                    @else
                                        <span class="text-muted">N/A</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span>
                                    @if($withdrawal->paid_at)
                                        <div class="text-muted small">{{ $withdrawal->paid_at->format('d/m/Y H:i') }}</div>
                                    @elseif($withdrawal->rejected_at)
                                        <div class="text-muted small">{{ $withdrawal->rejected_at->format('d/m/Y H:i') }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($canProcess)
                                        @if($withdrawal->is_latest_unapproved)
                                            @if($withdrawal->bank_name && $withdrawal->account_number)
                                                <button type="button" class="btn btn-primary btn-sm w-100 mb-2 show-qr-btn"
                                                    data-qr-url="{{ $withdrawal->getVietQrUrl() }}"
                                                    data-bank-name="{{ $withdrawal->bank_name }}"
                                                    data-bank-code="{{ $withdrawal->getVietQrBankCode() }}"
                                                    data-account-number="{{ $withdrawal->account_number }}"
                                                    data-account-name="{{ $withdrawal->account_name }}"
                                                    data-amount="{{ number_format((float) $withdrawal->amount, 0, ',', '.') }}đ"
                                                    data-amount-raw="{{ (int) $withdrawal->amount }}"
                                                    data-description="Rut vi Ecoluck {{ substr($withdrawal->id, 0, 8) }}"
                                                >
                                                    <i class="fas fa-qrcode me-1"></i> Quét mã QR chuyển tiền
                                                </button>
                                            @endif
                                            <form method="POST" action="{{ route('admin.store-wallet-withdrawals.mark-paid', $withdrawal) }}" class="mb-2" onsubmit="return confirm('Xác nhận đã chuyển khoản cho cửa hàng này?')">
                                                @csrf
                                                <button type="submit" class="btn btn-success btn-sm w-100">
                                                    <i class="fas fa-check me-1"></i> Đã chuyển khoản
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.store-wallet-withdrawals.reject', $withdrawal) }}" onsubmit="return confirm('Từ chối yêu cầu này và hoàn tiền vào ví cửa hàng?')">
                                                @csrf
                                                <div class="input-group input-group-sm">
                                                    <input type="text" name="reason" class="form-control" placeholder="Lý do từ chối">
                                                    <button type="submit" class="btn btn-outline-danger">Từ chối</button>
                                                </div>
                                            </form>
                                        @else
                                            <span class="text-muted small"><i class="fas fa-lock me-1"></i> Bị khóa (Chỉ duyệt đơn mới nhất)</span>
                                        @endif
                                    @else
                                        <div class="text-muted small">{{ $withdrawal->admin_note ?: 'Không còn thao tác' }}</div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">Chưa có lệnh rút hạn mức cửa hàng.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                {{ $withdrawals->links('pagination::bootstrap-4') }}
            </div>
        </div>
    </div>
</div>

<!-- Modal QR chuyển khoản -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="qrModalLabel">
                    <i class="fas fa-qrcode text-primary me-2"></i> QR Chuyển khoản VietQR
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <div class="mb-3 text-muted small">
                    Mở App Ngân hàng quét mã VietQR bên dưới để tự động điền thông tin chuyển khoản.
                </div>
                
                <div class="position-relative d-inline-block p-3 border rounded bg-white shadow-sm mb-4" style="border-color: #e2e8f0 !important;">
                    <img id="qrImage" src="" alt="Mã QR Chuyển khoản" style="max-width: 260px; height: auto;" class="img-fluid">
                </div>

                <div class="text-start border-top pt-3">
                    <div class="mb-2 row align-items-center">
                        <label class="col-sm-4 text-muted small mb-0">Ngân hàng:</label>
                        <div class="col-sm-8 fw-bold text-dark" id="qrBankText"></div>
                    </div>
                    <div class="mb-2 row align-items-center">
                        <label class="col-sm-4 text-muted small mb-0">Số tài khoản:</label>
                        <div class="col-sm-8 d-flex align-items-center justify-content-between">
                            <span class="fw-bold text-dark" id="qrAccNoText" style="font-size: 1.1rem;"></span>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2 copy-btn" data-target="qrAccNoText" title="Sao chép">
                                <i class="far fa-copy"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-2 row align-items-center">
                        <label class="col-sm-4 text-muted small mb-0">Tên người nhận:</label>
                        <div class="col-sm-8 fw-semibold text-uppercase text-dark" id="qrAccNameText"></div>
                    </div>
                    <div class="mb-2 row align-items-center">
                        <label class="col-sm-4 text-muted small mb-0">Số tiền:</label>
                        <div class="col-sm-8 d-flex align-items-center justify-content-between">
                            <span class="fw-bold text-success" id="qrAmountText" style="font-size: 1.1rem;"></span>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2 copy-btn" data-target="qrAmountRaw" title="Sao chép">
                                <i class="far fa-copy"></i>
                            </button>
                            <input type="hidden" id="qrAmountRaw" value="">
                        </div>
                    </div>
                    <div class="mb-2 row align-items-center">
                        <label class="col-sm-4 text-muted small mb-0">Nội dung chuyển:</label>
                        <div class="col-sm-8 d-flex align-items-center justify-content-between">
                            <span class="font-monospace text-primary fw-bold" id="qrDescText"></span>
                            <button type="button" class="btn btn-outline-secondary btn-xs py-1 px-2 copy-btn" data-target="qrDescText" title="Sao chép">
                                <i class="far fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

@section('scripts')
<script>
$(document).ready(function() {
    // Click button to show QR Modal
    $(document).on('click', '.show-qr-btn', function() {
        var btn = $(this);
        var qrUrl = btn.data('qr-url');
        var bankName = btn.data('bank-name');
        var bankCode = btn.data('bank-code');
        var accNo = btn.data('account-number');
        var accName = btn.data('account-name');
        var amount = btn.data('amount');
        var amountRaw = btn.data('amount-raw');
        var desc = btn.data('description');

        // Set values in modal
        $('#qrImage').attr('src', qrUrl);
        $('#qrBankText').text(bankName + ' (' + bankCode + ')');
        $('#qrAccNoText').text(accNo);
        $('#qrAccNameText').text(accName);
        $('#qrAmountText').text(amount);
        $('#qrAmountRaw').val(amountRaw);
        $('#qrDescText').text(desc);

        // Show modal (using Bootstrap 5 JS or jQuery fallback)
        try {
            var modalEl = document.getElementById('qrModal');
            var myModal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            myModal.show();
        } catch (e) {
            // Fallback for older configurations or jQuery integration
            $('#qrModal').modal('show');
        }
    });

    // Copy to clipboard function
    $(document).on('click', '.copy-btn', function() {
        var btn = $(this);
        var targetId = btn.data('target');
        var textToCopy = '';
        
        var element = $('#' + targetId);
        if (element.is('input')) {
            textToCopy = element.val();
        } else {
            textToCopy = element.text().trim();
        }

        if (textToCopy) {
            navigator.clipboard.writeText(textToCopy).then(function() {
                var originalHtml = btn.html();
                btn.html('<i class="fas fa-check text-success"></i>');
                setTimeout(function() {
                    btn.html(originalHtml);
                }, 1500);
            }).catch(function(err) {
                // Fallback copy method
                var temp = $('<input>');
                $('body').append(temp);
                temp.val(textToCopy).select();
                document.execCommand('copy');
                temp.remove();
                
                var originalHtml = btn.html();
                btn.html('<i class="fas fa-check text-success"></i>');
                setTimeout(function() {
                    btn.html(originalHtml);
                }, 1500);
            });
        }
    });
});
</script>
@endsection

@endsection
