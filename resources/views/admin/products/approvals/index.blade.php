@extends('admin.layout')

@section('styles')
<style>
    @import url('https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap');

    :root {
        --border: #ebedf2;
        --border-subtle: #ebedf2;
        --accent-green: #22c55e;
        --accent-green-dim: rgba(34,197,94,.12);
        --accent-red: #ef4444;
        --accent-red-dim: rgba(239,68,68,.12);
        --accent-amber: #f59e0b;
        --accent-blue: #3b82f6;
        --accent-blue-dim: rgba(59,130,246,.1);
        --radius: 10px;
        --radius-sm: 6px;
        --shadow: 0 4px 24px rgba(0,0,0,.4);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: var(--font);
        background: var(--bg-base);
        color: var(--text-primary);
    }

    /* ─── Page Header ───────────────────────────────── */
    .page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 28px;
        flex-wrap: wrap;
    }

    .page-title {
        font-size: 22px;
        font-weight: 700;
        letter-spacing: -.4px;
        color: var(--text-primary);
        line-height: 1.2;
    }

    .page-subtitle {
        font-size: 13px;
        color: var(--text-muted);
        margin-top: 4px;
        font-weight: 400;
    }

    /* ─── Panel ─────────────────────────────────────── */
    .panel {
        background: var(--bg-card);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        overflow: hidden;
    }

    .panel-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 16px 20px;
        border-bottom: 1px solid var(--border-subtle);
        flex-wrap: wrap;
    }

    /* ─── Search ─────────────────────────────────────── */
    .search-wrap {
        position: relative;
        flex: 1;
        min-width: 220px;
        max-width: 360px;
    }

    .search-icon {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        pointer-events: none;
        width: 15px;
        height: 15px;
    }

    .search-input {
        width: 100%;
        background: var(--bg-elevated);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font);
        font-size: 13.5px;
        padding: 9px 12px 9px 36px;
        transition: border-color .2s, box-shadow .2s;
        outline: none;
    }

    .search-input::placeholder { color: var(--text-muted); }

    .search-input:focus {
        border-color: var(--accent-blue);
        box-shadow: 0 0 0 3px var(--accent-blue-dim);
    }

    /* ─── Bulk Action Buttons ────────────────────────── */
    .bulk-actions { display: flex; gap: 8px; flex-shrink: 0; }

    .btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        font-family: var(--font);
        font-size: 13px;
        font-weight: 600;
        padding: 8px 16px;
        border-radius: var(--radius-sm);
        border: none;
        cursor: pointer;
        transition: all .18s ease;
        white-space: nowrap;
        letter-spacing: .01em;
    }

    .btn:disabled {
        opacity: .38;
        cursor: not-allowed;
        transform: none !important;
    }

    .btn-approve {
        background: var(--accent-green-dim);
        color: var(--accent-green);
        border: 1px solid rgba(34,197,94,.2);
    }

    .btn-approve:not(:disabled):hover {
        background: rgba(34,197,94,.2);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(34,197,94,.2);
    }

    .btn-reject {
        background: var(--accent-red-dim);
        color: var(--accent-red);
        border: 1px solid rgba(239,68,68,.2);
    }

    .btn-reject:not(:disabled):hover {
        background: rgba(239,68,68,.2);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(239,68,68,.2);
    }

    .btn-secondary {
        background: var(--bg-elevated);
        color: var(--text-secondary);
        border: 1px solid var(--border);
    }

    .btn-secondary:hover {
        background: var(--bg-hover);
        color: var(--text-primary);
    }

    /* ─── Count Badge on button ──────────────────────── */
    .btn-count {
        background: currentColor;
        color: var(--bg-base);
        border-radius: 20px;
        font-size: 10px;
        font-weight: 700;
        padding: 1px 6px;
        line-height: 1.6;
        font-family: var(--font-mono);
    }

    /* ─── Table Wrapper ──────────────────────────────── */
    .table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* DataTables reset & restyle */
    #productsTable_wrapper { padding: 0; }

    #productsTable {
        width: 100% !important;
        border-collapse: collapse;
    }

    #productsTable thead tr {
        background: var(--bg-elevated);
    }

    #productsTable thead th {
        font-family: var(--font);
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--text-muted);
        padding: 11px 16px;
        border: none;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        background: transparent;
    }

    #productsTable thead th:first-child { padding-left: 20px; }
    #productsTable thead th:last-child  { padding-right: 20px; }

    #productsTable tbody tr {
        border-bottom: 1px solid var(--border-subtle);
        transition: background .15s;
    }

    #productsTable tbody tr:last-child { border-bottom: none; }

    #productsTable tbody tr:hover { background: var(--bg-hover); }

    #productsTable tbody td {
        font-size: 13.5px;
        color: var(--text-primary);
        padding: 13px 16px;
        border: none;
        vertical-align: middle;
    }

    #productsTable tbody td:first-child { padding-left: 20px; }
    #productsTable tbody td:last-child  { padding-right: 20px; }

    /* Striped override — subtle */
    #productsTable.table-striped tbody tr:nth-of-type(odd) {
        background: rgba(255,255,255,.012);
    }

    #productsTable.table-striped tbody tr:nth-of-type(odd):hover {
        background: var(--bg-hover);
    }

    /* ─── Product Cell ───────────────────────────────── */
    .product-cell { display: flex; align-items: center; gap: 12px; }

    .product-thumb {
        width: 42px;
        height: 42px;
        border-radius: var(--radius-sm);
        object-fit: cover;
        background: var(--bg-elevated);
        flex-shrink: 0;
        border: 1px solid var(--border);
    }

    .product-thumb-placeholder {
        width: 42px;
        height: 42px;
        border-radius: var(--radius-sm);
        background: var(--bg-elevated);
        border: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        flex-shrink: 0;
    }

    .product-name {
        font-weight: 600;
        font-size: 13.5px;
        color: var(--text-primary);
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 200px;
    }

    .product-sku {
        font-family: var(--font-mono);
        font-size: 11px;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .approval-attempt-cell {
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        gap: 2px;
        min-width: 70px;
    }

    .approval-attempt-badge {
        font-size: 12px;
        font-weight: 700;
        color: var(--accent-blue);
        background: var(--accent-blue-dim);
        border: 1px solid rgba(59,130,246,.22);
        border-radius: 999px;
        padding: 3px 9px;
        white-space: nowrap;
    }

    .approval-attempt-sub {
        font-size: 11px;
        color: var(--text-muted);
        white-space: nowrap;
    }

    /* ─── Store / Category chips ─────────────────────── */
    .chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        font-weight: 500;
        padding: 3px 9px;
        border-radius: 20px;
        background: var(--bg-elevated);
        color: var(--text-secondary);
        border: 1px solid var(--border);
        white-space: nowrap;
    }

    /* ─── Variant badge ──────────────────────────────── */
    .badge-variant {
        font-family: var(--font-mono);
        font-size: 12px;
        font-weight: 500;
        color: var(--accent-amber);
        background: rgba(245,158,11,.1);
        border: 1px solid rgba(245,158,11,.2);
        padding: 2px 8px;
        border-radius: 20px;
    }

    /* ─── Price ──────────────────────────────────────── */
    .price-label {
        font-weight: 600;
        font-size: 13px;
        color: var(--text-primary);
        font-family: var(--font-mono);
    }

    /* ─── Date ───────────────────────────────────────── */
    .date-cell {
        font-size: 12.5px;
        color: var(--text-secondary);
        font-family: var(--font-mono);
    }

    /* ─── Row action buttons ─────────────────────────── */
    .row-actions { display: flex; gap: 6px; justify-content: center; }

    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: var(--radius-sm);
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all .15s;
        font-size: 14px;
        background: transparent;
    }

    .action-btn-approve {
        color: var(--accent-green);
        border-color: rgba(34,197,94,.2);
        background: var(--accent-green-dim);
    }

    .action-btn-approve:hover {
        background: rgba(34,197,94,.25);
        box-shadow: 0 2px 8px rgba(34,197,94,.2);
        transform: scale(1.08);
    }

    .action-btn-reject {
        color: var(--accent-red);
        border-color: rgba(239,68,68,.2);
        background: var(--accent-red-dim);
    }

    .action-btn-reject:hover {
        background: rgba(239,68,68,.25);
        box-shadow: 0 2px 8px rgba(239,68,68,.2);
        transform: scale(1.08);
    }

    .action-btn-view {
        color: var(--text-secondary);
        border-color: var(--border);
        background: var(--bg-elevated);
    }

    .action-btn-view:hover {
        color: var(--accent-blue);
        border-color: var(--accent-blue);
        background: var(--accent-blue-dim);
    }

    /* ─── Checkbox ───────────────────────────────────── */
    .form-check-input,
    input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: var(--accent-blue);
        cursor: pointer;
        border-radius: 4px;
    }

    /* ─── DataTables footer / pagination ─────────────── */
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_info {
        font-family: var(--font);
        font-size: 12.5px;
        color: var(--text-muted);
        padding: 14px 20px;
    }

    .dataTables_wrapper .dataTables_length select {
        background: var(--bg-elevated);
        border: 1px solid var(--border);
        color: var(--text-primary);
        border-radius: var(--radius-sm);
        padding: 4px 8px;
        font-family: var(--font);
        font-size: 12.5px;
        outline: none;
    }

    .dataTables_wrapper .dataTables_paginate {
        padding: 10px 20px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 4px;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button {
        font-family: var(--font);
        font-size: 12.5px;
        font-weight: 500;
        padding: 5px 11px !important;
        border-radius: var(--radius-sm) !important;
        border: 1px solid var(--border) !important;
        color: var(--text-secondary) !important;
        background: var(--bg-elevated) !important;
        cursor: pointer;
        transition: all .15s;
        line-height: 1.4;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        color: var(--text-primary) !important;
        background: var(--bg-hover) !important;
        border-color: var(--accent-blue) !important;
        box-shadow: none !important;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: var(--accent-blue) !important;
        border-color: var(--accent-blue) !important;
        color: #fff !important;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
        opacity: .35;
        cursor: not-allowed;
    }

    .dataTables_footer-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-top: 1px solid var(--border-subtle);
        flex-wrap: wrap;
    }

    /* DataTables processing overlay */
    .dataTables_wrapper .dataTables_processing {
        background: var(--bg-card);
        color: var(--text-secondary);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: var(--font);
        font-size: 13px;
        box-shadow: var(--shadow);
        padding: 10px 20px;
    }

    

    .form-label {
        font-size: 12.5px;
        font-weight: 600;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: .06em;
        display: block;
        margin-bottom: 8px;
    }

    .form-textarea {
        width: 100%;
        background: var(--bg-elevated);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        color: var(--text-primary);
        font-family: var(--font);
        font-size: 13.5px;
        padding: 10px 13px;
        resize: vertical;
        min-height: 110px;
        transition: border-color .2s, box-shadow .2s;
        outline: none;
    }

    .form-textarea::placeholder { color: var(--text-muted); }

    .form-textarea:focus {
        border-color: var(--accent-red);
        box-shadow: 0 0 0 3px rgba(239,68,68,.12);
    }

    .btn-close-custom {
        background: transparent;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: 4px;
        border-radius: 4px;
        line-height: 1;
        font-size: 18px;
        transition: color .15s;
    }

    .btn-close-custom:hover { color: var(--text-primary); }

    /* ─── Empty state ────────────────────────────────── */
    .empty-state {
        padding: 60px 20px;
        text-align: center;
    }

    .empty-icon {
        width: 52px;
        height: 52px;
        background: var(--bg-elevated);
        border: 1px solid var(--border);
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--text-muted);
        margin-bottom: 14px;
        font-size: 22px;
    }

    .empty-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-secondary);
    }

    /* ─── Selection bar ──────────────────────────────── */
    .selection-bar {
        display: none;
        align-items: center;
        gap: 10px;
        padding: 9px 16px;
        background: var(--accent-blue-dim);
        border-bottom: 1px solid rgba(59,130,246,.2);
        font-size: 13px;
        color: var(--text-secondary);
    }

    .selection-bar.active { display: flex; }

    .selection-count {
        font-weight: 700;
        color: var(--accent-blue);
        font-family: var(--font-mono);
    }

    /* ─── Responsive ─────────────────────────────────── */
    @media (max-width: 768px) {
        .page-header { margin-bottom: 18px; }
        .page-title { font-size: 18px; }
        .panel-toolbar { flex-direction: column; align-items: stretch; }
        .search-wrap { max-width: 100%; }
        .bulk-actions { justify-content: flex-end; }
        #productsTable thead th:nth-child(4),
        #productsTable tbody td:nth-child(4),
        #productsTable thead th:nth-child(7),
        #productsTable tbody td:nth-child(7) { display: none; }
        .product-name { max-width: 130px; }
        .dataTables_footer-row { flex-direction: column; align-items: flex-start; }
        .dataTables_wrapper .dataTables_paginate { justify-content: flex-start; }
    }

    @media (max-width: 480px) {
        #productsTable thead th:nth-child(5),
        #productsTable tbody td:nth-child(5) { display: none; }
        .btn { padding: 8px 12px; font-size: 12px; }
    }
