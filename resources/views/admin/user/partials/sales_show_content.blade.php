@php
    $data = $specialist360 ?? [];
    $stores = collect($data['stores'] ?? []);
    $assignmentHistory = collect($data['assignmentHistory'] ?? []);
    $careLogs = collect($data['careLogs'] ?? []);
    $tasks = collect($data['tasks'] ?? []);
    $ticketEarnings = collect($data['ticketEarnings'] ?? []);
    $settlements = collect($data['settlements'] ?? []);
    $feeConfigs = collect($data['feeConfigs'] ?? []);
    $ratings = collect($data['ratings'] ?? []);
    $creditLogs = collect($data['creditLogs'] ?? []);
    $auditLogs = collect($data['auditLogs'] ?? []);
    $careSummary = $data['careSummary'] ?? [];
    $taskSummary = $data['taskSummary'] ?? [];
    $ticketSummary = $data['ticketSummary'] ?? [];
    $settlementSummary = $data['settlementSummary'] ?? [];
    $ratingSummary = $data['ratingSummary'] ?? [];
    $links = $data['links'] ?? [];
    $careTypeOptions = $data['careTypeOptions'] ?? [];
    $taskTypeOptions = $data['taskTypeOptions'] ?? [];
    $taskStatusLabels = $data['taskStatusLabels'] ?? [];
    $settlementStatusLabels = $data['settlementStatusLabels'] ?? [];
    $feeStatusLabels = $data['feeStatusLabels'] ?? [];
    $ratingStatusLabels = $data['ratingStatusLabels'] ?? [];
    $creditActionLabels = $data['creditActionLabels'] ?? [];
    $ticketTypeOptions = $data['ticketTypeOptions'] ?? [];
    $canManageFees = (bool) ($data['canManageFees'] ?? false);
    $canManageCredit = (bool) ($data['canManageCredit'] ?? false);
    $activeModuleTab = $activeModuleTab ?? 'profile';

    $status = strtolower(trim((string) ($user->status ?? 'active'))) ?: 'active';
    $statusLabel = match ($status) {
        'locked' => 'Tạm khóa',
        'inactive' => 'Tạm dừng',
        default => 'Đang hoạt động',
    };
    $statusTone = match ($status) {
        'locked' => 'danger',
        'inactive' => 'muted',
        default => 'success',
    };

    $avatar = trim((string) ($user->avatar ?? ''));
    $avatarUrl = $avatar !== ''
        ? (\Illuminate\Support\Str::startsWith($avatar, ['http://', 'https://']) ? $avatar : asset('storage/' . $avatar))
        : 'https://static.vecteezy.com/system/resources/previews/009/292/244/non_2x/default-avatar-icon-of-social-media-user-vector.jpg';
    $displayName = $displayName ?? ($user->full_name ?: ($user->name ?: ('#' . $user->id)));
    $creditPoints = is_null($specialistCreditPoints ?? null) ? null : (int) $specialistCreditPoints;
    $starLevel = $specialistStarLevel ?? ($ratingSummary['latest_star'] ?? null);
    $formatMoney = fn($value) => number_format((float) $value, 0, ',', '.') . 'đ';
    $formatDate = fn($date, $format = 'd/m/Y') => $date ? \Carbon\Carbon::parse($date)->format($format) : '-';
    $shortText = fn($value, $limit = 88) => \Illuminate\Support\Str::limit((string) ($value ?: '-'), $limit);
    $profile = $user->specialistProfile;
    $documents = [
        'Hợp đồng' => $profile?->contract_file_url,
        'CCCD mặt trước' => $profile?->id_card_front_url,
        'CCCD mặt sau' => $profile?->id_card_back_url,
    ];
    foreach (array_values((array) ($profile?->other_documents_json ?? [])) as $index => $document) {
        $documents['Tài liệu khác #' . ($index + 1)] = is_array($document) ? ($document['url'] ?? $document['path'] ?? null) : $document;
    }

    $detailStats = [
        ['label' => 'Số cửa hàng', 'value' => number_format($stores->count()), 'note' => 'đang phụ trách', 'icon' => 'fas fa-store', 'tone' => 'blue'],
        ['label' => 'Số sao', 'value' => $starLevel ? number_format((float) $starLevel, 1) : '-', 'note' => 'đánh giá gần nhất', 'icon' => 'fas fa-star', 'tone' => 'orange'],
        ['label' => 'Điểm tín dụng', 'value' => is_null($creditPoints) ? '-' : number_format($creditPoints), 'note' => '/100', 'icon' => 'fas fa-shield-halved', 'tone' => 'blue'],
        ['label' => 'Ticket hợp lệ', 'value' => number_format((int) ($ticketSummary['eligible'] ?? 0)), 'note' => 'ticket', 'icon' => 'fas fa-ticket', 'tone' => 'green'],
    ];
@endphp

