@extends('admin.layout')

@section('content')
    <div class="container-fluid">
        <h2 class="mb-4">Duyệt cửa hàng mới (S12)</h2>

        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="row mb-3 gy-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Trạng thái</label>
                <select id="approvalStatusFilter" class="form-control">
                    <option value="pending">Chờ duyệt</option>
                    <option value="active">Đã duyệt</option>
                    <option value="rejected">Từ chối</option>
                    <option value="all">Tất cả</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Tìm kiếm</label>
                <input id="approvalSearch" type="text" class="form-control" placeholder="Mã cửa hàng / tên cửa hàng / số giấy phép / chủ cửa hàng">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="button" id="approvalResetFilter" class="btn btn-outline-secondary w-100">Reset</button>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="storeApprovalsTable" class="table table-striped table-hover mb-0" style="width:100%">
                        <thead>
                            <tr>
                                <th>Cửa hàng</th>
                                <th>Thời gian gửi</th>
                                <th>L&#7847;n g&#7917;i duy&#7879;t</th>
                                <th>Chủ cửa hàng</th>
                                <th>Đăng ký</th>
                                <th>Pháp lý</th>
                                <th>Trạng thái</th>
                                <th>Phê duyệt</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="licenseImageModal" tabindex="-1" aria-labelledby="licenseImageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="licenseImageModalLabel">Ảnh GPLK</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="licenseImagePreview" src="" alt="Ảnh GPLK" class="img-fluid rounded" style="max-height: 70vh; object-fit: contain;">
                </div>
                <div class="modal-footer">
                    <a id="licenseImageOpenInNewTab" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary" aria-disabled="true">
                        Mở rộng (tab mới)
                    </a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('styles')
<style>
    #storeApprovalsTable thead th {
        text-transform: none !important;
    }
</style>
@endsection

@section('scripts')
<script>
    $(function () {
        function getUrlState() {
            var params = new URLSearchParams(window.location.search);
            var parsedOrderCol = parseInt(params.get('order_col') || '1', 10);
            if (isNaN(parsedOrderCol) || parsedOrderCol < 0) {
                parsedOrderCol = 1;
            }
            return {
                search: params.get('q') || '',
                status: params.get('status') || '{{ $status ?? 'pending' }}',
                orderCol: parsedOrderCol,
                orderDir: params.get('order_dir') === 'asc' ? 'asc' : 'desc',
                page: Math.max(1, parseInt(params.get('page') || '1', 10)),
                length: Math.max(1, parseInt(params.get('length') || '20', 10))
            };
        }

        function updateUrlState(table) {
            var info = table.page.info();
            var order = table.order();
            var params = new URLSearchParams(window.location.search);

            var currentSearch = table.search() || '';
            if (currentSearch) {
                params.set('q', currentSearch);
            } else {
                params.delete('q');
            }

            var status = $('#approvalStatusFilter').val() || 'pending';
            params.set('status', status);
            params.set('order_col', order.length ? order[0][0] : 1);
            params.set('order_dir', order.length ? order[0][1] : 'desc');
            params.set('page', info.page + 1);
            params.set('length', info.length);

            var newUrl = window.location.pathname + '?' + params.toString();
            history.replaceState(null, '', newUrl);
        }

        var urlState = getUrlState();
        $('#approvalSearch').val(urlState.search);
        $('#approvalStatusFilter').val(urlState.status);

        var approvalsTable = $('#storeApprovalsTable').DataTable({
            dom: 'rt<"row mb-3"<"col-sm-6"l><"col-sm-6 text-end"p>>',
            processing: true,
            serverSide: true,
            autoWidth: false,
            pageLength: urlState.length,
            searchDelay: 300,
            displayStart: (urlState.page - 1) * urlState.length,
            search: { search: urlState.search },
            order: [[urlState.orderCol, urlState.orderDir]],
            ajax: {
                url: '{{ route('admin.store-approvals.datatable') }}',
                type: 'GET',
                data: function (d) {
                    d.status = $('#approvalStatusFilter').val() || 'pending';
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
            columns: [
                { data: 'store' },
                { data: 'submitted_at' },
                { data: 'approval_attempt', searchable: false },
                { data: 'owner' },
                { data: 'registration' },
                { data: 'legal' },
                { data: 'status' },
                { data: 'approval' },
                { data: 'action', orderable: false, searchable: false },
            ],
            columnDefs: [
                { targets: [1, 2, 8], className: 'text-nowrap' },
            ],
        });

        approvalsTable.on('draw', function () {
            updateUrlState(approvalsTable);
        });

        $('#approvalSearch').on('input', function () {
            approvalsTable.search(this.value).draw();
        });

        $('#approvalStatusFilter').on('change', function () {
            approvalsTable.draw();
        });

        $('#approvalResetFilter').on('click', function () {
            $('#approvalSearch').val('');
            $('#approvalStatusFilter').val('pending');
            approvalsTable.search('').order([1, 'desc']).page.len(20).draw();
        });

        $(document).on('click', '.license-preview-trigger', function () {
            var imageUrl = $(this).data('license-image');
            var title = $(this).data('license-title') || 'Ảnh GPLK';
            if (!imageUrl) {
                return;
            }

            $('#licenseImageModalLabel').text(title);
            $('#licenseImagePreview').attr('src', imageUrl);
            $('#licenseImageOpenInNewTab').attr('href', imageUrl).removeAttr('aria-disabled');
            var modalElement = document.getElementById('licenseImageModal');
            var modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            modal.show();
        });

        $('#licenseImageModal').on('hidden.bs.modal', function () {
            $('#licenseImagePreview').attr('src', '');
            $('#licenseImageOpenInNewTab').attr('href', '#').attr('aria-disabled', 'true');
        });
    });
</script>
@endsection
