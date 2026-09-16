@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <h2 class="mb-4">Khiếu nại toàn hệ thống (S15)</h2>

        @php
            $severityLabels = [
                'low' => 'Thấp',
                'normal' => 'Bình thường',
                'high' => 'Cao',
                'critical' => 'Khẩn cấp',
            ];
            $statusLabels = [
                'pending' => 'Mới tiếp nhận',
                'processing' => 'Đang xử lý',
                'resolved' => 'Đã giải quyết',
                'closed' => 'Đã đóng',
            ];
        @endphp

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.complaints.index') }}" class="row g-3" id="complaintFilters">
                    <div class="col-md-2">
                        <label class="form-label">Mức độ</label>
                        <select class="form-control" name="severity">
                            <option value="">Tất cả</option>
                            <option value="low" {{ request('severity') === 'low' ? 'selected' : '' }}>Thấp</option>
                            <option value="normal" {{ request('severity') === 'normal' ? 'selected' : '' }}>Bình thường</option>
                            <option value="high" {{ request('severity') === 'high' ? 'selected' : '' }}>Cao</option>
                            <option value="critical" {{ request('severity') === 'critical' ? 'selected' : '' }}>Khẩn cấp</option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Trạng thái</label>
                        <select class="form-control" name="status">
                            <option value="">Tất cả</option>
                            <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Mới tiếp nhận</option>
                            <option value="processing" {{ request('status') === 'processing' ? 'selected' : '' }}>Đang xử lý</option>
                            <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>Đã giải quyết</option>
                            <option value="closed" {{ request('status') === 'closed' ? 'selected' : '' }}>Đã đóng</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Cửa hàng</label>
                        <select class="form-control" name="store_id">
                            <option value="">Tất cả</option>
                            @foreach ($stores as $store)
                                <option value="{{ $store->id }}" {{ request('store_id') === (string) $store->id ? 'selected' : '' }}>
                                    {{ $store->store_code }} - {{ $store->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Yêu cầu hoàn Ticket</label>
                        <select class="form-control" name="refund_requested">
                            <option value="">Tất cả</option>
                            <option value="1" {{ request('refund_requested') === '1' ? 'selected' : '' }}>Có</option>
                            <option value="0" {{ request('refund_requested') === '0' ? 'selected' : '' }}>Không</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label">Tìm kiếm</label>
                        <input type="text" class="form-control" name="keyword" value="{{ request('keyword') }}"
                            placeholder="mã khiếu nại / tiêu đề / user / order">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Lọc dữ liệu</button>
                        <a href="{{ route('admin.complaints.index') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="complaintsTable" class="table table-striped table-hover mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th>Khiếu nại</th>
                                <th>Liên quan</th>
                                <th>Mức độ/Trạng thái</th>
                                <th>Phân công xử lý</th>
                                <th>Thời gian</th>
                                <th>Thao tác</th>
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
                    severity: params.get('severity') || '',
                    status: params.get('status') || '',
                    store_id: params.get('store_id') || '',
                    assigned_to: params.get('assigned_to') || '',
                    refund_requested: params.get('refund_requested') || '',
                    keyword: params.get('keyword') || '',
                    page: Math.max(1, parseInt(params.get('page') || '1', 10)),
                    length: Math.max(1, parseInt(params.get('length') || '20', 10)),
                    orderCol: Math.max(0, parseInt(params.get('order_col') || '4', 10)),
                    orderDir: params.get('order_dir') === 'asc' ? 'asc' : 'desc'
                };
            }

            function syncUrlState(table) {
                var info = table.page.info();
                var order = table.order();
                var params = new URLSearchParams(window.location.search);

                ['severity', 'status', 'store_id', 'assigned_to', 'refund_requested', 'keyword'].forEach(function (key) {
                    var value = $('#complaintFilters [name="' + key + '"]').val();
                    if (value) {
                        params.set(key, value);
                    } else {
                        params.delete(key);
                    }
                });

                params.set('page', info.page + 1);
                params.set('length', info.length);
                params.set('order_col', order.length ? order[0][0] : 4);
                params.set('order_dir', order.length ? order[0][1] : 'desc');

                history.replaceState(null, '', window.location.pathname + '?' + params.toString());
            }

            var urlState = getUrlState();
            var $filters = $('#complaintFilters');
            $filters.find('[name="severity"]').val(urlState.severity);
            $filters.find('[name="status"]').val(urlState.status);
            $filters.find('[name="store_id"]').val(urlState.store_id);
            $filters.find('[name="assigned_to"]').val(urlState.assigned_to);
            $filters.find('[name="refund_requested"]').val(urlState.refund_requested);
            $filters.find('[name="keyword"]').val(urlState.keyword);

            var complaintsTable = $('#complaintsTable').DataTable({
                dom: 'rt<"row mt-3 align-items-center"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                searchDelay: 300,
                pageLength: urlState.length,
                displayStart: (urlState.page - 1) * urlState.length,
                order: [[4, 'desc']],
                ajax: {
                    url: '{{ route('admin.complaints.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.severity = $filters.find('[name="severity"]').val();
                        d.status = $filters.find('[name="status"]').val();
                        d.store_id = $filters.find('[name="store_id"]').val();
                        d.assigned_to = $filters.find('[name="assigned_to"]').val();
                        d.refund_requested = $filters.find('[name="refund_requested"]').val();
                        d.keyword = $filters.find('[name="keyword"]').val();
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
                    { data: 'complaint', orderable: true, searchable: false },
                    { data: 'related', orderable: false, searchable: false },
                    { data: 'severity_status', orderable: false, searchable: false },
                    { data: 'assignment', orderable: false, searchable: false },
                    { data: 'created_at', orderable: true, searchable: false },
                    { data: 'actions', orderable: false, searchable: false }
                ],
                columnDefs: [
                    { targets: [0, 1, 2, 3, 4, 5], className: 'align-top' },
                    { targets: [0, 4], className: 'text-nowrap' }
                ]
            });

            $filters.on('submit', function (e) {
                e.preventDefault();
                complaintsTable.page('first').ajax.reload();
            });

            $filters.find('select, input').on('change keyup', function () {
                if ($(this).is('[name="keyword"]')) {
                    return;
                }
            });

            complaintsTable.on('draw', function () {
                syncUrlState(complaintsTable);
            });
        });
    </script>
@endsection
