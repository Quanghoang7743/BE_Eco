    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600&display=swap" rel="stylesheet" />

    <style>
        /* ── Header ── */
        .si-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            gap: 12px;
        }

        .si-title {
            font-size: 22px;
            font-weight: 600;
            color: #1a1a1a;
            letter-spacing: -.4px;
            margin: 0;
        }

        @media (max-width: 480px) {
            .si-title {
                font-size: 18px;
            }
        }

        /* ── Buttons ── */
        .si-btn {
            font-family: 'Be Vietnam Pro', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 13.5px;
            font-weight: 500;
            padding: 9px 18px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: all .14s;
        }

        .si-btn-primary {
            background: #1a1a1a;
            color: #fff;
        }

        .si-btn-primary:hover {
            background: #333;
            color: #fff;
        }

        .si-btn-danger {
            background: #fff;
            color: #c0392b;
            border: 1px solid #f5b7b7;
        }

        .si-btn-danger:hover:not(:disabled) {
            background: #fde8e8;
        }

        .si-btn-danger:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        @media (max-width: 480px) {
            .si-btn {
                font-size: 12.5px;
                padding: 8px 14px;
            }
        }

        /* ── Alert ── */
        .si-alert {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f0faf4;
            border: 1px solid #a3d9b7;
            border-radius: 12px;
            padding: 13px 18px;
            font-size: 13.5px;
            color: #1a6636;
            margin-bottom: 20px;
        }

        /* ── Filter bar ── */
        .si-filters {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 14px;
            align-items: end;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .si-filters {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 540px) {
            .si-filters {
                grid-template-columns: 1fr;
            }
        }

        .si-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .si-label {
            font-size: 12px;
            font-weight: 600;
            color: #888;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .si-input-wrap {
            position: relative;
        }

        .si-input-wrap .si-input-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #bbb;
            font-size: 13px;
            pointer-events: none;
        }

        .si-input,
        .si-select {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 14px;
            color: #1a1a1a;
            background: #fff;
            border: 1px solid #e2e0db;
            border-radius: 10px;
            padding: 9px 13px;
            width: 100%;
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }

        .si-input-wrap .si-input {
            padding-left: 34px;
        }

        .si-input:focus,
        .si-select:focus {
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(26, 26, 26, .07);
        }

        /* ── Card ── */
        .si-card {
            background: #fff;
            border: 1px solid #e8e6e1;
            border-radius: 18px;
            overflow: auto;
        }

        /* ════════════════════════════════════════
                                                                                       DESKTOP TABLE  (≥ 769px)
                                                                                    ════════════════════════════════════════ */
        .si-table-wrap {
            display: block;
        }

        .si-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
            table-layout: fixed;
        }

        .si-table thead {
            background: #faf9f7;
        }

        .si-table th {
            font-size: 11px;
            font-weight: 700;
            color: #999;
            letter-spacing: .07em;
            text-transform: uppercase;
            padding: 13px 14px;
            border-bottom: 1px solid #e8e6e1;
            white-space: nowrap;
            cursor: pointer;
            user-select: none;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .si-table th.no-sort {
            cursor: default;
        }

        .si-table th .sort-icon {
            margin-left: 4px;
            color: #ccc;
            font-size: 10px;
        }

        .si-table th.sorted-asc .sort-icon::before {
            content: '\f0de';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: #1a1a1a;
        }

        .si-table th.sorted-desc .sort-icon::before {
            content: '\f0dd';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: #1a1a1a;
        }

        .si-table th:not(.sorted-asc):not(.sorted-desc) .sort-icon::before {
            content: '\f0dc';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
        }

        .si-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f0ede8;
            color: #333;
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .si-table tbody tr {
            cursor: pointer;
        }

        .si-table tbody tr:last-child td {
            border-bottom: none;
        }

        .si-table tbody tr:hover td {
            background: #fdfcfb;
        }

        /* column widths — fixed layout keeps table from stretching */
        .si-col-check {
            width: 42px;
        }

        .si-col-code {
            width: 110px;
        }

        .si-col-name {
            width: 140px;
        }

        .si-col-phone {
            width: 150px;
        }

        .si-col-contact {
            width: 220px;
        }

        /* .si-col-email  { width: 160px; } */
        .si-col-addr {
            width: 140px;
        }

        .si-col-sale {
            width: 140px;
        }

        .si-col-pending {
            width: 52px;
        }

        .si-col-status {
            width: 66px;
        }

        .si-col-action {
            width: 80px;
        }

        /* ════════════════════════════════════════
                                                                                       MOBILE CARDS  (≤ 768px)
                                                                                    ════════════════════════════════════════ */
        @media (max-width: 768px) {

            /* Hide desktop table */
            .si-table-wrap {
                display: none;
            }

            /* Show card list */
            .si-card-list {
                display: block;
            }

            .si-store-card {
                border-bottom: 1px solid #f0ede8;
                padding: 16px 18px;
                cursor: pointer;
                transition: background-color .15s ease;
            }

            .si-store-card:hover {
                background: #faf9f6;
            }

            .si-store-card:last-child {
                border-bottom: none;
            }

            .si-store-card-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 12px;
            }

            .si-store-card-left {
                display: flex;
                align-items: center;
                gap: 10px;
                flex: 1;
                min-width: 0;
            }

            .si-store-card-check {
                flex-shrink: 0;
            }

            .si-store-card-check input[type=checkbox] {
                width: 17px;
                height: 17px;
                accent-color: #1a1a1a;
                cursor: pointer;
            }

            .si-store-card-avatar {
                width: 38px;
                height: 38px;
                border-radius: 10px;
                flex-shrink: 0;
                background: #1a1a1a;
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 15px;
                font-weight: 600;
            }

            .si-store-card-name-wrap {
                min-width: 0;
            }

            .si-store-card-name {
                font-size: 14.5px;
                font-weight: 600;
                color: #1a1a1a;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .si-store-card-code {
                font-family: monospace;
                font-size: 11.5px;
                background: #f4f3f0;
                padding: 2px 7px;
                border-radius: 5px;
                color: #666;
                display: inline-block;
                margin-top: 3px;
            }

            .si-store-card-badges {
                display: flex;
                gap: 6px;
                flex-wrap: wrap;
            }

            .si-store-card-body {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px 16px;
                margin-bottom: 12px;
            }

            .si-store-card-row {
                display: flex;
                flex-direction: column;
                gap: 2px;
            }

            .si-store-card-lbl {
                font-size: 10.5px;
                font-weight: 700;
                color: #bbb;
                letter-spacing: .06em;
                text-transform: uppercase;
            }

            .si-store-card-val {
                font-size: 13px;
                color: #333;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .si-store-card-val a {
                color: #444;
                text-decoration: none;
            }

            .si-store-card-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding-top: 10px;
                border-top: 1px solid #f4f2ee;
                gap: 8px;
            }

            .si-store-card-sale {
                flex: 1;
                min-width: 0;
            }

            /* Hide desktop table completely on mobile */
            .si-table {
                display: none;
            }
        }

        @media (min-width: 769px) {

            /* Hide card list on desktop */
            .si-card-list {
                display: none;
            }
        }

        /* ── Shared component styles ── */
        .si-table input[type=checkbox] {
            width: 16px;
            height: 16px;
            accent-color: #1a1a1a;
            cursor: pointer;
        }

        .si-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .si-badge-success {
            background: #d1f2e0;
            color: #1a6636;
        }

        .si-badge-warn {
            background: #fff3cd;
            color: #856404;
        }

        .si-badge-danger {
            background: #fde8e8;
            color: #9b2626;
        }

        .si-badge-neutral {
            background: #f1efe8;
            color: #666;
        }

        .si-badge-pending {
            background: #e8f0ff;
            color: #2255cc;
        }

        .si-code {
            font-family: monospace;
            font-size: 12px;
            background: #f4f3f0;
            padding: 3px 8px;
            border-radius: 6px;
            color: #555;
        }

        .si-store-name {
            font-weight: 500;
            color: #1a1a1a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
        }

        .si-sale-select {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13px;
            color: #333;
            background: #faf9f7;
            border: 1px solid #e2e0db;
            border-radius: 8px;
            padding: 5px 9px;
            cursor: pointer;
            outline: none;
            width: 100%;
            max-width: 180px;
            transition: border-color .14s;
        }

        .si-sale-select:focus {
            border-color: #1a1a1a;
            background: #fff;
        }

        .si-action-btn {
            font-family: 'Be Vietnam Pro', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 500;
            padding: 5px 11px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            transition: all .13s;
        }

        .si-action-edit {
            background: #fff8e1;
            color: #7a5200;
            border: 1px solid #ffe082;
        }

        .si-action-edit:hover {
            background: #fff3cd;
        }

        .si-action-delete {
            background: #fde8e8;
            color: #9b2626;
            border: 1px solid #f5b7b7;
        }

        .si-action-delete:hover {
            background: #fdd5d5;
        }

        /* ── Table footer ── */
        .si-table-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-top: 1px solid #f0ede8;
            background: #faf9f7;
            gap: 12px;
            flex-wrap: wrap;
        }

        @media (max-width: 540px) {
            .si-table-footer {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 12px 16px;
            }

            .si-table-footer>* {
                justify-content: center;
            }
        }

        .si-length-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .si-length-label {
            font-size: 13px;
            color: #888;
        }

        .si-length-select {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13px;
            color: #333;
            background: #fff;
            border: 1px solid #e2e0db;
            border-radius: 8px;
            padding: 5px 10px;
            outline: none;
            cursor: pointer;
        }

        .si-info {
            font-size: 13px;
            color: #999;
        }

        .si-pagination {
            display: flex;
            gap: 4px;
            align-items: center;
            flex-wrap: wrap;
        }

        .si-page-btn {
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13px;
            font-weight: 500;
            min-width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: 1px solid #e2e0db;
            background: #fff;
            color: #444;
            cursor: pointer;
            transition: all .13s;
            padding: 0 8px;
        }

        .si-page-btn:hover:not(:disabled):not(.active) {
            background: #f4f3f0;
            border-color: #bbb;
        }

        .si-page-btn.active {
            background: #1a1a1a;
            color: #fff;
            border-color: #1a1a1a;
        }

        .si-page-btn:disabled {
            opacity: .35;
            cursor: not-allowed;
        }

        /* ── States ── */
        .si-processing {
            text-align: center;
            padding: 40px 20px;
            font-size: 14px;
            color: #aaa;
        }

        .si-empty {
            text-align: center;
            padding: 48px 20px;
            color: #aaa;
            font-size: 14px;
        }

        .si-empty i {
            font-size: 32px;
            color: #ddd;
            display: block;
            margin-bottom: 10px;
        }

        /* ── Toast ── */
        #saleAssignToastContainer {
            position: fixed;
            top: 20px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        @media (max-width: 540px) {
            #saleAssignToastContainer {
                right: 12px;
                left: 12px;
            }
        }

        .si-toast {
            display: flex;
            align-items: center;
            gap: 10px;
            font-family: 'Be Vietnam Pro', sans-serif;
            font-size: 13.5px;
            font-weight: 500;
            padding: 12px 18px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, .15);
            animation: siToastIn .2s ease;
            min-width: 220px;
            max-width: 360px;
        }

        .si-toast-success {
            background: #1a1a1a;
            color: #fff;
        }

        .si-toast-danger {
            background: #c0392b;
            color: #fff;
        }

        @keyframes siToastIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
