@extends('admin.layout')

@section('content')
    @php
        $statusLabel = match($profile->status) {
            'pending', 'submitted' => 'Chờ duyệt',
            'under_review' => 'Đang kiểm tra',
            'need_more_info' => 'Cần bổ sung',
            'approved' => 'Đã duyệt',
            'rejected' => 'Từ chối',
            'suspended' => 'Tạm khóa',
            'locked' => 'Khóa',
            'terminated' => 'Chấm dứt',
            'draft' => 'Bản nháp',
            default => strtoupper($profile->status),
        };
        $user = $profile->user;
        $bank = $user?->bankAccount;
        $displayName = $formattedProfile['full_name'] ?? $user?->personal_full_name ?? $user?->full_name ?? $user?->name ?? '—';
        $statusBadge = match($profile->status) {
            'approved' => 'success',
            'rejected', 'locked', 'terminated' => 'danger',
            'need_more_info', 'suspended' => 'warning',
            'under_review' => 'info',
            default => 'secondary',
        };
        $canDeleteProfile = ! in_array($profile->status, ['approved', 'suspended', 'locked', 'terminated'], true);
        $hasStartedReview = !empty($formattedProfile['review_started_at']);
        $canStartReview = in_array($profile->status, ['pending', 'submitted', 'need_more_info'], true) && !$hasStartedReview;
        $canProcessReview = $profile->status === 'under_review'
            || ($profile->status === 'pending' && $hasStartedReview);
    @endphp
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <a href="{{ route('admin.affiliate.requests.index') }}" class="btn btn-link px-0 text-decoration-none">&larr; Quay lại danh sách</a>
                <h2 class="mb-1">Chi tiết hồ sơ Affiliate #{{ $profile->id }}</h2>
                <div class="text-muted">
                    <span class="badge bg-{{ $statusBadge }}">{{ $statusLabel }}</span>
                    <span class="ms-2">Người dùng: {{ $displayName }}</span>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.affiliate.customers.index', ['affiliate_id' => $profile->user_id]) }}" class="btn btn-outline-primary">
                    Khách hàng bên dưới
                </a>
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
                    <div class="card-header fw-semibold">1. Thông tin cá nhân</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><strong>Họ tên:</strong> {{ $displayName }}</div>
                            <div class="col-md-6"><strong>Số điện thoại:</strong> {{ $user?->phone ?? '—' }}</div>
                            <div class="col-md-6"><strong>Ngày sinh:</strong> {{ !empty($formattedProfile['birthday']) ? \Carbon\Carbon::parse($formattedProfile['birthday'])->format('d/m/Y') : '—' }}</div>
                            <div class="col-md-6"><strong>Email:</strong> {{ $user?->email ?? '—' }}</div>
                            <div class="col-12"><strong>Địa chỉ liên hệ:</strong> {{ $formattedProfile['contact_address'] ?? '—' }}</div>
                            <div class="col-12"><strong>Địa chỉ thường trú:</strong> {{ $formattedProfile['permanent_address'] ?? '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header fw-semibold">2. CCCD / KYC</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><strong>Số CCCD:</strong> {{ $profile->id_number }}</div>
                            <div class="col-md-6"><strong>Ngày nộp:</strong> {{ !empty($formattedProfile['submitted_at']) ? \Carbon\Carbon::parse($formattedProfile['submitted_at'])->format('d/m/Y H:i') : '—' }}</div>
                            <div class="col-md-4">
                                <a href="{{ $profile->id_front_image }}" target="_blank" rel="noopener">Ảnh CCCD mặt trước</a>
                            </div>
                            <div class="col-md-4">
                                <a href="{{ $profile->id_back_image }}" target="_blank" rel="noopener">Ảnh CCCD mặt sau</a>
                            </div>
                            <div class="col-md-4">
                                <a href="{{ $profile->id_selfie_image }}" target="_blank" rel="noopener">Ảnh chân dung / selfie</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header fw-semibold">3. Thuế và ngân hàng</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6"><strong>MST cá nhân:</strong> {{ $profile->personal_tax_code }}</div>
                            <div class="col-md-6"><strong>Mã Affiliate:</strong> {{ $formattedProfile['affiliate_code'] ?? '—' }}</div>
                            <div class="col-md-6"><strong>Ngân hàng:</strong> {{ $bank?->bank_name ?? $bank?->bank?->name ?? '—' }}</div>
                            <div class="col-md-6"><strong>Số tài khoản:</strong> {{ $bank?->account_number ?? '—' }}</div>
                            <div class="col-md-6"><strong>Chủ tài khoản:</strong> {{ $bank?->account_name ?? '—' }}</div>
                            <div class="col-md-6"><strong>Link Affiliate:</strong> {{ $formattedProfile['affiliate_link'] ?? '—' }}</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">4. Cam kết và mạng xã hội</div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>Điều khoản / cam kết:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Đã đồng ý điều khoản: {{ !empty($formattedProfile['accepted_policy']) ? 'Có' : 'Không' }}</li>
                                <li>Cam kết không tạo đơn ảo: {{ !empty($formattedProfile['accepted_no_fake_order_commitment']) ? 'Có' : 'Không' }}</li>
                                <li>Cam kết thông tin thuế / ngân hàng chính xác: {{ !empty($formattedProfile['accepted_tax_bank_accuracy_commitment']) ? 'Có' : 'Không' }}</li>
                                <li>Cam kết truyền thông đúng quy định: {{ !empty($formattedProfile['accepted_communication_commitment']) ? 'Có' : 'Không' }}</li>
                                <li>Phiên bản điều khoản: {{ $formattedProfile['policy_version'] ?? '—' }}</li>
                            </ul>
                        </div>
                        <div>
                            <strong>Link mạng xã hội:</strong>
                            @if (is_array($formattedProfile['social_media_links'] ?? null) && count($formattedProfile['social_media_links']) > 0)
                                <ul class="mb-0 mt-2 ps-3">
                                    @foreach ($formattedProfile['social_media_links'] as $link)
                                        <li><a href="{{ $link }}" target="_blank" rel="noopener">{{ $link }}</a></li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="text-muted mt-2">Không có</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header fw-semibold">Điều kiện duyệt hồ sơ</div>
                    <div class="card-body">
                        @if ($approvalChecklist['can_approve'])
                            <div class="alert alert-success mb-3">Hồ sơ hiện đủ điều kiện để duyệt.</div>
                        @else
                            <div class="alert alert-warning mb-3">Chưa thể duyệt hồ sơ. Các lý do:</div>
                            <ul class="ps-3 mb-3">
                                @foreach ($approvalChecklist['reasons'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="d-grid gap-2">
                            @if ($canDeleteProfile)
                                <form method="POST" action="{{ route('admin.affiliate.requests.destroy', $profile->id) }}" onsubmit="return confirm('Bạn có chắc muốn hủy và xóa hồ sơ Affiliate này? Thao tác này không thể hoàn tác.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger w-100">Hủy hồ sơ</button>
                                </form>
                            @endif

                            @if ($canStartReview)
                                <form method="POST" action="{{ route('admin.affiliate.requests.start-review', $profile->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-primary w-100">Nhận kiểm tra</button>
                                </form>
                            @endif

                            @if ($canProcessReview)
                                <form method="POST" action="{{ route('admin.affiliate.requests.approve', $profile->id) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-success w-100" @disabled(!$approvalChecklist['can_approve'])>Duyệt hồ sơ</button>
                                </form>

                                @if ($profile->status === 'under_review')
                                    <form method="POST" action="{{ route('admin.affiliate.requests.request-more-info', $profile->id) }}">
                                        @csrf
                                        <textarea name="need_more_info_reason" class="form-control mb-2" rows="3" placeholder="Lý do cần bổ sung" required></textarea>
                                        <button type="submit" class="btn btn-outline-warning w-100">Yêu cầu bổ sung</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.affiliate.requests.reject', $profile->id) }}">
                                    @csrf
                                    <textarea name="rejection_reason" class="form-control mb-2" rows="3" placeholder="Lý do từ chối" required></textarea>
                                    <button type="submit" class="btn btn-outline-danger w-100">Từ chối hồ sơ</button>
                                </form>
                            @endif

                            @if ($profile->status === 'approved')
                                <form method="POST" action="{{ route('admin.affiliate.requests.suspend', $profile->id) }}">
                                    @csrf
                                    <textarea name="suspended_reason" class="form-control mb-2" rows="3" placeholder="Lý do tạm khóa" required></textarea>
                                    <button type="submit" class="btn btn-outline-warning w-100">Tạm khóa</button>
                                </form>
                            @endif

                            @if (in_array($profile->status, ['approved', 'suspended', 'rejected']))
                                <form method="POST" action="{{ route('admin.affiliate.requests.lock', $profile->id) }}">
                                    @csrf
                                    <textarea name="suspended_reason" class="form-control mb-2" rows="3" placeholder="Lý do khóa" required></textarea>
                                    <button type="submit" class="btn btn-outline-danger w-100">Khóa hồ sơ</button>
                                </form>
                            @endif

                            @if ($profile->status === 'locked')
                                <form method="POST" action="{{ route('admin.affiliate.requests.unlock', $profile->id) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-success w-100">Mở khóa</button>
                                </form>
                            @endif

                            @if (in_array($profile->status, ['approved', 'suspended', 'locked']))
                                <form method="POST" action="{{ route('admin.affiliate.requests.terminate', $profile->id) }}">
                                    @csrf
                                    <textarea name="terminated_reason" class="form-control mb-2" rows="3" placeholder="Lý do chấm dứt" required></textarea>
                                    <button type="submit" class="btn btn-dark w-100">Chấm dứt Affiliate</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-semibold">Lịch sử trạng thái</div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li><strong>Trạng thái hiện tại:</strong> {{ $statusLabel }}</li>
                            <li><strong>Bắt đầu review:</strong> {{ !empty($formattedProfile['review_started_at']) ? \Carbon\Carbon::parse($formattedProfile['review_started_at'])->format('d/m/Y H:i') : '—' }}</li>
                            <li><strong>Duyệt lúc:</strong> {{ !empty($formattedProfile['approved_at']) ? \Carbon\Carbon::parse($formattedProfile['approved_at'])->format('d/m/Y H:i') : '—' }}</li>
                            <li><strong>Từ chối lúc:</strong> {{ !empty($formattedProfile['rejected_at']) ? \Carbon\Carbon::parse($formattedProfile['rejected_at'])->format('d/m/Y H:i') : '—' }}</li>
                            @if (!empty($formattedProfile['need_more_info_reason']))
                                <li class="mt-2"><strong>Lý do cần bổ sung:</strong> {{ $formattedProfile['need_more_info_reason'] }}</li>
                            @endif
                            @if ($profile->rejection_reason)
                                <li class="mt-2"><strong>Lý do từ chối:</strong> {{ $profile->rejection_reason }}</li>
                            @endif
                            @if (!empty($formattedProfile['suspended_reason']))
                                <li class="mt-2"><strong>Lý do tạm khóa / khóa:</strong> {{ $formattedProfile['suspended_reason'] }}</li>
                            @endif
                            @if (!empty($formattedProfile['terminated_reason']))
                                <li class="mt-2"><strong>Lý do chấm dứt:</strong> {{ $formattedProfile['terminated_reason'] }}</li>
                            @endif
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
