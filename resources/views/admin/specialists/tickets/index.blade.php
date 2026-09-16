@extends('admin.layout')

@section('styles')
    <style>
        .specialist-ticket-mobile-panel {
            display: none;
        }

        @media (max-width: 767.98px) {
            html,
            body {
                overflow-x: hidden;
            }

            body,
            .main-panel,
            .page-inner,
            .specialist-ticket-screen {
                background: #ffffff !important;
            }

            .main-header,
            .main-header .main-header-logo,
            .main-header .logo-header,
            .main-header .navbar-header,
            .main-header .navbar-header .container-fluid {
                background: #071d3a !important;
                border-color: rgba(255, 255, 255, 0.08) !important;
            }

            .main-header .navbar-header .nav-link,
            .main-header .navbar-header .nav-link i,
            .main-header .logo-header .btn-toggle,
            .main-header .logo-header .topbar-toggler {
                color: #ffffff !important;
            }

            .custom-template,
            .custom-toggle {
                display: none !important;
            }

            .page-inner {
                padding: 16px 20px 28px !important;
            }

            .main-panel > .container,
            .specialist-ticket-screen {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .specialist-ticket-heading {
                margin-bottom: 12px !important;
            }

            .specialist-ticket-title {
                color: #182235;
                font-size: 17px;
                font-weight: 700;
                line-height: 1.3;
            }

            .specialist-ticket-filter-card,
            .specialist-ticket-table-card {
                border: 0;
                border-radius: 0;
                box-shadow: none;
                background: transparent;
            }

            .specialist-ticket-filter-card {
                margin-bottom: 12px !important;
            }

            .specialist-ticket-filter-card .card-body {
                padding: 0 !important;
            }

            #specialistTicketFilters {
                --bs-gutter-x: 8px;
                --bs-gutter-y: 8px;
                align-items: stretch !important;
            }

            #specialistTicketFilters .form-label {
                color: #243047;
                font-size: 10px;
                font-weight: 700;
                line-height: 1.2;
                margin-bottom: 5px;
            }

            #specialistTicketFilters .ticket-filter-field,
            #specialistTicketFilters .ticket-filter-actions {
                min-width: 0;
            }

            #specialistTicketFilters .ticket-filter-field--half {
                flex: 0 0 auto;
                width: 50%;
            }

            #specialistTicketFilters .form-control {
                width: 100%;
                min-height: 36px;
                border: 1px solid #dce3ee;
                border-radius: 6px;
                background-color: #ffffff;
                color: #1f2937;
                font-size: 11px;
                line-height: 1.3;
                padding: 8px 10px;
                box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
            }

            #specialistTicketFilters select.form-control {
                padding-right: 24px;
                text-overflow: ellipsis;
            }

            #specialistTicketFilters .form-control::placeholder {
                color: #9aa5b1;
            }

            #specialistTicketFilters .ticket-filter-actions {
                display: grid !important;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                gap: 8px;
                margin-top: 2px;
            }

            #specialistTicketFilters .btn {
                width: 100%;
                min-height: 36px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 700;
                line-height: 1.2;
                padding: 8px 10px;
            }

            #specialistTicketFilters .btn-primary {
                background: #0d6efd;
                border-color: #0d6efd;
                box-shadow: 0 6px 12px rgba(13, 110, 253, 0.16);
            }

            #specialistTicketFilters .btn-outline-secondary {
                background: #ffffff;
                border-color: #b9c3d1;
                color: #536071;
            }

            .specialist-ticket-table-card .card-body {
                padding: 0 !important;
            }

            .specialist-ticket-table-card .table-responsive {
                overflow: visible;
            }

            #specialistTicketsTable {
                display: none !important;
            }

            .specialist-ticket-mobile-panel {
                display: block;
            }

            .specialist-ticket-mobile-toolbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                margin: 14px 0 10px;
                color: #172033;
                font-size: 11px;
                font-weight: 700;
            }

            .specialist-ticket-sort-button {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                border: 0;
                background: transparent;
                color: #172033;
                font-size: 11px;
                font-weight: 700;
                padding: 0;
                white-space: nowrap;
            }

            .specialist-ticket-mobile-list {
                display: grid;
                gap: 12px;
            }

            .specialist-ticket-card {
                width: 100%;
                min-width: 0;
                padding: 13px 14px;
                border: 1px solid #edf1f7;
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 7px 18px rgba(15, 23, 42, 0.07);
            }

            .specialist-ticket-card__top {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 10px;
            }

            .specialist-ticket-card__code {
                min-width: 0;
                color: #0f172a;
                font-size: 11px;
                line-height: 1.35;
                word-break: break-word;
            }

            .specialist-ticket-card__code .fw-semibold {
                font-size: 12px;
                font-weight: 800 !important;
                letter-spacing: 0;
            }

            .specialist-ticket-card__status {
                flex: 0 0 auto;
                text-align: right;
            }

            .specialist-ticket-card .badge {
                border-radius: 5px;
                font-size: 9px;
                font-weight: 700;
                line-height: 1;
                padding: 5px 7px;
            }

            .specialist-ticket-card__grid {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                column-gap: 12px;
                row-gap: 9px;
            }

            .specialist-ticket-field {
                display: grid;
                grid-template-columns: 13px minmax(0, 1fr);
                gap: 6px;
                min-width: 0;
                color: #1f2937;
                font-size: 10px;
                line-height: 1.35;
            }

            .specialist-ticket-field--wide {
                grid-column: 1 / -1;
            }

            .specialist-ticket-field__icon {
                color: #64748b;
                font-size: 10px;
                line-height: 1.35;
                padding-top: 2px;
            }

            .specialist-ticket-field__content {
                min-width: 0;
                word-break: break-word;
            }

            .specialist-ticket-field__label {
                display: block;
                color: #69778a;
                font-size: 9px;
                font-weight: 700;
                line-height: 1.2;
                margin-bottom: 2px;
            }

            .specialist-ticket-field .fw-semibold {
                color: #1d2738;
                font-size: 10px;
                font-weight: 700 !important;
            }

            .specialist-ticket-field small,
            .specialist-ticket-card small {
                color: #7a8796 !important;
                font-size: 9px;
                line-height: 1.25;
            }

            .specialist-ticket-field .text-success,
            .specialist-ticket-field .text-danger,
            .specialist-ticket-field .text-muted {
                font-weight: 700;
            }

            .specialist-ticket-empty {
                padding: 18px 14px;
                border: 1px dashed #d6deea;
                border-radius: 8px;
                background: #ffffff;
                color: #69778a;
                font-size: 12px;
                font-weight: 600;
                text-align: center;
            }

            #specialistTicketsTable_wrapper > .row {
                margin: 12px 0 0 !important;
                padding: 0;
            }

            #specialistTicketsTable_wrapper > .row > [class*="col-"] {
                width: 100%;
                padding: 0 !important;
                text-align: center !important;
            }

            #specialistTicketsTable_wrapper .dataTables_length {
                display: none;
            }

            #specialistTicketsTable_wrapper .dataTables_paginate .pagination {
                justify-content: center;
                flex-wrap: wrap;
                gap: 5px;
                margin: 0;
            }

            #specialistTicketsTable_wrapper .dataTables_paginate .page-link {
                min-width: 30px;
                border-radius: 6px !important;
                color: #1f2a3d;
                font-size: 11px;
                font-weight: 700;
                line-height: 1.2;
                padding: 7px 9px;
            }

            #specialistTicketsTable_wrapper .dataTables_paginate .active .page-link {
                background: #0d6efd;
                border-color: #0d6efd;
                color: #ffffff;
            }
        }
    </style>
