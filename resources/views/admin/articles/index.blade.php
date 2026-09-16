@extends('admin.layout')

@section('styles')
<style>
    /* Table general styles */
    #articlesTable {
        border-collapse: collapse !important;
        margin-top: 0 !important;
        margin-bottom: 0 !important;
    }
    #articlesTable tbody tr {
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.2s;
    }
    #articlesTable tbody tr:hover {
        background-color: #f8fafc;
    }
    #articlesTable td {
        padding: 16px 12px !important;
        vertical-align: middle !important;
        border: none !important;
    }
    #articlesTable th {
        border: none !important;
        vertical-align: middle !important;
    }
    /* Style disabled button state */
    #deleteSelectedArticles:disabled {
        opacity: 0.55;
        cursor: not-allowed !important;
        background-color: #f8fafb !important;
        border-color: #e5e7eb !important;
        color: #9ca3af !important;
    }
    /* Hover link on article title */
    .article-title-text {
        transition: color 0.15s ease-in-out;
        text-decoration: none;
    }
    .article-title-text:hover {
        color: #2563eb !important;
        text-decoration: underline !important;
    }
    /* Custom select style styling */
    .form-select {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23475569' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
        background-size: 12px 12px !important;
        background-position: right 12px center !important;
    }
    .form-control:focus, .form-select:focus {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
    }
    /* DataTables length menu select */
    .dataTables_wrapper .dataTables_length select {
        width: 70px !important;
        text-align: center;
        padding-right: 8px !important;
        background-image: none !important;
    }
</style>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="fw-bold mb-1" style="font-size: 28px; color: #0f172a;">Danh sách bài viết</h1>
        <p class="text-secondary mb-0" style="font-size: 14px;">Quản lý và theo dõi tất cả bài viết trên hệ thống.</p>
    </div>
    <a href="{{ route('admin.articles.create') }}" class="btn px-4 py-2.5 d-inline-flex align-items-center" style="background-color: #2563eb; color: #ffffff; font-weight: 500; font-size: 14px; border-radius: 8px; border: none; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.1), 0 2px 4px -2px rgba(37, 99, 235, 0.1); transition: all 0.2s;" onmouseover="this.style.backgroundColor='#1d4ed8'" onmouseout="this.style.backgroundColor='#2563eb'">
        <i class="fa-solid fa-plus me-2" style="font-size: 12px;"></i> Thêm bài viết
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success border-0 shadow-sm" style="border-radius: 8px; background-color: #f0fdf4; color: #16a34a;">{{ session('success') }}</div>
@endif

<div class="card border-0 mb-4" style="border-radius: 12px; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05); background: #ffffff;">
    <div class="card-body p-3">
        <div class="row g-3 align-items-center">
            <!-- Search input -->
            <div class="col-md-5 col-lg-4">
                <div class="position-relative">
                    <span class="position-absolute top-50 translate-middle-y text-muted" style="left: 15px;">
                        <i class="fa-solid fa-magnifying-glass" style="font-size: 14px; color: #94a3b8;"></i>
                    </span>
                    <input id="articleSearch" type="text" class="form-control" placeholder="Tìm kiếm bài viết..." style="padding-left: 40px; height: 44px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 14px; color: #1e293b;">
                </div>
            </div>
            <!-- Category Filter dropdown -->
            <div class="col-md-4 col-lg-3">
                <select id="categoryFilter" class="form-select" style="height: 44px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 14px; color: #475569; cursor: pointer;">
                    <option value="">Tất cả danh mục</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->title }}</option>
                    @endforeach
                </select>
            </div>
            <!-- Delete action button -->
            <div class="col-md-3 col-lg-3">
                <button id="deleteSelectedArticles" class="btn d-inline-flex align-items-center justify-content-center gap-2 w-100 w-md-auto" disabled style="height: 44px; border-radius: 8px; font-size: 14px; font-weight: 500; border: 1px solid #fee2e2; background-color: #fef2f2; color: #ef4444; transition: all 0.2s; padding: 0 20px; cursor: pointer;" onmouseover="if(!this.disabled) { this.style.backgroundColor='#fecaca'; }" onmouseout="if(!this.disabled) { this.style.backgroundColor='#fef2f2'; }">
                    <i class="fa-regular fa-trash-can" style="font-size: 14px;"></i> Xóa đã chọn
                </button>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 mb-4" style="border-radius: 12px; box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05); overflow: hidden; background: #ffffff;">
    <div class="table-responsive">
        <table id="articlesTable" class="table align-middle" style="width:100%; margin: 0;">
            <thead style="background-color: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                <tr>
                    <th style="width: 40px; padding: 16px 20px;"><input type="checkbox" id="selectAllArticles" class="form-check-input" style="cursor: pointer; width: 18px; height: 18px;"></th>
                    <th style="padding: 16px 12px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">ẢNH ĐẠI DIỆN</th>
                    <th style="display: none;">#</th>
                    <th style="padding: 16px 12px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">TÊN BÀI VIẾT</th>
                    <th style="display: none;">Slug</th>
                    <th style="padding: 16px 12px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">DANH MỤC</th>
                    <th style="display: none;">Khu hiển thị</th>
                    <th style="display: none;">Thời gian đăng</th>
                    <th style="padding: 16px 12px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">NGÀY TẠO</th>
                    <th style="padding: 16px 12px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase;">TRẠNG THÁI</th>
                    <th style="padding: 16px 12px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; text-align: center; width: 120px;">HÀNH ĐỘNG</th>
                </tr>
            </thead>
            <tbody style="border-top: none;"></tbody>
        </table>
    </div>
