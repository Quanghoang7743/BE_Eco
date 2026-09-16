@extends('admin.layout')

@section('content')
    <h1 class="mb-4">Yêu cầu rút tiền Affiliate</h1>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="row mb-3 gy-3 align-items-end">
        <div class="col-md-4">
            <label for="withdrawStatusFilter" class="form-label">Lọc trạng thái</label>
            <select id="withdrawStatusFilter" class="form-select">
                <option value="pending" selected>Chờ duyệt</option>
                <option value="approved">Đã duyệt</option>
                <option value="paid">Đã thanh toán</option>
                <option value="rejected">Từ chối</option>
            </select>
        </div>
        <div class="col-md-8">
            <label for="withdrawSearch" class="form-label">Tìm kiếm</label>
            <input id="withdrawSearch" type="text" class="form-control form-control-lg" placeholder="Tìm kiếm theo tên, số tài khoản, ngân hàng...">
        </div>
    </div>

    <table id="withdrawRequestsTable" class="table table-bordered table-striped" style="width:100%">
        <thead>
            <tr>
                <th>#</th>
                <th>Người yêu cầu</th>
                <th>Số tiền</th>
                <th>Trạng thái</th>
                <th>Chủ tài khoản</th>
                <th>Số tài khoản</th>
                <th>Ngân hàng</th>
                <th>Ngày tạo</th>
                <th>Hành động</th>
            </tr>
        </thead>
    </table>

@endsection

@section('scripts')
<script>
    $(function () {
        var withdrawRequestsTable = $('#withdrawRequestsTable').DataTable({
            dom: 'rt<"row mb-3"<"col-sm-6"l><"col-sm-6 text-end"p>>',
            processing: true,
            serverSide: true,
            autoWidth: false,
            pageLength: 20,
            searchDelay: 200,
            ajax: {
                url: '{{ route("admin.affiliate.withdrawals.datatable") }}',
                type: 'GET',
                data: function (d) {
                    d.status = $('#withdrawStatusFilter').val();
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
            order: [[0, 'desc']],
            columns: [
                { data: 'id' },
                { data: 'user' },
                { data: 'amount' },
                { data: 'status' },
                { data: 'account_name' },
                { data: 'account_number' },
                { data: 'bank_name' },
                { data: 'created_at' },
                { data: 'action', orderable: false, searchable: false },
            ],
            columnDefs: [{ targets: [3, 8], className: 'text-center' }],
        });

        $('#withdrawSearch').on('input', function () {
            withdrawRequestsTable.search(this.value).draw();
        });

        $('#withdrawStatusFilter').on('change', function () {
            withdrawRequestsTable.ajax.reload();
        });
    });
</script>
@endsection
