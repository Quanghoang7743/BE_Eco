@extends('admin.layout')

@section('styles')
    <style>
        #ordersTable th,
        #ordersTable td {
            font-size: 12px !important;
        }

        #ordersTable th {
            text-transform: none !important;
            letter-spacing: normal !important;
        }

        #ordersTable .badge {
            font-size: 10.5px !important;
            font-weight: 600 !important;
            padding: 4px 8px !important;
        }

        #ordersTable .btn {
            font-size: 11px !important;
            padding: 3px 8px !important;
        }

        .order-code-link {
            transition: color 0.15s ease-in-out;
            color: #1e293b;
            text-decoration: none;
        }

        .order-code-link:hover {
            color: #2563eb !important;
            text-decoration: underline !important;
        }

        .custom-tab-link {
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            font-size: 14px;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            padding: 10px 4px;
            transition: all 0.2s ease;
        }

        .custom-tab-link:hover {
            color: #111827 !important;
            text-decoration: none !important;
        }

        .custom-tab-link.active {
            color: #3b82f6 !important;
            border-bottom-color: #3b82f6 !important;
            font-weight: 600 !important;
        }

        .btn-custom-export {
            background-color: #00bcd4 !important;
            color: #fff !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            border-radius: 6px !important;
            padding: 8px 16px !important;
            border: none !important;
            transition: background-color 0.2s !important;
            text-decoration: none !important;
        }

        .btn-custom-export:hover {
            background-color: #00acc1 !important;
            color: #fff !important;
            text-decoration: none !important;
        }

        #deleteSelectedOrders:disabled {
            opacity: 0.5 !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
        }

        .custom-input {
            border: 1px solid #d1d5db !important;
            border-radius: 6px !important;
            height: 38px !important;
            font-size: 13px !important;
            color: #374151 !important;
            background-color: #fff !important;
            transition: all 0.2s ease-in-out !important;
            box-shadow: none !important;
        }

        .custom-input:focus {
            border-color: #3b82f6 !important;
            background-color: #fff !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
        }
    </style>
@endsection

