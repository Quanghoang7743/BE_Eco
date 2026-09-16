@extends('admin.layout')

@section('content')
    <style>
        :root {
            --recon-primary: #1f6fff;
            --recon-primary-hover: #1558d6;
            --recon-primary-light: #f5f9ff;
            --recon-primary-border: #b8cdf2;
            --recon-border: #e2e8f0;
            --recon-text: #0f172a;
            --recon-muted: #64748b;
            --recon-success: #10b981;
            --recon-success-light: #d1fae5;
            --recon-warning: #f59e0b;
            --recon-warning-light: #fef3c7;
            --recon-info: #3b82f6;
            --recon-info-light: #dbeafe;
            --recon-danger: #ef4444;
            --recon-danger-light: #fee2e2;
            --recon-bg-light: #f8fafc;
        }

        .prize-settings-wrapper {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--recon-text);
            padding-bottom: 30px;
        }

        .prize-settings-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .prize-settings-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .prize-settings-header p {
            color: var(--recon-muted);
            margin: 0;
            font-size: 14px;
        }

        /* Custom Switch Toggle */
        .form-switch-custom {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-switch-custom .form-check-input {
            width: 44px;
            height: 22px;
            background-color: #cbd5e1;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s ease, background-position 0.2s ease;
            appearance: none;
            position: relative;
        }
        .form-switch-custom .form-check-input:checked {
            background-color: var(--recon-success);
        }
        .form-switch-custom .form-check-input::before {
            content: "";
            position: absolute;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background-color: white;
            top: 2px;
            left: 2px;
            transition: transform 0.2s ease;
        }
        .form-switch-custom .form-check-input:checked::before {
            transform: translateX(22px);
        }

        /* Tabs Styling */
        .prize-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: none;
        }
        .prize-tabs .nav-link-custom {
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 14px;
            border: 1px solid transparent;
            background-color: #f1f5f9;
            color: var(--recon-muted);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .prize-tabs .nav-link-custom.active {
            background-color: var(--recon-primary);
            color: white;
        }
        .prize-tabs .nav-link-custom:hover:not(.active) {
            background-color: #e2e8f0;
            color: var(--recon-text);
        }

        /* Premium Card Design */
        .recon-card {
            background: #ffffff;
            border: 1px solid var(--recon-border) !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -1px rgba(0, 0, 0, 0.015);
            transition: all 0.2s ease;
            overflow: hidden;
            margin-bottom: 24px;
        }

        .recon-card .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--recon-border);
            padding: 18px 20px;
            font-weight: 700;
            color: var(--recon-text);
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .recon-card .card-body {
            padding: 20px;
        }

        /* Table styling */
        .recon-table-wrapper {
            overflow-x: auto;
        }
        .recon-table {
            width: 100%;
            border-collapse: collapse;
        }
        .recon-table th {
            background-color: var(--recon-bg-light);
            color: var(--recon-muted);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 12px 16px;
            border-bottom: 1px solid var(--recon-border);
            text-align: left;
        }
        .recon-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--recon-border);
            color: var(--recon-text);
            font-size: 14px;
            vertical-align: middle;
        }
        .recon-table tbody tr {
            cursor: pointer;
            transition: background-color 0.15s ease;
        }
        .recon-table tbody tr:hover {
            background-color: var(--recon-bg-light);
        }
        .recon-table tbody tr.active-row {
            background-color: var(--recon-primary-light);
            position: relative;
        }
        .recon-table tbody tr.active-row td:first-child {
            border-left: 3px solid var(--recon-primary);
        }

        /* Vehicle Meta Info column */
        .vehicle-info-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .vehicle-thumbnail {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            object-fit: cover;
            background-color: #f1f5f9;
            border: 1px solid var(--recon-border);
        }
        .vehicle-meta {
            display: flex;
            flex-direction: column;
        }
        .vehicle-name {
            font-weight: 600;
            color: var(--recon-text);
            font-size: 14px;
            margin-bottom: 2px;
        }
        .vehicle-brand {
            font-size: 12px;
            color: var(--recon-muted);
        }

        /* Status badge style */
        .status-badge-custom {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .status-badge-on {
            background-color: var(--recon-success-light);
            color: var(--recon-success);
        }
        .status-badge-off {
            background-color: var(--recon-warning-light);
            color: var(--recon-warning);
        }

        /* Action buttons */
        .action-btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: 1px solid var(--recon-border);
            background-color: white;
            color: var(--recon-muted);
            transition: all 0.2s;
            cursor: pointer;
        }
        .action-btn:hover {
            background-color: var(--recon-primary-light);
            color: var(--recon-primary);
            border-color: var(--recon-primary-border);
        }
        .action-btn.delete-btn:hover {
            background-color: var(--recon-danger-light);
            color: var(--recon-danger);
            border-color: var(--recon-danger);
        }

        /* Summary Cards */
        .summary-card-pills {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .summary-card-pills {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .summary-pill {
            background: #ffffff;
            border: 1px solid var(--recon-border);
            border-radius: 10px;
            padding: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .summary-pill-icon {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .summary-pill-info {
            display: flex;
            flex-direction: column;
        }
        .summary-pill-title {
            font-size: 11px;
            color: var(--recon-muted);
            margin-bottom: 2px;
            font-weight: 500;
        }
        .summary-pill-value {
            font-size: 16px;
            font-weight: 700;
            color: var(--recon-text);
        }

        .pill-blue { background-color: #eff6ff; color: #1e40af; }
        .pill-green { background-color: #ecfdf5; color: #065f46; }
        .pill-orange { background-color: #fff7ed; color: #9a3412; }
        .pill-gray { background-color: #f8fafc; color: #475569; }

        /* Detail / Edit form card components */
        .detail-image-container {
            width: 100%;
            height: 220px;
            background-color: var(--recon-bg-light);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 1px solid var(--recon-border);
            margin-bottom: 16px;
        }
        .detail-image-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        /* Small sub-thumbnails grid */
        .sub-thumbnails-grid {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 6px;
        }
        .sub-thumbnail-item {
            width: 50px;
            height: 40px;
            border-radius: 4px;
            object-fit: cover;
            border: 2px solid transparent;
            cursor: pointer;
            opacity: 0.6;
            transition: all 0.2s;
            background-color: #f1f5f9;
        }
        .sub-thumbnail-item:hover, .sub-thumbnail-item.active {
            opacity: 1;
            border-color: var(--recon-primary);
        }

        /* Custom File Input styled as drag & drop zone */
        .custom-file-dropzone {
            border: 2px dashed var(--recon-border);
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            background-color: var(--recon-bg-light);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .custom-file-dropzone:hover {
            border-color: var(--recon-primary-border);
            background-color: var(--recon-primary-light);
        }
        .custom-file-dropzone input[type="file"] {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        .dropzone-icon {
            font-size: 24px;
            color: var(--recon-muted);
            margin-bottom: 8px;
        }
        .dropzone-text {
            font-size: 13px;
            font-weight: 500;
            color: var(--recon-text);
            margin-bottom: 4px;
        }
        .dropzone-text span {
            color: var(--recon-primary);
        }
        .dropzone-info {
            font-size: 11px;
            color: var(--recon-muted);
        }

        /* Modal Form styling */
        .modal-content {
            border-radius: 14px;
            border: none;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .modal-header {
            border-bottom: 1px solid var(--recon-border);
            padding: 20px 24px;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            border-top: 1px solid var(--recon-border);
            padding: 16px 24px;
        }

        /* Character Counter */
        .char-counter {
            display: flex;
            justify-content: flex-end;
            font-size: 11px;
            color: var(--recon-muted);
            margin-top: 4px;
        }

        /* Utility Form elements */
        .form-label-custom {
            font-weight: 600;
            color: var(--recon-text);
            font-size: 13px;
            margin-bottom: 6px;
        }
        .form-control-custom {
            border: 1px solid var(--recon-border);
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 14px;
            color: var(--recon-text);
            transition: all 0.2s;
        }
        .form-control-custom:focus {
            border-color: var(--recon-primary-border);
            box-shadow: 0 0 0 3px rgba(31, 111, 255, 0.1);
            outline: none;
        }
    </style>

    <div class="container-fluid prize-settings-wrapper">
        <div class="prize-settings-header">
            <div>
                <h2>Cấu hình giải thưởng đa mẫu</h2>
                <p>Quản lý thư viện các mẫu xe điện và cấu hình mẫu mặc định hiển thị ra người dùng.</p>
            </div>
        </div>

        @php
            $initialSecondaryPrizes = old('secondary_prizes', $schedule->secondary_prizes ?? []);
            if (!is_array($initialSecondaryPrizes)) {
                $initialSecondaryPrizes = [];
            }

            $initialAllocations = old('prize_template_allocations', $schedule->prize_template_allocations ?? []);
            if (!is_array($initialAllocations)) {
                $initialAllocations = [];
            }

            $selectedDefaultTemplateId = old('default_prize_template_id', $schedule->default_prize_template_id);

            $voucherOptions = $vouchers
                ->map(function ($voucher) {
                    return [
                        'id' => (int) $voucher->id,
                        'label' => (string) ($voucher->code . ' - ' . ($voucher->description ?? 'Voucher')),
                    ];
                })
                ->values();

            $rewardOptions = $rewards
                ->map(function ($reward) {
                    return [
                        'id' => (int) $reward->id,
                        'label' => (string) ($reward->name ?? ('Reward #' . $reward->id)),
                    ];
                })
                ->values();

            $templateOptions = $prizeTemplates
                ->map(function ($template) {
                    return [
                        'id' => (int) $template->id,
                        'prize_name' => (string) ($template->prize_name ?? ''),
                        'monetary_value' => (float) ($template->monetary_value ?? 0),
                        'image_path' => (string) ($template->image_path ?? ''),
                        'description' => (string) ($template->description ?? ''),
                        'is_active' => (bool) ($template->is_active ?? false),
                    ];
                })
                ->values();
        @endphp

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

        @php
            $templateTabFields = ['prize_name', 'monetary_value', 'image_file', 'description', 'is_active'];
            $defaultTabFields = ['default_prize_template_id', 'default_prize_type', 'default_prize_value', 'default_prize_image_file', 'default_prize_description', 'secondary_prizes', 'prize_template_allocations'];
            $activePrizeTab = old('prize_settings_tab');

            if (!in_array($activePrizeTab, ['templates', 'default'], true)) {
                if ($errors->hasAny($defaultTabFields)) {
                    $activePrizeTab = 'default';
                } else {
                    $activePrizeTab = 'templates';
                }
            }
        @endphp

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div class="prize-tabs">
                <button type="button" class="nav-link-custom {{ $activePrizeTab === 'templates' ? 'active' : '' }}" data-prize-tab="templates">
                    Thư viện mẫu
                </button>
                <button type="button" class="nav-link-custom {{ $activePrizeTab === 'default' ? 'active' : '' }}" data-prize-tab="default">
                    Cấu hình mặc định
                </button>
            </div>
            
            <button type="button" class="btn btn-primary px-4 py-2" id="btn-add-template-header" data-bs-toggle="modal" data-bs-target="#addTemplateModal" style="{{ $activePrizeTab === 'templates' ? '' : 'display: none;' }}">
                + Thêm mẫu giải thưởng
            </button>
        </div>

        <div data-prize-tab-panel="templates" style="{{ $activePrizeTab === 'templates' ? '' : 'display:none;' }}">
            <div class="row">
                <!-- Left column: template table list -->
                <div class="col-lg-7">
                    <div class="recon-card">
                        <div class="card-header">
                            <span>Danh sách mẫu giải thưởng</span>
                        </div>
                        <div class="card-body">
                            <!-- Search & Filter Controls -->
                            <div class="row g-2 mb-3 align-items-center">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0 text-muted" style="border-radius: 8px 0 0 8px; border-color: var(--recon-border);">
                                            <i class="fas fa-search"></i>
                                        </span>
                                        <input type="text" id="search-input" class="form-control border-start-0" placeholder="Tìm kiếm mẫu xe, hãng..." oninput="filterTemplates()" style="border-radius: 0 8px 8px 0; border-color: var(--recon-border); height: 40px; font-size: 14px;">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <select id="status-filter" class="form-select" onchange="filterTemplates()" style="border-radius: 8px; border-color: var(--recon-border); height: 40px; font-size: 14px;">
                                        <option value="all">Trạng thái: Tất cả</option>
                                        <option value="active">Đang hoạt động</option>
                                        <option value="inactive">Tạm ẩn / Đã tắt</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-grid">
                                    <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()" style="border-radius: 8px; height: 40px; font-size: 14px;">
                                        <i class="fas fa-sync-alt me-1"></i> Đặt lại
                                    </button>
                                </div>
                            </div>

                            @php
                                $totalCount = $prizeTemplates->count();
                                $activeCount = $prizeTemplates->where('is_active', true)->count();
                                $inactiveCount = $prizeTemplates->where('is_active', false)->count();
                                $disabledCount = 0; // standard mockup value
                            @endphp

                            <!-- Summary Metrics Cards -->
                            <div class="summary-card-pills">
                                <div class="summary-pill">
                                    <div class="summary-pill-icon pill-blue">
                                        <i class="fas fa-car"></i>
                                    </div>
                                    <div class="summary-pill-info">
                                        <span class="summary-pill-title">Tổng mẫu xe</span>
                                        <span class="summary-pill-value" id="summary-total">{{ $totalCount }}</span>
                                    </div>
                                </div>
                                <div class="summary-pill">
                                    <div class="summary-pill-icon pill-green">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                    <div class="summary-pill-info">
                                        <span class="summary-pill-title">Đang hoạt động</span>
                                        <span class="summary-pill-value" id="summary-active">{{ $activeCount }}</span>
                                    </div>
                                </div>
                                <div class="summary-pill">
                                    <div class="summary-pill-icon pill-orange">
                                        <i class="fas fa-eye-slash"></i>
                                    </div>
                                    <div class="summary-pill-info">
                                        <span class="summary-pill-title">Tạm ẩn</span>
                                        <span class="summary-pill-value" id="summary-inactive">{{ $inactiveCount }}</span>
                                    </div>
                                </div>
                                <div class="summary-pill">
                                    <div class="summary-pill-icon pill-gray">
                                        <i class="fas fa-power-off"></i>
                                    </div>
                                    <div class="summary-pill-info">
                                        <span class="summary-pill-title">Đã tắt</span>
                                        <span class="summary-pill-value" id="summary-disabled">{{ $disabledCount }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="recon-table-wrapper">
                                <table class="recon-table" id="template-table">
                                    <thead>
                                        <tr>
                                            <th>Mẫu xe</th>
                                            <th>Giá trị (VND)</th>
                                            <th>Trạng thái</th>
                                            <th>Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse ($prizeTemplates as $template)
                                            @php
                                                $parts = explode(' ', trim($template->prize_name));
                                                $brand = count($parts) > 1 ? $parts[0] : 'Ecoluck';
                                            @endphp
                                            <tr data-template-id="{{ $template->id }}" 
                                                data-template-name="{{ $template->prize_name }}" 
                                                data-template-value="{{ (float)$template->monetary_value }}" 
                                                data-template-image="{{ $template->image_path }}" 
                                                data-template-desc="{{ $template->description }}" 
                                                data-template-active="{{ $template->is_active ? 1 : 0 }}"
                                                onclick="selectTemplate({{ $template->id }})">
                                                <td>
                                                    <div class="vehicle-info-cell">
                                                        <img src="{{ $template->image_path ?: '/assets/img/no-image.png' }}" class="vehicle-thumbnail" alt="{{ $template->prize_name }}">
                                                        <div class="vehicle-meta">
                                                            <span class="vehicle-name">{{ $template->prize_name }}</span>
                                                            <span class="vehicle-brand">{{ $brand }}</span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <strong class="text-dark">{{ number_format((float)($template->monetary_value ?? 0), 0, ',', '.') }} đ</strong>
                                                </td>
                                                <td>
                                                    @if ($template->is_active)
                                                        <span class="status-badge-custom status-badge-on">ON</span>
                                                    @else
                                                        <span class="status-badge-custom status-badge-off">Tạm ẩn</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-2 align-items-center" onclick="event.stopPropagation();">
                                                        <button type="button" class="action-btn" onclick="selectTemplate({{ $template->id }})" title="Xem chi tiết">
                                                            <i class="fa fa-eye"></i>
                                                        </button>
                                                        <button type="button" class="action-btn" onclick="selectTemplate({{ $template->id }})" title="Sửa">
                                                            <i class="fa fa-pen"></i>
                                                        </button>
                                                        <div class="dropdown d-inline-block">
                                                            <button type="button" class="action-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Thao tác khác" style="padding: 0; outline: none; border-color: var(--recon-border);">
                                                                <i class="fa fa-ellipsis-h"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border" style="border-radius: 8px;">
                                                                @if ($template->is_active)
                                                                    <li>
                                                                        <a class="dropdown-item text-warning py-2" href="javascript:void(0)" onclick="toggleTemplateStatus({{ $template->id }}, 0)">
                                                                            <i class="fa fa-eye-slash me-2"></i> Tạm ẩn
                                                                        </a>
                                                                    </li>
                                                                    <li>
                                                                        <a class="dropdown-item text-danger py-2" href="javascript:void(0)" onclick="toggleTemplateStatus({{ $template->id }}, 0)">
                                                                            <i class="fa fa-power-off me-2"></i> Tắt hoạt động
                                                                        </a>
                                                                    </li>
                                                                @else
                                                                    <li>
                                                                        <a class="dropdown-item text-success py-2" href="javascript:void(0)" onclick="toggleTemplateStatus({{ $template->id }}, 1)">
                                                                            <i class="fa fa-play me-2"></i> Bật hoạt động
                                                                        </a>
                                                                    </li>
                                                                @endif
                                                                <li><hr class="dropdown-divider"></li>
                                                                <li>
                                                                    <form method="POST" action="{{ route('admin.prize-settings.templates.delete', $template->id) }}"
                                                                          onsubmit="return confirm('Xác nhận xóa mẫu giải thưởng này?');" style="display:inline;">
                                                                        @csrf
                                                                        @method('DELETE')
                                                                        <button type="submit" class="dropdown-item text-danger py-2">
                                                                            <i class="fa fa-trash me-2"></i> Xóa mẫu
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="4" class="text-center text-muted py-4">Chưa có mẫu giải thưởng. Hãy thêm mẫu đầu tiên.</td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right column: template detail editor -->
                <div class="col-lg-5">
                    <div class="recon-card" id="detail-card" style="display: none;">
                        <div class="card-header">
                            <span>Chi tiết mẫu xe đang chọn</span>
                            <div class="form-check form-switch form-switch-custom mb-0 p-0">
                                <label class="form-check-label small me-2" id="detail-active-label" for="detail_is_active">Đang hoạt động</label>
                                <input class="form-check-input ms-0" type="checkbox" role="switch" id="detail_is_active" value="1">
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="detail-image-container">
                                <img id="detail-image-preview" src="" alt="Vehicle Preview">
                            </div>

                            @if ($prizeTemplates->isNotEmpty())
                                <div class="sub-thumbnails-grid">
                                    @foreach ($prizeTemplates as $subT)
                                        @if ($subT->image_path)
                                            <img src="{{ $subT->image_path }}" 
                                                 class="sub-thumbnail-item" 
                                                 data-sub-id="{{ $subT->id }}"
                                                 onclick="selectTemplate({{ $subT->id }})"
                                                 alt="{{ $subT->prize_name }}"
                                                 title="{{ $subT->prize_name }}">
                                        @endif
                                    @endforeach
                                </div>
                            @endif

                            <form id="detail-edit-form" method="POST" action="" enctype="multipart/form-data">
                                @csrf
                                @method('PATCH')
                                
                                <input type="hidden" name="is_active" id="hidden_is_active" value="0">
                                
                                <div class="mb-3">
                                    <label class="form-label-custom">Tên mẫu xe *</label>
                                    <input type="text" name="prize_name" id="detail_prize_name" class="form-control form-control-custom" maxlength="150" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label-custom">Giá trị (VND) *</label>
                                    <input type="number" name="monetary_value" id="detail_monetary_value" class="form-control form-control-custom" step="0.01" min="0" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label-custom">Mô tả</label>
                                    <textarea name="description" id="detail_description" class="form-control form-control-custom" rows="3" maxlength="5000"></textarea>
                                    <div class="char-counter">
                                        <span id="char-count-text">0/5000 ký tự</span>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="form-label-custom">Ảnh mẫu xe *</label>
                                    <div class="custom-file-dropzone">
                                        <i class="fa fa-cloud-upload-alt dropzone-icon"></i>
                                        <div class="dropzone-text">Kéo thả ảnh vào đây hoặc <span>Chọn ảnh</span></div>
                                        <div class="dropzone-info">Định dạng: JPG, PNG. Dung lượng tối đa 5MB. Tỉ lệ khuyến nghị 16:9</div>
                                        <input type="file" name="image_file" accept="image/*" onchange="previewUploadImage(this)">
                                    </div>
                                </div>

                                <div class="d-flex gap-2 justify-content-end">
                                    <button type="button" class="btn btn-outline-secondary px-4" onclick="cancelEditing()">Hủy</button>
                                    <button type="submit" class="btn btn-primary px-4">Lưu thay đổi</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="recon-card text-center p-5" id="detail-card-empty">
                        <div class="py-4">
                            <i class="fa fa-car-side text-muted mb-3" style="font-size: 48px;"></i>
                            <h5 class="text-dark mb-2">Chưa chọn mẫu giải thưởng</h5>
                            <p class="text-muted small px-4">Hãy chọn một mẫu giải thưởng từ danh sách bên trái để chỉnh sửa hoặc nhấn "Thêm mẫu giải thưởng" để tạo mới.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden form for toggling template active status -->
        <form id="action-toggle-form" method="POST" action="" style="display:none;">
            @csrf
            @method('PATCH')
            <input type="hidden" name="prize_name" id="toggle_prize_name">
            <input type="hidden" name="monetary_value" id="toggle_monetary_value">
            <input type="hidden" name="description" id="toggle_description">
            <input type="hidden" name="is_active" id="toggle_is_active">
        </form>

        <!-- Add Template Modal -->
        <div class="modal fade" id="addTemplateModal" tabindex="-1" aria-labelledby="addTemplateModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header text-dark">
                        <h5 class="modal-title" id="addTemplateModalLabel" style="font-weight:700;">Thêm mẫu giải thưởng mới</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="{{ route('admin.prize-settings.templates.store') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="modal-body text-dark">
                            <div class="mb-3 text-start">
                                <label class="form-label-custom">Tên mẫu xe *</label>
                                <input type="text" name="prize_name" class="form-control form-control-custom" maxlength="150" placeholder="VD: VinFast VF6" required>
                            </div>
                            <div class="mb-3 text-start">
                                <label class="form-label-custom">Giá trị (VND) *</label>
                                <input type="number" name="monetary_value" class="form-control form-control-custom" step="0.01" min="0" placeholder="700000000" required>
                            </div>
                            <div class="mb-3 text-start">
                                <label class="form-label-custom">Mô tả</label>
                                <textarea name="description" class="form-control form-control-custom" rows="3" maxlength="5000" placeholder="Mẫu xe cho khung giờ vàng"></textarea>
                            </div>
                            <div class="mb-3 text-start">
                                <label class="form-label-custom">Ảnh mẫu xe</label>
                                <div class="detail-image-container mb-2" id="modal-image-preview-container" style="display: none; height: 180px;">
                                    <img id="modal-image-preview" src="" alt="Uploaded image preview" style="max-height: 100%; max-width: 100%; object-fit: contain;">
                                </div>
                                <div class="custom-file-dropzone">
                                    <i class="fa fa-cloud-upload-alt dropzone-icon"></i>
                                    <div class="dropzone-text">Kéo thả ảnh vào đây hoặc <span>Chọn ảnh</span></div>
                                    <div class="dropzone-info">Định dạng: JPG, PNG. Dung lượng tối đa 5MB. Tỉ lệ khuyến nghị 16:9</div>
                                    <input type="file" name="image_file" accept="image/*" onchange="previewModalUploadImage(this)">
                                </div>
                            </div>
                            <div class="form-check form-switch form-switch-custom mb-0 p-0 justify-content-start text-start">
                                <label class="form-check-label small me-2" for="new_template_active_modal">Kích hoạt ngay khi tạo</label>
                                <input class="form-check-input ms-0" type="checkbox" role="switch" name="is_active" value="1" checked id="new_template_active_modal">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" class="btn btn-primary px-4">Lưu</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div data-prize-tab-panel="default" style="{{ $activePrizeTab === 'default' ? '' : 'display:none;' }}">
            <div class="recon-card">
                <div class="card-header">
                    <span>Thiết lập cấu hình giải thưởng mặc định</span>
                </div>
                <div class="card-body">
                    <form action="{{ route('admin.prize-settings.update', $schedule->id) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="prize_settings_tab" id="prize_settings_tab" value="{{ $activePrizeTab }}">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Mẫu giải thưởng mặc định</label>
                            <select class="form-control" id="default_prize_template_id" name="default_prize_template_id">
                                <option value="">-- Không chọn (dùng nhập tay bên dưới) --</option>
                                @foreach ($prizeTemplates as $template)
                                    @if ($template->is_active)
                                        <option value="{{ $template->id }}" {{ (string) $selectedDefaultTemplateId === (string) $template->id ? 'selected' : '' }}>
                                            {{ $template->prize_name }} - {{ number_format((float) ($template->monetary_value ?? 0), 0, ',', '.') }} đ
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                            <small class="text-muted">Nếu chọn mẫu mặc định, hệ thống ưu tiên dữ liệu từ template cho phòng không nằm trong dải phân bổ.</small>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded p-3 h-100" id="default-template-preview">
                                <div class="fw-semibold mb-1">Preview mẫu mặc định</div>
                                <div id="default-template-preview-name" class="mb-1 text-muted">Chưa chọn mẫu</div>
                                <div id="default-template-preview-value" class="small text-muted mb-2"></div>
                                <img id="default-template-preview-image" src="" alt="Template preview" style="max-height: 120px; display: none;" class="img-fluid rounded mb-2">
                                <div id="default-template-preview-description" class="small text-muted"></div>
                            </div>
                        </div>

                        <!-- 
                        <div class="col-md-3">
                            <label class="form-label">Loại giải thưởng fallback</label>
                            <input type="text" name="default_prize_type" class="form-control"
                                value="{{ old('default_prize_type', $schedule->default_prize_type) }}" maxlength="100" required>
                        </div>
                        -->
                        <input type="hidden" name="default_prize_type" value="{{ old('default_prize_type', $schedule->default_prize_type ?: 'Fallback') }}">

                        <!-- 
                        <div class="col-md-3">
                            <label class="form-label">Trị giá fallback (VND)</label>
                            <input type="number" name="default_prize_value" class="form-control" step="0.01" min="0"
                                value="{{ old('default_prize_value', $schedule->default_prize_value) }}" placeholder="VD: 25000000">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Ảnh fallback (Upload)</label>
                            <input type="file" name="default_prize_image_file" class="form-control" accept="image/*">
                            @if (!empty($schedule->default_prize_image_url))
                                <div class="mt-2">
                                    <img src="{{ $schedule->default_prize_image_url }}" alt="Fallback prize" class="img-fluid rounded border"
                                        style="max-height: 120px; object-fit: cover;">
                                </div>
                            @endif
                        </div>

                        <div class="col-12">
                            <label class="form-label">Mô tả fallback</label>
                            <textarea name="default_prize_description" class="form-control" rows="2" maxlength="2000"
                                placeholder="VD: Xe điện Ecoluck model 2026">{{ old('default_prize_description', $schedule->default_prize_description) }}</textarea>
                        </div>
                        -->

                        <div class="col-12">
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Phân bổ mẫu giải thưởng theo dải phòng</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="add-template-allocation">
                                    + Thêm dải phòng
                                </button>
                            </div>
                            <small class="text-muted d-block mb-3">
                                Ví dụ: phòng 1-10 dùng mẫu A, phòng 11-40 dùng mẫu B. Dải không được chồng lấn.
                            </small>

                            <div id="template-allocation-list" class="d-flex flex-column gap-2"></div>
                            <div id="template-allocation-empty" class="text-muted">Chưa có dải phân bổ. Hệ thống sẽ dùng mẫu mặc định cho toàn bộ phòng.</div>
                        </div>

                        <div class="col-12">
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Giải thưởng phụ</label>
                                <button type="button" class="btn btn-sm btn-outline-primary" id="add-secondary-prize">
                                    + Thêm giải phụ
                                </button>
                            </div>
                            <small class="text-muted d-block mb-3">
                                Mỗi dòng gồm loại quà, quà cụ thể và số lượng trúng trong một phòng.
                            </small>

                            <div id="secondary-prize-list" class="d-flex flex-column gap-2"></div>
                            <div id="secondary-prize-empty" class="text-muted">Chưa có giải thưởng phụ. Nhấn "Thêm giải phụ" để cấu hình.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-3">Lưu cấu hình giải thưởng</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const tabButtons = document.querySelectorAll('[data-prize-tab]');
            const tabPanels = document.querySelectorAll('[data-prize-tab-panel]');
            const activeTabField = document.getElementById('prize_settings_tab');

            function activateTab(tabName) {
                tabButtons.forEach(function(button) {
                    const isActive = button.getAttribute('data-prize-tab') === tabName;
                    button.classList.toggle('active', isActive);
                });

                tabPanels.forEach(function(panel) {
                    const isActive = panel.getAttribute('data-prize-tab-panel') === tabName;
                    panel.style.display = isActive ? '' : 'none';
                });

                const addBtnHeader = document.getElementById('btn-add-template-header');
                if (addBtnHeader) {
                    addBtnHeader.style.display = tabName === 'templates' ? '' : 'none';
                }

                if (activeTabField) {
                    activeTabField.value = tabName;
                }
            }

            tabButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    activateTab(this.getAttribute('data-prize-tab'));
                });
            });

            let selectedTemplateId = null;

            function selectTemplate(id) {
                const row = document.querySelector(`tr[data-template-id="${id}"]`);
                if (!row) return;

                selectedTemplateId = id;

                // Remove active class from all rows
                document.querySelectorAll('#template-table tbody tr').forEach(r => r.classList.remove('active-row'));
                row.classList.add('active-row');

                // Remove active class from all sub-thumbnails
                document.querySelectorAll('.sub-thumbnail-item').forEach(img => img.classList.remove('active'));
                const subThumb = document.querySelector(`.sub-thumbnail-item[data-sub-id="${id}"]`);
                if (subThumb) subThumb.classList.add('active');

                // Extract data
                const name = row.getAttribute('data-template-name');
                const value = row.getAttribute('data-template-value');
                const image = row.getAttribute('data-template-image');
                const desc = row.getAttribute('data-template-desc');
                const active = parseInt(row.getAttribute('data-template-active')) === 1;

                // Update form elements
                document.getElementById('detail_prize_name').value = name;
                document.getElementById('detail_monetary_value').value = value;
                document.getElementById('detail_description').value = desc || '';
                
                // Update character count
                updateCharCount(desc || '');

                // Set toggle switch state
                const toggle = document.getElementById('detail_is_active');
                toggle.checked = active;
                document.getElementById('hidden_is_active').value = active ? '1' : '0';
                updateActiveLabel(active);

                // Update image preview
                const preview = document.getElementById('detail-image-preview');
                preview.src = image ? image : '/assets/img/no-image.png';

                // Set form action
                const form = document.getElementById('detail-edit-form');
                const urlPattern = "{{ route('admin.prize-settings.templates.update', ':id') }}";
                form.action = urlPattern.replace(':id', id);

                // Hide empty card and show detail card
                document.getElementById('detail-card-empty').style.display = 'none';
                document.getElementById('detail-card').style.display = 'block';
            }

            function updateActiveLabel(checked) {
                const label = document.getElementById('detail-active-label');
                label.textContent = checked ? 'Đang hoạt động' : 'Tạm ẩn';
            }

            // Bind toggle change event
            const detailToggle = document.getElementById('detail_is_active');
            if (detailToggle) {
                detailToggle.addEventListener('change', function() {
                    document.getElementById('hidden_is_active').value = this.checked ? '1' : '0';
                    updateActiveLabel(this.checked);
                });
            }

            // Character count helper
            function updateCharCount(text) {
                const count = text.length;
                document.getElementById('char-count-text').textContent = `${count}/5000 ký tự`;
            }
            
            const detailDesc = document.getElementById('detail_description');
            if (detailDesc) {
                detailDesc.addEventListener('input', function() {
                    updateCharCount(this.value);
                });
            }

            // Image preview for upload
            function previewUploadImage(input) {
                if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('detail-image-preview').src = e.target.result;
                    }
                    reader.readAsDataURL(input.files[0]);
                }
            }

            function previewModalUploadImage(input) {
                if (input.files && input.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        document.getElementById('modal-image-preview').src = e.target.result;
                        document.getElementById('modal-image-preview-container').style.display = 'flex';
                    }
                    reader.readAsDataURL(input.files[0]);
                } else {
                    document.getElementById('modal-image-preview-container').style.display = 'none';
                    document.getElementById('modal-image-preview').src = '';
                }
            }

            function cancelEditing() {
                if (selectedTemplateId) {
                    selectTemplate(selectedTemplateId);
                } else {
                    document.getElementById('detail-card').style.display = 'none';
                    document.getElementById('detail-card-empty').style.display = 'block';
                }
            }

            // Expose functions to global window object
            window.selectTemplate = selectTemplate;
            window.previewUploadImage = previewUploadImage;
            window.previewModalUploadImage = previewModalUploadImage;
            window.cancelEditing = cancelEditing;

            function filterTemplates() {
                const searchQuery = document.getElementById('search-input').value.toLowerCase().trim();
                const statusFilter = document.getElementById('status-filter').value;

                const rows = document.querySelectorAll('#template-table tbody tr[data-template-id]');
                
                rows.forEach(row => {
                    const name = row.getAttribute('data-template-name').toLowerCase();
                    const active = parseInt(row.getAttribute('data-template-active')) === 1;

                    // Match search query
                    const matchesSearch = name.includes(searchQuery);

                    // Match status filter
                    let matchesStatus = true;
                    if (statusFilter === 'active') {
                        matchesStatus = active === true;
                    } else if (statusFilter === 'inactive') {
                        matchesStatus = active === false;
                    }

                    if (matchesSearch && matchesStatus) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            function resetFilters() {
                document.getElementById('search-input').value = '';
                document.getElementById('status-filter').value = 'all';
                filterTemplates();
            }

            function toggleTemplateStatus(id, newStatus) {
                const row = document.querySelector(`tr[data-template-id="${id}"]`);
                if (!row) return;

                const name = row.getAttribute('data-template-name');
                const value = row.getAttribute('data-template-value');
                const desc = row.getAttribute('data-template-desc');

                document.getElementById('toggle_prize_name').value = name;
                document.getElementById('toggle_monetary_value').value = value;
                document.getElementById('toggle_description').value = desc || '';
                document.getElementById('toggle_is_active').value = newStatus ? '1' : '0';

                const form = document.getElementById('action-toggle-form');
                const urlPattern = "{{ route('admin.prize-settings.templates.update', ':id') }}";
                form.action = urlPattern.replace(':id', id);
                form.submit();
            }

            window.filterTemplates = filterTemplates;
            window.resetFilters = resetFilters;
            window.toggleTemplateStatus = toggleTemplateStatus;

            // Auto-select the first template row on load
            document.addEventListener('DOMContentLoaded', function() {
                const firstRow = document.querySelector('#template-table tbody tr[data-template-id]');
                if (firstRow) {
                    const id = firstRow.getAttribute('data-template-id');
                    selectTemplate(id);
                } else {
                    document.getElementById('detail-card').style.display = 'none';
                    document.getElementById('detail-card-empty').style.display = 'block';
                }
            });

            const initialRows = @json($initialSecondaryPrizes, JSON_UNESCAPED_UNICODE);
            const initialAllocations = @json($initialAllocations, JSON_UNESCAPED_UNICODE);
            const templateOptions = @json($templateOptions, JSON_UNESCAPED_UNICODE);
            const options = {
                voucher: @json($voucherOptions, JSON_UNESCAPED_UNICODE),
                reward: @json($rewardOptions, JSON_UNESCAPED_UNICODE),
            };

            const listEl = document.getElementById('secondary-prize-list');
            const emptyEl = document.getElementById('secondary-prize-empty');
            const addBtn = document.getElementById('add-secondary-prize');

            const allocationListEl = document.getElementById('template-allocation-list');
            const allocationEmptyEl = document.getElementById('template-allocation-empty');
            const addAllocationBtn = document.getElementById('add-template-allocation');

            const defaultTemplateSelect = document.getElementById('default_prize_template_id');
            const previewName = document.getElementById('default-template-preview-name');
            const previewValue = document.getElementById('default-template-preview-value');
            const previewDescription = document.getElementById('default-template-preview-description');
            const previewImage = document.getElementById('default-template-preview-image');

            function escapeHtml(text) {
                return String(text || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatVnd(value) {
                const number = Number(value || 0);
                return new Intl.NumberFormat('vi-VN').format(Math.max(0, number)) + ' đ';
            }

            function createOptionsByType(type, selectedId) {
                const rows = options[type] || [];
                let html = '<option value="">-- Chọn quà --</option>';
                if (rows.length === 0) {
                    const emptyLabel = type === 'voucher' ? '-- Chưa có voucher --' : '-- Chưa có quà tặng --';
                    html += '<option value="" disabled>' + emptyLabel + '</option>';
                }
                rows.forEach(function(row) {
                    const selected = Number(selectedId) === Number(row.id) ? 'selected' : '';
                    html += '<option value="' + row.id + '" ' + selected + '>' + escapeHtml(row.label) + '</option>';
                });
                return html;
            }

            function createTemplateOptions(selectedId) {
                let html = '<option value="">-- Chọn mẫu giải thưởng --</option>';
                templateOptions.forEach(function(template) {
                    if (!template.is_active) {
                        return;
                    }
                    const selected = Number(selectedId) === Number(template.id) ? 'selected' : '';
                    html += '<option value="' + template.id + '" ' + selected + '>' +
                        escapeHtml(template.prize_name) + ' - ' + formatVnd(template.monetary_value) +
                        '</option>';
                });
                return html;
            }

            function updateNames() {
                const rows = listEl.querySelectorAll('[data-secondary-row]');
                rows.forEach(function(row, index) {
                    const typeEl = row.querySelector('[data-field="type"]');
                    const itemEl = row.querySelector('[data-field="id"]');
                    const qtyEl = row.querySelector('[data-field="qty"]');

                    if (typeEl) typeEl.name = 'secondary_prizes[' + index + '][type]';
                    if (itemEl) itemEl.name = 'secondary_prizes[' + index + '][id]';
                    if (qtyEl) qtyEl.name = 'secondary_prizes[' + index + '][qty]';
                });

                emptyEl.style.display = rows.length > 0 ? 'none' : 'block';
            }

            function updateAllocationNames() {
                const rows = allocationListEl.querySelectorAll('[data-allocation-row]');
                rows.forEach(function(row, index) {
                    const startEl = row.querySelector('[data-field="start_room"]');
                    const endEl = row.querySelector('[data-field="end_room"]');
                    const templateEl = row.querySelector('[data-field="prize_template_id"]');

                    if (startEl) startEl.name = 'prize_template_allocations[' + index + '][start_room]';
                    if (endEl) endEl.name = 'prize_template_allocations[' + index + '][end_room]';
                    if (templateEl) templateEl.name = 'prize_template_allocations[' + index + '][prize_template_id]';
                });

                allocationEmptyEl.style.display = rows.length > 0 ? 'none' : 'block';
            }

            function createRow(data) {
                const type = (data && data.type) ? String(data.type).toLowerCase() : 'voucher';
                const selectedId = data && data.id ? data.id : '';
                const qty = data && data.qty ? data.qty : 1;

                const row = document.createElement('div');
                row.setAttribute('data-secondary-row', '1');
                row.className = 'border rounded p-3 bg-light';
                row.innerHTML = '' +
                    '<div class="row g-2 align-items-end">' +
                    '  <div class="col-md-3">' +
                    '    <label class="form-label mb-1">Loại giải phụ</label>' +
                    '    <select class="form-control" data-field="type">' +
                    '      <option value="voucher" ' + (type === 'voucher' ? 'selected' : '') + '>Voucher</option>' +
                    '      <option value="reward" ' + (type === 'reward' ? 'selected' : '') + '>Quà tặng</option>' +
                    '    </select>' +
                    '  </div>' +
                    '  <div class="col-md-6">' +
                    '    <label class="form-label mb-1">Món quà cụ thể</label>' +
                    '    <select class="form-control" data-field="id">' +
                    createOptionsByType(type, selectedId) +
                    '    </select>' +
                    '  </div>' +
                    '  <div class="col-md-2">' +
                    '    <label class="form-label mb-1">Số lượng</label>' +
                    '    <input type="number" class="form-control" data-field="qty" min="1" max="1000000" value="' + escapeHtml(qty) + '">' +
                    '  </div>' +
                    '  <div class="col-md-1 d-grid">' +
                    '    <button type="button" class="btn btn-outline-danger" data-remove-row title="Xóa">×</button>' +
                    '  </div>' +
                    '</div>';

                const typeEl = row.querySelector('[data-field="type"]');
                const itemEl = row.querySelector('[data-field="id"]');
                const removeEl = row.querySelector('[data-remove-row]');

                typeEl.addEventListener('change', function() {
                    itemEl.innerHTML = createOptionsByType(typeEl.value, '');
                });

                removeEl.addEventListener('click', function() {
                    row.remove();
                    updateNames();
                });

                listEl.appendChild(row);
                updateNames();
            }

            function createAllocationRow(data) {
                const startRoom = data && data.start_room ? Number(data.start_room) : 1;
                const endRoom = data && data.end_room ? Number(data.end_room) : 10;
                const templateId = data && data.prize_template_id ? Number(data.prize_template_id) : '';

                const row = document.createElement('div');
                row.setAttribute('data-allocation-row', '1');
                row.className = 'border rounded p-3 bg-light';
                row.innerHTML = '' +
                    '<div class="row g-2 align-items-end">' +
                    '  <div class="col-md-2">' +
                    '    <label class="form-label mb-1">Từ phòng</label>' +
                    '    <input type="number" class="form-control" data-field="start_room" min="1" value="' + escapeHtml(startRoom) + '">' +
                    '  </div>' +
                    '  <div class="col-md-2">' +
                    '    <label class="form-label mb-1">Đến phòng</label>' +
                    '    <input type="number" class="form-control" data-field="end_room" min="1" value="' + escapeHtml(endRoom) + '">' +
                    '  </div>' +
                    '  <div class="col-md-7">' +
                    '    <label class="form-label mb-1">Mẫu giải thưởng áp dụng</label>' +
                    '    <select class="form-control" data-field="prize_template_id">' + createTemplateOptions(templateId) + '</select>' +
                    '  </div>' +
                    '  <div class="col-md-1 d-grid">' +
                    '    <button type="button" class="btn btn-outline-danger" data-remove-allocation title="Xóa">×</button>' +
                    '  </div>' +
                    '</div>';

                const removeEl = row.querySelector('[data-remove-allocation]');
                removeEl.addEventListener('click', function() {
                    row.remove();
                    updateAllocationNames();
                });

                allocationListEl.appendChild(row);
                updateAllocationNames();
            }

            function updateDefaultTemplatePreview() {
                const selectedId = Number(defaultTemplateSelect.value || 0);
                const selected = templateOptions.find(function(template) {
                    return Number(template.id) === selectedId;
                });

                if (!selected) {
                    previewName.textContent = 'Chưa chọn mẫu';
                    previewValue.textContent = '';
                    previewDescription.textContent = '';
                    previewImage.style.display = 'none';
                    previewImage.src = '';
                    return;
                }

                previewName.textContent = selected.prize_name;
                previewValue.textContent = 'Giá trị: ' + formatVnd(selected.monetary_value);
                previewDescription.textContent = selected.description || 'Không có mô tả.';

                if (selected.image_path) {
                    previewImage.src = selected.image_path;
                    previewImage.style.display = 'block';
                } else {
                    previewImage.style.display = 'none';
                    previewImage.src = '';
                }
            }

            addBtn.addEventListener('click', function() {
                createRow({type: 'voucher', id: '', qty: 1});
            });

            addAllocationBtn.addEventListener('click', function() {
                createAllocationRow({start_room: 1, end_room: 10, prize_template_id: ''});
            });

            defaultTemplateSelect.addEventListener('change', updateDefaultTemplatePreview);

            if (Array.isArray(initialRows) && initialRows.length > 0) {
                initialRows.forEach(function(row) {
                    createRow(row || {});
                });
            } else {
                updateNames();
            }

            if (Array.isArray(initialAllocations) && initialAllocations.length > 0) {
                initialAllocations.forEach(function(row) {
                    createAllocationRow(row || {});
                });
            } else {
                updateAllocationNames();
            }

            updateDefaultTemplatePreview();

            activateTab(@json($activePrizeTab, JSON_UNESCAPED_UNICODE));
        })();
    </script>
@endsection
