@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        @php
            $statusLabels = [
                'pending' => 'Mới tiếp nhận',
                'processing' => 'Đang xử lý',
                'resolved' => 'Đã duyệt',
                'closed' => 'Đã đóng',
                'new' => 'Mới báo cáo',
            ];
            $severityLabels = [
                'low' => 'Thấp',
                'normal' => 'Bình thường',
                'high' => 'Cao',
                'critical' => 'Khẩn cấp',
            ];
        @endphp

        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">Danh sách báo cáo affiliate</h2>
                <div class="text-muted">Quản lý các báo cáo từ F2 gửi về F1 và phê duyệt chuyển tuyến.</div>
            </div>
        </div>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <div class="fw-semibold mb-1">Có lỗi xảy ra:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.affiliate.reports.index') }}" class="row g-3 align-items-end" id="affiliateFilters">
                    <div class="col-md-3">
                        <label class="form-label">Lọc theo trạng thái</label>
                        <select name="status" class="form-control">
                            <option value="">Tất cả trạng thái</option>
                            @foreach ($statusLabels as $code => $label)
                                <option value="{{ $code }}" {{ request('status') === $code ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Tìm kiếm báo cáo</label>
                        <input type="text" name="keyword" class="form-control" value="{{ request('keyword') }}" placeholder="mã báo cáo / tiêu đề / user ID / mô tả">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Áp dụng lọc</button>
                        <a href="{{ route('admin.affiliate.reports.index') }}" class="btn btn-outline-secondary">Xóa lọc</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="affiliateReportsTable" class="table table-striped table-hover mb-0 align-middle" style="width:100%">
                        <thead>
                            <tr>
                                <th>Mã báo cáo</th>
                                <th>Thông tin F2 và F1 bị báo cáo</th>
                                <th>F1 hiện tại của F2</th>
                                <th>Trạng thái</th>
                                <th>Thời gian</th>
                                <th>Hành động</th>
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
                    status: params.get('status') || '',
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

                ['status', 'keyword'].forEach(function (key) {
                    var value = $('#affiliateFilters [name="' + key + '"]').val();
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
            var $filters = $('#affiliateFilters');
            $filters.find('[name="status"]').val(urlState.status);
            $filters.find('[name="keyword"]').val(urlState.keyword);

            var affiliateTable = $('#affiliateReportsTable').DataTable({
                dom: 'rt<"row mt-3 align-items-center"<"col-sm-6"l><"col-sm-6 text-end"p>>',
                processing: true,
                serverSide: true,
                autoWidth: false,
                searchDelay: 300,
                pageLength: urlState.length,
                displayStart: (urlState.page - 1) * urlState.length,
                order: [[4, 'desc']],
                ajax: {
                    url: '{{ route('admin.affiliate.reports.datatable') }}',
                    type: 'GET',
                    data: function (d) {
                        d.status = $filters.find('[name="status"]').val();
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
                    { data: 'complaint_code', orderable: true, searchable: false },
                    { data: 'users_info', orderable: false, searchable: false },
                    { data: 'current_upline', orderable: false, searchable: false },
                    { data: 'status', orderable: false, searchable: false },
                    { data: 'created_at', orderable: true, searchable: false },
                    { data: 'actions', orderable: false, searchable: false }
                ],
                columnDefs: [
                    { targets: [0, 1, 2, 3, 4, 5], className: 'align-top' }
                ]
            });

            $filters.on('submit', function (e) {
                e.preventDefault();
                affiliateTable.page('first').ajax.reload();
            });

            affiliateTable.on('draw', function () {
                syncUrlState(affiliateTable);
            });
        });
    </script>
@endsection