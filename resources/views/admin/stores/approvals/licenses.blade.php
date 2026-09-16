@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <div class="mb-4 d-flex align-items-center justify-content-between">
            <div>
                <h2 class="mb-0">Chi tiết duyệt cửa hàng</h2>
                <div class="text-muted small mt-1">{{ $store->store_code }} - {{ $store->name }}</div>
            </div>
            <a href="{{ route('admin.store-approvals.index') }}" class="btn btn-outline-secondary">← Quay lại</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <!-- Store Information Grid -->
        <div class="row g-4 mb-4">
            <!-- Store and Owner Info -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; border: 1px solid #e2e8f0 !important;">
                    <div class="card-header bg-white border-0 py-3" style="border-radius: 12px 12px 0 0;">
                        <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-store text-primary me-2"></i> Thông tin cửa hàng & Chủ sở hữu</h5>
                    </div>
                    <div class="card-body pt-0">
                        <table class="table table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted ps-0" style="width: 35%;">Mã cửa hàng:</td>
                                    <td class="fw-semibold">{{ $store->store_code }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Tên cửa hàng:</td>
                                    <td>{{ $store->name }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Địa chỉ:</td>
                                    <td>{{ $store->address ?? 'N/A' }}</td>
                                </tr>
                                @if(!empty($store->description))
                                <tr>
                                    <td class="text-muted ps-0">Mô tả:</td>
                                    <td>{{ $store->description }}</td>
                                </tr>
                                @endif
                                <tr class="border-top">
                                    <td class="text-muted ps-0 pt-3">Chủ cửa hàng:</td>
                                    <td class="fw-semibold pt-3">{{ optional($store->owner)->full_name ?: (optional($store->owner)->name ?: 'N/A') }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Số điện thoại:</td>
                                    <td>{{ optional($store->owner)->phone ?: ($store->phone ?? 'N/A') }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Email:</td>
                                    <td>{{ optional($store->owner)->email ?: ($store->email ?? 'N/A') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Registration and Bank Info -->
            <div class="col-md-6">
                <div class="card h-100 border-0 shadow-sm" style="border-radius: 12px; border: 1px solid #e2e8f0 !important;">
                    <div class="card-header bg-white border-0 py-3" style="border-radius: 12px 12px 0 0;">
                        <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-file-invoice text-primary me-2"></i> Thông tin đăng ký & Ngân hàng</h5>
                    </div>
                    <div class="card-body pt-0">
                        <table class="table table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <td class="text-muted ps-0" style="width: 35%;">Người đại diện:</td>
                                    <td class="fw-semibold">{{ $store->representative ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Loại hình KD:</td>
                                    <td>{{ $store->business_type ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Ngân hàng:</td>
                                    <td>{{ $store->bank_name ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Số tài khoản:</td>
                                    <td>{{ $store->bank_account ?? 'N/A' }}</td>
                                </tr>
                                <tr class="border-top">
                                    <td class="text-muted ps-0 pt-3">Số GPLK:</td>
                                    <td class="fw-semibold pt-3">{{ $store->license_number ?? 'N/A' }}</td>
                                </tr>
                                <tr>
                                    <td class="text-muted ps-0">Ảnh GPLK chính:</td>
                                    <td>
                                        @if (!empty($store->license_image))
                                            <button type="button" class="btn btn-link btn-sm p-0 license-preview-trigger text-primary" data-license-image="{{ $store->license_image }}" data-license-title="Ảnh giấy phép chính">
                                                Xem ảnh GPLK chính
                                            </button>
                                        @else
                                            <span class="text-muted small">Chưa có ảnh chính</span>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approval / Rejection Action Panel for Store -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; border: 1px solid #e2e8f0 !important;">
            <div class="card-header bg-white border-0 py-3" style="border-radius: 12px 12px 0 0;">
                <h5 class="fw-bold mb-0 text-dark"><i class="fas fa-tasks text-primary me-2"></i> Phê duyệt cửa hàng</h5>
            </div>
            <div class="card-body pt-0">
                <div class="d-flex align-items-center justify-content-between p-3 bg-light rounded mb-3" style="border-radius: 8px;">
                    <div>
                        <span class="text-muted me-2">Trạng thái hiện tại:</span>
                        @if ($store->status === 'pending')
                            <span class="badge bg-warning text-dark px-3 py-2" style="font-size: 0.9rem;">Chờ duyệt</span>
                        @elseif ($store->status === 'active')
                            <span class="badge bg-success px-3 py-2" style="font-size: 0.9rem;">Đã duyệt</span>
                        @else
                            <span class="badge bg-danger px-3 py-2" style="font-size: 0.9rem;">Từ chối</span>
                        @endif
                    </div>
                    @if ($store->status !== 'pending')
                        <div class="text-end small">
                            <div><strong>Người duyệt:</strong> {{ optional($store->approver)->full_name ?: (optional($store->approver)->name ?: 'N/A') }}</div>
                            <div><strong>Thời gian:</strong> {{ $store->approved_at ? $store->approved_at->format('Y-m-d H:i:s') : 'N/A' }}</div>
                        </div>
                    @endif
                </div>

                @if ($store->status === 'pending')
                    <div class="row pt-2">
                        <div class="col-md-6 border-end">
                            <h6 class="fw-bold text-success mb-3"><i class="fas fa-check-circle me-1"></i> Đồng ý phê duyệt</h6>
                            <p class="text-muted small">Nhấn nút bên dưới để duyệt cửa hàng này hoạt động trên hệ thống.</p>
                            <form method="POST" action="{{ route('admin.store-approvals.review', $store->id) }}" onsubmit="return confirm('Xác nhận duyệt cửa hàng {{ $store->name }}?');">
                                @csrf
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success px-4 py-2" style="border-radius: 8px; font-weight: 600;">
                                    <i class="fas fa-check me-2"></i> Duyệt cửa hàng
                                </button>
                            </form>
                        </div>
                        <div class="col-md-6 ps-md-4 mt-4 mt-md-0">
                            <h6 class="fw-bold text-danger mb-3"><i class="fas fa-times-circle me-1"></i> Từ chối phê duyệt</h6>
                            <form method="POST" action="{{ route('admin.store-approvals.review', $store->id) }}" onsubmit="return confirm('Xác nhận từ chối cửa hàng {{ $store->name }}?');">
                                @csrf
                                <input type="hidden" name="action" value="reject">
                                <div class="mb-3">
                                    <label class="form-label small text-muted">Lý do từ chối <span class="text-danger">*</span></label>
                                    <textarea name="reason" rows="3" class="form-control" placeholder="Nhập lý do từ chối cửa hàng..." required style="border-radius: 8px;"></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger px-4 py-2" style="border-radius: 8px; font-weight: 600;">
                                    <i class="fas fa-ban me-2"></i> Từ chối cửa hàng
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    @if (!empty($store->rejection_reason))
                        <div class="alert alert-danger mb-0" style="border-radius: 8px;">
                            <strong>Lý do từ chối:</strong>
                            <p class="mb-0 mt-1">{{ $store->rejection_reason }}</p>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <!-- CCCD Section -->
        @if(!empty($store->cccd_front_url) || !empty($store->cccd_back_url))
            <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; border: 1px solid #e2e8f0 !important;">
                <div class="card-header bg-light py-3" style="border-radius: 12px 12px 0 0;">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-id-card text-primary me-2"></i> Xác minh danh tính (CCCD)</h5>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        @if(!empty($store->cccd_front_url))
                            <div class="col-md-6 col-lg-4">
                                <div class="card border h-100" style="box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04); border-radius: 10px;">
                                    <div class="card-body d-flex flex-column gap-3">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <div>
                                                <h6 class="mb-1 fw-bold">CCCD Mặt trước</h6>
                                                <small class="text-muted">Ảnh xác minh chủ sở hữu</small>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100 license-preview-trigger" data-license-image="{{ $store->cccd_front_url }}" data-license-title="Ảnh CCCD mặt trước">
                                            Xem ảnh mặt trước
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif

                        @if(!empty($store->cccd_back_url))
                            <div class="col-md-6 col-lg-4">
                                <div class="card border h-100" style="box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04); border-radius: 10px;">
                                    <div class="card-body d-flex flex-column gap-3">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <div>
                                                <h6 class="mb-1 fw-bold">CCCD Mặt sau</h6>
                                                <small class="text-muted">Ảnh xác minh chủ sở hữu</small>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-outline-primary btn-sm w-100 license-preview-trigger" data-license-image="{{ $store->cccd_back_url }}" data-license-title="Ảnh CCCD mặt sau">
                                            Xem ảnh mặt sau
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endif

        <!-- Other Licenses Section -->
        <div class="card mb-4 border-0 shadow-sm" style="border-radius: 12px; border: 1px solid #e2e8f0 !important;">
            <div class="card-header bg-light py-3" style="border-radius: 12px 12px 0 0;">
                <div class="d-flex align-items-center justify-content-between">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-file-alt text-primary me-2"></i> Giấy tờ đính kèm ({{ $licenses->count() }})</h5>
                </div>
            </div>
            <div class="card-body">
                @if ($licenses->isEmpty())
                    <div class="text-center text-muted py-5">
                        <p class="mb-0">Chưa có giấy tờ đính kèm nào khác</p>
                    </div>
                @else
                    <div class="row g-4">
                        @foreach ($licenses as $license)
                            <div class="col-md-6 col-lg-4">
                                <div class="card border h-100" style="box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04); border-radius: 10px;">
                                    <div class="card-body d-flex flex-column gap-3">
                                        <!-- Header -->
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <div>
                                                <h6 class="mb-1 fw-bold text-dark">{{ $license->license_type ?? 'N/A' }}</h6>
                                                <small class="text-muted">Giấy tờ #{{ $loop->iteration }}</small>
                                            </div>
                                            @php
                                                $statusClass = match ($license->status) {
                                                    'approved' => 'bg-success',
                                                    'rejected' => 'bg-danger',
                                                    default => 'bg-warning text-dark',
                                                };
                                                $statusLabel = match ($license->status) {
                                                    'approved' => 'Đã duyệt',
                                                    'rejected' => 'Từ chối',
                                                    default => 'Chờ duyệt',
                                                };
                                            @endphp
                                            <span class="badge {{ $statusClass }}" style="font-size: 0.7rem; padding: 0.35rem 0.6rem;">{{ $statusLabel }}</span>
                                        </div>

                                        <!-- Meta Info -->
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem; text-transform: uppercase; font-weight: 600;">Số GPLK</small>
                                                <strong>{{ $license->license_number ?? 'N/A' }}</strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem; text-transform: uppercase; font-weight: 600;">Hạn dùng</small>
                                                <strong>{{ $license->expiry_date ? $license->expiry_date->format('d/m/Y') : 'N/A' }}</strong>
                                            </div>
                                        </div>

                                        <!-- Image Preview -->
                                        @if (!empty($license->image_url))
                                            <button type="button" class="btn btn-outline-primary btn-sm w-100 license-preview-trigger" data-license-image="{{ $license->image_url }}">
                                                Xem ảnh giấy tờ
                                            </button>
                                        @endif

                                        <!-- Rejection Reason -->
                                        @if (!empty($license->rejection_reason))
                                            <div class="alert alert-danger py-2 px-3 mb-0" style="font-size: 0.85rem; border-radius: 6px;">
                                                <strong>Lý do từ chối:</strong>
                                                <div>{{ $license->rejection_reason }}</div>
                                            </div>
                                        @endif

                                        <!-- Action Buttons -->
                                        @if ($license->status === 'pending')
                                            <div class="d-flex gap-2 pt-2">
                                                <form method="POST" action="{{ route('admin.store-approvals.licenses.approve', $license->id) }}" class="flex-fill" onsubmit="return confirm('Xác nhận duyệt giấy tờ này?');">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-success btn-sm w-100">Duyệt</button>
                                                </form>
                                                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectLicenseModal" data-license-id="{{ $license->id }}">
                                                    Từ chối
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Image Preview Modal -->
    <div class="modal fade" id="licenseImageModal" tabindex="-1" aria-labelledby="licenseImageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="licenseImageModalLabel">Ảnh giấy tờ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="licenseImagePreview" src="" alt="Ảnh giấy tờ" class="img-fluid rounded" style="max-height: 70vh; object-fit: contain;">
                </div>
                <div class="modal-footer">
                    <a id="licenseImageOpenInNewTab" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary" aria-disabled="true">
                        Mở rộng (tab mới)
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Reject License Modal -->
    <div class="modal fade" id="rejectLicenseModal" tabindex="-1" aria-labelledby="rejectLicenseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectLicenseModalLabel">Từ chối giấy tờ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="rejectLicenseForm" method="POST" onsubmit="return confirm('Xác nhận từ chối giấy tờ này?');">
                    @csrf
                    @method('PATCH')
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="rejection_reason" class="form-label">Lý do từ chối <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="4" required placeholder="Nhập lý do từ chối..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        <button type="submit" class="btn btn-danger">Từ chối</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
<script>
    $(function () {
        $(document).on('click', '.license-preview-trigger', function () {
            var imageUrl = $(this).data('license-image');
            var title = $(this).data('license-title') || 'Ảnh giấy tờ';
            if (!imageUrl) {
                return;
            }

            $('#licenseImageModalLabel').text(title);
            $('#licenseImagePreview').attr('src', imageUrl);
            $('#licenseImageOpenInNewTab').attr('href', imageUrl).removeAttr('aria-disabled');
            var modalElement = document.getElementById('licenseImageModal');
            var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        });

        $('#licenseImageModal').on('hidden.bs.modal', function () {
            $('#licenseImagePreview').attr('src', '');
            $('#licenseImageOpenInNewTab').attr('href', '#').attr('aria-disabled', 'true');
        });

        $('#rejectLicenseModal').on('show.bs.modal', function (event) {
            var button = event.relatedTarget;
            var licenseId = $(button).data('license-id');
            var action = '{{ route("admin.store-approvals.licenses.reject", ":id") }}'.replace(':id', licenseId);
            $('#rejectLicenseForm').attr('action', action);
            $('#rejection_reason').val('').focus();
        });
    });
</script>
@endsection
