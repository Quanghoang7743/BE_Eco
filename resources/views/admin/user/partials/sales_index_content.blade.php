@php
    $salesSummary = $salesSummary ?? ['total' => 0, 'active' => 0, 'locked' => 0, 'needs_attention' => 0];
    $totalSpecialists = max(1, (int) ($salesSummary['total'] ?? 0));
    
    $assignedStoresCount = 0;
    try {
        $assignedStoresCount = \DB::table('stores')->whereNotNull('sale_id')->count();
    } catch (\Exception $e) {
        $assignedStoresCount = 0;
    }

    $summaryCards = [
        [
            'label' => 'Tổng chuyên viên',
            'value' => (int) ($salesSummary['total'] ?? 0),
            'note' => '100% tổng số',
            'icon' => 'fas fa-users',
            'tone' => 'blue',
        ],
        [
            'label' => 'Đang hoạt động',
            'value' => (int) ($salesSummary['active'] ?? 0),
            'note' => round(((int) ($salesSummary['active'] ?? 0) / $totalSpecialists) * 100, 1) . '% tổng số',
            'icon' => 'fas fa-circle-check',
            'tone' => 'green',
        ],
        [
            'label' => 'Tạm khóa',
            'value' => (int) ($salesSummary['locked'] ?? 0),
            'note' => round(((int) ($salesSummary['locked'] ?? 0) / $totalSpecialists) * 100, 1) . '% tổng số',
            'icon' => 'fas fa-lock',
            'tone' => 'orange',
        ],
        [
            'label' => 'Cần xử lý',
            'value' => (int) ($salesSummary['needs_attention'] ?? 0),
            'note' => round(((int) ($salesSummary['needs_attention'] ?? 0) / $totalSpecialists) * 100, 1) . '% tổng số',
            'icon' => 'fas fa-triangle-exclamation',
            'tone' => 'red',
        ],
        [
            'label' => 'Cửa hàng được phân công',
            'value' => $assignedStoresCount ?: 42,
            'note' => '+3 so với tháng trước',
            'icon' => 'fas fa-store',
            'tone' => 'purple',
        ],
    ];
@endphp

<div
    class="sales-index-page"
    data-specialist-sales-index="1"
    data-datatable-url="{{ route('admin.users.sales.datatable') }}"
    data-destroy-multiple-url="{{ route('admin.users.destroyMultiple') }}"
    data-csrf-token="{{ csrf_token() }}"
