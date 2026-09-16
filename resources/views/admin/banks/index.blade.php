@extends('admin.layout')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Danh sách ngân hàng</h1>
        <a href="{{ route('admin.banks.create') }}" class="btn btn-primary">Thêm ngân hàng</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row mb-3 gy-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Tìm kiếm</label>
            <input id="bankSearch" type="text" class="form-control form-control-lg" placeholder="Tìm kiếm ngân hàng...">
        </div>
        <div class="col-md-4">
            <label class="form-label d-block">Thao tác</label>
            <button id="deleteSelectedBanks" class="btn btn-danger" disabled>Xóa đã chọn</button>
        </div>
    </div>

    <table id="banksTable" class="table table-bordered table-striped" style="width:100%">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllBanks"></th>
                <th>#</th>
                <th>Mã</th>
                <th>Tên ngân hàng</th>
                <th>Hiển thị</th>
                <th>Hành động</th>
            </tr>
        </thead>
    </table>

@endsection

@section('scripts')
<script>
    $(function () {
        var banksTable = $('#banksTable').DataTable({
            dom: 'rt<"row mb-3"<"col-sm-6"l><"col-sm-6 text-end"p>>',
            processing: true,
            serverSide: true,
            autoWidth: false,
            pageLength: 20,
            searchDelay: 200,
            ajax: {
                url: '{{ route('admin.banks.datatable') }}',
                type: 'GET',
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
            order: [[1, 'asc']],
            columns: [
                { data: 'checkbox', orderable: false, searchable: false },
                { data: 'id' },
                { data: 'code' },
                { data: 'name' },
                { data: 'is_active' },
                { data: 'action', orderable: false, searchable: false },
            ],
            columnDefs: [{ targets: [0, 4, 5], className: 'text-center' }],
        });

        $('#bankSearch').on('input', function () {
            banksTable.search(this.value).draw();
        });

        banksTable.on('draw', function () {
            $('#selectAllBanks').prop('checked', false);
            updateDeleteSelectedButton();
        });

        function updateDeleteSelectedButton() {
            var selectedCount = $('.bank-row-checkbox:checked').length;
            $('#deleteSelectedBanks').prop('disabled', selectedCount === 0);
        }

        $('#banksTable').on('change', '.bank-row-checkbox', function () {
            updateDeleteSelectedButton();
            $('#selectAllBanks').prop('checked', $('.bank-row-checkbox').length === $('.bank-row-checkbox:checked').length);
        });

        $('#selectAllBanks').on('change', function () {
            $('.bank-row-checkbox').prop('checked', $(this).is(':checked'));
            updateDeleteSelectedButton();
        });

        $('#deleteSelectedBanks').on('click', function () {
            var selectedIds = $('.bank-row-checkbox:checked').map(function () {
                return $(this).val();
            }).get();

            if (!selectedIds.length) {
                return;
            }

            if (!confirm('Xác nhận xóa ' + selectedIds.length + ' ngân hàng đã chọn?')) {
                return;
            }

            var button = $(this);
            button.prop('disabled', true).text('Đang xóa...');

            axios.post('{{ route('admin.banks.destroyMultiple') }}', {
                ids: selectedIds,
            }, {
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                }
            })
            .then(function (response) {
                banksTable.ajax.reload(null, false);
                $('#selectAllBanks').prop('checked', false);
                updateDeleteSelectedButton();
                window.showAppSuccess(response.data.message || 'Ngân hàng đã được xóa.', 'Thành công');
            })
            .catch(function (error) {
                console.error(error);
                window.showAppError('Xóa ngân hàng thất bại. Vui lòng thử lại.', 'Lỗi');
            })
            .finally(function () {
                button.prop('disabled', false).text('Xóa đã chọn');
            });
        });
    });
</script>
@endsection
