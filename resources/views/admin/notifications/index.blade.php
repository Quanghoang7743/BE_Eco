@extends('admin.layout')

@section('styles')
    <style>
        #notificationsTable th,
        #notificationsTable td {
            font-size: 13px !important;
            padding: 16px 20px !important;
            vertical-align: middle !important;
            border-bottom: 1px solid #f1f5f9 !important;
        }

        #notificationsTable th {
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            font-weight: 700 !important;
            color: #475569 !important;
            background-color: #ffffff !important;
            border-bottom: 1px solid #e2e8f0 !important;
        }

        /* Row styling based on read status */
        .notification-row-unread {
            background-color: #fffaf0 !important; /* Soft warm cream matching the mockup */
            transition: background-color 0.2s ease;
        }

        .notification-row-read {
            background-color: #ffffff !important;
            transition: background-color 0.2s ease;
        }

        .notification-row-unread:hover {
            background-color: #fff5e6 !important;
        }

        .notification-row-read:hover {
            background-color: #f8fafc !important;
        }

        /* Buttons and filters */
        .custom-input {
            border: 1px solid #e2e8f0 !important;
            border-radius: 6px !important;
            height: 38px !important;
            font-size: 13.5px !important;
            color: #334155 !important;
            background-color: #fff !important;
            transition: all 0.2s ease-in-out !important;
            box-shadow: none !important;
        }

        .custom-input:focus {
            border-color: #3b82f6 !important;
            background-color: #fff !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12) !important;
        }

        .notification-content-link {
            display: block;
            color: inherit !important;
            text-decoration: none !important;
            cursor: pointer;
        }

        .notification-content-link:hover .notification-title-hover {
            color: #2262eb !important;
            text-decoration: underline !important;
        }

        .notification-content-link:hover .notification-body-hover {
            color: #1e293b !important;
        }

        .btn-action-read {
            color: #16a34a !important;
            background-color: #fff !important;
            border: 1px solid #22c55e !important;
            font-size: 12.5px !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            padding: 6px 14px !important;
            transition: all 0.15s ease-in-out !important;
            white-space: nowrap !important;
        }

        .btn-action-read:hover {
            background-color: #f0fdf4 !important;
            border-color: #16a34a !important;
            color: #16a34a !important;
        }

        .btn-action-unread-styled {
            color: #94a3b8 !important;
            background-color: #fff !important;
            border: 1px solid #cbd5e1 !important;
            font-size: 12.5px !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            padding: 6px 14px !important;
            transition: all 0.15s ease-in-out !important;
            white-space: nowrap !important;
        }

        .btn-action-unread-styled:hover {
            background-color: #f8fafc !important;
            border-color: #94a3b8 !important;
            color: #64748b !important;
        }

        /* Custom badge styles matching the mockup */
        .badge-audience {
            background-color: #e0f2fe !important;
            color: #0284c7 !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            padding: 6px 14px !important;
            border: none !important;
        }

        .badge-type {
            background-color: #ffffff !important;
            color: #475569 !important;
            border: 1px solid #e2e8f0 !important;
            font-weight: 500 !important;
            font-size: 12px !important;
            padding: 5px 12px !important;
        }

        .badge-status-unread {
            background-color: #fff7ed !important;
            color: #ea580c !important;
            border: 1px solid #ffedd5 !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            padding: 6px 14px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .badge-status-read {
            background-color: #f0fdf4 !important;
            color: #16a34a !important;
            border: 1px solid #dcfce7 !important;
            font-weight: 600 !important;
            font-size: 12px !important;
            padding: 6px 14px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .status-dot-orange {
            width: 6px;
            height: 6px;
            background-color: #ea580c;
            border-radius: 50%;
            display: inline-block;
        }

        /* Center alignment for the footer pagination */
        .card-footer .pagination {
            justify-content: center !important;
        }

        #notificationsTable_wrapper .dataTables_length label,
        #notificationsTable_wrapper .dataTables_info {
            color: #64748b !important;
            font-size: 13px !important;
            margin: 0 !important;
        }

        #notificationsTable_wrapper .dataTables_length select {
            border: 1px solid #e2e8f0 !important;
            border-radius: 6px !important;
            height: 34px !important;
            color: #334155 !important;
            font-size: 12.5px !important;
            margin: 0 6px !important;
            box-shadow: none !important;
        }

        #notificationsTable_wrapper .dataTables_paginate .paginate_button {
            padding: 0 !important;
            margin: 0 2px !important;
            border: 0 !important;
            background: transparent !important;
        }
    </style>
@endsection