</style>
@endsection

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Sản phẩm chờ duyệt</h1>
        <p class="page-subtitle">Danh sách tất cả sản phẩm đang chờ admin duyệt</p>
    </div>
</div>

<div class="panel">

    {{-- Toolbar --}}
    <div class="panel-toolbar">
        <div class="search-wrap">
            <svg class="search-icon" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="text" id="productSearch" class="search-input"
                   placeholder="Tên SP, SKU, cửa hàng, danh mục...">
        </div>

        <div class="bulk-actions">
            <button id="approveSelectedProducts" class="btn btn-approve" disabled>
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
                <span id="approveLabel">Duyệt đã chọn</span>
            </button>
            <button id="rejectSelectedProducts" class="btn btn-reject" disabled>
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
                <span id="rejectLabel">Từ chối đã chọn</span>
            </button>
        </div>
    </div>

    {{-- Selection info bar --}}
    <div class="selection-bar" id="selectionBar">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
        </svg>
        Đã chọn <span class="selection-count" id="selectionCount">0</span> sản phẩm
    </div>

    {{-- Table --}}
    <div class="table-wrap">
        <table id="productsTable" class="table table-striped w-100">
            <thead>
                <tr>
                    <th width="40">
                        <input type="checkbox" id="selectAllProducts">
                    </th>
                    <th>Sản phẩm</th>
                    <th>L&#7847;n duy&#7879;t</th>
                    <th>Cửa hàng</th>
                    <th>Danh mục</th>
                    <th>Biến thể</th>
                    <th>Giá</th>
                    {{-- <th>Ngày tạo</th> --}}
                    <th>Ticket</th>
                    <th>AFF</th>
                    <th width="110">Hành động</th>
                </tr>
            </thead>
        </table>
    </div>

    {{-- DT footer injected by drawCallback --}}
