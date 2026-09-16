    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600&display=swap" rel="stylesheet" />

    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body,
        .store-edit-root {
            font-family: 'Be Vietnam Pro', sans-serif;
        }

        /* ── Page shell ── */
        .se-page {
            min-height: 100vh;
        }

        /* ── Header ── */
        .se-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 28px;
            gap: 16px;
        }

        .se-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .se-store-avatar {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: #1a1a1a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            font-weight: 600;
            letter-spacing: -0.5px;
            flex-shrink: 0;
        }

        .se-title {
            font-size: 22px;
            font-weight: 600;
            color: #1a1a1a;
            letter-spacing: -0.4px;
            margin: 0;
        }

        .se-subtitle {
            font-size: 13px;
            color: #888;
            margin: 2px 0 0;
        }

        .se-btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
            color: #555;
            background: #fff;
            border: 1px solid #e2e0db;
            border-radius: 10px;
            padding: 8px 14px;
            text-decoration: none;
            transition: all .15s;
        }

        .se-btn-back:hover {
            background: #f4f3f0;
            color: #1a1a1a;
            border-color: #ccc;
        }

        /* ── Error alert ── */
        .se-alert {
            background: #fff5f5;
            border: 1px solid #fcc;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }

        .se-alert ul {
            margin: 0;
            padding-left: 18px;
            color: #c0392b;
            font-size: 13.5px;
        }

        /* ── Main card ── */
        .se-card {
            background: #fff;
            border: 1px solid #e8e6e1;
            border-radius: 18px;
            overflow: hidden;
        }

        /* ── Tabs ── */
        .se-tabs {
            display: flex;
            gap: 0;
            border-bottom: 1px solid #e8e6e1;
            padding: 0 24px;
            background: #ffff;
            overflow-x: auto;
            white-space: nowrap;
            -webkit-overflow-scrolling: touch;
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }

        .se-tabs::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }

        .se-tab-btn {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13.5px;
            font-weight: 500;
            color: #888;
            background: none;
            border: none;
            padding: 16px 18px 14px;
            cursor: pointer;
            transition: color .15s;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .se-tab-btn::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 18px;
            right: 18px;
            height: 2px;
            background: transparent;
            border-radius: 2px 2px 0 0;
            transition: background .15s;
        }

        .se-tab-btn:hover {
            color: #333;
        }

        .se-tab-btn.active {
            color: #1a1a1a;
        }

        .se-tab-btn.active::after {
            background: #1a1a1a;
        }

        .se-tab-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 20px;
            background: #ffc107;
            color: #7a4f00;
            line-height: 1.4;
        }

        /* ── Tab pane ── */
        .se-tab-pane {
            display: none;
            padding: 28px 28px 8px;
        }

        .se-tab-pane.active {
            display: block;
        }

        /* ── Section label ── */
        .se-section {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #aaa;
            margin: 24px 0 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0ede8;
        }

        .se-section:first-child {
            margin-top: 0;
        }

        /* ── Form grid ── */
        .se-grid {
            display: grid;
            gap: 18px;
        }

        .se-grid-2 {
            grid-template-columns: 1fr 1fr;
        }

        .se-grid-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .se-grid-4 {
            grid-template-columns: 2fr 1fr 1fr 1fr;
        }

        @media (max-width: 768px) {

            .se-grid-2,
            .se-grid-3,
            .se-grid-4 {
                grid-template-columns: 1fr;
            }
        }

        /* ── Label / input ── */
        .se-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .se-label {
            font-size: 12.5px;
            font-weight: 600;
            color: #555;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .se-label .req {
            color: #e74c3c;
            font-size: 13px;
        }

        .se-input,
        .se-select,
        .se-textarea {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 14px;
            color: #1a1a1a;
            background: #ffff;
            border: 1px solid #e0ddd7;
            border-radius: 10px;
            padding: 9px 13px;
            width: 100%;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }

        .se-input:focus,
        .se-select:focus,
        .se-textarea:focus {
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(26, 26, 26, .07);
            background: #fff;
        }

        .se-input::placeholder,
        .se-textarea::placeholder {
            color: #bbb;
        }

        .se-textarea {
            resize: vertical;
            min-height: 90px;
        }

        .se-hint {
            font-size: 12px;
            color: #aaa;
            margin-top: 3px;
        }

        /* ── Select2 override ── */
        .select2-container .select2-selection--single {
            height: 40px !important;
            background: #ffff !important;
            border: 1px solid #e0ddd7 !important;
            border-radius: 10px !important;
            font-family: 'Be Vietnam Pro', sans-serif;
        }

        .select2-container .select2-selection--single .select2-selection__rendered {
            line-height: 38px !important;
            padding-left: 13px !important;
            font-size: 14px;
            color: #1a1a1a !important;
        }

        .select2-container .select2-selection--single .select2-selection__arrow {
            height: 38px !important;
        }

        .select2-container--open .select2-selection--single {
            border-color: #1a1a1a !important;
            box-shadow: 0 0 0 3px rgba(26, 26, 26, .07) !important;
        }

        .select2-dropdown {
            border: 1px solid #e0ddd7 !important;
            border-radius: 10px !important;
            overflow: hidden;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .1) !important;
        }

        .select2-results__option--highlighted {
            background: #1a1a1a !important;
        }

        /* ── Sale info box ── */
        .se-sale-card {
            background: #ffff;
            border: 1px dashed #d8d4cc;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            min-height: 72px;
        }

        .se-sale-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            flex-shrink: 0;
            background: #1a1a1a;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
        }

        .se-sale-name {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0;
        }

        .se-sale-meta {
            font-size: 12.5px;
            color: #888;
            margin: 2px 0 0;
        }

        /* ── Map ── */
        .se-map-wrap {
            border: 1px solid #e0ddd7;
            border-radius: 14px;
            overflow: hidden;
            height: 300px;
        }

        .se-map-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .se-btn-map {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 12.5px;
            font-weight: 500;
            background: #fff;
            border: 1px solid #e0ddd7;
            border-radius: 8px;
            padding: 7px 14px;
            cursor: pointer;
            color: #444;
            transition: all .14s;
        }

        .se-btn-map:hover {
            background: #f4f3f0;
            border-color: #aaa;
            color: #1a1a1a;
        }

        /* ── Checkbox toggle ── */
        .se-toggle-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffff;
            border: 1px solid #e0ddd7;
            border-radius: 12px;
            padding: 12px 16px;
        }

        .se-toggle-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #1a1a1a;
            cursor: pointer;
        }

        .se-toggle-label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            cursor: pointer;
            user-select: none;
        }

        /* ── Table area ── */
        .se-table-card {
            border: 1px solid #e8e6e1;
            border-radius: 14px;
            overflow: hidden;
        }

        .se-table {
            width: 100%;
            border-collapse: collapse;
        }

        .se-table thead {
            background: #ffff;
        }

        .se-table th {
            font-size: 11.5px;
            font-weight: 600;
            color: #888;
            letter-spacing: .06em;
            text-transform: uppercase;
            padding: 13px 16px;
            border-bottom: 1px solid #e8e6e1;
            white-space: nowrap;
        }

        .se-table td {
            font-size: 13.5px;
            color: #333;
            padding: 12px 16px;
            border-bottom: 1px solid #f0ede8;
            vertical-align: middle;
        }

        .se-table tbody tr:last-child td {
            border-bottom: none;
        }

        .se-table tbody tr:hover td {
            background: #ffff;
        }

        .se-table tbody tr.out-of-stock td {
            background: #fff8f8;
        }

        .se-table img {
            border-radius: 8px;
            object-fit: cover;
        }

        .se-product-name {
            font-weight: 500;
            color: #1a1a1a;
        }

        .se-product-sku {
            font-size: 11.5px;
            color: #aaa;
            margin-top: 1px;
        }

        .se-price {
            font-variant-numeric: tabular-nums;
        }

        /* ── Badges ── */
        .se-badge {
            display: inline-flex;
            align-items: center;
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 9px;
            border-radius: 20px;
        }

        .se-badge-warn {
            background: #fff3cd;
            color: #856404;
        }

        .se-badge-success {
            background: #d1f2e0;
            color: #1a6636;
        }

        .se-badge-danger {
            background: #fde8e8;
            color: #9b2626;
        }

        /* ── Action buttons ── */
        .se-btn {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 14px;
            border-radius: 9px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: all .14s;
        }

        .se-btn-primary {
            background: #1a1a1a;
            color: #fff;
        }

        .se-btn-primary:hover {
            background: #333;
        }

        .se-btn-secondary {
            background: #fff;
            color: #444;
            border: 1px solid #e0ddd7;
        }

        .se-btn-secondary:hover {
            background: #f4f3f0;
            color: #1a1a1a;
        }

        .se-btn-warn {
            background: #fff8e1;
            color: #8a6400;
            border: 1px solid #ffe082;
        }

        .se-btn-warn:hover {
            background: #fff3cd;
        }

        .se-btn-danger {
            background: #fde8e8;
            color: #9b2626;
            border: 1px solid #f5b7b7;
        }

        .se-btn-danger:hover {
            background: #fdd5d5;
        }

        .se-btn-outline-info {
            background: #eef5ff;
            color: #1a4f9c;
            border: 1px solid #b8d4f8;
        }

        .se-btn-outline-info:hover {
            background: #ddeaff;
        }

        .se-btn-success {
            background: #d1f2e0;
            color: #1a6636;
            border: 1px solid #a3d9b7;
        }

        .se-btn-success:hover {
            background: #bde8d0;
        }

        .se-btn-sm {
            padding: 5px 11px;
            font-size: 12.5px;
        }

        /* ── Stock form ── */
        .se-stock-form {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .se-stock-input {
            font-family: 'Be Vietnam Pro', sans-serif;
            width: 90px;
            font-size: 13.5px;
            padding: 6px 10px;
            border-radius: 8px;
            border: 1px solid #e0ddd7;
            background: #ffff;
            outline: none;
            transition: border-color .15s;
        }

        .se-stock-input:focus {
            border-color: #1a1a1a;
            background: #fff;
        }

        /* ── Approval actions ── */
        .se-approval-actions {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
            align-items: center;
        }

        /* ── Footer ── */
        .se-footer {
            padding: 20px 28px 24px;
            border-top: 1px solid #f0ede8;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #ffff;
        }

        /* ── Pagination wrapper ── */
        .se-pagination {
            margin-top: 16px;
        }

        /* ── Toast ── */
        #seToastContainer {
            position: fixed;
            top: 20px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .se-toast {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #1a1a1a;
            color: #fff;
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13.5px;
            font-weight: 500;
            padding: 12px 18px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .18);
            animation: seToastIn .2s ease;
            min-width: 220px;
            max-width: 360px;
        }

        .se-toast.danger {
            background: #c0392b;
        }

        @keyframes seToastIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .se-toast-icon {
            font-size: 16px;
        }

        /* ── Empty state ── */
        .se-empty {
            text-align: center;
            padding: 40px 20px;
            color: #aaa;
            font-size: 14px;
        }

        .se-empty-icon {
            font-size: 36px;
            display: block;
            margin-bottom: 8px;
            color: #ddd;
        }

        /* ── Divider ── */
        .se-divider {
            border: none;
            border-top: 1px solid #f0ede8;
            margin: 4px 0 20px;
        }

        /* ── Tab bar action ── */
        .se-tab-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
        }

        .se-tab-header-text {
            font-size: 13px;
            color: #888;
        }
    </style>