@endsection

@section('content')
    <div class="container-fluid specialist-ticket-screen">
        <div class="specialist-ticket-heading d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <h1 class="specialist-ticket-title mb-0">
                <span class="d-md-none">Lucky Ticket của tôi</span>
                <span class="d-none d-md-inline">{{ $isSaleUser ? 'Lucky Ticket của tôi' : 'Lucky Ticket chuyên viên' }}</span>
            </h1>
        </div>

        <div class="card specialist-ticket-filter-card mb-3">
            <div class="card-body">
                <form id="specialistTicketFilters" class="row g-3 align-items-end">
                    <div class="ticket-filter-field ticket-filter-field--search col-md-3">
                        <label class="form-label">Tìm kiếm</label>
                        <input type="text" class="form-control" name="keyword"
                            placeholder="Mã ticket, khách hàng, đơn, cửa hàng">
                    </div>

                    <div class="ticket-filter-field ticket-filter-field--half col-md-2">
                        <label class="form-label">Trạng thái ticket</label>
                        <select class="form-control" name="status">
                            @foreach ($statusOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ticket-filter-field ticket-filter-field--half col-md-3">
                        <label class="form-label">Cửa hàng</label>
                        <select class="form-control" name="store_id">
                            <option value="">Tất cả cửa hàng</option>
                            @foreach ($storeOptions as $store)
                                <option value="{{ $store->id }}">
                                    {{ $store->name }}{{ !empty($store->store_code) ? ' - ' . $store->store_code : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="ticket-filter-field ticket-filter-field--half col-md-2">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" class="form-control" name="date_from">
                    </div>

                    <div class="ticket-filter-field ticket-filter-field--half col-md-2">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" class="form-control" name="date_to">
                    </div>

                    <div class="ticket-filter-actions col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Lọc dữ liệu</button>
                        <button type="button" id="resetSpecialistTicketFilters"
                            class="btn btn-outline-secondary">Reset</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card specialist-ticket-table-card">
            <div class="card-body p-0">
                <div class="specialist-ticket-mobile-panel">
                    <div class="specialist-ticket-mobile-toolbar">
                        <span id="specialistTicketResultCount">0 kết quả</span>
                        <button type="button" id="specialistTicketSortNewest" class="specialist-ticket-sort-button">
                            <span>Mới nhất</span>
                            <i class="fas fa-sort-amount-down-alt"></i>
                        </button>
                    </div>
                    <div id="specialistTicketMobileList" class="specialist-ticket-mobile-list"></div>
                </div>

                <div class="table-responsive">
                    <table id="specialistTicketsTable" class="table table-striped table-hover mb-0 align-middle"
                        style="width:100%">
                        <thead>
                            <tr>
                                <th>Mã ticket</th>
                                <th>Cửa hàng</th>
                                <th>Đơn hàng</th>
                                <th>Khách hàng</th>
                                <th>Trạng thái ticket</th>
                                <th>Thời gian phát sinh</th>
                                <th>Tính thu nhập</th>
                                <th>Thu nhập</th>
                                <th>Lý do treo/không tính</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var params = new URLSearchParams(window.location.search);
            var $filters = $('#specialistTicketFilters');

            function getState() {
                return {
                    keyword: params.get('keyword') || '',
                    status: params.get('status') || 'all',
                    store_id: params.get('store_id') || '',
                    date_from: params.get('date_from') || '',
                    date_to: params.get('date_to') || '',
                    page: Math.max(1, parseInt(params.get('page') || '1', 10)),
                    length: Math.max(1, parseInt(params.get('length') || '20', 10)),
                    orderCol: Math.max(0, parseInt(params.get('order_col') || '5', 10)),
                    orderDir: params.get('order_dir') === 'asc' ? 'asc' : 'desc'
                };
            }

            var state = getState();
            $filters.find('[name="keyword"]').val(state.keyword);
            $filters.find('[name="status"]').val(state.status);
            $filters.find('[name="store_id"]').val(state.store_id);
            $filters.find('[name="date_from"]').val(state.date_from);
            $filters.find('[name="date_to"]').val(state.date_to);

            var table = $('#specialistTicketsTable').DataTable({
                dom: 'rt<"row mt-3 align-items-center"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                pageLength: state.length,
                displayStart: (state.page - 1) * state.length,
                order: [[state.orderCol, state.orderDir]],
                ajax: {
                    url: '{{ route('admin.tickets.specialist.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.keyword = $filters.find('[name="keyword"]').val();
                        d.status = $filters.find('[name="status"]').val();
                        d.store_id = $filters.find('[name="store_id"]').val();
                        d.date_from = $filters.find('[name="date_from"]').val();
                        d.date_to = $filters.find('[name="date_to"]').val();
                    }
                },
                language: {
                    processing: 'Đang xử lý...',
                    lengthMenu: '_MENU_ mục',
                    info: 'Hiển thị _START_ đến _END_ của _TOTAL_ mục',
                    infoEmpty: 'Hiển thị 0 đến 0 của 0 mục',
                    infoFiltered: '(lọc từ _MAX_ mục)',
                    loadingRecords: 'Đang tải...',
                    zeroRecords: 'Không tìm thấy dữ liệu phù hợp',
                    emptyTable: 'Chưa có dữ liệu',
                    paginate: {
                        first: 'Đầu',
                        previous: 'Trước',
                        next: 'Tiếp',
                        last: 'Cuối'
                    }
                },
                lengthMenu: [[20, 50, 100], [20, 50, 100]],
                columns: [
                    { data: 'ticket_code' },
                    { data: 'store' },
                    { data: 'order' },
                    { data: 'owner' },
                    { data: 'ticket_status' },
                    { data: 'generated_at' },
                    { data: 'income_eligible', orderable: false, searchable: false },
                    { data: 'earning_amount' },
                    { data: 'reason', orderable: false, searchable: false },
                ],
                columnDefs: [
                    { targets: [0, 1, 2, 3, 4, 8], className: 'align-top' },
                    { targets: [5, 6, 7], className: 'text-center align-top' },
                ]
            });

            var $mobileList = $('#specialistTicketMobileList');
            var $mobileResultCount = $('#specialistTicketResultCount');

            function mobileBadgeOnly(html) {
                var $html = $('<div>').html(html || '');
                var $badge = $html.find('.badge').first();

                return $badge.length ? $badge.prop('outerHTML') : (html || '');
            }

            function mobileField(label, value, iconClass, isWide) {
                return '<div class="specialist-ticket-field' + (isWide ? ' specialist-ticket-field--wide' : '') + '">' +
                    '<i class="specialist-ticket-field__icon ' + iconClass + '"></i>' +
                    '<div class="specialist-ticket-field__content">' +
                    '<span class="specialist-ticket-field__label">' + label + '</span>' +
                    '<div>' + (value || '<span class="text-muted">N/A</span>') + '</div>' +
                    '</div>' +
                    '</div>';
            }

            function renderMobileTickets() {
                var info = table.page.info();
                var rows = table.rows({ page: 'current' }).data().toArray();
                var total = info ? info.recordsDisplay : rows.length;

                $mobileResultCount.text(total + ' kết quả');

                if (!rows.length) {
                    $mobileList.html('<div class="specialist-ticket-empty">Không tìm thấy dữ liệu phù hợp</div>');
                    return;
                }

                var cards = rows.map(function (row) {
                    return '<article class="specialist-ticket-card">' +
                        '<div class="specialist-ticket-card__top">' +
                        '<div class="specialist-ticket-card__code">' + (row.ticket_code || '') + '</div>' +
                        '<div class="specialist-ticket-card__status">' + mobileBadgeOnly(row.ticket_status) + '</div>' +
                        '</div>' +
                        '<div class="specialist-ticket-card__grid">' +
                        mobileField('Cửa hàng', row.store, 'fas fa-store') +
                        mobileField('Đơn hàng', row.order, 'fas fa-receipt') +
                        mobileField('Khách hàng', row.owner, 'fas fa-user') +
                        mobileField('Thời gian', row.generated_at, 'far fa-clock') +
                        mobileField('Tính thu nhập', row.income_eligible, 'fas fa-check-circle') +
                        mobileField('Thu nhập', row.earning_amount, 'fas fa-coins') +
                        mobileField('Lý do', row.reason, 'fas fa-info-circle', true) +
                        '</div>' +
                        '</article>';
                }).join('');

                $mobileList.html(cards);
            }

            function syncUrl(resetPage) {
                var info = table.page.info();
                var order = table.order()[0] || [5, 'desc'];

                ['keyword', 'status', 'store_id', 'date_from', 'date_to'].forEach(function (key) {
                    var value = $filters.find('[name="' + key + '"]').val();
                    if (value && !(key === 'status' && value === 'all')) {
                        params.set(key, value);
                    } else {
                        params.delete(key);
                    }
                });

                params.set('page', resetPage ? '1' : (info.page + 1));
                params.set('length', info.length);
                params.set('order_col', order[0]);
                params.set('order_dir', order[1]);

                var queryString = params.toString();
                window.history.replaceState(null, '', queryString ? window.location.pathname + '?' + queryString : window.location.pathname);
            }

            $filters.on('submit', function (event) {
                event.preventDefault();
                syncUrl(true);
                table.page('first').ajax.reload();
            });

            $filters.find('select, input[type="date"]').on('change', function () {
                syncUrl(true);
                table.page('first').ajax.reload();
            });

            $filters.find('[name="keyword"]').on('input', function () {
                clearTimeout(window.specialistTicketSearchTimer);
                window.specialistTicketSearchTimer = setTimeout(function () {
                    syncUrl(true);
                    table.page('first').ajax.reload();
                }, 250);
            });

            $('#resetSpecialistTicketFilters').on('click', function () {
                $filters.find('[name="keyword"]').val('');
                $filters.find('[name="status"]').val('all');
                $filters.find('[name="store_id"]').val('');
                $filters.find('[name="date_from"]').val('');
                $filters.find('[name="date_to"]').val('');
                syncUrl(true);
                table.page('first').ajax.reload();
            });

            $('#specialistTicketSortNewest').on('click', function () {
                table.order([[5, 'desc']]);
                table.page('first');
                syncUrl(true);
                table.draw();
            });

            table.on('processing.dt', function (event, settings, processing) {
                if (processing && window.matchMedia('(max-width: 767.98px)').matches) {
                    $mobileList.html('<div class="specialist-ticket-empty">Đang tải dữ liệu...</div>');
                }
            });

            table.on('draw', function () {
                syncUrl(false);
                renderMobileTickets();
            });
        });
    </script>
@endsection