@section('content')
    @php
        $supportsReadStatus = $supportsReadStatus ?? true;
        $unreadCount = $unreadCount ?? 0;
    @endphp

    <div class="mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="mb-1 fw-bold text-dark" style="font-size: 24px; letter-spacing: -0.5px;">Quản lý thông báo</h2>
                <small class="text-muted d-block" style="font-size: 13.5px;">Theo dõi và quản lý tất cả các thông báo hệ thống</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                @if ($supportsReadStatus)
                    <button class="btn d-flex align-items-center gap-2" type="button"
                        style="font-size: 14px; font-weight: 600; border-radius: 8px; border: 1px solid #ffd8a8; color: #f76707; background-color: #fff9db; padding: 8px 16px; pointer-events: none;">
                        <i class="far fa-bell" style="font-size: 14px;"></i> Chưa đọc: {{ $unreadCount }}
                    </button>
                @endif

                @if ($supportsReadStatus && $unreadCount > 0)
                    <form method="POST" action="{{ route('admin.notifications.mark-all-read') }}" class="m-0" id="markAllReadForm">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="redirect_url" id="markAllRedirectUrl" value="{{ request()->fullUrl() }}">
                        <button type="submit" class="btn btn-primary d-flex align-items-center gap-2"
                            style="font-size: 14px; font-weight: 600; border-radius: 8px; background-color: #2262eb; border-color: #2262eb; padding: 8px 16px; transition: background-color 0.2s;">
                            <i class="far fa-check-circle" style="font-size: 14px;"></i> Đánh dấu tất cả đã đọc
                        </button>
                    </form>
                @else
                    <button class="btn btn-secondary d-flex align-items-center gap-2" disabled
                        style="font-size: 14px; font-weight: 600; border-radius: 8px; background-color: #64748b; border-color: #64748b; padding: 8px 16px; opacity: 0.5;">
                        <i class="far fa-check-circle" style="font-size: 14px;"></i> Đánh dấu tất cả đã đọc
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="mb-4">
        <form method="GET" action="{{ route('admin.notifications.index') }}" id="filterForm">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div class="position-relative" style="width: 240px;">
                    <span class="position-absolute" style="left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 13px;">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" name="search" id="notificationSearch" value="{{ request('search') }}" class="form-control custom-input"
                        placeholder="Tìm kiếm nội dung, người gửi..." style="padding-left: 36px;">
                </div>

                <div>
                    <select name="status" id="notificationStatusFilter" class="form-select custom-input" style="width: 180px;">
                        <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>Trạng thái: Tất cả</option>
                        <option value="unread" {{ request('status') === 'unread' ? 'selected' : '' }}>Trạng thái: Chưa đọc</option>
                        <option value="read" {{ request('status') === 'read' ? 'selected' : '' }}>Trạng thái: Đã đọc</option>
                    </select>
                </div>

                <div>
                    <select name="type" id="notificationTypeFilter" class="form-select custom-input" style="width: 160px;">
                        <option value="all" {{ request('type') === 'all' ? 'selected' : '' }}>Loại: Tất cả</option>
                        <option value="order" {{ request('type') === 'order' ? 'selected' : '' }}>Loại: order</option>
                        <option value="system" {{ request('type') === 'system' ? 'selected' : '' }}>Loại: system</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-light border d-flex align-items-center gap-2"
                    style="height: 38px; border-radius: 6px; font-size: 13px; font-weight: 500; background-color: #fff; border-color: #d1d5db !important; padding: 0 16px;">
                    <i class="fas fa-filter text-muted" style="font-size: 12px;"></i> Bộ lọc
                </button>
            </div>
        </form>
    </div>

    <!-- Table Card -->
    <div class="card shadow-none mb-4" style="border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background-color: #fff;">
        <div class="table-responsive">
            <table id="notificationsTable" class="table align-middle mb-0" style="width: 100%;">
                <thead>
                    <tr>
                        <th class="text-start">NỘI DUNG</th>
                        <th style="width: 140px;" class="text-center">TRẠNG THÁI</th>
                        <th style="width: 160px;" class="text-start">THỜI GIAN</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        $(function () {
            var urlParams = new URLSearchParams(window.location.search);
            var initialSearch = urlParams.get('search') || '';
            var initialStatus = urlParams.get('status') || 'all';
            var initialType = urlParams.get('type') || 'all';
            var initialLength = parseInt(urlParams.get('length') || '10', 10);
            var initialPage = parseInt(urlParams.get('page') || '1', 10);
            var initialOrderCol = parseInt(urlParams.get('orderCol') || '2', 10);
            var initialOrderDir = urlParams.get('orderDir') === 'asc' ? 'asc' : 'desc';

            if ([10, 20, 50, 100].indexOf(initialLength) === -1) {
                initialLength = 10;
            }

            if (!initialPage || initialPage < 1) {
                initialPage = 1;
            }

            if ([0, 1, 2].indexOf(initialOrderCol) === -1) {
                initialOrderCol = 2;
            }

            $('#notificationSearch').val(initialSearch);
            $('#notificationStatusFilter').val(initialStatus);
            $('#notificationTypeFilter').val(initialType);

            var notificationsTable = $('#notificationsTable').DataTable({
                dom: 'rt<"card-footer d-flex align-items-center justify-content-between bg-white border-top-0 py-3 px-4 flex-wrap gap-3"<"text-muted"i><"d-flex justify-content-center flex-grow-1"p><"d-flex justify-content-end"l>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                pageLength: initialLength,
                displayStart: (initialPage - 1) * initialLength,
                searchDelay: 200,
                search: { search: initialSearch },
                ajax: {
                    url: '{{ route('admin.notifications.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.status = $('#notificationStatusFilter').val();
                        d.type = $('#notificationTypeFilter').val();
                        d.redirect_url = buildListPathFromData(d);
                    }
                },
                language: {
                    processing: 'Đang xử lý...',
                    search: 'Tìm kiếm:',
                    lengthMenu: '_MENU_ / trang',
                    info: 'Hiển thị _START_ đến _END_ trong tổng số _TOTAL_ thông báo',
                    infoEmpty: 'Hiển thị 0 đến 0 trong 0 thông báo',
                    infoFiltered: '(lọc từ _MAX_ thông báo)',
                    loadingRecords: 'Đang tải...',
                    zeroRecords: 'Không tìm thấy dữ liệu phù hợp',
                    emptyTable: 'Chưa có thông báo.',
                    paginate: {
                        first: 'Đầu',
                        previous: '‹',
                        next: '›',
                        last: 'Cuối'
                    }
                },
                lengthMenu: [[10, 20, 50, 100], [10, 20, 50, 100]],
                order: [[initialOrderCol, initialOrderDir]],
                columns: [
                    { data: 'content' },
                    { data: 'status', searchable: false },
                    { data: 'created_at', searchable: false },
                ],
                columnDefs: [
                    { targets: 0, className: 'text-start' },
                    { targets: 1, className: 'text-center', width: '140px' },
                    { targets: 2, className: 'text-start', width: '160px' },
                ],
                createdRow: function (row, data) {
                    if (data.row_class) {
                        $(row).addClass(data.row_class);
                    }
                }
            });

            $('#notificationSearch').on('input', function () {
                notificationsTable.search(this.value).draw();
            });

            $('#notificationStatusFilter, #notificationTypeFilter').on('change', function () {
                notificationsTable.draw();
            });

            $('#filterForm').on('submit', function (event) {
                event.preventDefault();
                notificationsTable.search($('#notificationSearch').val()).draw();
            });

            notificationsTable.on('draw', function () {
                updateUrlState();
            });

            $('#markAllReadForm').on('submit', function () {
                $('#markAllRedirectUrl').val(buildCurrentListPath());
            });

            function buildCurrentListPath() {
                var info = notificationsTable.page.info();
                var order = notificationsTable.order()[0] || [2, 'desc'];

                return buildListPath({
                    search: notificationsTable.search(),
                    status: $('#notificationStatusFilter').val(),
                    type: $('#notificationTypeFilter').val(),
                    page: info.page + 1,
                    length: info.length,
                    orderCol: order[0],
                    orderDir: order[1],
                });
            }

            function buildListPathFromData(data) {
                var length = parseInt(data.length || initialLength, 10);
                if (!length || length < 1) {
                    length = initialLength;
                }

                var start = parseInt(data.start || 0, 10);
                var order = (data.order && data.order[0]) ? data.order[0] : { column: initialOrderCol, dir: initialOrderDir };

                return buildListPath({
                    search: data.search ? data.search.value : $('#notificationSearch').val(),
                    status: $('#notificationStatusFilter').val(),
                    type: $('#notificationTypeFilter').val(),
                    page: Math.floor(start / length) + 1,
                    length: length,
                    orderCol: order.column,
                    orderDir: order.dir,
                });
            }

            function buildListPath(state) {
                var params = new URLSearchParams();

                if (state.search) {
                    params.set('search', state.search);
                }

                if (state.status && state.status !== 'all') {
                    params.set('status', state.status);
                }

                if (state.type && state.type !== 'all') {
                    params.set('type', state.type);
                }

                params.set('page', String(state.page || 1));
                params.set('length', String(state.length || 10));
                params.set('orderCol', String(state.orderCol || 2));
                params.set('orderDir', state.orderDir === 'asc' ? 'asc' : 'desc');

                return window.location.pathname + '?' + params.toString();
            }

            function updateUrlState() {
                var newUrl = buildCurrentListPath();
                window.history.replaceState({}, '', newUrl);
            }
        });
    </script>
@endsection