@section('content')
    @php
        $isSaleUser = (bool) ($isSaleUser ?? false);
        $canManageOrders = (bool) ($canManageOrders ?? true);
        $defaultStatus = $defaultStatus ?? 'all';
        $statusFilters = $isSaleUser
            ? [
                'successful' => 'Thành công',
                'delivered' => 'Đã giao',
                'completed' => 'Hoàn thành',
                'call_completed' => 'Gọi hoàn tất',
            ]
            : [
                'all' => 'Tất cả',
                'new' => 'Mới',
                'processing' => 'Đang xử lý',
                'confirmed' => 'Đã xác nhận',
                'completed' => 'Hoàn thành',
                'cancelled' => 'Đã hủy',
                'returned' => 'Hoàn trả',
                'partially_refunded' => 'Hoàn trả một phần',
            ];
        $currentStatus = request('status', $defaultStatus);
    @endphp

    <div class="mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <small class="text-muted d-block mb-1"
                    style="font-size: 13px; font-weight: 500; letter-spacing: 0.5px; text-transform: none !important;">Đơn
                    hàng</small>
                <h2 class="mb-0 fw-bold text-dark" style="font-size: 24px; letter-spacing: -0.5px;">
                    {{ $isSaleUser ? 'Đơn hàng thành công' : 'Tất cả đơn hàng' }}</h2>
            </div>
            <div class="d-flex align-items-center gap-2">
                @if ($canManageOrders)
                    <div class="dropdown">
                        <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-2" type="button"
                            id="headerBulkActions" data-bs-toggle="dropdown" aria-expanded="false"
                            style="font-size: 14px; font-weight: 500; border-radius: 6px; border-color: #d1d5db; color: #374151; background: #fff; padding: 8px 16px;">
                            Thao tác
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="headerBulkActions"
                            style="border-radius: 8px; border: 1px solid #e5e7eb; padding: 6px 0;">
                            <li>
                                <button type="button" id="btnToggleSelectMultiple"
                                    class="dropdown-item d-flex align-items-center gap-2"
                                    style="font-size: 13.5px; padding: 8px 16px; border: none; background: none; width: 100%; text-align: left;">
                                    <i class="far fa-square" style="font-size: 12px;"></i> Chọn nhiều
                                </button>
                            </li>
                            <li>
                                <hr class="dropdown-divider" style="margin: 4px 0; border-color: #f3f4f6;">
                            </li>
                            <li>
                                <button type="button" id="deleteSelectedOrders"
                                    class="dropdown-item text-danger d-flex align-items-center gap-2" disabled
                                    style="font-size: 13.5px; padding: 8px 16px; border: none; background: none; width: 100%; text-align: left;">
                                    <i class="fas fa-trash-alt" style="font-size: 12px;"></i> Xóa đã chọn
                                </button>
                            </li>
                        </ul>
                    </div>
                @endif
                <a href="{{ route('admin.orders.export', request()->query()) }}"
                    class="btn btn-custom-export d-flex align-items-center gap-2"
                    data-export-base="{{ route('admin.orders.export') }}">
                    Xuất đơn hàng
                </a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="order-status-tabs mb-4 border-bottom"
        style="overflow-x: auto; white-space: nowrap; -webkit-overflow-scrolling: touch; border-color: #e5e7eb !important;">
        <div class="d-flex gap-4" style="padding-bottom: 0;">
            @foreach ($statusFilters as $key => $label)
                <a href="{{ route('admin.orders.index', array_merge(request()->query(), ['status' => $key])) }}"
                    data-status="{{ $key }}" class="custom-tab-link {{ $currentStatus === $key ? 'active' : '' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <div class="card shadow-none mb-4" style="border: 1px solid #e5e7eb; border-radius: 8px; background-color: #fff;">
        <div class="card-body p-3">
            <div class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-12">
                    <label class="form-label"
                        style="font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 6px;">Tìm kiếm</label>
                    <div class="position-relative">
                        <span class="position-absolute"
                            style="left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 13px;">
                            <i class="fas fa-search"></i>
                        </span>
                        <input id="orderSearch" type="text" class="form-control custom-input"
                            placeholder="Mã đơn, khách hàng, SĐT, cửa hàng..." style="padding-left: 36px;">
                    </div>
                </div>
                <div class="col-lg-3 col-md-4 col-sm-12">
                    <label class="form-label"
                        style="font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 6px;">Cửa hàng</label>
                    <select id="orderStoreFilter" class="form-select custom-input">
                        <option value="">Tất cả cửa hàng</option>
                        @foreach ($storeOptions as $store)
                            <option value="{{ $store->id }}">
                                {{ $store->name }}{{ !empty($store->store_code) ? ' - ' . $store->store_code : '' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="form-label"
                        style="font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 6px;">Từ ngày</label>
                    <input type="date" id="orderFromDate" value="{{ request('from_date') }}"
                        class="form-control custom-input">
                </div>
                <div class="col-lg-2 col-md-4 col-sm-6">
                    <label class="form-label"
                        style="font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 6px;">Đến ngày</label>
                    <input type="date" id="orderToDate" value="{{ request('to_date') }}" class="form-control custom-input">
                </div>
                <div class="col-lg-2 col-md-12 d-flex gap-2">
                    <button type="button" id="applyOrderFilters"
                        class="btn btn-primary d-flex align-items-center justify-content-center"
                        style="height: 38px; border-radius: 6px; font-size: 13px; font-weight: 500; background-color: #3b82f6; border-color: #3b82f6; width: 100%;">Lọc</button>
                    <button type="button" id="resetOrderFilters"
                        class="btn btn-outline-secondary d-flex align-items-center justify-content-center"
                        style="height: 38px; border-radius: 6px; font-size: 13px; font-weight: 500; width: auto; min-width: 44px; padding: 0;"><i
                            class="fas fa-redo-alt"></i></button>
                </div>
            </div>
        </div>
    </div>

    <div style="overflow-x: auto;">
        <table id="ordersTable" class="table table-bordered table-striped" style="width:100%">
            <thead>
                <tr>
                    <th>@if($canManageOrders)<input type="checkbox" id="selectAllOrders">@endif</th>
                    <th>Mã đơn</th>
                    <th>Cửa hàng</th>
                    <th>Khách hàng</th>
                    <th>Phương thức vận chuyển</th>
                    <th>Tổng tiền</th>
                    <th>Thời gian</th>
                    <th>Trạng thái</th>
                    <th>Thanh toán</th>
                    <th>Số ticket</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    @if ($canManageOrders)
        <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <form id="updateStatusForm" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Cập nhật trạng thái đơn hàng</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="orderStatusSelect" class="form-label">Trạng thái</label>
                                <select class="form-select" id="orderStatusSelect" name="status">
                                    <option value="pending">Khởi tạo</option>
                                    <option value="confirmed">Đã xác nhận</option>
                                    <option value="preparing">Đang chuẩn bị</option>
                                    <option value="ready">Sẵn sàng</option>
                                    <option value="picked_up">Đã lấy hàng</option>
                                    <option value="delivered">Đã giao</option>
                                    <option value="completed">Hoàn thành</option>
                                    <option value="cancelled">Hủy đơn</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-success">Cập nhật</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="updateStatusPaymentModal" tabindex="-1" aria-labelledby="updatePaymentStatusModalLabel"
            aria-hidden="true">
            <div class="modal-dialog">
                <form id="updateStatusPaymentForm" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Cập nhật trạng thái thanh toán</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="paymentStatusSelect" class="form-label">Trạng thái</label>
                                <select class="form-select" id="paymentStatusSelect" name="status_payment">
                                    <option value="pending">Chờ thanh toán</option>
                                    <option value="paid">Đã thanh toán</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-success">Cập nhật</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var canManageOrders = @json($canManageOrders);
            var defaultStatus = @json($defaultStatus);
            var urlParams = new URLSearchParams(window.location.search);
            var initialSearch = urlParams.get('search') || '';
            var initialLength = parseInt(urlParams.get('length') || '20', 10);
            var initialPage = Math.max(1, parseInt(urlParams.get('page') || '1', 10));
            var initialOrderCol = parseInt(urlParams.get('orderCol') || '6', 10);
            var initialOrderDir = urlParams.get('orderDir') === 'asc' ? 'asc' : 'desc';
            var currentStatus = urlParams.get('status') || defaultStatus || 'all';

            $('#orderSearch').val(initialSearch);
            $('#orderStoreFilter').val(urlParams.get('store_id') || '');

            var ordersTable = $('#ordersTable').DataTable({
                dom: 'rt<"row mb-3"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                pageLength: initialLength,
                displayStart: (initialPage - 1) * initialLength,
                searchDelay: 350,
                search: { search: initialSearch },
                ajax: {
                    url: '{{ route('admin.orders.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.status = currentStatus || 'all';
                        d.store_id = $('#orderStoreFilter').val();
                        d.from_date = $('#orderFromDate').val();
                        d.to_date = $('#orderToDate').val();
                    }
                },
                language: {
                    processing: 'Đang xử lý...',
                    search: 'Tìm kiếm:',
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
                order: [[initialOrderCol, initialOrderDir]],
                columns: [
                    { data: 'checkbox', orderable: false, searchable: false },
                    { data: 'id' },
                    { data: 'store', orderable: false, searchable: false },
                    { data: 'name' },
                    { data: 'shipping_method', orderable: false, searchable: false },
                    { data: 'total' },
                    { data: 'created_at' },
                    { data: 'status' },
                    { data: 'status_payment' },
                    { data: 'ticket_count', orderable: false, searchable: false },
                ],
                columnDefs: [
                    { targets: 0, visible: false },
                    { targets: [4, 5, 6, 7, 8, 9], className: 'text-center' },
                ],
            });

            var searchTimer = null;
            $('#orderSearch').on('input', function () {
                var searchValue = this.value;
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(function () {
                    ordersTable.search(searchValue).draw();
                }, 350);
            });

            var isSelectMultipleActive = false;
            $('#btnToggleSelectMultiple').on('click', function (e) {
                e.preventDefault();
                isSelectMultipleActive = !isSelectMultipleActive;
                ordersTable.column(0).visible(isSelectMultipleActive);

                if (isSelectMultipleActive) {
                    $(this).find('i').removeClass('far fa-square').addClass('fas fa-check-square text-primary');
                } else {
                    $(this).find('i').removeClass('fas fa-check-square text-primary').addClass('far fa-square');
                    $('.order-row-checkbox').prop('checked', false);
                    $('#selectAllOrders').prop('checked', false);
                    updateDeleteSelectedButton();
                }
            });


            $('#applyOrderFilters').on('click', function () {
                updateUrlState(true);
                ordersTable.page('first').draw('page');
            });

            $('#orderStoreFilter, #orderFromDate, #orderToDate').on('change', function () {
                updateUrlState(true);
                ordersTable.page('first').draw('page');
            });

            $('#resetOrderFilters').on('click', function () {
                currentStatus = defaultStatus || 'all';
                $('#orderSearch').val('');
                $('#orderStoreFilter').val('');
                $('#orderFromDate').val('');
                $('#orderToDate').val('');
                ordersTable.search('');
                $('.custom-tab-link').removeClass('active');
                $('.custom-tab-link[data-status="' + currentStatus + '"]').addClass('active');
                updateUrlState(true);
                ordersTable.page('first').draw('page');
            });

            $('.order-status-tabs').on('click', 'a', function (event) {
                event.preventDefault();
                currentStatus = $(this).data('status') || 'all';
                $(this).closest('.order-status-tabs').find('a').removeClass('active');
                $(this).addClass('active');
                updateUrlState(true);
                ordersTable.page('first').draw('page');
            });

            ordersTable.on('draw', function () {
                $('#selectAllOrders').prop('checked', false);
                updateDeleteSelectedButton();
                updateUrlState();
            });

            function updateDeleteSelectedButton() {
                if (!canManageOrders) {
                    return;
                }

                var selectedCount = $('.order-row-checkbox:checked').length;
                $('#deleteSelectedOrders').prop('disabled', selectedCount === 0);
            }

            if (canManageOrders) {
                $('#ordersTable').on('change', '.order-row-checkbox', function () {
                    updateDeleteSelectedButton();
                    $('#selectAllOrders').prop('checked', $('.order-row-checkbox').length === $('.order-row-checkbox:checked').length);
                });

                $('#ordersTable').on('change', '#selectAllOrders', function () {
                    $('.order-row-checkbox').prop('checked', $(this).is(':checked'));
                    updateDeleteSelectedButton();
                });

                $('#deleteSelectedOrders').on('click', function () {
                    var selectedIds = $('.order-row-checkbox:checked').map(function () {
                        return $(this).val();
                    }).get();

                    if (!selectedIds.length) {
                        return;
                    }

                    if (!confirm('Xác nhận xóa ' + selectedIds.length + ' đơn hàng đã chọn?')) {
                        return;
                    }

                    var button = $(this);
                    button.prop('disabled', true).text('Đang xóa...');

                    axios.post('{{ route('admin.orders.destroyMultiple') }}', {
                        ids: selectedIds,
                    }, {
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        }
                    })
                        .then(function (response) {
                            ordersTable.ajax.reload(null, false);
                            $('#selectAllOrders').prop('checked', false);
                            updateDeleteSelectedButton();
                            window.showAppSuccess(response.data.message || 'Đơn hàng đã được xóa.', 'Thành công');
                        })
                        .catch(function (error) {
                            console.error(error);
                            window.showAppError('Xóa đơn hàng thất bại. Vui lòng thử lại.', 'Lỗi');
                        })
                        .finally(function () {
                            button.prop('disabled', false).text('Xóa đã chọn');
                        });
                });
            }

            function updateUrlState(resetPage) {
                var info = ordersTable.page.info();
                var order = ordersTable.order()[0] || [6, 'desc'];

                if (resetPage) {
                    urlParams.set('page', '1');
                } else {
                    urlParams.set('page', (info.page + 1).toString());
                }

                urlParams.set('length', info.length.toString());
                urlParams.set('orderCol', order[0].toString());
                urlParams.set('orderDir', order[1]);

                if (currentStatus && currentStatus !== 'all') {
                    urlParams.set('status', currentStatus);
                } else {
                    urlParams.delete('status');
                }

                var storeId = $('#orderStoreFilter').val();
                if (storeId) {
                    urlParams.set('store_id', storeId);
                } else {
                    urlParams.delete('store_id');
                }

                var fromDate = $('#orderFromDate').val();
                var toDate = $('#orderToDate').val();
                if (fromDate) {
                    urlParams.set('from_date', fromDate);
                } else {
                    urlParams.delete('from_date');
                }

                if (toDate) {
                    urlParams.set('to_date', toDate);
                } else {
                    urlParams.delete('to_date');
                }

                var searchValue = ordersTable.search();
                if (searchValue) {
                    urlParams.set('search', searchValue);
                } else {
                    urlParams.delete('search');
                }

                var queryString = urlParams.toString();
                var newUrl = queryString ? (window.location.pathname + '?' + queryString) : window.location.pathname;
                window.history.replaceState({}, '', newUrl);

                var exportBase = $('a[data-export-base]').data('export-base');
                if (exportBase) {
                    var exportUrl = queryString ? (exportBase + '?' + queryString) : exportBase;
                    $('a[data-export-base]').attr('href', exportUrl);
                }
            }

            if (canManageOrders) {
                const updateStatusModal = document.getElementById('updateStatusModal');
                const updateStatusPaymentModal = document.getElementById('updateStatusPaymentModal');

                updateStatusModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const orderId = button.getAttribute('data-id');
                    const currentOrderStatus = button.getAttribute('data-status');
                    const form = document.getElementById('updateStatusForm');
                    form.action = `/admin/orders/${orderId}/status`;
                    document.getElementById('orderStatusSelect').value = currentOrderStatus;
                });

                updateStatusPaymentModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const orderId = button.getAttribute('data-id');
                    const currentPaymentStatus = button.getAttribute('data-statusPayment');
                    const form = document.getElementById('updateStatusPaymentForm');
                    form.action = `/admin/orders/${orderId}/updateStatusPayment`;
                    document.getElementById('paymentStatusSelect').value = currentPaymentStatus;
                });
            }

            // Export selected orders click listener
            $('a[data-export-base]').on('click', function () {
                var selectedIds = $('.order-row-checkbox:checked').map(function () {
                    return $(this).val();
                }).get();

                var href = $(this).attr('href');
                try {
                    var url = new URL(href, window.location.origin);
                    if (selectedIds.length > 0) {
                        url.searchParams.set('ids', selectedIds.join(','));
                    } else {
                        url.searchParams.delete('ids');
                    }
                    $(this).attr('href', url.pathname + url.search);
                } catch (e) {
                    console.error("Error updating export URL:", e);
                }
            });
        });
    </script>
@endsection