</div>
@endsection

@section('scripts')
<script>
    $(function () {
        var urlParams = new URLSearchParams(window.location.search);
        var initialSearch = urlParams.get('search') || '';
        var initialCategory = urlParams.get('category') || '';
        var initialLength = parseInt(urlParams.get('length') || '20', 10);
        var initialPage = Math.max(1, parseInt(urlParams.get('page') || '1', 10));
        var initialOrderCol = parseInt(urlParams.get('orderCol') || '8', 10);
        var initialOrderDir = urlParams.get('orderDir') === 'asc' ? 'asc' : 'desc';

        $('#articleSearch').val(initialSearch);
        $('#categoryFilter').val(initialCategory);

        var articlesTable = $('#articlesTable').DataTable({
            dom: 'rt<"row align-items-center mt-3 gy-3"<"col-md-4"l><"col-md-4 text-center"i><"col-md-4"p>>',
            processing: true,
            serverSide: true,
            autoWidth: false,
            pageLength: initialLength,
            displayStart: (initialPage - 1) * initialLength,
            searchDelay: 200,
            search: { search: initialSearch },
            ajax: {
                url: '{{ route('admin.articles.datatable') }}',
                type: 'GET',
                data: function (d) {
                    d.category_id = $('#categoryFilter').val();
                }
            },
            language: {
                processing: 'Đang xử lý...',
                search: 'Tìm kiếm:',
                lengthMenu: '_MENU_ mục / trang',
                info: 'Hiển thị _START_ – _END_ trong _TOTAL_ bài viết',
                infoEmpty: 'Hiển thị 0 – 0 trong 0 bài viết',
                infoFiltered: '(lọc từ _MAX_ bài viết)',
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
                { data: 'image', orderable: false, searchable: false },
                { data: 'id' },
                { data: 'title' },
                { data: 'slug' },
                { data: 'category' },
                { data: 'display_area' },
                { data: 'published_at' },
                { data: 'created_at' },
                { data: 'status', orderable: false, searchable: false },
                { data: 'action', orderable: false, searchable: false },
            ],
            columnDefs: [
                { targets: [2, 4, 6, 7], visible: false, searchable: false },
                { targets: [0, 1, 9, 10], className: 'text-center' }
            ],
        });

        $('#articleSearch').on('input', function () {
            articlesTable.search(this.value).draw();
        });

        $('#categoryFilter').on('change', function () {
            articlesTable.draw();
        });

        articlesTable.on('draw', function () {
            $('#selectAllArticles').prop('checked', false);
            updateDeleteSelectedButton();
            updateUrlState();
        });

        function updateUrlState() {
            var info = articlesTable.page.info();
            var order = articlesTable.order()[0] || [8, 'desc'];
            var searchValue = articlesTable.search();
            var categoryValue = $('#categoryFilter').val();

            if (searchValue) {
                urlParams.set('search', searchValue);
            } else {
                urlParams.delete('search');
            }

            if (categoryValue) {
                urlParams.set('category', categoryValue);
            } else {
                urlParams.delete('category');
            }

            urlParams.set('page', (info.page + 1).toString());
            urlParams.set('length', info.length.toString());
            urlParams.set('orderCol', order[0].toString());
            urlParams.set('orderDir', order[1]);

            var newUrl = window.location.pathname + '?' + urlParams.toString();
            window.history.replaceState({}, '', newUrl);
        }

        function updateDeleteSelectedButton() {
            var selectedCount = $('.article-row-checkbox:checked').length;
            $('#deleteSelectedArticles').prop('disabled', selectedCount === 0);
        }

        $('#articlesTable').on('change', '.article-row-checkbox', function () {
            updateDeleteSelectedButton();
            $('#selectAllArticles').prop('checked', $('.article-row-checkbox').length === $('.article-row-checkbox:checked').length);
        });

        $('#selectAllArticles').on('change', function () {
            $('.article-row-checkbox').prop('checked', $(this).is(':checked'));
            updateDeleteSelectedButton();
        });

        $('#deleteSelectedArticles').on('click', function () {
            var selectedIds = $('.article-row-checkbox:checked').map(function () {
                return $(this).val();
            }).get();

            if (!selectedIds.length) {
                return;
            }

            if (!confirm('Xác nhận xóa ' + selectedIds.length + ' bài viết đã chọn?')) {
                return;
            }

            var button = $(this);
            button.prop('disabled', true).html('<i class="fa-solid fa-circle-notch fa-spin me-2"></i> Đang xóa...');

            axios.post('{{ route('admin.articles.destroyMultiple') }}', {
                ids: selectedIds,
            })
            .then(function (response) {
                articlesTable.ajax.reload(null, false);
                $('#selectAllArticles').prop('checked', false);
                updateDeleteSelectedButton();
                window.showAppSuccess(response.data.message || 'Bài viết đã được xóa.', 'Thành công');
            })
            .catch(function (error) {
                console.error(error);
                window.showAppError('Xóa bài viết thất bại. Vui lòng thử lại.', 'Lỗi');
            })
            .finally(function () {
                button.prop('disabled', false).html('<i class="fa-regular fa-trash-can"></i> Xóa đã chọn');
            });
        });
    });
</script>
@endsection