<div class="sales-detail-page" data-specialist-sales-detail="1" data-active-module-tab="{{ $activeModuleTab }}"
    data-return-url="{{ $returnUrl ?? route('admin.users.sales') }}">
    <style>
        .sales-detail-page {
            --sd-bg: #f4f7fb;
            --sd-card: #ffffff;
            --sd-border: #e2e8f0;
            --sd-text: #0f172a;
            --sd-muted: #64748b;
            --sd-primary: #1f6fff;
            --sd-green: #10b981;
            --sd-orange: #f59e0b;
            --sd-red: #ef4444;
            color: var(--sd-text);
            margin: -8px -6px 0;
            padding-bottom: 28px;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* Top Navigation & Breadcrumbs */
        .sales-detail-page .sd-topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--sd-border);
        }

        .sales-detail-page .sd-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .sales-detail-page .sd-breadcrumb a {
            color: #0f172a;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .sales-detail-page .sd-breadcrumb a:hover {
            color: var(--sd-primary);
        }

        .sales-detail-page .sd-breadcrumb-separator {
            color: #cbd5e1;
            font-size: 12px;
        }

        .sales-detail-page .sd-breadcrumb-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .sales-detail-page .sd-breadcrumb-item.active {
            color: #64748b;
            font-weight: 500;
        }

        /* Action Buttons */
        .sales-detail-page .sd-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .sales-detail-page .sd-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 18px;
            border-radius: 8px;
            border: 1px solid #d8e0ec;
            background: #ffffff;
            color: #33415c;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-btn:hover {
            border-color: #b8cdf2;
            color: var(--sd-primary);
            background: #f5f9ff;
        }

        .sales-detail-page .sd-btn-primary {
            border-color: var(--sd-primary);
            background: var(--sd-primary);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(31, 111, 255, 0.15);
        }

        .sales-detail-page .sd-btn-primary:hover {
            color: #ffffff;
            background: #145ce0;
            border-color: #145ce0;
        }

        .sales-detail-page .sd-btn-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 8px;
            border: 1px solid #d8e0ec;
            background: #ffffff;
            color: #33415c;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-btn-icon:hover {
            border-color: #b8cdf2;
            color: var(--sd-primary);
            background: #f5f9ff;
        }

        /* Hero / Profile cards container */
        .sales-detail-page .sd-hero-container {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(0, 2fr);
            gap: 18px;
            margin-bottom: 18px;
        }

        .sales-detail-page .sd-profile-card {
            background: var(--sd-card);
            border: 1px solid var(--sd-border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .sales-detail-page .sd-profile-avatar {
            width: 78px;
            height: 78px;
            border-radius: 50%;
            overflow: hidden;
            background: #edf3ff;
            flex: 0 0 auto;
        }

        .sales-detail-page .sd-profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .sales-detail-page .sd-profile-info {
            min-width: 0;
            flex: 1;
        }

        .sales-detail-page .sd-profile-name-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .sales-detail-page .sd-profile-name {
            margin: 0;
            color: #0f1d3a;
            font-size: 24px;
            line-height: 1.15;
            font-weight: 800;
        }

        .sales-detail-page .sd-profile-status {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sales-detail-page .status-success {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        .sales-detail-page .status-danger {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
        }

        .sales-detail-page .status-muted {
            color: #374151;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
        }

        .sales-detail-page .sd-profile-contacts {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            color: #64748b;
            font-size: 13.5px;
        }

        .sales-detail-page .sd-profile-contact-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            color: #64748b;
        }

        .sales-detail-page .sd-profile-contact-item i {
            color: #94a3b8;
        }

        /* KPI Dashboard block on the right */
        .sales-detail-page .sd-kpis-card {
            background: var(--sd-card);
            border: 1px solid var(--sd-border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            align-items: center;
        }

        .sales-detail-page .sd-kpi-item {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .sales-detail-page .sd-kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .sales-detail-page .sd-kpi-icon.icon-blue {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .sales-detail-page .sd-kpi-icon.icon-green {
            background: #ecfdf5;
            color: #047857;
        }

        .sales-detail-page .sd-kpi-content {
            min-width: 0;
            flex: 1;
        }

        .sales-detail-page .sd-kpi-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 550;
            margin-bottom: 2px;
        }

        .sales-detail-page .sd-kpi-value {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .sales-detail-page .sd-kpi-value.val-green {
            color: #16a34a;
        }

        /* Stats Grid & Watermarks */
        .sales-detail-page .sd-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .sales-detail-page .sd-stat-card {
            position: relative;
            overflow: hidden;
            background: var(--sd-card);
            border: 1px solid var(--sd-border);
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 15px;
            min-height: 92px;
            padding: 18px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
        }

        .sales-detail-page .sd-stat-watermark {
            position: absolute;
            right: -10px;
            bottom: -10px;
            width: 76px;
            height: 76px;
            opacity: 0.04;
            color: currentColor;
            pointer-events: none;
        }

        .sales-detail-page .sd-stat-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 14px;
            font-size: 20px;
            flex: 0 0 auto;
            z-index: 1;
        }

        .sales-detail-page .tone-blue .sd-stat-icon {
            color: var(--sd-primary);
            background: #eaf2ff;
        }

        .sales-detail-page .tone-green .sd-stat-icon {
            color: var(--sd-green);
            background: #e9f9ef;
        }

        .sales-detail-page .tone-orange .sd-stat-icon {
            color: var(--sd-orange);
            background: #fff5df;
        }

        .sales-detail-page .sd-stat-content {
            flex: 1;
            z-index: 1;
        }

        .sales-detail-page .sd-stat-label {
            color: var(--sd-muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .sales-detail-page .sd-stat-value {
            color: #0f1d3a;
            font-size: 26px;
            line-height: 1.1;
            font-weight: 800;
        }

        .sales-detail-page .sd-stat-note {
            margin-top: 4px;
            color: var(--sd-muted);
            font-size: 12px;
        }

        /* Tabbar Navigation */
        .sales-detail-page .sd-tabs-shell {
            background: transparent;
            border: none;
            border-radius: 0;
            box-shadow: none;
        }

        .sales-detail-page .sd-tabbar {
            display: flex;
            align-items: center;
            overflow-x: auto;
            border: 1px solid var(--sd-border);
            border-radius: 12px;
            background: #ffffff;
            padding: 8px 16px;
            margin-bottom: 20px;
            gap: 12px;
        }

        .sales-detail-page .sd-tab {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 16px;
            border-radius: 8px;
            border: 0;
            background: transparent;
            color: #4c5c75;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-tab:hover {
            color: var(--sd-primary);
            background: #f8fafc;
        }

        .sales-detail-page .sd-tab.active {
            color: #ffffff !important;
            background: var(--sd-primary) !important;
            box-shadow: 0 4px 12px rgba(31, 111, 255, 0.2);
        }

        .sales-detail-page .sd-tabbar-settings {
            margin-left: auto;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-tabbar-settings:hover {
            border-color: #cbd5e1;
            color: #0f172a;
            background: #f8fafc;
        }

        /* Subtabbar (Row Below Main Tabbar) */
        .sales-detail-page .sd-subtabbar {
            display: flex;
            align-items: center;
            overflow-x: auto;
            border-bottom: 2px solid #e2e8f0;
            background: transparent;
            gap: 24px;
            padding: 0 8px;
            margin-bottom: 20px;
        }

        .sales-detail-page .sd-subtab {
            border: 0;
            background: transparent;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            padding: 12px 4px;
            position: relative;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-subtab:hover {
            color: var(--sd-primary);
        }

        .sales-detail-page .sd-subtab.active {
            color: var(--sd-primary);
            font-weight: 700;
        }

        .sales-detail-page .sd-subtab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--sd-primary);
            border-radius: 2px;
        }

        .sales-detail-page .sd-panel {
            display: none;
        }

        .sales-detail-page .sd-panel.active {
            display: block;
        }

        .sales-detail-page .sd-panel-body {
            padding: 0;
        }

        /* Card Structures inside Panels */
        .sales-detail-page .sd-grid-2 {
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
            gap: 20px;
        }

        .sales-detail-page .sd-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .sales-detail-page .sd-card {
            background: var(--sd-card);
            border: 1px solid var(--sd-border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            margin-top: 0;
        }

        .sales-detail-page .sd-card+.sd-card {
            margin-top: 20px;
        }

        .sales-detail-page .sd-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 14px;
        }

        .sales-detail-page .sd-card-title-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sales-detail-page .sd-card-title-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        .sales-detail-page .sd-card-title-icon.icon-blue {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .sales-detail-page .sd-card-title-icon.icon-purple {
            background: #faf5ff;
            color: #7c3aed;
        }

        .sales-detail-page .sd-card-title {
            margin: 0;
            color: #0f172a;
            font-size: 16px;
            font-weight: 700;
        }

        /* Info list grid for "Thông tin cá nhân" */
        .sales-detail-page .sd-info-list {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 40px;
        }

        .sales-detail-page .sd-info-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .sales-detail-page .sd-info-row:last-child {
            border-bottom: none;
        }

        .sales-detail-page .sd-info-row i {
            width: 20px;
            color: #94a3b8;
            margin-right: 12px;
            font-size: 14px;
            display: inline-flex;
            justify-content: center;
        }

        .sales-detail-page .sd-info-label {
            color: #64748b;
            font-size: 13.5px;
            width: 130px;
            flex-shrink: 0;
        }

        .sales-detail-page .sd-info-value {
            color: #0f172a;
            font-weight: 600;
            font-size: 13.5px;
            flex: 1;
            word-break: break-word;
        }

        /* Store Cell formatting */
        .sales-detail-page .sd-store-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sales-detail-page .sd-store-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #eff6ff;
            color: #1d4ed8;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .sales-detail-page .sd-store-details {
            min-width: 0;
        }

        .sales-detail-page .sd-store-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 13.5px;
            margin-bottom: 2px;
        }

        .sales-detail-page .sd-store-code {
            font-size: 11px;
            color: #94a3b8;
        }

        /* General Mini Metrics (in other tabs) */
        .sales-detail-page .sd-mini-metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .sales-detail-page .sd-mini {
            border: 1px solid var(--sd-border);
            border-radius: 12px;
            padding: 16px;
            background: #ffffff;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.01);
        }

        .sales-detail-page .sd-mini-value {
            margin-top: 6px;
            color: #0f172a;
            font-size: 24px;
            font-weight: 800;
        }

        /* General Tables Styling */
        .sales-detail-page .sd-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--sd-border);
            border-radius: 12px;
            background: #ffffff;
        }

        .sales-detail-page .sd-table {
            width: 100%;
            margin: 0;
            border-collapse: collapse;
        }

        .sales-detail-page .sd-table th,
        .sales-detail-page .sd-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 13.5px;
            vertical-align: middle;
        }

        .sales-detail-page .sd-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        .sales-detail-page .sd-table tr:hover td {
            background: #f8fafc;
        }

        .sales-detail-page .sd-table tr:last-child td {
            border-bottom: 0;
        }

        /* Badges */
        .sales-detail-page .sd-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 24px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sales-detail-page .sd-badge-blue {
            color: #1e40af;
            background: #dbeafe;
            border: 1px solid #bfdbfe;
        }

        .sales-detail-page .sd-badge-green {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        .sales-detail-page .sd-badge-orange {
            color: #9a3412;
            background: #ffedd5;
            border: 1px solid #fed7aa;
        }

        .sales-detail-page .sd-badge-red {
            color: #991b1b;
            background: #fee2e2;
            border: 1px solid #fecaca;
        }

        /* Table Footer Pagination */
        .sales-detail-page .sd-table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 16px;
            margin-top: 16px;
            border-top: 1px solid #f1f5f9;
        }

        .sales-detail-page .sd-table-info {
            font-size: 13px;
            color: #64748b;
        }

        .sales-detail-page .sd-pagination {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .sales-detail-page .sd-page-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            user-select: none;
        }

        .sales-detail-page .sd-page-btn:hover:not(.disabled) {
            border-color: #cbd5e1;
            color: #0f172a;
            background: #f8fafc;
        }

        .sales-detail-page .sd-page-btn.active {
            background: var(--sd-primary);
            border-color: var(--sd-primary);
            color: #ffffff;
            font-weight: 600;
        }

        .sales-detail-page .sd-page-btn.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            background: #f1f5f9;
            border-color: #e2e8f0;
        }

        /* Empty & Docs styling */
        .sales-detail-page .sd-empty {
            padding: 24px;
            border: 1px dashed #cbd5e1;
            border-radius: 12px;
            color: var(--sd-muted);
            background: #fbfcff;
            font-size: 14px;
            text-align: center;
        }

        .sales-detail-page .sd-inline-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .sales-detail-page .sd-doc-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .sales-detail-page .sd-doc {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px;
            border: 1px solid var(--sd-border);
            border-radius: 12px;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-doc:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
        }

        /* Drawers/Drawers Overlay */
        .sales-detail-page .sd-drawer-overlay {
            position: fixed;
            inset: 0;
            z-index: 1090;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(2px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .sales-detail-page .sd-drawer-overlay.is-open {
            opacity: 1;
            visibility: visible;
        }

        .sales-detail-page .sd-drawer {
            position: fixed;
            top: 0;
            right: 0;
            z-index: 1100;
            width: min(460px, 100vw);
            height: 100vh;
            border-radius: 0;
            transform: translateX(calc(100% + 64px));
            visibility: hidden;
            pointer-events: none;
            transition: transform 0.24s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.24s ease;
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border-left: 1px solid var(--sd-border);
            box-shadow: none;
        }

        .sales-detail-page .sd-drawer.is-open {
            transform: translateX(0);
            visibility: visible;
            pointer-events: auto;
            box-shadow: -10px 0 30px rgba(15, 23, 42, 0.08);
        }

        .sales-detail-page .sd-drawer-head,
        .sales-detail-page .sd-drawer-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 24px;
            border-bottom: 1px solid var(--sd-border);
        }

        .sales-detail-page .sd-drawer-foot {
            border-top: 1px solid var(--sd-border);
            border-bottom: 0;
        }

        .sales-detail-page .sd-drawer-title {
            margin: 0;
            color: #0f172a;
            font-size: 18px;
            font-weight: 700;
        }

        .sales-detail-page .sd-drawer-body {
            flex: 1 1 auto;
            overflow-y: auto;
            padding: 24px;
        }

        .sales-detail-page .sd-close {
            width: 36px;
            height: 36px;
            border: 0;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-close:hover {
            background: #e2e8f0;
            color: #0f172a;
        }

        .sales-detail-page .sd-form-grid {
            display: grid;
            gap: 18px;
        }

        .sales-detail-page .sd-form-field label {
            display: block;
            margin-bottom: 6px;
            color: #33415c;
            font-size: 13px;
            font-weight: 700;
        }

        .sales-detail-page .sd-control {
            width: 100%;
            min-height: 42px;
            border: 1px solid #d8e0ec;
            border-radius: 8px;
            padding: 9px 14px;
            color: #172033;
            font-size: 14px;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .sales-detail-page .sd-control:focus {
            outline: none;
            border-color: var(--sd-primary);
            box-shadow: 0 0 0 3px rgba(31, 111, 255, 0.1);
        }

        .sales-detail-page textarea.sd-control {
            min-height: 108px;
            resize: vertical;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1199.98px) {
            .sales-detail-page .sd-hero-container {
                grid-template-columns: 1fr;
            }

            .sales-detail-page .sd-stat-grid,
            .sales-detail-page .sd-mini-metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .sales-detail-page .sd-grid-2,
            .sales-detail-page .sd-grid-3,
            .sales-detail-page .sd-info-list {
                grid-template-columns: 1fr;
            }
            
            .sales-detail-page .sd-info-row:last-child {
                border-bottom: 1px solid #f1f5f9;
            }
        }

        @media (max-width: 767.98px) {
            .sales-detail-page .sd-topline {
                align-items: stretch;
                flex-direction: column;
                gap: 12px;
            }
            
            .sales-detail-page .sd-actions {
                justify-content: flex-start;
            }

            .sales-detail-page .sd-hero-container,
            .sales-detail-page .sd-stat-grid,
            .sales-detail-page .sd-mini-metrics,
            .sales-detail-page .sd-doc-list {
                grid-template-columns: 1fr;
            }

            .sales-detail-page .sd-profile-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            .sales-detail-page .sd-profile-name {
                font-size: 20px;
            }

            .sales-detail-page .sd-kpis-card {
                grid-template-columns: 1fr;
                gap: 14px;
            }
        }
    </style>

    <div class="sd-topline">
        <div class="sd-breadcrumb">
            <a href="{{ $returnUrl ?? route('admin.users.sales') }}" class="sd-back" data-specialist-sales-nav>
                <i class="fas fa-arrow-left"></i>
                Quay lại danh sách
            </a>
            <span class="sd-breadcrumb-separator">/</span>
            <span class="sd-breadcrumb-item">
                <i class="far fa-id-card"></i> Hồ sơ chuyên viên
            </span>
            <span class="sd-breadcrumb-separator">/</span>
            <span class="sd-breadcrumb-item active">{{ $displayName }}</span>
        </div>
        <div class="sd-actions">
            <a href="{{ route('admin.users.edit', $user->id) }}" class="sd-btn">
                <i class="fas fa-pen"></i>
                Sửa chuyên viên
            </a>
            <button type="button" class="sd-btn sd-btn-primary" data-sales-drawer-open="careLogDrawer">
                <i class="fas fa-plus"></i>
                Tạo lịch chăm sóc
            </button>
            <button type="button" class="sd-btn-icon">
                <i class="fas fa-ellipsis-v"></i>
            </button>
        </div>
    </div>

    <div class="sd-hero-container">
        <div class="sd-profile-card">
            <div class="sd-profile-avatar">
                <img src="{{ $avatarUrl }}" alt="{{ $displayName }}">
            </div>
            <div class="sd-profile-info">
                <div class="sd-profile-name-row">
                    <h1 class="sd-profile-name">{{ $displayName }}</h1>
                    <span class="sd-profile-status status-{{ $statusTone }}">{{ $statusLabel }}</span>
                </div>
                <div class="sd-profile-contacts">
                    <span class="sd-profile-contact-item"><i class="fas fa-phone"></i>{{ $user->phone ?: '-' }}</span>
                    <span class="sd-profile-contact-item"><i class="fas fa-envelope"></i>{{ $user->email ?: '-' }}</span>
                </div>
            </div>
        </div>
        <div class="sd-kpis-card">
            <div class="sd-kpi-item">
                <div class="sd-kpi-icon icon-blue">
                     <i class="far fa-id-badge"></i>
                </div>
                <div class="sd-kpi-content">
                    <div class="sd-kpi-label">ID chuyên viên</div>
                    <div class="sd-kpi-value">#{{ $user->id }}</div>
                </div>
            </div>
            <div class="sd-kpi-item">
                <div class="sd-kpi-icon icon-blue">
                     <i class="fas fa-store"></i>
                </div>
                <div class="sd-kpi-content">
                    <div class="sd-kpi-label">Phạm vi / Cửa hàng</div>
                    <div class="sd-kpi-value">{{ number_format($stores->count()) }} cửa hàng</div>
                </div>
            </div>
            <div class="sd-kpi-item">
                <div class="sd-kpi-icon icon-blue">
                     <i class="far fa-calendar"></i>
                </div>
                <div class="sd-kpi-content">
                    <div class="sd-kpi-label">Ngày tham gia</div>
                    <div class="sd-kpi-value">{{ $formatDate($user->created_at) }}</div>
                </div>
            </div>
            <div class="sd-kpi-item">
                <div class="sd-kpi-icon icon-green">
                     <i class="fas fa-circle-notch"></i>
                </div>
                <div class="sd-kpi-content">
                    <div class="sd-kpi-label">Trạng thái</div>
                    <div class="sd-kpi-value val-green">{{ $statusLabel }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="sd-stat-grid">
        @foreach($detailStats as $index => $stat)
            <div class="sd-stat-card tone-{{ $stat['tone'] }}">
                <!-- Watermarks -->
                @if($index === 0)
                    <svg class="sd-stat-watermark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M13 21h-2v-9h2v9zm4 0h-2V7h2v14zm4 0h-2V3h2v18zM9 21H7v-6h2v6zm-4 0H3v-3h2v3z"/></svg>
                @elseif($index === 1)
                    <svg class="sd-stat-watermark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                @elseif($index === 2)
                    <svg class="sd-stat-watermark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.18l7 3.12v4.7c0 4.29-2.94 8.29-7 9.4-4.06-1.11-7-5.11-7-9.4v-4.7l7-3.12z"/></svg>
                @elseif($index === 3)
                    <svg class="sd-stat-watermark" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M22 10V6c0-1.11-.9-2-2-2H4c-1.1 0-2 .89-2 2v4c1.1 0 1.99.9 1.99 2S3.1 14 2 14v4c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2v-4c-1.1 0-2-.9-2-2s.9-2 2-2zm-2 1.42c-1.22.84-2 2.27-2 3.88s.78 3.04 2 3.88H4c1.22-.84 2-2.27 2-3.88s-.78-3.04-2-3.88V7.88c1.22.84 2 2.27 2 3.88s-.78 3.04-2 3.88h16z"/></svg>
                @endif

                <div class="sd-stat-icon"><i class="{{ $stat['icon'] }}"></i></div>
                <div class="sd-stat-content">
                    <div class="sd-stat-label">{{ $stat['label'] }}</div>
                    <div class="sd-stat-value">{{ $stat['value'] }}</div>
                    <div class="sd-stat-note">{{ $stat['note'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <section class="sd-tabs-shell">
        <div class="sd-tabbar">
            @foreach($specialistModuleTabs ?? [] as $tab)
                @if(($tab['key'] ?? null) !== 'activity')
                    <button type="button" class="sd-tab {{ $activeModuleTab === $tab['key'] ? 'active' : '' }}"
                        data-sales-tab-button="primary" data-sales-tab-target="{{ $tab['key'] }}">
                        <i class="{{ $tab['icon'] }}"></i>
                        {{ $tab['label'] }}
                    </button>
                @endif
            @endforeach
            <button type="button" class="sd-tabbar-settings">
                <i class="fas fa-cog"></i>
            </button>
        </div>

        <div class="sd-panel {{ $activeModuleTab === 'profile' ? 'active' : '' }}" data-sales-tab-panel="primary"
            data-sales-tab-key="profile">
            <div class="sd-subtabbar">
                <button type="button" class="sd-subtab active" data-sales-tab-button="profile"
                    data-sales-tab-target="profile-info">Thông tin cá nhân</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="profile"
                    data-sales-tab-target="profile-stores">Cửa hàng phụ trách</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="profile"
                    data-sales-tab-target="profile-assignments">Lịch sử phân công</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="profile"
                    data-sales-tab-target="profile-documents">Tài liệu</button>
            </div>
            <div class="sd-panel-body">
                <div class="sd-panel active" data-sales-tab-panel="profile" data-sales-tab-key="profile-info">
                    <div class="sd-grid-2">
                        <div class="sd-card">
                            <div class="sd-card-head">
                                <div class="sd-card-title-wrap">
                                    <div class="sd-card-title-icon icon-blue">
                                         <i class="far fa-user"></i>
                                    </div>
                                    <h2 class="sd-card-title">Thông tin cá nhân</h2>
                                </div>
                                <a href="{{ route('admin.users.edit', $user->id) }}" class="sd-btn"><i
                                        class="fas fa-pen"></i>Sửa</a>
                            </div>
                            <div class="sd-info-list">
                                <div class="sd-info-column">
                                    <div class="sd-info-row">
                                        <i class="far fa-user"></i>
                                        <span class="sd-info-label">Họ và tên</span>
                                        <span class="sd-info-value">{{ $displayName }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="far fa-calendar"></i>
                                        <span class="sd-info-label">Ngày sinh</span>
                                        <span class="sd-info-value">{{ $user->birthday ? $user->birthday->format('d/m/Y') : '-' }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="far fa-id-badge"></i>
                                        <span class="sd-info-label">Mã chuyên viên</span>
                                        <span class="sd-info-value">{{ $profile?->specialist_code ?: '-' }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="fas fa-phone"></i>
                                        <span class="sd-info-label">Số điện thoại</span>
                                        <span class="sd-info-value">{{ $user->phone ?: '-' }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="fas fa-venus-mars"></i>
                                        <span class="sd-info-label">Giới tính</span>
                                        <span class="sd-info-value">{{ $user->gender === 'male' ? 'Nam' : ($user->gender === 'female' ? 'Nữ' : ($user->gender === 'other' ? 'Khác' : ($user->gender ?: '-'))) }}</span>
                                    </div>
                                </div>
                                <div class="sd-info-column">
                                    <div class="sd-info-row">
                                        <i class="far fa-envelope"></i>
                                        <span class="sd-info-label">Email</span>
                                        <span class="sd-info-value">{{ $user->email ?: '-' }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="far fa-id-card"></i>
                                        <span class="sd-info-label">CMND/CCCD</span>
                                        <span class="sd-info-value">{{ $user->personal_identity_number ?: '-' }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                        <span class="sd-info-label">Mã số thuế</span>
                                        <span class="sd-info-value">{{ $profile?->tax_code ?: '-' }}</span>
                                    </div>
                                    <div class="sd-info-row">
                                        <i class="fas fa-location-dot"></i>
                                        <span class="sd-info-label">Địa chỉ</span>
                                        <span class="sd-info-value">{{ $user->address ?: ($user->gps_address ?: '-') }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="sd-card">
                            <div class="sd-card-head">
                                <div class="sd-card-title-wrap">
                                    <div class="sd-card-title-icon icon-purple">
                                         <i class="far fa-file-alt"></i>
                                    </div>
                                    <h2 class="sd-card-title">Phân công hiện tại</h2>
                                </div>
                                <a href="{{ route('admin.stores.index', ['sale_id' => $user->id]) }}" class="sd-btn"><i
                                        class="fas fa-store"></i>Xem cửa hàng</a>
                            </div>
                            @if($stores->isEmpty())
                                <div class="sd-empty">Chuyên viên này chưa được phân công cửa hàng nào.</div>
                            @else
                                <div class="sd-table-wrap">
                                    <table class="sd-table">
                                        <thead>
                                            <tr>
                                                <th>Cửa hàng</th>
                                                <th>Địa chỉ</th>
                                                <th>Trạng thái</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($stores->take(5) as $store)
                                                <tr>
                                                    <td>
                                                        <div class="sd-store-cell">
                                                            <div class="sd-store-icon">
                                                                <i class="fas fa-store"></i>
                                                            </div>
                                                            <div class="sd-store-details">
                                                                <div class="sd-store-name">{{ $store->name ?: $store->store_code }}</div>
                                                                <div class="sd-store-code">{{ $store->store_code ?: $store->id }}</div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>{{ $shortText($store->address, 56) }}</td>
                                                    <td>
                                                        <span class="sd-badge {{ strtolower($store->status) === 'inactive' ? 'sd-badge-red' : 'sd-badge-green' }}">
                                                            {{ strtolower($store->status) === 'active' || !$store->status ? 'Đang hoạt động' : (strtolower($store->status) === 'inactive' ? 'Không hoạt động' : $store->status) }}
                                                        </span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="sd-table-footer">
                                    <div class="sd-table-info">
                                        Hiển thị 1 - {{ min(5, $stores->count()) }} của {{ $stores->count() }} cửa hàng
                                    </div>
                                    <div class="sd-pagination">
                                        <span class="sd-page-btn disabled"><i class="fas fa-chevron-left"></i></span>
                                        <span class="sd-page-btn active">1</span>
                                        <span class="sd-page-btn disabled"><i class="fas fa-chevron-right"></i></span>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="profile" data-sales-tab-key="profile-stores">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Cửa hàng phụ trách</h2>
                            <button type="button" class="sd-btn" data-sales-drawer-open="taskDrawer"><i
                                    class="fas fa-list-check"></i>Giao nhiệm vụ</button>
                        </div>
                        @if($stores->isEmpty())
                            <div class="sd-empty">Chưa có cửa hàng phụ trách.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Cửa hàng</th>
                                            <th>Liên hệ</th>
                                            <th>Địa chỉ</th>
                                            <th>Đánh giá</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($stores as $store)
                                            <tr>
                                                <td><strong>{{ $store->name ?: $store->store_code }}</strong>
                                                    <div class="sd-muted small">{{ $store->store_code ?: $store->id }}</div>
                                                </td>
                                                <td>{{ $store->phone ?: '-' }}</td>
                                                <td>{{ $shortText($store->address, 72) }}</td>
                                                <td>{{ $store->rating_avg ? number_format((float) $store->rating_avg, 1) . ' sao' : '-' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="profile" data-sales-tab-key="profile-assignments">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Lịch sử phân công</h2>
                        </div>
                        @if($assignmentHistory->isEmpty())
                            <div class="sd-empty">Chưa có lịch sử phân công.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Cửa hàng</th>
                                            <th>Từ ngày</th>
                                            <th>Đến ngày</th>
                                            <th>Người phân công</th>
                                            <th>Ghi chú</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($assignmentHistory as $assignment)
                                            <tr>
                                                <td><strong>{{ $assignment->store_name ?: $assignment->store_code ?: $assignment->store_id }}</strong>
                                                </td>
                                                <td>{{ $formatDate($assignment->assigned_from, 'd/m/Y H:i') }}</td>
                                                <td>{{ $assignment->assigned_to ? $formatDate($assignment->assigned_to, 'd/m/Y H:i') : 'Hiện tại' }}
                                                </td>
                                                <td>{{ $assignment->actor_full_name ?: ($assignment->actor_name ?: '-') }}</td>
                                                <td>{{ $shortText($assignment->notes, 70) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="profile" data-sales-tab-key="profile-documents">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Tài liệu / Ghi chú</h2>
                            <a href="{{ route('admin.users.edit', $user->id) }}" class="sd-btn"><i
                                    class="fas fa-upload"></i>Cập nhật hồ sơ</a>
                        </div>
                        <div class="sd-doc-list">
                            @foreach($documents as $label => $url)
                                <div class="sd-doc">
                                    <div>
                                        <strong>{{ $label }}</strong>
                                        <div class="sd-muted small">{{ $url ? 'Đã có tài liệu' : 'Chưa tải lên' }}</div>
                                    </div>
                                    @if($url)
                                        <a href="{{ $url }}" target="_blank" class="sd-btn"><i class="fas fa-eye"></i>Xem</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="sd-panel {{ $activeModuleTab === 'store' ? 'active' : '' }}" data-sales-tab-panel="primary"
            data-sales-tab-key="store">
            <div class="sd-subtabbar">
                <button type="button" class="sd-subtab active" data-sales-tab-button="store"
                    data-sales-tab-target="store-care">Chăm sóc cửa hàng</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="store"
                    data-sales-tab-target="store-tasks">Nhiệm vụ</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="store"
                    data-sales-tab-target="store-log">Nhật ký chăm sóc</button>
            </div>
            <div class="sd-panel-body">
                <div class="sd-panel active" data-sales-tab-panel="store" data-sales-tab-key="store-care">
                    <div class="sd-mini-metrics">
                        <div class="sd-mini">
                            <div class="sd-meta-label">Tổng số cửa hàng phụ trách</div>
                            <div class="sd-mini-value">{{ number_format($stores->count()) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Đã chăm sóc tháng này</div>
                            <div class="sd-mini-value text-success">
                                {{ number_format((int) ($careSummary['month_logs'] ?? 0)) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Hẹn chăm sóc tiếp</div>
                            <div class="sd-mini-value text-warning">
                                {{ number_format((int) ($careSummary['followups'] ?? 0)) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Cửa hàng có vấn đề</div>
                            <div class="sd-mini-value text-danger">
                                {{ number_format((int) ($careSummary['problems'] ?? 0)) }}</div>
                        </div>
                    </div>
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Chăm sóc cửa hàng</h2>
                            <div class="sd-inline-actions">
                                <button type="button" class="sd-btn sd-btn-primary"
                                    data-sales-drawer-open="careLogDrawer"><i class="fas fa-plus"></i>Tạo log</button>
                                <a href="{{ $links['care'] ?? '#' }}" class="sd-btn"><i
                                        class="fas fa-up-right-from-square"></i>Mở module</a>
                            </div>
                        </div>
                        @include('admin.user.partials.sales_show_tables_care', ['careLogs' => $careLogs, 'shortText' => $shortText, 'formatDate' => $formatDate])
                    </div>
                </div>
                <div class="sd-panel" data-sales-tab-panel="store" data-sales-tab-key="store-tasks">
                    <div class="sd-mini-metrics">
                        <div class="sd-mini">
                            <div class="sd-meta-label">Chưa làm</div>
                            <div class="sd-mini-value">{{ number_format((int) ($taskSummary['pending'] ?? 0)) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Đang làm</div>
                            <div class="sd-mini-value text-primary">
                                {{ number_format((int) ($taskSummary['in_progress'] ?? 0)) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Quá hạn</div>
                            <div class="sd-mini-value text-danger">
                                {{ number_format((int) ($taskSummary['overdue'] ?? 0)) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Hoàn tất</div>
                            <div class="sd-mini-value text-success">
                                {{ number_format((int) ($taskSummary['completed'] ?? 0)) }}</div>
                        </div>
                    </div>
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Nhiệm vụ</h2>
                            <button type="button" class="sd-btn sd-btn-primary" data-sales-drawer-open="taskDrawer"><i
                                    class="fas fa-plus"></i>Giao nhiệm vụ</button>
                        </div>
                        @if($tasks->isEmpty())
                            <div class="sd-empty">Chưa có nhiệm vụ cho chuyên viên này.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Nhiệm vụ</th>
                                            <th>Cửa hàng</th>
                                            <th>Hạn xử lý</th>
                                            <th>Trạng thái</th>
                                            <th>Kết quả</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($tasks as $task)
                                            <tr>
                                                <td><strong>{{ $task->title }}</strong>
                                                    <div class="sd-muted small">
                                                        {{ $taskTypeOptions[$task->task_type] ?? $task->task_type }}</div>
                                                </td>
                                                <td>{{ $task->store?->name ?: '-' }}</td>
                                                <td>{{ $formatDate($task->deadline ?: $task->due_at, 'd/m/Y H:i') }}</td>
                                                <td><span
                                                        class="sd-badge {{ $task->status === 'completed' ? 'sd-badge-green' : ($task->status === 'overdue' ? 'sd-badge-red' : 'sd-badge-orange') }}">{{ $taskStatusLabels[$task->status] ?? $task->status }}</span>
                                                </td>
                                                <td>{{ $shortText($task->result_note ?: $task->description, 72) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
                <div class="sd-panel" data-sales-tab-panel="store" data-sales-tab-key="store-log">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Nhật ký chăm sóc gần đây</h2>
                        </div>
                        @include('admin.user.partials.sales_show_tables_care', ['careLogs' => $careLogs, 'shortText' => $shortText, 'formatDate' => $formatDate])
                    </div>
                </div>
            </div>
        </div>

        <div class="sd-panel {{ $activeModuleTab === 'ticket' ? 'active' : '' }}" data-sales-tab-panel="primary"
            data-sales-tab-key="ticket">
            <div class="sd-subtabbar">
                <button type="button" class="sd-subtab active" data-sales-tab-button="ticket"
                    data-sales-tab-target="ticket-list">Lucky Ticket</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="ticket"
                    data-sales-tab-target="ticket-income">Thu nhập</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="ticket"
                    data-sales-tab-target="ticket-settlement">Đối soát</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="ticket"
                    data-sales-tab-target="ticket-fee">Cấu hình phí</button>
            </div>
            <div class="sd-panel-body">
                <div class="sd-mini-metrics">
                    <div class="sd-mini">
                        <div class="sd-meta-label">Tổng ticket</div>
                        <div class="sd-mini-value">{{ number_format((int) ($ticketSummary['total'] ?? 0)) }}</div>
                    </div>
                    <div class="sd-mini">
                        <div class="sd-meta-label">Ticket hợp lệ</div>
                        <div class="sd-mini-value text-success">
                            {{ number_format((int) ($ticketSummary['eligible'] ?? 0)) }}</div>
                    </div>
                    <div class="sd-mini">
                        <div class="sd-meta-label">Ticket chờ đối soát</div>
                        <div class="sd-mini-value text-danger">
                            {{ number_format((int) ($ticketSummary['pending'] ?? 0)) }}</div>
                    </div>
                    <div class="sd-mini">
                        <div class="sd-meta-label">Thu nhập ghi nhận</div>
                        <div class="sd-mini-value">{{ $formatMoney($ticketSummary['income'] ?? 0) }}</div>
                    </div>
                </div>

                <div class="sd-panel active" data-sales-tab-panel="ticket" data-sales-tab-key="ticket-list">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Lucky Ticket chuyên viên</h2>
                            <a href="{{ $links['tickets'] ?? '#' }}" class="sd-btn"><i
                                    class="fas fa-up-right-from-square"></i>Mở module</a>
                        </div>
                        @if($ticketEarnings->isEmpty())
                            <div class="sd-empty">Chưa có ticket phát sinh cho chuyên viên này.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Ngày phát sinh</th>
                                            <th>Cửa hàng</th>
                                            <th>Giá trị</th>
                                            <th>Phí</th>
                                            <th>Thu nhập</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($ticketEarnings as $earning)
                                            <tr>
                                                <td>{{ $formatDate($earning->earned_at ?: $earning->generated_at ?: $earning->created_at, 'd/m/Y H:i') }}
                                                </td>
                                                <td>{{ $earning->store?->name ?: '-' }}</td>
                                                <td>{{ $formatMoney($earning->ticket_value_amount ?? 0) }}</td>
                                                <td>{{ $formatMoney($earning->fee_per_ticket ?? 0) }}</td>
                                                <td><strong>{{ $formatMoney($earning->earning_amount ?? 0) }}</strong></td>
                                                <td><span
                                                        class="sd-badge {{ $earning->is_eligible === false ? 'sd-badge-red' : 'sd-badge-green' }}">{{ $earning->earning_status ?: $earning->status ?: 'Hợp lệ' }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="ticket" data-sales-tab-key="ticket-income">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Thu nhập chuyên viên</h2>
                            <a href="{{ $links['income'] ?? '#' }}" class="sd-btn"><i
                                    class="fas fa-up-right-from-square"></i>Mở thu nhập</a>
                        </div>
                        <div class="sd-info-grid">
                            <div>
                                <div class="sd-meta-label">Thu nhập ticket</div>
                                <div class="sd-meta-value">{{ $formatMoney($ticketSummary['income'] ?? 0) }}</div>
                            </div>
                            <div>
                                <div class="sd-meta-label">Số ticket hợp lệ</div>
                                <div class="sd-meta-value">{{ number_format((int) ($ticketSummary['eligible'] ?? 0)) }}
                                </div>
                            </div>
                            <div>
                                <div class="sd-meta-label">Tổng kỳ đối soát</div>
                                <div class="sd-meta-value">{{ number_format((int) ($settlementSummary['total'] ?? 0)) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="ticket" data-sales-tab-key="ticket-settlement">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Đối soát chuyên viên</h2>
                            <a href="{{ $links['settlements'] ?? '#' }}" class="sd-btn"><i
                                    class="fas fa-up-right-from-square"></i>Mở đối soát</a>
                        </div>
                        @if($settlements->isEmpty())
                            <div class="sd-empty">Chưa có kỳ đối soát.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Kỳ</th>
                                            <th>Ticket hợp lệ</th>
                                            <th>Tổng tiền</th>
                                            <th>Điều chỉnh</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($settlements as $settlement)
                                            <tr>
                                                <td>{{ $formatDate($settlement->period_from) }} -
                                                    {{ $formatDate($settlement->period_to) }}</td>
                                                <td>{{ number_format((int) ($settlement->total_eligible_tickets ?? 0)) }}</td>
                                                <td><strong>{{ $formatMoney($settlement->final_income ?? $settlement->amount ?? 0) }}</strong>
                                                </td>
                                                <td>{{ $formatMoney($settlement->adjustment_amount ?? 0) }}</td>
                                                <td><span
                                                        class="sd-badge {{ $settlement->status === 'PAID' ? 'sd-badge-green' : ($settlement->status === 'CANCELLED' ? 'sd-badge-red' : 'sd-badge-orange') }}">{{ $settlementStatusLabels[$settlement->status] ?? $settlement->status }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="ticket" data-sales-tab-key="ticket-fee">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Cấu hình phí chuyên viên</h2>
                            <div class="sd-inline-actions">
                                @if($canManageFees)
                                    <button type="button" class="sd-btn sd-btn-primary"
                                        data-sales-drawer-open="feeDrawer"><i class="fas fa-sliders"></i>Cấu hình
                                        phí</button>
                                @endif
                                <a href="{{ $links['feeConfigs'] ?? '#' }}" class="sd-btn"><i
                                        class="fas fa-up-right-from-square"></i>Mở danh sách</a>
                            </div>
                        </div>
                        @if($feeConfigs->isEmpty())
                            <div class="sd-empty">Chưa có cấu hình phí riêng cho chuyên viên này.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Cấu hình</th>
                                            <th>Loại ticket</th>
                                            <th>Phí / ticket</th>
                                            <th>Hiệu lực</th>
                                            <th>Trạng thái</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($feeConfigs as $config)
                                            <tr>
                                                <td><strong>{{ $config->config_name }}</strong></td>
                                                <td>{{ $config->ticket_type_code ?: 'ALL' }}</td>
                                                <td>{{ $formatMoney($config->fee_per_ticket ?? 0) }}</td>
                                                <td>{{ $formatDate($config->effective_from) }} -
                                                    {{ $config->effective_to ? $formatDate($config->effective_to) : 'Không giới hạn' }}
                                                </td>
                                                <td><span
                                                        class="sd-badge {{ $config->status === 'ACTIVE' ? 'sd-badge-green' : ($config->status === 'CANCELLED' ? 'sd-badge-red' : 'sd-badge-orange') }}">{{ $feeStatusLabels[$config->status] ?? $config->status }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="sd-panel {{ $activeModuleTab === 'review' ? 'active' : '' }}" data-sales-tab-panel="primary"
            data-sales-tab-key="review">
            <div class="sd-subtabbar">
                <button type="button" class="sd-subtab active" data-sales-tab-button="review"
                    data-sales-tab-target="review-rating">Đánh giá sao</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="review"
                    data-sales-tab-target="review-credit">Điểm tín dụng</button>
                <button type="button" class="sd-subtab" data-sales-tab-button="review"
                    data-sales-tab-target="review-report">Báo cáo chuyên viên</button>
            </div>
            <div class="sd-panel-body">
                <div class="sd-panel active" data-sales-tab-panel="review" data-sales-tab-key="review-rating">
                    <div class="sd-mini-metrics">
                        <div class="sd-mini">
                            <div class="sd-meta-label">Số sao hiện tại</div>
                            <div class="sd-mini-value text-warning">
                                {{ $ratingSummary['latest_star'] ? number_format((float) $ratingSummary['latest_star'], 1) : '-' }}
                            </div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Điểm gần nhất</div>
                            <div class="sd-mini-value">
                                {{ is_null($ratingSummary['latest_score'] ?? null) ? '-' : number_format((float) $ratingSummary['latest_score'], 1) }}
                            </div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Đã duyệt</div>
                            <div class="sd-mini-value text-success">
                                {{ number_format((int) ($ratingSummary['approved'] ?? 0)) }}</div>
                        </div>
                        <div class="sd-mini">
                            <div class="sd-meta-label">Tổng đánh giá</div>
                            <div class="sd-mini-value">{{ number_format((int) ($ratingSummary['total'] ?? 0)) }}</div>
                        </div>
                    </div>
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Lịch sử đánh giá</h2>
                            <a href="{{ $links['ratings'] ?? '#' }}" class="sd-btn"><i
                                    class="fas fa-up-right-from-square"></i>Mở đánh giá</a>
                        </div>
                        @if($ratings->isEmpty())
                            <div class="sd-empty">Chưa có đánh giá sao.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Kỳ đánh giá</th>
                                            <th>Số sao</th>
                                            <th>Điểm</th>
                                            <th>Trạng thái</th>
                                            <th>Ghi chú</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($ratings as $rating)
                                            <tr>
                                                <td>{{ $formatDate($rating->period_from) }} -
                                                    {{ $formatDate($rating->period_to) }}</td>
                                                <td class="text-warning">
                                                    <strong>{{ $rating->star_level ?? $rating->stars ?? '-' }}</strong></td>
                                                <td>{{ $rating->total_score ?? $rating->score ?? '-' }}</td>
                                                <td><span
                                                        class="sd-badge {{ in_array($rating->status, ['APPROVED', 'NOTIFIED'], true) ? 'sd-badge-green' : 'sd-badge-orange' }}">{{ $ratingStatusLabels[$rating->status] ?? $rating->status }}</span>
                                                </td>
                                                <td>{{ $shortText($rating->notes ?: $rating->adjustment_reason, 72) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="review" data-sales-tab-key="review-credit">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Điểm tín dụng</h2>
                            <div class="sd-inline-actions">
                                @if($canManageCredit)
                                    <button type="button" class="sd-btn sd-btn-primary"
                                        data-sales-drawer-open="creditDrawer"><i class="fas fa-plus"></i>Cập nhật
                                        điểm</button>
                                @endif
                                <a href="{{ $links['credit'] ?? '#' }}" class="sd-btn"><i
                                        class="fas fa-up-right-from-square"></i>Mở chi tiết</a>
                            </div>
                        </div>
                        <div class="sd-mini-metrics">
                            <div class="sd-mini">
                                <div class="sd-meta-label">Điểm hiện tại</div>
                                <div class="sd-mini-value">
                                    {{ is_null($creditPoints) ? '-' : number_format($creditPoints) }}</div>
                            </div>
                            <div class="sd-mini">
                                <div class="sd-meta-label">Xếp loại</div>
                                <div class="sd-mini-value">{{ $profile?->credit_rank_label ?: '-' }}</div>
                            </div>
                            <div class="sd-mini">
                                <div class="sd-meta-label">Số log</div>
                                <div class="sd-mini-value">{{ number_format($creditLogs->count()) }}</div>
                            </div>
                            <div class="sd-mini">
                                <div class="sd-meta-label">Trạng thái</div>
                                <div class="sd-mini-value text-success">{{ $statusLabel }}</div>
                            </div>
                        </div>
                        @if($creditLogs->isEmpty())
                            <div class="sd-empty">Chưa có lịch sử điểm tín dụng.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Thời gian</th>
                                            <th>Hành động</th>
                                            <th>Điểm</th>
                                            <th>Trước / Sau</th>
                                            <th>Lý do</th>
                                            <th>Người cập nhật</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($creditLogs as $log)
                                            <tr>
                                                <td>{{ $formatDate($log->created_at, 'd/m/Y H:i') }}</td>
                                                <td>{{ $creditActionLabels[$log->action_type] ?? $log->action_type }}</td>
                                                <td
                                                    class="{{ (int) $log->points_change >= 0 ? 'text-success' : 'text-danger' }}">
                                                    <strong>{{ (int) $log->points_change >= 0 ? '+' : '' }}{{ number_format((int) $log->points_change) }}</strong>
                                                </td>
                                                <td>{{ $log->points_before }} / {{ $log->points_after }}</td>
                                                <td>{{ $shortText($log->reason, 72) }}</td>
                                                <td>{{ $log->adminUser?->full_name ?: ($log->adminUser?->name ?: '-') }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="sd-panel" data-sales-tab-panel="review" data-sales-tab-key="review-report">
                    <div class="sd-grid-3">
                        <div class="sd-card">
                            <h2 class="sd-card-title">Hiệu suất</h2>
                            <div class="sd-info-grid mt-3">
                                <div>
                                    <div class="sd-meta-label">Cửa hàng</div>
                                    <div class="sd-meta-value">{{ number_format($stores->count()) }}</div>
                                </div>
                                <div>
                                    <div class="sd-meta-label">Log chăm sóc</div>
                                    <div class="sd-meta-value">
                                        {{ number_format((int) ($careSummary['total_logs'] ?? 0)) }}</div>
                                </div>
                                <div>
                                    <div class="sd-meta-label">Nhiệm vụ hoàn tất</div>
                                    <div class="sd-meta-value">
                                        {{ number_format((int) ($taskSummary['completed'] ?? 0)) }}</div>
                                </div>
                            </div>
                        </div>
                        <div class="sd-card">
                            <h2 class="sd-card-title">Doanh thu hỗ trợ</h2>
                            <div class="sd-info-grid mt-3">
                                <div>
                                    <div class="sd-meta-label">Thu nhập ticket</div>
                                    <div class="sd-meta-value">{{ $formatMoney($ticketSummary['income'] ?? 0) }}</div>
                                </div>
                                <div>
                                    <div class="sd-meta-label">Đối soát đã trả</div>
                                    <div class="sd-meta-value">
                                        {{ number_format((int) ($settlementSummary['paid'] ?? 0)) }}</div>
                                </div>
                                <div>
                                    <div class="sd-meta-label">Tổng đối soát</div>
                                    <div class="sd-meta-value">{{ $formatMoney($settlementSummary['amount'] ?? 0) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="sd-card">
                            <h2 class="sd-card-title">Báo cáo chuyên viên</h2>
                            <p class="sd-muted mb-3">Mở báo cáo tổng hợp đã lọc theo chuyên viên này.</p>
                            <a href="{{ $links['reports'] ?? '#' }}" class="sd-btn sd-btn-primary"><i
                                    class="fas fa-chart-line"></i>Mở báo cáo</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- <div class="sd-panel {{ $activeModuleTab === 'activity' ? 'active' : '' }}" data-sales-tab-panel="primary"
            data-sales-tab-key="activity">
            <div class="sd-panel-body">
                <div class="sd-grid-2">
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Lịch sử hoạt động</h2>
                        </div>
                        @if($auditLogs->isEmpty())
                            <div class="sd-empty">Chưa có lịch sử hoạt động chuyên viên.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Thời gian</th>
                                            <th>Hành động</th>
                                            <th>Người thao tác</th>
                                            <th>Dữ liệu</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($auditLogs as $log)
                                            <tr>
                                                <td>{{ $formatDate($log->created_at, 'd/m/Y H:i') }}</td>
                                                <td><strong>{{ $log->action }}</strong></td>
                                                <td>{{ $log->actor?->full_name ?: ($log->actor?->name ?: '-') }}</td>
                                                <td>{{ $shortText(is_array($log->metadata) ? json_encode($log->metadata, JSON_UNESCAPED_UNICODE) : $log->metadata, 80) }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                    <div class="sd-card">
                        <div class="sd-card-head">
                            <h2 class="sd-card-title">Ledger Ticket</h2>
                        </div>
                        @if($ledgerEntries->isEmpty())
                            <div class="sd-empty">Chưa có dữ liệu ledger Ticket.</div>
                        @else
                            <div class="sd-table-wrap">
                                <table class="sd-table">
                                    <thead>
                                        <tr>
                                            <th>Thời gian</th>
                                            <th>Biến động</th>
                                            <th>Sau</th>
                                            <th>Mã ticket</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($ledgerEntries as $entry)
                                            <tr>
                                                <td>{{ $formatDate($entry->created_at, 'd/m/Y H:i') }}</td>
                                                <td
                                                    class="{{ (int) $entry->amount_change >= 0 ? 'text-success' : 'text-danger' }}">
                                                    <strong>{{ (int) $entry->amount_change >= 0 ? '+' : '' }}{{ number_format((int) $entry->amount_change) }}</strong>
                                                </td>
                                                <td>{{ is_null($entry->balance_after) ? '-' : number_format((int) $entry->balance_after) }}
                                                </td>
                                                <td>{{ optional($entry->ticket)->ticket_code ?? '-' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div> --}}
    </section>

    <div class="sd-drawer-overlay" data-sales-drawer-close></div>

    <aside class="sd-drawer" id="careLogDrawer" aria-hidden="true">
        <form method="POST" action="{{ route('admin.specialist-care.logs.store') }}" enctype="multipart/form-data"
            class="h-100 d-flex flex-column">
            @csrf
            <input type="hidden" name="specialist_id" value="{{ $user->id }}">
            <div class="sd-drawer-head">
                <h2 class="sd-drawer-title">Tạo log chăm sóc</h2>
                <button type="button" class="sd-close" data-sales-drawer-close><i class="fas fa-xmark"></i></button>
            </div>
            <div class="sd-drawer-body">
                <div class="sd-form-grid">
                    <div class="sd-form-field">
                        <label>Cửa hàng *</label>
                        <select name="store_id" class="sd-control" required>
                            <option value="">Chọn cửa hàng</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name ?: $store->store_code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sd-form-field">
                        <label>Hình thức chăm sóc *</label>
                        <select name="care_type" class="sd-control" required>
                            @foreach($careTypeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sd-form-field"><label>Hẹn chăm sóc tiếp</label><input type="datetime-local"
                            name="next_follow_up_at" class="sd-control"></div>
                    <div class="sd-form-field"><label>Nội dung chăm sóc *</label><textarea name="care_content"
                            class="sd-control" required maxlength="5000"
                            placeholder="Nhập nội dung chăm sóc..."></textarea></div>
                    <div class="sd-form-field"><label>Vấn đề phát hiện</label><textarea name="problem_detected"
                            class="sd-control" maxlength="5000"></textarea></div>
                    <div class="sd-form-field"><label>Đề xuất xử lý</label><textarea name="proposed_solution"
                            class="sd-control" maxlength="5000"></textarea></div>
                    <div class="sd-form-field"><label>Kết quả / ghi chú</label><textarea name="result_note"
                            class="sd-control" maxlength="5000"></textarea></div>
                    <div class="sd-form-field"><label>Tệp đính kèm</label><input type="file" name="attachment"
                            class="sd-control"></div>
                </div>
            </div>
            <div class="sd-drawer-foot">
                <button type="button" class="sd-btn" data-sales-drawer-close>Hủy</button>
                <button type="submit" class="sd-btn sd-btn-primary">Lưu log chăm sóc</button>
            </div>
        </form>
    </aside>

    <aside class="sd-drawer" id="taskDrawer" aria-hidden="true">
        <form method="POST" action="{{ route('admin.specialist-care.tasks.store') }}" class="h-100 d-flex flex-column">
            @csrf
            <input type="hidden" name="specialist_id" value="{{ $user->id }}">
            <div class="sd-drawer-head">
                <h2 class="sd-drawer-title">Giao nhiệm vụ</h2>
                <button type="button" class="sd-close" data-sales-drawer-close><i class="fas fa-xmark"></i></button>
            </div>
            <div class="sd-drawer-body">
                <div class="sd-form-grid">
                    <div class="sd-form-field">
                        <label>Cửa hàng</label>
                        <select name="store_id" class="sd-control">
                            <option value="">Không gắn cửa hàng</option>
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name ?: $store->store_code }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sd-form-field">
                        <label>Loại nhiệm vụ *</label>
                        <select name="task_type" class="sd-control" required>
                            @foreach($taskTypeOptions as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sd-form-field"><label>Tiêu đề *</label><input name="title" class="sd-control" required
                            maxlength="191"></div>
                    <div class="sd-form-field"><label>Hạn xử lý *</label><input type="datetime-local" name="deadline"
                            class="sd-control" required></div>
                    <div class="sd-form-field"><label>Mô tả</label><textarea name="description" class="sd-control"
                            maxlength="5000"></textarea></div>
                </div>
            </div>
            <div class="sd-drawer-foot">
                <button type="button" class="sd-btn" data-sales-drawer-close>Hủy</button>
                <button type="submit" class="sd-btn sd-btn-primary">Giao nhiệm vụ</button>
            </div>
        </form>
    </aside>

    @if($canManageFees)
        <aside class="sd-drawer" id="feeDrawer" aria-hidden="true">
            <form method="POST" action="{{ route('admin.specialist-fee-configs.store') }}" class="h-100 d-flex flex-column">
                @csrf
                <input type="hidden" name="apply_scope" value="specialist">
                <input type="hidden" name="specialist_id" value="{{ $user->id }}">
                <div class="sd-drawer-head">
                    <h2 class="sd-drawer-title">Cấu hình phí chuyên viên</h2>
                    <button type="button" class="sd-close" data-sales-drawer-close><i class="fas fa-xmark"></i></button>
                </div>
                <div class="sd-drawer-body">
                    <div class="sd-form-grid">
                        <div class="sd-form-field"><label>Tên cấu hình *</label><input name="config_name" class="sd-control"
                                required value="Phí riêng - {{ $displayName }}"></div>
                        <div class="sd-form-field">
                            <label>Loại ticket *</label>
                            <select name="ticket_type_code" class="sd-control" required>
                                @foreach($ticketTypeOptions as $type)
                                    <option value="{{ $type['code'] }}">{{ $type['name'] }} ({{ $type['code'] }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="sd-form-field"><label>Phí / ticket (đ) *</label><input type="number" min="1"
                                max="100000000" name="fee_per_ticket" class="sd-control" required></div>
                        <div class="sd-form-field"><label>Hiệu lực từ *</label><input type="datetime-local"
                                name="effective_from" class="sd-control" required
                                value="{{ now('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i') }}"></div>
                        <div class="sd-form-field"><label>Hiệu lực đến</label><input type="datetime-local"
                                name="effective_to" class="sd-control"></div>
                        <div class="sd-form-field"><label>Ghi chú</label><textarea name="notes" class="sd-control"
                                maxlength="3000"></textarea></div>
                    </div>
                </div>
                <div class="sd-drawer-foot">
                    <button type="button" class="sd-btn" data-sales-drawer-close>Hủy</button>
                    <button type="submit" class="sd-btn sd-btn-primary">Lưu cấu hình</button>
                </div>
            </form>
        </aside>
    @endif

    @if($canManageCredit && $profile)
        <aside class="sd-drawer" id="creditDrawer" aria-hidden="true">
            <form method="POST" action="{{ route('admin.specialist-credit.evaluate', $profile->id) }}"
                class="h-100 d-flex flex-column">
                @csrf
                <div class="sd-drawer-head">
                    <h2 class="sd-drawer-title">Cập nhật điểm tín dụng</h2>
                    <button type="button" class="sd-close" data-sales-drawer-close><i class="fas fa-xmark"></i></button>
                </div>
                <div class="sd-drawer-body">
                    <div class="sd-form-grid">
                        <div class="sd-form-field">
                            <label>Hành động *</label>
                            <select name="action_type" class="sd-control" required>
                                <option value="PLUS">Cộng điểm</option>
                                <option value="MINUS">Trừ điểm</option>
                                <option value="ADJUST">Đặt lại điểm</option>
                            </select>
                        </div>
                        <div class="sd-form-field"><label>Số điểm *</label><input type="number" min="0" max="100"
                                name="points" class="sd-control" required></div>
                        <div class="sd-form-field"><label>Lý do *</label><textarea name="reason" class="sd-control" required
                                minlength="3" maxlength="2000"></textarea></div>
                    </div>
                </div>
                <div class="sd-drawer-foot">
                    <button type="button" class="sd-btn" data-sales-drawer-close>Hủy</button>
                    <button type="submit" class="sd-btn sd-btn-primary">Lưu điểm</button>
                </div>
            </form>
        </aside>
    @endif
</div>
