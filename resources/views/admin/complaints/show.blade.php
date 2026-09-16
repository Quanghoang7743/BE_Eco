@extends('admin.layout')

@section('styles')
<style>
    /* Premium details page design */
    .info-card, .assign-card, .decision-card, .log-card, .chat-card {
        border-radius: 20px;
        border: 1px solid rgba(226, 232, 240, 0.8);
        box-shadow: 0 10px 30px rgba(0,0,0,0.03);
        background: #fff;
        overflow: hidden;
        margin-bottom: 24px;
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }
    .info-card:hover, .assign-card:hover, .decision-card:hover, .log-card:hover, .chat-card:hover {
        box-shadow: 0 12px 35px rgba(0,0,0,0.06);
        transform: translateY(-2px);
    }
    .card-header-premium {
        border-bottom: 1px solid #f1f5f9;
        font-weight: 700;
        color: #1e293b;
        padding: 20px 24px;
        font-size: 1.05rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .card-header-premium i {
        font-size: 1.25rem;
    }
    .card-body-premium {
        padding: 24px;
    }

    /* Page Header */
    .page-header-premium {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        color: #ffffff;
        border-radius: 20px;
        padding: 24px 30px;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.05);
    }
    .page-header-premium h2 {
        color: #ffffff;
        font-weight: 800;
    }
    .badge-code {
        background: rgba(255, 255, 255, 0.15);
        color: #ffffff;
        padding: 6px 14px;
        border-radius: 9999px;
        font-size: 0.85rem;
        font-weight: 700;
        border: 1px solid rgba(255, 255, 255, 0.1);
        backdrop-filter: blur(4px);
    }
    .btn-back {
        background: rgba(255, 255, 255, 0.1);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 9999px;
        padding: 10px 22px;
        font-weight: 600;
        font-size: 0.85rem;
        transition: all 0.2s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .btn-back:hover {
        background: #ffffff;
        color: #0f172a;
        border-color: #ffffff;
        transform: translateX(-2px);
    }
    .bg-primary-soft {
        background-color: rgba(59, 130, 246, 0.15);
        border: 1px solid rgba(59, 130, 246, 0.2);
    }
    .header-icon-circle {
        width: 46px;
        height: 46px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .header-icon-circle i {
        font-size: 1.3rem;
    }

    /* Grid layout for Complaint details info */
    .info-grid-premium {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    .info-section-box {
        background: #f8fafc;
        border-radius: 14px;
        padding: 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.01);
    }
    .info-section-title {
        font-size: 0.8rem;
        font-weight: 800;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 14px;
        padding-bottom: 8px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .info-section-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        font-size: 0.85rem;
    }
    .info-section-row:last-child {
        margin-bottom: 0;
    }
    .info-section-label {
        color: #64748b;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .info-section-value {
        color: #1e293b;
        font-weight: 700;
        text-align: right;
        max-width: 60%;
        word-wrap: break-word;
    }

    .description-box {
        background: #f1f5f9;
        border-left: 4px solid #64748b;
        border-radius: 8px;
        padding: 16px;
        margin-top: 20px;
    }
    .resolution-box {
        background: #ecfdf5;
        border-left: 4px solid #10b981;
        border-radius: 8px;
        padding: 16px;
        margin-top: 16px;
    }
    .info-block-title {
        font-weight: 700;
        color: #475569;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .info-block-content {
        color: #334155;
        font-size: 0.9rem;
        line-height: 1.5;
        white-space: pre-wrap;
    }
    
    /* Badges */
    .badge-custom {
        padding: 6px 12px;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.02em;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    /* Severity */
    .badge-sev-low { background-color: #f1f5f9; color: #475569; }
    .badge-sev-normal { background-color: #e0f2fe; color: #0369a1; }
    .badge-sev-high { background-color: #ffedd5; color: #c2410c; }
    .badge-sev-critical { background-color: #fee2e2; color: #b91c1c; }

    /* Status */
    .badge-status-new, .badge-status-pending { background-color: #ecfdf5; color: #047857; }
    .badge-status-processing { background-color: #eff6ff; color: #1d4ed8; }
    .badge-status-awaiting_store_action { background-color: #fef3c7; color: #b45309; }
    .badge-status-refund_processing { background-color: #faf5ff; color: #7e22ce; }
    .badge-status-resolved, .badge-status-closed { background-color: #d1fae5; color: #065f46; }
    .badge-status-cancelled { background-color: #f3f4f6; color: #374151; }

    /* Form stylings */
    .form-group-custom {
        margin-bottom: 18px;
    }
    .form-group-custom label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 8px;
        display: block;
    }
    .form-control-custom {
        border-radius: 12px;
        border: 1px solid #cbd5e1;
        padding: 11px 14px;
        font-size: 0.9rem;
        transition: all 0.2s ease;
    }
    .form-control-custom:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        outline: 0;
    }

    /* Assign card theme border */
    .assign-card {
        border-left: 4px solid #3b82f6;
    }
    .assign-card .card-header-premium {
        background: linear-gradient(90deg, #f8fafc 0%, #eff6ff 100%);
        color: #1e3a8a;
    }

    /* Decision card theme border */
    .decision-card {
        border-left: 4px solid #f59e0b;
    }
    .decision-card .card-header-premium {
        background: linear-gradient(90deg, #fffdf5 0%, #fffbeb 100%);
        color: #b45309;
        border-bottom-color: #fef3c7;
    }

    /* Decision buttons */
    .btn-decision-premium {
        padding: 12px 20px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.85rem;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        width: 100%;
        border: 1px solid transparent;
        box-shadow: 0 2px 6px rgba(0,0,0,0.02);
    }
    .btn-decision-premium:hover {
        transform: translateY(-1.5px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .btn-decision-premium:active {
        transform: translateY(0);
    }
    .btn-decision-premium-primary {
        background-color: #eff6ff;
        color: #1d4ed8;
        border-color: #bfdbfe;
    }
    .btn-decision-premium-primary:hover {
        background-color: #1d4ed8;
        color: #ffffff;
        border-color: #1d4ed8;
    }
    .btn-decision-premium-danger {
        background-color: #fef2f2;
        color: #dc2626;
        border-color: #fecaca;
    }
    .btn-decision-premium-danger:hover {
        background-color: #dc2626;
        color: #ffffff;
        border-color: #dc2626;
    }
    .btn-decision-premium-warning {
        background-color: #fffbeb;
        color: #d97706;
        border-color: #fef3c7;
    }
    .btn-decision-premium-warning:hover {
        background-color: #d97706;
        color: #ffffff;
        border-color: #d97706;
    }
    .btn-decision-premium-success {
        background-color: #f0fdf4;
        color: #16a34a;
        border-color: #bbf7d0;
    }
    .btn-decision-premium-success:hover {
        background-color: #16a34a;
        color: #ffffff;
        border-color: #16a34a;
    }

    /* Financial Receipt Preview widget */
    .funding-preview-card-premium {
        background: #fffdf5;
        border: 1px dashed #f59e0b;
        border-radius: 14px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 10px rgba(245, 158, 11, 0.03);
    }
    .funding-preview-title-premium {
        font-weight: 800;
        color: #b45309;
        font-size: 0.8rem;
        margin-bottom: 12px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .funding-row-premium {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        font-size: 0.85rem;
    }
    .funding-row-premium:last-child {
        margin-bottom: 0;
        border-top: 1px solid rgba(217, 119, 6, 0.2);
        padding-top: 10px;
        margin-top: 10px;
    }
    .funding-label-premium {
        color: #64748b;
        font-weight: 500;
    }
    .funding-value-premium {
        font-weight: 700;
        color: #1e293b;
    }
    .funding-value-premium.highlight {
        color: #dc2626;
        font-size: 1.05rem;
        font-weight: 800;
    }

    /* Timeline style */
    .timeline-container-premium {
        position: relative;
        padding-left: 28px;
        margin-left: 12px;
    }
    .timeline-container-premium::before {
        content: '';
        position: absolute;
        left: 0;
        top: 8px;
        bottom: 8px;
        width: 2px;
        background: #e2e8f0;
    }
    .timeline-item-premium {
        position: relative;
        margin-bottom: 20px;
    }
    .timeline-item-premium:last-child {
        margin-bottom: 0;
    }
    .timeline-dot-premium {
        position: absolute;
        left: -35px;
        top: 4px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background-color: #cbd5e1;
        border: 3px solid #fff;
        box-shadow: 0 0 0 2px #e2e8f0;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .timeline-item-premium:hover .timeline-dot-premium {
        transform: scale(1.15);
    }
    .timeline-dot-premium.active {
        background-color: #3b82f6;
        box-shadow: 0 0 0 2px #93c5fd;
    }
    .timeline-dot-premium.terminal {
        background-color: #10b981;
        box-shadow: 0 0 0 2px #a7f3d0;
    }
    .timeline-content-box-premium {
        background: #f8fafc;
        border-radius: 14px;
        padding: 18px;
        border: 1px solid #f1f5f9;
        transition: all 0.2s ease;
    }
    .timeline-content-box-premium:hover {
        background: #ffffff;
        border-color: #e2e8f0;
        box-shadow: 0 4px 12px rgba(0,0,0,0.02);
    }
    .timeline-header-premium {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .timeline-title-premium {
        font-weight: 700;
        color: #1e293b;
        font-size: 0.95rem;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .timeline-badge-premium {
        font-size: 0.75rem;
        background: #e2e8f0;
        color: #475569;
        padding: 4px 10px;
        border-radius: 9999px;
        font-weight: 600;
        border: 1px solid #cbd5e1;
    }
    .timeline-meta-premium {
        font-size: 0.75rem;
        color: #64748b;
        border-top: 1px solid #f1f5f9;
        padding-top: 8px;
        margin-top: 8px;
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 6px;
    }

    /* Chat bubble style */
    .chat-stream {
        display: flex;
        flex-direction: column;
        gap: 16px;
        max-height: 550px;
        overflow-y: auto;
        padding: 12px;
        background: #fafafa;
        border-radius: 12px;
        border: 1px solid #f1f5f9;
    }
    .chat-item-wrapper {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        margin-bottom: 8px;
    }
    .chat-item-wrapper.admin-side {
        flex-direction: row-reverse;
    }
    .chat-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.85rem;
        flex-shrink: 0;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .chat-avatar.customer-avatar {
        background-color: #eff6ff;
        color: #2563eb;
        border: 1px solid #dbeafe;
    }
    .chat-avatar.admin-avatar {
        background-color: #ecfdf5;
        color: #059669;
        border: 1px solid #d1ebd9;
    }
    .chat-bubble-container {
        max-width: 75%;
        display: flex;
        flex-direction: column;
    }
    .chat-item-wrapper.admin-side .chat-bubble-container {
        align-items: flex-end;
    }
    .chat-meta-new {
        font-size: 0.75rem;
        color: #94a3b8;
        margin-bottom: 4px;
        font-weight: 500;
    }
    .chat-bubble-new {
        padding: 12px 18px;
        border-radius: 18px;
        font-size: 0.9rem;
        line-height: 1.5;
        box-shadow: 0 2px 8px rgba(0,0,0,0.02);
        word-break: break-word;
    }
    .chat-item-wrapper.customer-side .chat-bubble-new {
        background-color: #ffffff;
        color: #334155;
        border-top-left-radius: 4px;
        border: 1px solid #e2e8f0;
    }
    .chat-item-wrapper.admin-side .chat-bubble-new {
        background-color: #eff6ff;
        color: #1e40af;
        border-top-right-radius: 4px;
        border: 1px solid #dbeafe;
    }
    .chat-attachments-list {
        margin-top: 10px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }
    .chat-attachment-img {
        width: 80px;
        height: 80px;
        border-radius: 10px;
        object-fit: cover;
        border: 1px solid #cbd5e1;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .chat-attachment-img:hover {
        transform: scale(1.05);
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .chat-attachment-file {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.8rem;
        color: #475569;
        text-decoration: none;
        transition: all 0.2s ease;
    }
    .chat-attachment-file:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        color: #1e293b;
    }

    .info-card .card-header-premium {
        background: linear-gradient(90deg, #f8fafc 0%, #f1f5f9 100%);
        border-left: 4px solid #3b82f6;
    }
    .log-card .card-header-premium {
        background: linear-gradient(90deg, #f8fafc 0%, #f1f5f9 100%);
        border-left: 4px solid #64748b;
    }
    .chat-card .card-header-premium {
        background: linear-gradient(90deg, #f8fafc 0%, #f1f5f9 100%);
        border-left: 4px solid #10b981;
    }
</style>
@endsection

@section('content')
    <div class="container-fluid">
        @php
            $severityLabels = [
                'low' => 'Thấp',
                'normal' => 'Bình thường',
                'high' => 'Cao',
                'critical' => 'Khẩn cấp',
            ];
            $statusLabels = [
                'new' => 'Mới tiếp nhận',
                'pending' => 'Mới tiếp nhận',
                'processing' => 'Đang xử lý',
                'awaiting_store_action' => 'Chờ cửa hàng xác nhận',
                'refund_processing' => 'Đang hoàn tiền',
                'resolved' => 'Đã giải quyết',
                'closed' => 'Đã đóng',
                'cancelled' => 'Khách hàng đã hủy',
            ];
            $isTerminal = in_array($complaint->status, ['closed', 'cancelled'], true);
            $customerPhone = $complaint->order?->delivery_phone ?: $complaint->order?->phone ?: $complaint->user?->phone;
            $deliveryAddress = $complaint->order?->delivery_address ?: $complaint->order?->address;
            $hasRejectedReturn = \App\Models\OrderReturn::where('order_id', $complaint->order_id)
                ->where('status', 'rejected')
                ->exists();
        @endphp

        <!-- Page Header -->
        <div class="page-header-premium mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="header-icon-circle bg-primary-soft">
                        <i class="fas fa-clipboard-list text-primary"></i>
                    </div>
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h2 class="mb-0 fw-bold" style="font-size: 1.4rem; letter-spacing: -0.02em;">Chi tiết khiếu nại</h2>
                            <span class="badge-code">{{ $complaint->complaint_code }}</span>
                        </div>
                        <p class="text-white-50 mb-0 mt-1 small"><i class="far fa-clock me-1"></i>Tạo lúc: {{ $complaint->created_at->format('Y-m-d H:i:s') }}</p>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="{{ route('admin.complaints.index') }}" class="btn btn-back">
                        <i class="fas fa-arrow-left me-1"></i> Quay lại danh sách
                    </a>
                </div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success border-0 shadow-sm mb-4 rounded-12"><i class="fas fa-check-circle me-1"></i> {{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger border-0 shadow-sm mb-4 rounded-12">
                @foreach ($errors->all() as $error)
                    <div><i class="fas fa-exclamation-circle me-1"></i> {{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="row g-4">
            <!-- LEFT COLUMN (col-lg-8): Detail Information, Chat stream, Action logs -->
            <div class="col-lg-8">
                
                <!-- THÔNG TIN KHIẾU NẠI CARD -->
                <div class="info-card">
                    <div class="card-header-premium">
                        <i class="fas fa-info-circle text-primary"></i>
                        <span>Thông tin khiếu nại</span>
                    </div>
                    <div class="card-body-premium">
                        <div class="info-grid-premium">
                            
                            <!-- Box 1: General Info -->
                            <div class="info-section-box">
                                <div class="info-section-title">
                                    <i class="fas fa-sliders-h text-primary"></i> Thông tin chung
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Tiêu đề:</span>
                                    <span class="info-section-value">{{ $complaint->title ?? 'N/A' }}</span>
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Mức độ:</span>
                                    <span class="info-section-value">
                                        <span class="badge-custom badge-sev-{{ $complaint->severity }}">
                                            {{ $severityLabels[$complaint->severity] ?? $complaint->severity }}
                                        </span>
                                    </span>
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Trạng thái:</span>
                                    <span class="info-section-value">
                                        <span class="badge-custom badge-status-{{ $complaint->status }}">
                                            {{ $statusLabels[$complaint->status] ?? $complaint->status }}
                                        </span>
                                    </span>
                                </div>
                            </div>

                            <!-- Box 2: Customer Info -->
                            <div class="info-section-box">
                                <div class="info-section-title">
                                    <i class="fas fa-user text-primary"></i> Khách hàng
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Họ tên:</span>
                                    <span class="info-section-value">{{ $complaint->user?->full_name ?: $complaint->user?->name ?: $complaint->user_id ?: 'N/A' }}</span>
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Số điện thoại:</span>
                                    <span class="info-section-value">{{ $customerPhone ?: 'N/A' }}</span>
                                </div>
                                @if ($deliveryAddress && $deliveryAddress !== 'N/A')
                                <div class="info-section-row">
                                    <span class="info-section-label">Địa chỉ đơn:</span>
                                    <span class="info-section-value">{{ $deliveryAddress }}</span>
                                </div>
                                @endif
                            </div>

                            <!-- Box 3: Order & Store Info -->
                            <div class="info-section-box">
                                <div class="info-section-title">
                                    <i class="fas fa-store text-primary"></i> Cửa hàng & Đơn hàng
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Đơn hàng:</span>
                                    <span class="info-section-value">
                                        @if ($complaint->order?->order_code)
                                            <span class="text-primary font-bold">{{ $complaint->order->order_code }}</span>
                                        @else
                                            {{ $complaint->order_id ?: 'N/A' }}
                                        @endif
                                    </span>
                                </div>
                                @if ($complaint->store_id)
                                <div class="info-section-row">
                                    <span class="info-section-label">Mã cửa hàng:</span>
                                    <span class="info-section-value">{{ $complaint->store_id }}</span>
                                </div>
                                @endif
                                @if ($complaint->store)
                                <div class="info-section-row">
                                    <span class="info-section-label">Cửa hàng:</span>
                                    <span class="info-section-value">
                                        {{ $complaint->store->store_code }} - {{ $complaint->store->name }}
                                    </span>
                                </div>
                                @endif
                            </div>

                            <!-- Box 4: Financial & Resolution Info -->
                            <div class="info-section-box">
                                <div class="info-section-title">
                                    <i class="fas fa-receipt text-primary"></i> Hoàn tiền & Kết quả
                                </div>
                                <div class="info-section-row">
                                    <span class="info-section-label">Yêu cầu hoàn Ticket:</span>
                                    <span class="info-section-value">{{ $complaint->refund_requested ? 'Có' : 'Không' }}</span>
                                </div>
                                @if ($complaint->refund?->refund_code || $complaint->refund_id)
                                <div class="info-section-row">
                                    <span class="info-section-label">Mã hoàn Ticket:</span>
                                    <span class="info-section-value">{{ $complaint->refund?->refund_code ?? $complaint->refund_id }}</span>
                                </div>
                                @endif
                                @if ($complaint->resolution_type)
                                <div class="info-section-row">
                                    <span class="info-section-label">Loại kết quả:</span>
                                    <span class="info-section-value">{{ $complaint->resolution_type }}</span>
                                </div>
                                @endif
                                @if ($complaint->resolved_at)
                                <div class="info-section-row">
                                    <span class="info-section-label">Thời điểm giải quyết:</span>
                                    <span class="info-section-value">{{ $complaint->resolved_at->format('Y-m-d H:i:s') }}</span>
                                </div>
                                @endif
                            </div>

                        </div>

                        <!-- Customer Description Quote block -->
                        <div class="description-box">
                            <div class="info-block-title">
                                <i class="fas fa-comment-alt text-muted"></i> Mô tả từ khách hàng
                            </div>
                            <div class="info-block-content mt-1">{{ $complaint->description ?? 'Không có mô tả.' }}</div>
                        </div>

                        <!-- Resolution internal notes block -->
                        @if ($complaint->resolution_note)
                        <div class="resolution-box">
                            <div class="info-block-title" style="color: #065f46;">
                                <i class="fas fa-check-circle text-success"></i> Hướng xử lý / Kết quả giải quyết
                            </div>
                            <div class="info-block-content mt-1" style="color: #0f5132;">{{ $complaint->resolution_note }}</div>
                        </div>
                        @endif
                    </div>
                </div>

                <!-- LUỒNG TRAO ĐỔI (CHAT) -->
                <div class="chat-card">
                    <div class="card-header-premium">
                        <i class="fas fa-comments text-primary"></i>
                        <span>Luồng trao đổi (complaint_messages)</span>
                    </div>
                    <div class="card-body-premium">
                        @if ($complaint->messages->isNotEmpty())
                            <div class="chat-stream">
                                @foreach($complaint->messages as $msg)
                                    @php
                                        $isFromAdmin = (bool) $msg->is_from_admin;
                                        $senderName = $isFromAdmin ? 'Admin / Sale' : 'Khách hàng';
                                    @endphp
                                    <div class="chat-item-wrapper {{ $isFromAdmin ? 'admin-side' : 'customer-side' }}">
                                        <div class="chat-avatar {{ $isFromAdmin ? 'admin-avatar' : 'customer-avatar' }}">
                                            {{ $isFromAdmin ? 'A' : 'K' }}
                                        </div>
                                        <div class="chat-bubble-container">
                                            <div class="chat-meta-new">
                                                <strong>{{ $senderName }}</strong> (ID: {{ $msg->sender_id ?? 'N/A' }}) • {{ $msg->created_at?->format('Y-m-d H:i:s') }}
                                            </div>
                                            <div class="chat-bubble-new shadow-sm">
                                                {{ $msg->message ?? '' }}

                                                @if (!empty($msg->attachments))
                                                    @php
                                                        $attachments = is_string($msg->attachments) ? json_decode($msg->attachments, true) : $msg->attachments;
                                                    @endphp
                                                    @if(is_array($attachments))
                                                        <div class="chat-attachments-list">
                                                            @foreach($attachments as $file)
                                                                @if(is_string($file) && preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $file))
                                                                    <a href="{{ asset('storage/' . $file) }}" target="_blank">
                                                                        <img src="{{ asset('storage/' . $file) }}" alt="attachment" class="chat-attachment-img">
                                                                    </a>
                                                                @else
                                                                    <a href="{{ asset('storage/' . (is_string($file) ? $file : '')) }}" target="_blank" class="chat-attachment-file">
                                                                        <i class="fas fa-file-alt"></i>
                                                                        <span>{{ basename(is_string($file) ? $file : 'file') }}</span>
                                                                    </a>
                                                                @endif
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <pre class="chat-attachments mt-2 mb-0">{{ json_encode($msg->attachments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-5 text-muted">
                                <i class="far fa-comments fa-3x mb-3 opacity-50"></i>
                                <p class="mb-0">Chưa có tin nhắn trao đổi nào.</p>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- NHẬT KÝ XỬ LÝ (TIMELINE) -->
                <div class="log-card">
                    <div class="card-header-premium">
                        <i class="fas fa-history text-primary"></i>
                        <span>Nhật ký xử lý</span>
                    </div>
                    <div class="card-body-premium">
                        @if ($actionLogs->isNotEmpty())
                            <div class="timeline-container-premium">
                                @foreach($actionLogs as $index => $log)
                                    @php
                                        $isLast = $index === count($actionLogs) - 1;
                                        $isTerminalLog = in_array($log->to_status, ['closed', 'cancelled'], true);
                                    @endphp
                                    <div class="timeline-item-premium">
                                        <div class="timeline-dot-premium {{ $isLast ? ($isTerminalLog ? 'terminal' : 'active') : '' }}">
                                            @if($isLast)
                                                @if($isTerminalLog)
                                                    <i class="fas fa-check text-white" style="font-size: 8px;"></i>
                                                @else
                                                    <i class="fas fa-spinner text-white fa-spin" style="font-size: 8px;"></i>
                                                @endif
                                            @else
                                                <i class="fas fa-circle text-muted" style="font-size: 5px;"></i>
                                            @endif
                                        </div>
                                        <div class="timeline-content-box-premium">
                                            <div class="timeline-header-premium">
                                                <div class="timeline-title-premium">
                                                    <i class="fas fa-cog text-muted me-1"></i> {{ $log->action }}
                                                </div>
                                                <span class="timeline-badge-premium">
                                                    {{ $statusLabels[$log->from_status] ?? $log->from_status }} <i class="fas fa-long-arrow-alt-right mx-1"></i> {{ $statusLabels[$log->to_status] ?? $log->to_status }}
                                                </span>
                                            </div>
                                            @if(!empty($log->metadata))
                                                @php
                                                    $meta = json_decode($log->metadata, true);
                                                @endphp
                                                @if(is_array($meta))
                                                    @if(isset($meta['reason']) && !empty($meta['reason']))
                                                        <div class="text-dark small my-1">
                                                            <strong>Lý do:</strong> {{ $meta['reason'] }}
                                                        </div>
                                                    @endif
                                                    @if(isset($meta['released_ticket_count']))
                                                        <div class="text-success small my-1">
                                                            <strong>Vé giải phóng:</strong> {{ $meta['released_ticket_count'] }}
                                                        </div>
                                                    @endif
                                                @endif
                                            @endif
                                            <div class="timeline-meta-premium mt-2">
                                                <div>
                                                    <i class="fas fa-user-circle me-1"></i> Người thực hiện: <strong>{{ $log->actor_role ?? 'Hệ thống' }} (ID: {{ $log->actor_id ?? 'system' }})</strong>
                                                </div>
                                                <div>
                                                    <i class="far fa-clock me-1"></i> {{ $log->created_at }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-history fa-2x mb-2 opacity-50"></i>
                                <p class="mb-0">Chưa có thao tác xử lý nào được ghi lại.</p>
                            </div>
                        @endif
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN (col-lg-4): Assignment & Super Admin Decisions (Sidebar actions) -->
            <div class="col-lg-4">
                
                <!-- PHÂN CÔNG XỬ LÝ CARD -->
                <div class="assign-card">
                    <div class="card-header-premium">
                        <i class="fas fa-tasks text-primary"></i>
                        <span>Phân công xử lý</span>
                    </div>
                    <div class="card-body-premium">
                        <form method="POST" action="{{ route('admin.complaints.assign', $complaint->id) }}">
                            @csrf
                            @method('PATCH')

                            <div class="form-group-custom">
                                <label><i class="fas fa-user-tag me-1"></i> Chuyên viên phụ trách (Sale)</label>
                                <select name="assigned_to" class="form-control form-control-custom">
                                    <option value="">-- Chưa phân công --</option>
                                    @foreach ($assigneeOptions as $opt)
                                        <option value="{{ $opt['value'] }}" {{ (string) $complaint->assigned_to === (string) $opt['value'] ? 'selected' : '' }}>
                                            {{ $opt['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="form-group-custom">
                                <label><i class="fas fa-info-circle me-1"></i> Trạng thái</label>
                                <select name="status" class="form-control form-control-custom">
                                    <option value="new" {{ $complaint->status === 'new' ? 'selected' : '' }}>Mới tiếp nhận</option>
                                    <option value="pending" {{ $complaint->status === 'pending' ? 'selected' : '' }}>Mới tiếp nhận (cũ)</option>
                                    <option value="processing" {{ $complaint->status === 'processing' ? 'selected' : '' }}>Đang xử lý</option>
                                </select>
                            </div>

                            <div class="form-group-custom">
                                <label><i class="fas fa-sticky-note me-1"></i> Ghi chú xử lý</label>
                                <textarea name="resolution_note" rows="4" class="form-control form-control-custom" placeholder="Nhập ghi chú hoặc kết quả xử lý...">{{ old('resolution_note', $complaint->resolution_note) }}</textarea>
                            </div>

                            @if (\App\Support\AdminCmsAccess::isSuperAdmin() && !$isTerminal)
                                <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 shadow-sm font-semibold">
                                    <i class="fas fa-save me-1"></i> Cập nhật phân công
                                </button>
                            @endif
                        </form>
                    </div>
                </div>

                <!-- QUYẾT ĐỊNH CỦA SUPER ADMIN -->
                @if (\App\Support\AdminCmsAccess::isSuperAdmin() && !$isTerminal)
                    <div class="decision-card">
                        <div class="card-header-premium">
                            <i class="fas fa-gavel text-warning"></i>
                            <span>Quyết định xử lý của Super Admin</span>
                        </div>
                        <div class="card-body-premium">
                            @if ($hasRejectedReturn)
                                <div class="funding-preview-card-premium">
                                    <div class="funding-preview-title-premium">
                                        <i class="fas fa-calculator me-1"></i> Xem trước tài chính hoàn tiền
                                    </div>
                                    <div class="funding-row-premium">
                                        <span class="funding-label-premium">Dự kiến hoàn:</span>
                                        <span class="funding-value-premium highlight">{{ number_format($fundingPreview['refund_amount'] ?? 0) }} đ</span>
                                    </div>
                                    <div class="funding-row-premium">
                                        <span class="funding-label-premium">Trừ ví Store:</span>
                                        <span class="funding-value-premium">{{ number_format($fundingPreview['wallet_debit'] ?? 0) }} đ</span>
                                    </div>
                                    <div class="funding-row-premium">
                                        <span class="funding-label-premium">Bù đối soát chờ:</span>
                                        <span class="funding-value-premium">{{ number_format($fundingPreview['pending_settlement_offset'] ?? 0) }} đ</span>
                                    </div>
                                    <div class="funding-row-premium">
                                        <span class="funding-label-premium">Platform advance:</span>
                                        <span class="funding-value-premium">{{ number_format($fundingPreview['platform_advance'] ?? 0) }} đ</span>
                                    </div>
                                    @if (($fundingPreview['platform_advance'] ?? 0) > 0)
                                        <div class="alert alert-warning border-0 mt-3 p-2 small mb-0 rounded-8">
                                            <i class="fas fa-exclamation-circle me-1 text-warning"></i>
                                            <strong>Cảnh báo:</strong> Hoàn tiền sẽ phát sinh công nợ và Store tiếp tục bị khóa.
                                        </div>
                                    @endif
                                </div>

                                <div class="d-flex flex-column gap-3">
                                    <form method="POST" action="{{ route('admin.complaints.create-return', $complaint->id) }}">
                                        @csrf
                                        <button type="submit" class="btn-decision-premium btn-decision-premium-primary">
                                            <i class="fas fa-file-invoice"></i> 1. Tạo đơn hoàn gửi Store
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.complaints.reject', $complaint->id) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <label class="form-label font-bold text-muted small"><i class="fas fa-pen me-1"></i> Lý do từ chối</label>
                                            <textarea name="reason" class="form-control form-control-custom" rows="2" required minlength="5" placeholder="Nhập lý do từ chối khiếu nại...">{{ old('reason') }}</textarea>
                                        </div>
                                        <button type="submit" class="btn-decision-premium btn-decision-premium-danger">
                                            <i class="fas fa-times-circle"></i> 2. Từ chối & giải phóng vé
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.complaints.force-action', $complaint->id) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <label class="form-label font-bold text-muted small"><i class="fas fa-ban me-1"></i> Lý do cưỡng chế/khóa Store</label>
                                            <textarea name="reason" class="form-control form-control-custom" rows="2" required minlength="5" placeholder="Nhập lý do cưỡng chế...">{{ old('reason') }}</textarea>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <button type="submit" name="action" value="lock_store" class="btn-decision-premium btn-decision-premium-warning h-100">
                                                    <i class="fas fa-lock"></i> 3a. Khóa Store
                                                </button>
                                            </div>
                                            <div class="col-6">
                                                <button type="submit" name="action" value="force_refund" class="btn-decision-premium btn-decision-premium-danger h-100" onclick="return confirm('Xác nhận hoàn tiền ngay? Thao tác tài chính này không thể hoàn tác.')">
                                                    <i class="fas fa-money-bill-alt"></i> 3b. Buộc hoàn
                                                </button>
                                            </div>
                                        </div>
                                    </form>

                                    <form method="POST" action="{{ route('admin.complaints.resolve-manual', $complaint->id) }}">
                                        @csrf
                                        <div class="mb-2">
                                            <label class="form-label font-bold text-muted small"><i class="fas fa-clipboard-check me-1"></i> Biên bản/kết quả giải quyết thủ công</label>
                                            <textarea name="resolution_note" class="form-control form-control-custom" rows="2" required minlength="5" placeholder="Nhập kết quả xử lý thủ công...">{{ old('resolution_note') }}</textarea>
                                        </div>
                                        <button type="submit" class="btn-decision-premium btn-decision-premium-success">
                                            <i class="fas fa-check-double"></i> 4. Đã giải quyết thủ công
                                        </button>
                                    </form>
                                </div>
                            @else
                                <form method="POST" action="{{ route('admin.complaints.acknowledge', $complaint->id) }}">
                                    @csrf
                                    <div class="alert alert-info border-0 p-3 mb-3 rounded-8 small">
                                        <i class="fas fa-info-circle me-1"></i> Đơn hàng chưa có yêu cầu trả hàng bị Store từ chối. Lựa chọn xử lý duy nhất khả dụng:
                                    </div>
                                    <button type="submit" class="btn btn-success w-100 rounded-pill py-2 shadow-sm font-semibold">
                                        <i class="fas fa-check me-1"></i> Đã tiếp nhận khiếu nại
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
