@extends('admin.layout')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Danh sách danh mục</h1>
        <a href="{{ route('admin.categories.create') }}" class="btn btn-primary">Thêm danh mục</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row mb-3 gy-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Tìm kiếm</label>
            <input id="categorySearch" type="text" class="form-control form-control-lg" placeholder="Tìm kiếm danh mục...">
        </div>
        <div class="col-md-4">
            <label class="form-label d-block">Thao tác</label>
            <button id="deleteSelectedCategories" class="btn btn-danger" disabled>Xóa đã chọn</button>
        </div>
    </div>

    <table id="categoriesTable" class="table table-bordered table-striped" style="width:100%">
        <thead>
            <tr>
                <th><input type="checkbox" id="selectAllCategories"></th>
                <th>#</th>
                <th>Tên</th>
                <th>Slug</th>
                <th>Danh mục cha</th>
                <th>Mô tả</th>
                <th>Hành động</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
@endsection

@section('scripts')
<script>
    $(function () {
        var urlParams = new URLSearchParams(window.location.search);
        var initialSearch = urlParams.get('search') || '';
        var initialLength = parseInt(urlParams.get('length') || '20', 10);
        var initialPage = Math.max(1, parseInt(urlParams.get('page') || '1', 10));
        var initialOrderCol = parseInt(urlParams.get('orderCol') || '1', 10);
        var initialOrderDir = urlParams.get('orderDir') === 'asc' ? 'asc' : 'desc';

        $('#categorySearch').val(initialSearch);

        var categoriesTable = $('#categoriesTable').DataTable({
            dom: 'rt<"row mb-3"<"col-sm-6"l><"col-sm-6 text-end"p>>',
            processing: true,
            serverSide: true,
            autoWidth: false,
            pageLength: initialLength,
            displayStart: (initialPage - 1) * initialLength,
            searchDelay: 200,
            search: { search: initialSearch },
            ajax: {
                url: '{{ route('admin.categories.datatable') }}',
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
            order: [[initialOrderCol, initialOrderDir]],
            columns: [
                { data: 'checkbox', orderable: false, searchable: false },
                { data: 'id' },
                { data: 'title' },
                { data: 'slug' },
                { data: 'parent' },
                { data: 'description' },
                { data: 'action', orderable: false, searchable: false },
            ],
            columnDefs: [{ targets: [0, 6], className: 'text-center' }],
        });

        $('#categorySearch').on('input', function () {
            categoriesTable.search(this.value).draw();
        });

        categoriesTable.on('draw', function () {
            $('#selectAllCategories').prop('checked', false);
            updateDeleteSelectedButton();
            updateUrlState();
        });

        function updateUrlState() {
            var info = categoriesTable.page.info();
            var order = categoriesTable.order()[0] || [1, 'asc'];
            var searchValue = categoriesTable.search();

            if (searchValue) {
                urlParams.set('search', searchValue);
            } else {
                urlParams.delete('search');
            }

            urlParams.set('page', (info.page + 1).toString());
            urlParams.set('length', info.length.toString());
            urlParams.set('orderCol', order[0].toString());
            urlParams.set('orderDir', order[1]);

            var newUrl = window.location.pathname + '?' + urlParams.toString();
            window.history.replaceState({}, '', newUrl);
        }

        function updateDeleteSelectedButton() {
            var selectedCount = $('.category-row-checkbox:checked').length;
            $('#deleteSelectedCategories').prop('disabled', selectedCount === 0);
        }

        $('#categoriesTable').on('change', '.category-row-checkbox', function () {
            updateDeleteSelectedButton();
            $('#selectAllCategories').prop('checked', $('.category-row-checkbox').length === $('.category-row-checkbox:checked').length);
        });

        $('#selectAllCategories').on('change', function () {
            $('.category-row-checkbox').prop('checked', $(this).is(':checked'));
            updateDeleteSelectedButton();
        });

        $('#deleteSelectedCategories').on('click', function () {
            var selectedIds = $('.category-row-checkbox:checked').map(function () {
                return $(this).val();
            }).get();

            if (!selectedIds.length) {
                return;
            }

            if (!confirm('Xác nhận xóa ' + selectedIds.length + ' danh mục đã chọn?')) {
                return;
            }

            var button = $(this);
            button.prop('disabled', true).text('Đang xóa...');

            axios.post('{{ route('admin.categories.destroyMultiple') }}', {
                ids: selectedIds,
            })
            .then(function (response) {
                categoriesTable.ajax.reload(null, false);
                $('#selectAllCategories').prop('checked', false);
                updateDeleteSelectedButton();
                window.showAppSuccess(response.data.message || 'Danh mục đã được xóa.', 'Thành công');
            })
            .catch(function (error) {
                console.error(error);
                window.showAppError('Xóa danh mục thất bại. Vui lòng thử lại.', 'Lỗi');
            })
            .finally(function () {
                button.prop('disabled', false).text('Xóa đã chọn');
            });
        });
    });
</script>
@endsection