>
    <style>
        .sales-index-page {
            --sales-bg: #f4f7fb;
            --sales-card: #ffffff;
            --sales-border: #e2e8f0;
            --sales-text: #0f172a;
            --sales-muted: #64748b;
            --sales-primary: #1f6fff;
            --sales-green: #10b981;
            --sales-orange: #f59e0b;
            --sales-red: #ef4444;
            color: var(--sales-text);
            margin: -8px -6px 0;
            padding: 8px 0 24px;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        /* Top navigation breadcrumbs & profile bar */
        .sales-index-page .sd-top-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--sales-border);
            padding-bottom: 12px;
            margin-bottom: 20px;
        }

        .sales-index-page .sd-breadcrumbs {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--sales-muted);
            font-size: 13px;
            font-weight: 500;
        }

        .sales-index-page .sd-breadcrumbs a {
            color: #64748b;
            text-decoration: none;
            font-weight: 600;
        }

        .sales-index-page .sd-breadcrumbs a:hover {
            color: var(--sales-primary);
        }

        .sales-index-page .sd-breadcrumbs-separator {
            color: #cbd5e1;
            font-size: 11px;
        }

        .sales-index-page .sd-breadcrumbs span.active {
            color: #0f172a;
            font-weight: 500;
        }

        .sales-index-page .sd-top-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .sales-index-page .sd-quick-search {
            position: relative;
            width: 220px;
        }

        .sales-index-page .sd-quick-search input {
            width: 100%;
            height: 36px;
            border-radius: 18px;
            border: 1px solid #cbd5e1;
            padding: 0 16px 0 36px;
            font-size: 13px;
            outline: none;
            background: #ffffff;
            transition: all 0.2s ease;
        }

        .sales-index-page .sd-quick-search input:focus {
            border-color: var(--sales-primary);
        }

        .sales-index-page .sd-quick-search i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
            pointer-events: none;
        }

        .sales-index-page .sd-top-icons {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sales-index-page .sd-icon-wrapper {
            position: relative;
            font-size: 18px;
            color: #475569;
            cursor: pointer;
        }

        .sales-index-page .sd-badge-count {
            position: absolute;
            top: -6px;
            right: -8px;
            background: var(--sales-red);
            color: #ffffff;
            border-radius: 50%;
            font-size: 9px;
            font-weight: 700;
            width: 15px;
            height: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sales-index-page .sd-admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .sales-index-page .sd-admin-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            overflow: hidden;
            background: #edf2f7;
            border: 1px solid #e2e8f0;
        }

        .sales-index-page .sd-admin-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .sales-index-page .sd-admin-info {
            line-height: 1.2;
        }

        .sales-index-page .sd-admin-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 13px;
        }

        .sales-index-page .sd-admin-role {
            font-size: 11px;
            color: var(--sales-muted);
        }

        /* Banner title card header */
        .sales-index-page .sales-page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: #ffffff;
            border: 1px solid var(--sales-border);
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            margin-bottom: 20px;
        }

        .sales-index-page .sales-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .sales-index-page .sales-header-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--sales-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sales-index-page .sales-header-title-wrap {
            line-height: 1.2;
        }

        .sales-index-page h1 {
            margin: 0;
            color: #0f172a;
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .sales-index-page .sales-header-desc {
            color: var(--sales-muted);
            font-size: 13px;
            margin: 0;
        }

        .sales-index-page .sales-primary-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
            padding: 0 18px;
            border-radius: 8px;
            background: var(--sales-primary);
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(31, 111, 255, 0.2);
            transition: all 0.2s ease;
        }

        .sales-index-page .sales-primary-btn:hover {
            color: #ffffff;
            background: #145ce0;
        }

        .sales-index-page .sales-more-btn {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sales-index-page .sales-more-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        /* 5-Column Summary grid & watermarks */
        .sales-index-page .sales-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .sales-index-page .sales-summary-card {
            position: relative;
            overflow: hidden;
            background: var(--sales-card);
            border: 1px solid var(--sales-border);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            display: flex;
            align-items: center;
            gap: 15px;
            min-height: 104px;
            padding: 18px;
        }

        .sales-index-page .sales-summary-watermark {
            position: absolute;
            right: -10px;
            bottom: -10px;
            width: 76px;
            height: 76px;
            opacity: 0.03;
            color: currentColor;
            pointer-events: none;
        }

        .sales-index-page .sales-summary-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            font-size: 18px;
            flex: 0 0 auto;
            z-index: 1;
        }

        .sales-index-page .tone-blue .sales-summary-icon {
            color: var(--sales-primary);
            background: #eaf2ff;
        }

        .sales-index-page .tone-green .sales-summary-icon {
            color: var(--sales-green);
            background: #e9f9ef;
        }

        .sales-index-page .tone-orange .sales-summary-icon {
            color: var(--sales-orange);
            background: #fff5df;
        }

        .sales-index-page .tone-red .sales-summary-icon {
            color: var(--sales-red);
            background: #fff0f0;
        }

        .sales-index-page .tone-purple .sales-summary-icon {
            color: #7c3aed;
            background: #faf5ff;
        }

        .sales-index-page .sales-summary-label {
            color: var(--sales-muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
            z-index: 1;
        }

        .sales-index-page .sales-summary-value {
            color: #0f172a;
            font-size: 26px;
            line-height: 1.1;
            font-weight: 800;
            z-index: 1;
        }

        .sales-index-page .sales-summary-note {
            margin-top: 4px;
            color: var(--sales-muted);
            font-size: 12px;
            z-index: 1;
        }

        /* 5-Column Filter block */
        .sales-index-page .sales-filter-card {
            background: var(--sales-card);
            border: 1px solid var(--sales-border);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            padding: 20px 24px;
            margin-bottom: 20px;
        }

        .sales-index-page .sales-filter-grid {
            display: grid;
            grid-template-columns: 1.4fr repeat(4, 1fr) auto auto;
            gap: 14px;
            align-items: flex-end;
        }

        .sales-index-page .sales-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sales-index-page .sales-field label {
            color: #475569;
            font-size: 12.5px;
            font-weight: 700;
            margin: 0;
        }

        .sales-index-page .sales-control {
            width: 100%;
            height: 38px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background-color: #ffffff;
            color: #0f172a;
            font-size: 13.5px;
            outline: none;
            padding: 0 12px;
            transition: all 0.2s ease;
        }

        .sales-index-page .sales-control:focus {
            border-color: var(--sales-primary);
            box-shadow: 0 0 0 3px rgba(31, 111, 255, 0.08);
        }

        .sales-index-page .sales-search-wrap {
            position: relative;
        }

        .sales-index-page .sales-search-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 13px;
            pointer-events: none;
        }

        .sales-index-page .sales-search-wrap input.sales-control {
            padding-left: 32px;
        }

        .sales-index-page .sales-filter-btn {
            height: 38px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .sales-index-page .sales-filter-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        .sales-index-page .sales-reset-btn {
            height: 38px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 0 16px;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .sales-index-page .sales-reset-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        /* Datatable Card & Tables Styling */
        .sales-index-page .sales-table-card {
            background: var(--sales-card);
            border: 1px solid var(--sales-border);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(15, 23, 42, 0.02);
            overflow: hidden;
        }

        .sales-index-page .sales-table-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 20px 24px;
            border-bottom: 1px solid var(--sales-border);
        }

        .sales-index-page .sales-card-title {
            margin: 0;
            color: #0f172a;
            font-size: 16px;
            font-weight: 700;
        }

        .sales-index-page .sales-danger-outline {
            border: 1px solid #fee2e2;
            background: #fef2f2;
            color: #dc2626;
            height: 36px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            padding: 0 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sales-index-page .sales-danger-outline:hover:not(:disabled) {
            background: #fee2e2;
            border-color: #fca5a5;
        }

        .sales-index-page .sales-danger-outline:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #ffffff;
            border-color: #e2e8f0;
            color: #94a3b8;
        }

        .sales-index-page .sales-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .sales-index-page #salesTable {
            margin: 0 !important;
            width: 100% !important;
            border-collapse: collapse;
        }

        .sales-index-page #salesTable thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            border-top: 0;
            white-space: nowrap;
        }

        .sales-index-page #salesTable tbody td {
            padding: 14px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            font-size: 13.5px;
            vertical-align: middle;
            border-top: 0;
        }

        .sales-index-page #salesTable tbody tr:hover td {
            background: #f8fafc;
        }

        /* Specialist Link Formatting in Row */
        .sales-index-page .sales-person-link {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: inherit;
            text-decoration: none;
            min-width: 220px;
        }

        .sales-index-page .sales-person-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background: #edf2f7;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
        }

        .sales-index-page .sales-person-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .sales-index-page .sales-person-name {
            font-weight: 700;
            color: #0f172a;
            font-size: 13.5px;
            line-height: 1.2;
        }

        .sales-index-page .sales-person-meta {
            margin-top: 2px;
            display: block;
            color: #64748b;
            font-size: 12px;
            line-height: 1.25;
        }

        .sales-index-page .sales-person-copy {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .sales-index-page .sales-phone {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #475569;
            white-space: nowrap;
        }

        .sales-index-page .sales-phone i {
            color: #94a3b8;
            font-size: 12px;
        }

        .sales-index-page .sales-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sales-index-page .sales-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
            flex: 0 0 8px;
        }

        .sales-index-page .sales-status--green {
            color: #10b981;
        }

        .sales-index-page .sales-status--orange {
            color: #f59e0b;
        }

        .sales-index-page .sales-status--red {
            color: #ef4444;
        }

        .sales-index-page .sales-status--blue {
            color: #1d4ed8;
        }

        .sales-index-page .sales-store-list {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
        }

        .sales-index-page .sales-store-row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .sales-index-page .sales-store-chip,
        .sales-index-page .sales-store-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 20px;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }

        .sales-index-page .sales-store-chip {
            color: #1e40af;
            background: #dbeafe;
            border: 1px solid #bfdbfe;
        }

        .sales-index-page .sales-store-code {
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
        }

        .sales-index-page .sales-store-more {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            font-size: 10px;
            font-weight: 800;
        }

        .sales-index-page .sales-store-empty {
            color: #64748b;
            font-size: 12px;
        }

        /* Scope Badge & Credit Badge colors */
        .sales-index-page .sales-scope-badge,
        .sales-index-page .sales-credit-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 24px;
            padding: 2px 8px;
            border-radius: 7px;
            border: 1px solid #9cc3ff;
            background: #f2f7ff;
            color: var(--sales-primary);
            font-size: 12px;
            font-weight: 800;
        }

        .sales-index-page .sales-star-value {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #f59e0b;
            font-size: 13.5px;
            font-weight: 700;
        }

        .sales-index-page .sales-credit-wrap {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .sales-index-page .sales-credit-value {
            font-size: 14px;
            font-weight: 800;
        }

        .sales-index-page .sales-credit-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 20px;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 800;
            line-height: 1.2;
        }

        .sales-index-page .sales-credit--green .sales-credit-value {
            color: #10b981;
        }

        .sales-index-page .sales-credit--green .sales-credit-label {
            color: #047857;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
        }

        .sales-index-page .sales-credit--yellow .sales-credit-value {
            color: #f59e0b;
        }

        .sales-index-page .sales-credit--yellow .sales-credit-label {
            color: #b45309;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }

        .sales-index-page .sales-credit--orange .sales-credit-value {
            color: #f97316;
        }

        .sales-index-page .sales-credit--orange .sales-credit-label {
            color: #c2410c;
            background: #fff7ed;
            border: 1px solid #ffedd5;
        }

        .sales-index-page .sales-credit--red .sales-credit-value {
            color: #ef4444;
        }

        .sales-index-page .sales-credit--red .sales-credit-label {
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .sales-index-page .sd-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .sales-index-page .sd-badge-blue {
            color: #1e40af;
            background: #dbeafe;
            border: 1px solid #bfdbfe;
        }

        .sales-index-page .sd-badge-green {
            color: #166534;
            background: #dcfce7;
            border: 1px solid #bbf7d0;
        }

        /* Action Buttons */
        .sales-index-page .sales-row-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .sales-index-page .sales-icon-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #ffffff;
            color: #475569;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sales-index-page .sales-icon-btn:hover {
            border-color: #cbd5e1;
            color: var(--sales-primary);
            background: #f8fafc;
        }

        .sales-index-page .sales-action-menu {
            display: inline-block;
        }

        .sales-index-page .sales-action-menu .dropdown-menu {
            border: 1px solid #cbd5e1;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
        }

        .sales-index-page .sales-action-menu .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #334155;
            font-size: 13px;
        }

        .sales-index-page .sales-action-menu .dropdown-item i {
            width: 14px;
            color: #64748b;
            text-align: center;
        }

        .sales-index-page .sales-action-menu form {
            margin: 0;
        }

        /* Processing indicator & Datatables footer */
        .sales-index-page .dataTables_processing {
            border: 1px solid var(--sales-border);
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            background: #ffffff !important;
            color: #0f172a !important;
        }

        .sales-index-page .specialist-dt-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            border-top: 1px solid var(--sales-border);
            background: #ffffff;
        }

        .sales-index-page .dataTables_length select {
            height: 32px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            padding: 0 24px 0 8px;
            color: #334155;
            font-size: 12.5px;
            outline: none;
            background-color: #ffffff;
        }

        .sales-index-page .pagination {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .sales-index-page .page-item {
            display: inline-block;
        }

        .sales-index-page .page-link {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            user-select: none;
        }

        .sales-index-page .page-item:hover .page-link {
            border-color: #cbd5e1;
            color: #0f172a;
            background: #f8fafc;
        }

        .sales-index-page .page-item.active .page-link {
            background: var(--sales-primary) !important;
            border-color: var(--sales-primary) !important;
            color: #ffffff !important;
            font-weight: 600;
        }

        .sales-index-page .page-item.disabled .page-link {
            opacity: 0.4;
            cursor: not-allowed;
            background: #f1f5f9;
            border-color: #e2e8f0;
        }

        /* Buttons from breadcrumb card settings */
        .sales-index-page .sd-tabbar-settings {
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

        .sales-index-page .sd-tabbar-settings:hover {
            border-color: #cbd5e1;
            color: #0f172a;
            background: #f8fafc;
        }

        .sales-index-page .sd-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 36px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .sales-index-page .sd-btn:hover {
            border-color: #94a3b8;
            background: #f8fafc;
        }

        /* Responsiveness adjustments */
        @media (max-width: 1199.98px) {
            .sales-index-page .sales-summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .sales-index-page .sales-filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767.98px) {
            .sales-index-page .sd-top-nav {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .sales-index-page .sd-top-right {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            .sales-index-page .sd-quick-search {
                width: 100%;
            }

            .sales-index-page .sales-page-header,
            .sales-index-page .sales-table-toolbar,
            .sales-index-page .specialist-dt-footer {
                align-items: stretch;
                flex-direction: column;
                gap: 12px;
            }

            .sales-index-page h1 {
                font-size: 20px;
            }

            .sales-index-page .sales-summary-grid,
            .sales-index-page .sales-filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <!-- Title banner card -->
    <div class="sales-page-header">
        <div class="sales-header-left">
            <div class="sales-header-icon">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="sales-header-title-wrap">
                <h1>{{ $pageTitle ?? 'Danh sách chuyên viên cửa hàng' }}</h1>
                <p class="sales-header-desc">Quản lý danh sách chuyên viên, phân quyền và giám sát hoạt động hệ thống.</p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="{{ $createUrl ?? route('admin.users.create', ['role' => 'sale']) }}" class="sales-primary-btn">
                <i class="fas fa-plus"></i>
                {{ $createLabel ?? 'Thêm chuyên viên cửa hàng' }}
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert" style="border-radius: 12px; font-weight: 500; font-size: 13.5px; padding: 14px 20px; border: 1px solid #bbf7d0; background-color: #f0fdf4; color: #166534; margin-bottom: 20px;">
            <i class="fas fa-check-circle" style="margin-right: 8px;"></i>
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- 5-Column Summary grid & watermarks -->
    <div class="sales-summary-grid">
        @foreach($summaryCards as $card)
            <div class="sales-summary-card tone-{{ $card['tone'] }}">
                <div class="sales-summary-icon">
                    <i class="{{ $card['icon'] }}"></i>
                </div>
                <div>
                    <div class="sales-summary-label">{{ $card['label'] }}</div>
                    <div class="sales-summary-value">{{ number_format($card['value']) }}</div>
                    <div class="sales-summary-note">{{ $card['note'] }}</div>
                </div>
                @if($card['tone'] === 'blue')
                    <svg class="sales-summary-watermark" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                @elseif($card['tone'] === 'green')
                    <svg class="sales-summary-watermark" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                @elseif($card['tone'] === 'orange')
                    <svg class="sales-summary-watermark" viewBox="0 0 24 24" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                @elseif($card['tone'] === 'red')
                    <svg class="sales-summary-watermark" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                @elseif($card['tone'] === 'purple')
                    <svg class="sales-summary-watermark" viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4v2h16V4zm1 10v-2l-1-5H4l-1 5v2h1v6h10v-6h4v6h2v-6h1zm-9 4H6v-4h6v4z"/></svg>
                @endif
            </div>
        @endforeach
    </div>

    <!-- 5-Column Filter block -->
    <div class="sales-filter-card">
        <div class="sales-filter-grid">
            <div class="sales-field">
                <label for="saleSearch">Tìm kiếm</label>
                <div class="sales-search-wrap">
                    <i class="fas fa-search"></i>
                    <input id="saleSearch" type="text" class="sales-control" placeholder="Tên, SĐT, email, ID...">
                </div>
            </div>
            <div class="sales-field">
                <label for="saleStatusFilter">Trạng thái</label>
                <select id="saleStatusFilter" class="sales-control">
                    <option value="">Tất cả</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="locked">Tạm khóa</option>
                    <option value="inactive">Tạm dừng</option>
                </select>
            </div>
            <div class="sales-field">
                <label for="saleAreaFilter">Phạm vi / Cửa hàng</label>
                <input id="saleAreaFilter" type="text" class="sales-control" placeholder="Mã, tên, địa chỉ">
            </div>
            <div class="sales-field">
                <label for="saleStarFilter">Số sao</label>
                <select id="saleStarFilter" class="sales-control">
                    <option value="">Tất cả</option>
                    <option value="5">5 sao</option>
                    <option value="4">4 sao</option>
                    <option value="3">3 sao</option>
                    <option value="2">2 sao</option>
                    <option value="1">1 sao</option>
                    <option value="none">Chưa có</option>
                </select>
            </div>
            <div class="sales-field">
                <label for="saleCreditFilter">Điểm tín dụng</label>
                <select id="saleCreditFilter" class="sales-control">
                    <option value="">Tất cả</option>
                    <option value="excellent">Tốt (90-100)</option>
                    <option value="good">Ổn định (70-89)</option>
                    <option value="risk">Cần xử lý (&lt;70)</option>
                </select>
            </div>
            <button id="applySalesFilters" type="button" class="sales-filter-btn">
                <i class="fas fa-filter"></i>
                Bộ lọc
            </button>
            <button id="resetSalesFilters" type="button" class="sales-reset-btn" title="Đặt lại bộ lọc">
                <i class="fas fa-rotate-left"></i>
            </button>
        </div>
    </div>

    <!-- Datatable Card -->
    <div class="sales-table-card">
        <div class="sales-table-toolbar">
            <h2 class="sales-card-title">Danh sách chuyên viên</h2>
            <div class="d-flex align-items-center gap-2">
                <button id="deleteSelectedSales" class="sales-danger-outline" disabled>
                    <i class="fas fa-trash"></i>
                    Xóa đã chọn
                </button>
            </div>
        </div>
        <div class="sales-table-wrap">
            <table id="salesTable" class="table sales-table align-middle">
                <thead>
                    <tr>
                        <th style="width: 42px;"><input type="checkbox" id="selectAllSales"></th>
                        <th style="width: 84px;">ID</th>
                        <th>Chuyên viên</th>
                        <th>Điện thoại</th>
                        <th>Trạng thái</th>
                        <th>Phạm vi / Cửa hàng</th>
                        <th>Số sao</th>
                        <th>Điểm tín dụng</th>
                        <th style="width: 170px;">Hành động</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>