</div>

{{-- Bulk Reject Modal --}}
<div class="modal fade" id="bulkRejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title">Từ chối sản phẩm hàng loạt</h5>
                <button type="button" class="btn-close-custom" data-bs-dismiss="modal">&#215;</button>
            </div>

            <div class="modal-body">
                <label class="form-label">Lý do từ chối</label>
                <textarea id="bulkRejectReason" class="form-textarea"
                          placeholder="Nhập lý do từ chối..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="button" id="confirmBulkReject" class="btn btn-reject">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                    Từ chối sản phẩm
                </button>
            </div>

        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
<script>
$(function () {

    let selectedIds = [];

    /* ─── DataTable init ─────────────────────────── */
    const table = $('#productsTable').DataTable({
        processing : true,
        serverSide : true,
        autoWidth  : false,
        pageLength : 20,
        searchDelay: 300,
        dom: 'rt<"dataTables_footer-row"<"dataTables_length-wrap"l><"dataTables_info-wrap"i><"dataTables_paginate-wrap"p>>',

        ajax: {
            url : '{{ route('admin.products.approvals.datatable') }}',
            type: 'GET',
        },

        language: {
            processing  : 'Đang xử lý...',
            search      : '',
            lengthMenu  : '_MENU_',
            info        : 'Hiển thị _START_–_END_ / _TOTAL_',
            infoEmpty   : 'Không có mục nào',
            infoFiltered: '(lọc từ _MAX_)',
            loadingRecords : 'Đang tải...',
            zeroRecords    : '<div class="empty-state"><div class="empty-icon">📭</div><div class="empty-title">Không tìm thấy sản phẩm phù hợp</div></div>',
            emptyTable     : '<div class="empty-state"><div class="empty-icon">🎉</div><div class="empty-title">Không có sản phẩm chờ duyệt</div></div>',
            paginate: { first: '«', previous: '‹', next: '›', last: '»' }
        },

        order: [[10, 'desc']],

        columns: [
            { data: 'checkbox',       orderable: false, searchable: false },
            { data: 'product',        name: 'name' },
            { data: 'approval_attempt', searchable: false, orderable: false },
            { data: 'store',          orderable: false },
            { data: 'category',       orderable: false },
            { data: 'variants_count', searchable: false },
            { data: 'price_label',    searchable: false, orderable: false },
            { data: 'ticket_reward_label', searchable: false, orderable: false },
            { data: 'affiliate_commission_label', searchable: false, orderable: false },
            { data: 'action',         orderable: false, searchable: false },
            { data: 'created_at',     name: 'created_at', visible: false, searchable: false }
        ],

        columnDefs: [
            { targets: [0, 2, 4, 7, 8, 9], className: 'text-center align-middle' }
        ],

        drawCallback: function () {
            $('.product-row-checkbox').each(function () {
                $(this).prop('checked', selectedIds.includes($(this).val()));
            });
            syncHeaderCheckbox();
            updateBulkButtons();

            // Sync the sidebar badge with the actual count from the server response
            try {
                const api = this.api();
                const info = api.page.info();
                if (info) {
                    const totalPending = info.recordsTotal;
                    const badge = document.getElementById('pending-products-badge');
                    if (badge) {
                        if (totalPending <= 0) {
                            badge.style.display = 'none';
                        } else {
                            badge.style.display = 'inline-block';
                            badge.innerText = totalPending;
                        }
                    } else if (totalPending > 0) {
                        const link = document.querySelector('a[href*="product-approvals"]');
                        if (link) {
                            const newBadge = document.createElement('span');
                            newBadge.id = 'pending-products-badge';
                            newBadge.className = 'sb-badge badge bg-danger';
                            newBadge.innerText = totalPending;
                            link.appendChild(newBadge);
                        }
                    }
                    if (typeof window.updateCuaHangTotalBadge === 'function') {
                        window.updateCuaHangTotalBadge();
                    }
                }
            } catch (e) {
                console.error('Failed to update pending products badge:', e);
            }
        }
    });

    /* ─── Search ─────────────────────────────────── */
    $('#productSearch').on('input', function () {
        table.search(this.value).draw();
    });

    /* ─── Bulk button state ──────────────────────── */
    function updateBulkButtons () {
        const n = selectedIds.length;

        $('#approveSelectedProducts').prop('disabled', !n);
        $('#rejectSelectedProducts' ).prop('disabled', !n);

        $('#approveLabel').text(n ? `Duyệt đã chọn (${n})` : 'Duyệt đã chọn');
        $('#rejectLabel' ).text(n ? `Từ chối đã chọn (${n})` : 'Từ chối đã chọn');

        $('#selectionCount').text(n);
        $('#selectionBar').toggleClass('active', n > 0);
    }

    function syncHeaderCheckbox () {
        const total   = $('.product-row-checkbox').length;
        const checked = $('.product-row-checkbox:checked').length;
        $('#selectAllProducts').prop('checked', total > 0 && total === checked);
    }

    /* ─── Row checkbox ───────────────────────────── */
    $('#productsTable').on('change', '.product-row-checkbox', function () {
        const id = $(this).val();
        if ($(this).is(':checked')) {
            if (!selectedIds.includes(id)) selectedIds.push(id);
        } else {
            selectedIds = selectedIds.filter(i => i != id);
        }
        updateBulkButtons();
        syncHeaderCheckbox();
    });

    /* ─── Select-all ─────────────────────────────── */
    $('#selectAllProducts').on('change', function () {
        const checked = $(this).is(':checked');
        $('.product-row-checkbox').each(function () {
            const id = $(this).val();
            $(this).prop('checked', checked);
            if (checked) {
                if (!selectedIds.includes(id)) selectedIds.push(id);
            } else {
                selectedIds = selectedIds.filter(i => i != id);
            }
        });
        updateBulkButtons();
    });

    /* ─── Bulk approve ───────────────────────────── */
    $('#approveSelectedProducts').on('click', function () {
        if (!selectedIds.length) return;
        if (!confirm(`Duyệt ${selectedIds.length} sản phẩm?`)) return;

        const ids    = [...selectedIds];
        const button = $(this);

        button.prop('disabled', true).find('#approveLabel').text('Đang duyệt...');

        axios.post('{{ route('admin.product-approvals.bulk-approve') }}', { ids })
            .then(() => {
                selectedIds = [];
                $('#selectAllProducts').prop('checked', false);
                table.ajax.reload(null, false);
                window.updatePendingProductsBadge(-ids.length);
                window.showAppSuccess('Đã duyệt sản phẩm.', 'Thành công');
            })
            .catch(() => window.showAppError('Duyệt sản phẩm thất bại.', 'Lỗi'))
            .finally(() => updateBulkButtons());
    });

    /* ─── Bulk reject (open modal) ───────────────── */
    $('#rejectSelectedProducts').on('click', function () {
        if (!selectedIds.length) return;
        $('#bulkRejectModal').modal('show');
    });

    /* ─── Confirm bulk reject ────────────────────── */
    $('#confirmBulkReject').on('click', function () {
        if (!selectedIds.length) return;

        const reason = $('#bulkRejectReason').val().trim();
        if (!reason) {
            window.showAppError('Vui lòng nhập lý do từ chối.', 'Lỗi');
            return;
        }

        const ids    = [...selectedIds];
        const button = $(this);

        button.prop('disabled', true).html(`
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
            Đang xử lý...
        `);

        axios.post('{{ route('admin.product-approvals.bulk-reject') }}', {
            ids,
            rejection_reason: reason
        })
        .then(() => {
            selectedIds = [];
            $('#bulkRejectModal').modal('hide');
            $('#bulkRejectReason').val('');
            $('#selectAllProducts').prop('checked', false);
            table.ajax.reload(null, false);
            window.updatePendingProductsBadge(-ids.length);
            window.showAppSuccess('Đã từ chối sản phẩm.', 'Thành công');
        })
        .catch(() => window.showAppError('Từ chối sản phẩm thất bại.', 'Lỗi'))
        .finally(() => {
            button.prop('disabled', false).html(`
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M18 6 6 18M6 6l12 12"/>
                </svg>
                Từ chối sản phẩm
            `);
            updateBulkButtons();
        });
    });

    /* ─── Realtime Pusher integration ────────────────── */
    (function () {
        if (typeof Pusher === 'undefined') {
            return;
        }

        const appKey = @json(config('broadcasting.connections.pusher.key'));
        const appCluster = @json(config('broadcasting.connections.pusher.options.cluster'));

        if (!appKey) {
            return;
        }

        const pusher = new Pusher(appKey, {
            cluster: appCluster || undefined,
            forceTLS: true,
            disableStats: true,
        });

        const channel = pusher.subscribe('product-approvals');
        channel.bind('product.approval.updated', function (data) {
            console.log('Realtime product approval event received:', data);
            table.ajax.reload(null, false);
        });

        window.addEventListener('beforeunload', function () {
            channel.unbind('product.approval.updated');
            pusher.unsubscribe('product-approvals');
            pusher.disconnect();
        });
    })();

});
</script>
@endsection
