@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <h2 class="mb-4">Ticket chưa dùng - chưa gọi món (S10)</h2>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.tickets.unused') }}" class="row g-3" id="ticketFilters">
                    <div class="col-md-2">
                        <label class="form-label">Từ ngày</label>
                        <input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Đến ngày</label>
                        <input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-control" name="status">
                            <option value="">Tất cả</option>
                            <option value="AVAILABLE" {{ request('status') === 'AVAILABLE' ? 'selected' : '' }}>AVAILABLE</option>
                            <option value="USED_IN_ROOM" {{ request('status') === 'USED_IN_ROOM' ? 'selected' : '' }}>USED_IN_ROOM</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Nguồn phát hành</label>
                        <input type="text" class="form-control" name="source_type" value="{{ request('source_type') }}"
                            placeholder="VD: batch/store/order">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Tìm kiếm</label>
                        <input type="text" class="form-control" name="keyword" value="{{ request('keyword') }}"
                            placeholder="Mã Ticket / user id / tên / SĐT">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.tickets.unused') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Tổng Ticket chưa dùng</h6>
                        <h4 class="mb-0">{{ number_format((int) ($summary['total_unused_tickets'] ?? 0)) }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Ticket mua tuần này</h6>
                        <h4 class="mb-0">{{ number_format((int) ($summary['week_unused_tickets'] ?? 0)) }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Ticket mua tháng này</h6>
                        <h4 class="mb-0">{{ number_format((int) ($summary['month_unused_tickets'] ?? 0)) }}</h4>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="text-muted mb-2">Tổng available_balance</h6>
                        <h4 class="mb-0">{{ number_format((int) ($summary['total_available_balance'] ?? 0)) }}</h4>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="unusedTicketsTable" class="table table-striped table-hover mb-0 align-middle" style="width:100%">
                        <thead>
                            <tr>
                                <th>Mã Ticket</th>
                                <th>Người sở hữu</th>
                                <th>Trạng thái</th>
                                <th>Thời gian mua</th>
                                <th>Hết hạn</th>
                                <th>Nguồn</th>
                                <th>Giá trị Ticket</th>
                                <th>Available balance</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        $(function () {
            function getUrlState() {
                var params = new URLSearchParams(window.location.search);
                return {
                    date_from: params.get('date_from') || '',
                    date_to: params.get('date_to') || '',
                    status: params.get('status') || '',
                    source_type: params.get('source_type') || '',
                    keyword: params.get('keyword') || '',
                    page: Math.max(1, parseInt(params.get('page') || '1', 10)),
                    length: Math.max(1, parseInt(params.get('length') || '20', 10)),
                    orderCol: Math.max(0, parseInt(params.get('order_col') || '3', 10)),
                    orderDir: params.get('order_dir') === 'asc' ? 'asc' : 'desc'
                };
            }

            function syncUrlState(table) {
                var info = table.page.info();
                var order = table.order();
                var params = new URLSearchParams(window.location.search);

                ['date_from', 'date_to', 'status', 'source_type', 'keyword'].forEach(function (key) {
                    var value = $('#ticketFilters [name="' + key + '"]').val();
                    if (value) {
                        params.set(key, value);
                    } else {
                        params.delete(key);
                    }
                });

                params.set('page', info.page + 1);
                params.set('length', info.length);
                params.set('order_col', order.length ? order[0][0] : 3);
                params.set('order_dir', order.length ? order[0][1] : 'desc');

                history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            }

            var urlState = getUrlState();
            var $filters = $('#ticketFilters');
            
            console.log('Form element found:', $filters.length > 0);
            console.log('URL State:', urlState);
            
            $filters.find('[name="date_from"]').val(urlState.date_from);
            $filters.find('[name="date_to"]').val(urlState.date_to);
            $filters.find('[name="status"]').val(urlState.status);
            $filters.find('[name="source_type"]').val(urlState.source_type);
            $filters.find('[name="keyword"]').val(urlState.keyword);

            var ticketTable = $('#unusedTicketsTable').DataTable({
                dom: 'rt<"row mt-3 align-items-center"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                searchDelay: 300,
                pageLength: urlState.length,
                displayStart: (urlState.page - 1) * urlState.length,
                order: [[urlState.orderCol, urlState.orderDir]],
                ajax: {
                    url: '{{ route('admin.tickets.unused.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.date_from = $filters.find('[name="date_from"]').val();
                        d.date_to = $filters.find('[name="date_to"]').val();
                        d.status = $filters.find('[name="status"]').val();
                        d.source_type = $filters.find('[name="source_type"]').val();
                        d.keyword = $filters.find('[name="keyword"]').val();
                    },
                    dataSrc: function (json) {
                        console.log('DataTables AJAX Success:', json);
                        return json.data || [];
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
                    { data: 'ticket_code', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'owner_name', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'status', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'purchased_at', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'expired_at', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'source_type', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'purchased_price', orderable: true, searchable: false, render: function(data) { return data || ''; } },
                    { data: 'available_balance', orderable: true, searchable: false, render: function(data) { return data || ''; } }
                ],
                columnDefs: [
                    { targets: [0, 1, 2, 3, 4, 5, 6, 7], className: 'align-top' }
                ]
            });

            $filters.on('submit', function (e) {
                e.preventDefault();
                ticketTable.page('first').ajax.reload();
            });

            ticketTable.on('draw', function () {
                syncUrlState(ticketTable);
            });
        });
    </script>
@endsection
