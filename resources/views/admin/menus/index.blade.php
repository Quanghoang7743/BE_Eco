@extends('admin.layout')

@section('content')

    {{-- ================= TABS HEADER ================= --}}
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#menuTab" type="button">
                Menu Footer
            </button>
        </li>

        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#mapTab" type="button">
                Bản đồ
            </button>
        </li>

        <li class="nav-item">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#socialTab" type="button">
                Social Link
            </button>
        </li>
    </ul>

    {{-- ================= TAB CONTENT ================= --}}
    <div class="tab-content">

        {{-- ================= MENU TAB ================= --}}
        <div class="tab-pane fade show active" id="menuTab">

            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <h5 class="alert-heading">
                    <i class="bi bi-info-circle me-2"></i>Hướng dẫn sử dụng Menu Footer Setting
                </h5>
                <hr>
                <div class="row">
                    <div class="col-md-4">
                        <strong>1️⃣ Thêm menu:</strong>
                        <ul class="small mb-2" style="padding-left: 15px;">
                            <li><strong>Custom Link:</strong> Nhập URL + Tiêu đề → Click "Thêm"</li>
                            <li><strong>Bài viết:</strong> Tick chọn → Click "Thêm đã chọn"</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <strong>2️⃣ Sắp xếp menu:</strong>
                        <ul class="small mb-2" style="padding-left: 15px;">
                            <li>Kéo icon <i class="bi bi-arrows-move"></i> để di chuyển thứ tự</li>
                            <li>Kéo sang phải để tạo menu con (tối đa 2 cấp)</li>
                            <li>Click nút <strong>"Lưu thứ tự"</strong> ở cuối trang</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <strong>3️⃣ Chỉnh sửa:</strong>
                        <ul class="small mb-2" style="padding-left: 15px;">
                            <li>Click <i class="bi bi-pencil text-warning"></i> để sửa menu</li>
                            <li>Click <i class="bi bi-trash text-danger"></i> để xóa menu</li>
                            <li>⚠️ <small>Xóa menu cha sẽ xóa luôn menu con</small></li>
                        </ul>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <div class="row">
                <!-- Cột bên trái -->
                <div class="col-md-4">
                    <h4 class="mb-3">Thêm vào Menu Footer</h4>

                    <!-- Accordion -->
                    <div class="accordion" id="menuAccordion">

                        <!-- Custom Link -->
                        <div class="accordion-item shadow-sm mb-2">
                            <h2 class="accordion-header" id="headingCustom">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapseCustom" aria-expanded="true" aria-controls="collapseCustom">
                                    <i class="bi bi-link-45deg me-1"></i> Custom Link
                                </button>
                            </h2>
                            <div id="collapseCustom" class="accordion-collapse collapse show"
                                aria-labelledby="headingCustom" data-bs-parent="#menuAccordion">
                                <div class="accordion-body">
                                    <form id="addCustomForm">
                                        <div class="mb-3">
                                            <label class="form-label">URL</label>
                                            <input type="text" name="url" class="form-control"
                                                placeholder="https://example.com" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Tiêu đề</label>
                                            <input type="text" name="title" class="form-control"
                                                placeholder="Tên hiển thị menu" required>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-primary w-100" id="addCustom">
                                            <i class="bi bi-plus-circle"></i> Thêm
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        {{-- Categories --}}
                <div class="accordion-item shadow-sm mb-2">
                    <h2 class="accordion-header" id="headingCategories">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                            data-bs-target="#collapseCategories" aria-expanded="false" aria-controls="collapseCategories">
                            <i class="bi bi-folder2-open me-1"></i> Danh mục bài viết
                        </button>
                    </h2>
                    <div id="collapseCategories" class="accordion-collapse collapse" aria-labelledby="headingCategories"
                        data-bs-parent="#menuAccordion">
                        <div class="accordion-body p-2">
                            <p class="small text-muted mb-2">
                                <i class="bi bi-info-circle me-1"></i>
                                Chọn các danh mục muốn thêm vào menu
                            </p>
                            <ul id="categoryList" class="list-group list-group-flush"
                                style="max-height: 400px; overflow-y: auto; padding-left: 0;">
                                <li class="list-group-item bg-light" style="list-style: none;">
                                    <a href="{{ route('admin.categories.create') }}" class="text-decoration-none small">
                                        <i class="bi bi-plus-circle me-1"></i>Thêm danh mục mới
                                    </a>
                                </li>
                                <li class="list-group-item d-flex align-items-center py-1" style="list-style: none;">
                                    <input type="checkbox" id="checkAllCategories" class="form-check-input me-2">
                                    <span class="small fw-bold text-primary">Chọn tất cả</span>
                                    <a href="#" id="toggleViewAllCategories"
                                        class="ms-auto small text-decoration-none">
                                        <i class="bi bi-caret-down-fill me-1"></i>Xem tất cả
                                    </a>
                                </li>

                                @foreach ($categories as $cat)
                                    @include('admin.menus._category_item', [
                                        'category' => $cat,
                                        'level' => 0,
                                        'maxLevel' => 3,
                                        'menuType' => 'normal',
                                    ])
                                @endforeach

                            </ul>
                            <button type="button" class="btn btn-sm btn-primary w-100 mt-2" id="addCategories">
                                <i class="bi bi-check2-square me-1"></i> Thêm đã chọn vào menu
                            </button>
                        </div>
                    </div>
                </div>

                        <!-- Posts -->
                        <div class="accordion-item shadow-sm mb-2">
                            <h2 class="accordion-header" id="headingPosts">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#collapsePosts" aria-expanded="false" aria-controls="collapsePosts">
                                    <i class="bi bi-file-text me-1"></i> Bài viết
                                </button>
                            </h2>
                            <div id="collapsePosts" class="accordion-collapse collapse" aria-labelledby="headingPosts"
                                data-bs-parent="#menuAccordion">
                                <div class="accordion-body p-2">
                                    <ul id="postList" class="list-group list-group-flush"
                                        style="max-height: 400px; overflow-y: auto; padding-left: 0;">
                                        <li class="list-group-item bg-light" style="list-style: none;">
                                            <a href="{{ route('admin.articles.create') }}"
                                                class="text-decoration-none small">
                                                <i class="bi bi-plus-circle me-1"></i>Thêm bài viết mới
                                            </a>
                                        </li>
                                        @foreach ($posts as $post)
                                            <li class="list-group-item d-flex align-items-center py-2"
                                                style="list-style: none;">
                                                <input type="checkbox" class="form-check-input me-2 post-checkbox"
                                                    value="{{ $post->id }}"
                                                    data-title="{{ $post->title ?? 'Không có tên' }}">
                                                <span class="small">{{ $post->title ?? 'Không có tên' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                    <button type="button" class="btn btn-sm btn-primary w-100 mt-2" id="addPosts">
                                        <i class="bi bi-check2-square me-1"></i> Thêm đã chọn vào menu
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Cột bên phải -->
                <div class="col-md-8">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="mb-0">
                            <i class="bi bi-list-ul me-2"></i>Cấu trúc Menu Footer
                        </h4>
                        <div class="d-flex gap-2">
                            <span class="badge bg-info text-dark" data-bs-toggle="tooltip" title="Kéo icon ⇄ để sắp xếp">
                                <i class="bi bi-info-circle me-1"></i>Kéo để sắp xếp
                            </span>
                            <span class="badge bg-warning text-dark" data-bs-toggle="tooltip" title="Tối đa 2 cấp menu">
                                <i class="bi bi-layers me-1"></i>Tối đa 2 cấp
                            </span>
                        </div>
                    </div>

                    @if ($menuTree->isEmpty())
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Chưa có menu nào!</strong>
                            Hãy thêm menu từ cột bên trái.
                        </div>
                    @else
                        <div class="card shadow-sm">
                            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                                <span class="text-muted small">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Kéo icon <i class="bi bi-arrows-move"></i> để sắp xếp và lồng cấp
                                </span>
                                <button class="btn btn-primary btn-sm save-menu-btn">
                                    <i class="bi bi-save me-1"></i>Lưu thứ tự
                                </button>
                            </div>
                            <div class="card-body p-0">
                                <div class="menu-scroll-container"
                                    style="max-height: 800px; overflow-y: auto; padding: 1.5rem;">
                                    <div class="dd" id="nestable">
                                        <ol class="dd-list" style="list-style: none; padding-left: 0;">
                                            @foreach ($menuTree as $menu)
                                                @include('admin.menus._menu_item', ['menu' => $menu])
                                            @endforeach
                                        </ol>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer bg-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        <i class="bi bi-list-check me-1"></i>
                                        Tổng số menu: <strong class="text-primary">{{ $totalMenus }}</strong>
                                        <span class="mx-1">|</span>
                                        Cấp 1: <strong class="text-success">{{ $menuTree->count() }}</strong>
                                    </small>
                                    <button class="btn btn-primary btn-sm save-menu-btn">
                                        <i class="bi bi-save me-1"></i>Lưu thứ tự
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
            <!-- Modal chỉnh sửa menu -->
            <div class="modal fade" id="editMenuModal" tabindex="-1" aria-labelledby="editMenuModalLabel"
                aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form id="editMenuForm">
                            @csrf
                            @method('PUT')
                            <div class="modal-header">
                                <h5 class="modal-title" id="editMenuModalLabel">Chỉnh sửa Menu Footer</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                    aria-label="Đóng"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="menu_id" id="menu_id">

                                <div class="mb-3">
                                    <label class="form-label">Tiêu đề</label>
                                    <input type="text" name="title" id="menu_title" class="form-control" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Menu cha</label>
                                    <select name="parent_id" id="menu_parent" class="form-select">
                                        <option value="0">— Không có —</option>
                                        @foreach ($menuTree as $m)
                                            <option value="{{ $m->id }}">{{ $m->title }}</option>
                                            @if (!empty($m->children) && $m->children->isNotEmpty())
                                                @foreach ($m->children as $child)
                                                    <option value="{{ $child->id }}">— {{ $child->title }}</option>
                                                @endforeach
                                            @endif
                                        @endforeach
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Loại menu</label>
                                    <select name="type" id="menu_type" class="form-select" disabled required>
                                        <option value="custom">Custom Link</option>
                                        <option value="category">Danh mục</option>
                                        <option value="post">Bài viết</option>
                                    </select>
                                </div>

                                <div class="mb-3" id="menu_url_wrap">
                                    <label class="form-label">URL</label>
                                    <input type="text" name="url" id="menu_url" class="form-control">
                                </div>

                                <div class="mb-3 d-none" id="menu_reference_wrap">
                                    <label class="form-label" id="menu_reference_label">Liên kết</label>
                                    <select name="reference_id" id="menu_reference" class="form-select">
                                        <!-- options fill JS -->
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="submit" class="btn btn-success">Lưu</button>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================= MAP TAB ================= --}}
        <div class="tab-pane fade" id="mapTab">

            <div class="card">
                <div class="border-b border-[#f3f4f6] px-4 py-3">
                    <h3 class="text-[14px] font-semibold text-[#111827]">
                        Bản đồ Google Map
                    </h3>
                    <p class="mt-1 text-[12px] text-[#6b7280]">
                        Nhúng iframe để hiển thị bản đồ ở footer
                    </p>
                </div>
                <div class="card-body">

                    <form id="mapForm">
                        @csrf
                        <textarea name="map_iframe" class="form-control" rows="6" placeholder="Dán iframe Google Map">{{ $map_iframe ?? '' }}</textarea>

                        <button class="btn btn-primary mt-2">
                            Lưu bản đồ
                        </button>
                    </form>

                    <hr>

                    <div>
                        {!! $map_iframe ?? '<p class="text-muted">Chưa có bản đồ</p>' !!}
                    </div>

                </div>
            </div>

        </div>

        {{-- ================= SOCIAL TAB ================= --}}
        <div class="tab-pane fade" id="socialTab">

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">Social Links</h5>

                <button class="btn btn-primary btn-sm" id="btnAddSocial" data-bs-toggle="modal"
                    data-bs-target="#socialModal">
                    + Thêm Social
                </button>
            </div>

            {{-- TABLE --}}
            <div class="table-responsive border rounded-3 overflow-hidden">
                <table class="table align-middle mb-0 table-hover">
                    <thead style="background:#F8F9FA;">
                        <tr>
                            <th width="70">Icon</th>
                            <th width="180">Tên</th>
                            <th>Liên kết</th>
                            <th width="120">Trạng thái</th>
                            <th width="140" class="text-center">Hành động</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($socials ?? [] as $s)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center justify-content-center bg-light border rounded-3"
                                        style="width:40px;height:40px;">
                                        <img src="{{ $s->icon }}" alt="{{ $s->name }}" width="22"
                                            height="22" style="object-fit:contain;">
                                    </div>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        {{ $s->name }}
                                    </div>
                                </td>

                                <td>
                                    <a href="{{ $s->url }}" target="_blank"
                                        class="text-decoration-none text-primary d-inline-block text-truncate"
                                        style="max-width:300px;">
                                        {{ $s->url }}
                                    </a>
                                </td>

                                <td>
                                    <button
                                        class="btn btn-sm toggle-status {{ $s->status ? 'btn-success' : 'btn-secondary' }}"
                                        data-id="{{ $s->id }}">
                                        {{ $s->status ? 'Bật' : 'Tắt' }}
                                    </button>
                                </td>

                                <td>
                                    <div class="d-flex justify-content-center gap-2">

                                        <button class="btn btn-sm btn-light border btn-edit-social"
                                            data-id="{{ $s->id }}" data-name="{{ $s->name }}"
                                            data-url="{{ $s->url }}" data-status="{{ $s->status }}">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>

                                        <button class="btn btn-sm btn-light border text-danger btn-delete-social"
                                            data-id="{{ $s->id }}">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>

                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="modal fade" id="socialModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-sm rounded-3" style="overflow: hidden;">
                        <div class="modal-header border-bottom px-4 py-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="rounded-3 bg-primary-subtle d-flex align-items-center justify-content-center"
                                    style="width:36px;height:36px;">
                                    <i class="fa-solid fa-share-nodes text-primary"></i>
                                </div>

                                <div>
                                    <h6 class="modal-title mb-0 fw-semibold">
                                        Social Link
                                    </h6>
                                    <small class="text-muted">
                                        Thêm hoặc chỉnh sửa mạng xã hội
                                    </small>
                                </div>
                            </div>

                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                            </button>
                        </div>
                        <form id="socialForm" enctype="multipart/form-data">
                            @csrf
                            <div class="modal-body px-4 py-3 d-flex flex-column gap-3">

                                <input type="hidden" name="id" id="social_id">

                                {{-- Icon --}}
                                <div>
                                    <label class="form-label text-muted small mb-1">Icon</label>
                                    <label class="d-flex align-items-center gap-2 px-3 py-2 rounded-2 text-muted small"
                                        style="border: 0.5px dashed var(--bs-border-color); cursor: pointer;">
                                        <i class="ti ti-upload"></i>
                                        <span>Chọn file ảnh...</span>
                                        <input type="file" name="icon" id="social_icon" class="d-none">
                                    </label>
                                </div>

                                {{-- Tên --}}
                                <div>
                                    <label class="form-label text-muted small mb-1">Tên</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent border-end-0">
                                            <i class="fa-solid fa-at text-muted" style="font-size:15px;"></i>
                                        </span>
                                        <input type="text" name="name" id="social_name"
                                            class="form-control border-start-0" placeholder="Facebook, Instagram...">
                                    </div>
                                </div>

                                {{-- Link --}}
                                <div>
                                    <label class="form-label text-muted small mb-1">Link</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-transparent border-end-0">
                                            <i class="fa-solid fa-link text-muted" style="font-size:15px;"></i>
                                        </span>
                                        <input type="text" name="url" id="social_url"
                                            class="form-control border-start-0" placeholder="https://...">
                                    </div>
                                </div>

                                {{-- Trạng thái --}}
                                <div>
                                    <label class="form-label text-muted small mb-1">Trạng thái</label>
                                    <select name="status" id="social_status" class="form-select">
                                        <option value="1">Bật</option>
                                        <option value="0">Tắt</option>
                                    </select>
                                </div>

                            </div>

                            <div class="modal-footer border-top px-4 py-3 gap-2">
                                <button type="button" class="btn btn-light flex-fill"
                                    data-bs-dismiss="modal">Hủy</button>
                                <button type="submit"
                                    class="btn btn-primary flex-fill d-flex align-items-center justify-content-center gap-1">
                                    <i class="ti ti-device-floppy" style="font-size:15px;"></i> Lưu
                                </button>
                            </div>
                        </form>

                    </div>
                </div>
            </div>
        </div>

    </div>

@endsection

@section('styles')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/nestable2/1.6.0/jquery.nestable.min.css" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .dd-handle {
            cursor: move;
        }

        #categoryList li.category-item[data-level="0"] {
            display: list-item;
        }

        #categoryList li.category-item[data-level]:not([data-level="0"]) {
            display: none;
        }

        #categoryList.expanded li.category-item {
            display: list-item;
        }

        .dd {
            max-width: 100% !important;
        }

        .accordion-button::after {
            background-size: 1rem;
        }

        /* Ẩn hoàn toàn nút thu gọn/mở rộng (+ / -) của Nestable2 để giao diện sạch sẽ, tránh gạch đè lên drag handle */
        .dd-item>button {
            display: none !important;
        }

        /* Định nghĩa khoảng cách thụt lề rõ ràng cho menu con cấp 2 */
        .dd-list .dd-list {
            padding-left: 45px;
        }
    </style>
@endsection

@section('scripts')
    <!-- Nestable2 JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/nestable2/1.6.0/jquery.nestable.min.js"></script>
    <script>
        $(function() {
            // Khởi tạo Nestable2
            const $nestable = $('#nestable');
            if ($nestable.length) {
                $nestable.nestable({
                    maxDepth: 2 // Tối đa 2 cấp
                });
            }

            // Lưu thứ tự menu
            $('.save-menu-btn').click(function() {
                let $btn = $(this);
                let originalText = $btn.html();

                $btn.prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Đang lưu...');

                let order = $nestable.nestable('serialize');
                axios.post("{{ route('admin.menus.sort') }}", {
                        menu: order
                    })
                    .then(res => {
                        $btn.html('<i class="bi bi-check-circle me-1"></i>Đã lưu!').removeClass(
                            'btn-primary').addClass('btn-success');
                        if (typeof window.showAppSuccess === 'function') {
                            window.showAppSuccess('Đã lưu thứ tự menu thành công!');
                        }
                        setTimeout(() => {
                            location.reload();
                        }, 800);
                    })
                    .catch(err => {
                        console.error(err);
                        $btn.html('<i class="bi bi-x-circle me-1"></i>Lỗi!').removeClass('btn-primary')
                            .addClass('btn-danger');
                        if (typeof window.showAppError === 'function') {
                            window.showAppError('Có lỗi xảy ra khi lưu thứ tự menu');
                        } else {
                            alert('Có lỗi xảy ra khi lưu thứ tự menu');
                        }
                        setTimeout(() => {
                            $btn.prop('disabled', false).html(originalText).removeClass(
                                'btn-danger').addClass('btn-primary');
                        }, 2000);
                    });
            });

            // Thêm custom link
            $('#addCustom').click(function() {
                let title = $('#addCustomForm input[name=title]').val();
                let url = $('#addCustomForm input[name=url]').val();

                if (!title || !url) {
                    alert('Vui lòng nhập đầy đủ tiêu đề và URL!');
                    return;
                }

                axios.post("{{ route('admin.menus.store') }}", {
                    title: title,
                    url: url,
                    type: 'custom'
                }).then(res => {
                    location.reload();
                }).catch(err => {
                    console.error(err);
                    alert('Có lỗi xảy ra khi thêm custom link');
                });
            });

            // Thêm posts
            $('#checkAllCategories').change(function() {
                $('#categoryList .cat-checkbox, #categoryList .category-post-checkbox').prop('checked', this.checked);
            });

            $('#toggleViewAllCategories').click(function(e) {
                e.preventDefault();

                const $categoryList = $('#categoryList');
                const isExpanded = $categoryList.toggleClass('expanded').hasClass('expanded');

                $categoryList.find('.child-list').toggle(isExpanded);
                $categoryList.find('.toggle-btn').text(isExpanded ? '－' : '＋');
                $(this).html(isExpanded ?
                    '<i class="bi bi-caret-up-fill me-1"></i>Thu gọn' :
                    '<i class="bi bi-caret-down-fill me-1"></i>Xem tất cả'
                );
            });

            $('#addCategories').click(function() {
                let selected = [];

                $('#categoryList .cat-checkbox:checked').each(function() {
                    selected.push({
                        type: 'category',
                        reference_id: $(this).val(),
                        title: $(this).data('title'),
                        url: ''
                    });
                });

                $('#categoryList .category-post-checkbox:checked').each(function() {
                    selected.push({
                        type: 'post',
                        reference_id: $(this).val(),
                        title: $(this).data('title'),
                        url: ''
                    });
                });

                if (selected.length === 0) {
                    alert('Vui lòng chọn ít nhất một danh mục hoặc bài viết!');
                    return;
                }

                axios.post("{{ route('admin.menus.store') }}", {
                    items: selected
                }).then(() => {
                    location.reload();
                }).catch(err => {
                    console.error(err);
                    alert('Có lỗi xảy ra khi thêm danh mục hoặc bài viết!');
                });
            });

            $('#addPosts').click(function() {
                let selected = [];

                $('#postList .post-checkbox:checked').each(function() {
                    selected.push({
                        type: 'post',
                        reference_id: $(this).val(),
                        title: $(this).data('title'),
                        url: ''
                    });
                });

                if (selected.length === 0) {
                    alert('Vui lòng chọn ít nhất một bài viết!');
                    return;
                }

                axios.post("{{ route('admin.menus.store') }}", {
                    items: selected
                }).then(() => {
                    location.reload();
                }).catch(err => {
                    console.error(err);
                    alert('Có lỗi xảy ra khi thêm bài viết!');
                });
            });

            // Khi click nút Edit Menu
            $('.btn-edit-menu').click(function(e) {
                e.preventDefault();

                let menuId = $(this).data('id');
                let $menuItem = $nestable.find(`.dd-item[data-id="${menuId}"]`);

                // Lấy tất cả ID con đệ quy để loại trừ khỏi select box menu cha
                function getChildIds($item) {
                    let ids = [];
                    $item.children('ol.dd-list').children('li.dd-item').each(function() {
                        let childId = $(this).data('id');
                        ids.push(childId);
                        ids = ids.concat(getChildIds($(this)));
                    });
                    return ids;
                }
                let childIds = getChildIds($menuItem);
                let excludeIds = [menuId].concat(childIds);

                // Reset và điền danh sách select menu cha
                let $parentSelect = $('#menu_parent');
                $parentSelect.empty();
                $parentSelect.append('<option value="0">— Không có —</option>');

                $('#nestable .dd-item').each(function() {
                    let id = $(this).data('id');
                    let title = $(this).children('div').find('strong').first().text();
                    let depth = $(this).parents('ol.dd-list').length;

                    // Chỉ cho phép chọn menu cha nếu là cấp 1 và không phải chính nó hoặc con nó
                    if (depth === 1 && !excludeIds.includes(id)) {
                        $parentSelect.append(`<option value="${id}">${title}</option>`);
                    }
                });

                // Điền dữ liệu vào form modal
                let parentId = $(this).data('parent') || '0';
                $('#menu_id').val(menuId);
                $('#menu_title').val($(this).data('title'));
                $('#menu_type').val($(this).data('type')).trigger('change');
                $('#menu_parent').val(parentId);

                if ($(this).data('type') === 'custom') {
                    $('#menu_url').val($(this).data('url'));
                } else {
                    $('#menu_reference').val($(this).data('reference'));
                }

                // Show modal
                let modal = new bootstrap.Modal(document.getElementById('editMenuModal'));
                modal.show();
            });

            // Submit form modal edit
            $('#editMenuForm').submit(function(e) {
                e.preventDefault();
                let id = $('#menu_id').val();
                let parentId = $('#menu_parent').val();
                let type = $('#menu_type').val();
                let data = $(this).serialize() + `&type=${type}&parent_id=${parentId}`;

                axios.post('/admin/menus/' + id, data)
                    .then(res => {
                        location.reload();
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Có lỗi xảy ra khi cập nhật menu!');
                    });
            });

            // Toggle URL vs Reference select trong modal edit
            $('#menu_type').change(function() {
                let type = $(this).val();

                if (type === 'custom') {
                    $('#menu_url_wrap').removeClass('d-none');
                    $('#menu_reference_wrap').addClass('d-none');
                } else {
                    $('#menu_url_wrap').addClass('d-none');
                    $('#menu_reference_wrap').removeClass('d-none');

                    let options = '';
                    if (type === 'category') {
                        @foreach ($allCategoriesFlat as $cat)
                            @php
                                $catTitle = $cat->title ?? 'Không có tên';
                                $level = $cat->getLevel();
                                $prefix = str_repeat('—', $level);
                            @endphp
                            options +=
                                `<option value="{{ $cat->id }}">{{ $prefix }} {{ $catTitle }}</option>`;
                        @endforeach
                        $('#menu_reference_label').text('Danh mục bài viết');
                    } else if (type === 'post') {
                        @foreach ($posts as $post)
                            options +=
                                `<option value="{{ $post->id }}">{{ $post->title ?? 'Không có tên' }}</option>`;
                        @endforeach
                        $('#menu_reference_label').text('Bài viết');
                    }

                    $('#menu_reference').html(options);
                }
            });

            // Tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>



    <script>
        // =====================
        // List socails
        // =====================
        $(function() {
            const menuSettingRoutes = {
                mapUpdate: @json(route('admin.menus.map.update')),
                socialStore: @json(route('admin.menus.socials.store')),
                socialUpdate: @json(route('admin.menus.socials.update', ['id' => '__ID__'])),
                socialDestroy: @json(route('admin.menus.socials.destroy', ['id' => '__ID__'])),
                socialToggle: @json(route('admin.menus.socials.toggle', ['id' => '__ID__'])),
            };

            const routeWithId = (route, id) => route.replace('__ID__', encodeURIComponent(id));

            // =====================
            // SAVE MAP
            // =====================
            $('#mapForm').submit(function(e) {
                e.preventDefault();

                axios.post(menuSettingRoutes.mapUpdate, new FormData(this))
                    .then(() => location.reload())
                    .catch(error => alert(error.response?.data?.message || 'Lỗi lưu bản đồ'));
            });

            // =====================
            // RESET ADD MODAL
            // =====================
            $('#btnAddSocial').click(function() {
                $('#socialForm')[0].reset();
                $('#social_id').val('');
                $('#social_status').val('1');
                $('#social_icon').val('');
            });

            // =====================
            // CREATE / UPDATE
            // =====================
            $('#socialForm').submit(function(e) {
                e.preventDefault();

                let formData = new FormData(this);
                let id = $('#social_id').val();

                let url = id ?
                    routeWithId(menuSettingRoutes.socialUpdate, id) :
                    menuSettingRoutes.socialStore;

                axios.post(url, formData)
                    .then(() => location.reload())
                    .catch(error => alert(error.response?.data?.message || 'Lỗi lưu social'));
            });


            // =====================
            // EDIT
            // =====================
            $('.btn-edit-social').click(function() {

                $('#social_id').val($(this).data('id'));
                $('#social_name').val($(this).data('name'));
                $('#social_url').val($(this).data('url'));
                $('#social_status').val($(this).data('status'));
                $('#social_icon').val('');

                $('#socialModal').modal('show');
            });


            // =====================
            // DELETE
            // =====================
            $('.btn-delete-social').click(function() {

                if (!confirm('Xóa social này?')) return;

                axios.delete(routeWithId(menuSettingRoutes.socialDestroy, $(this).data('id')))
                    .then(() => location.reload())
                    .catch(error => alert(error.response?.data?.message || 'Lỗi xóa'));
            });


            // =====================
            // TOGGLE STATUS
            // =====================
            $('.toggle-status').click(function() {

                axios.post(routeWithId(menuSettingRoutes.socialToggle, $(this).data('id')))
                    .then(() => location.reload())
                    .catch(error => alert(error.response?.data?.message || 'Lỗi toggle'));
            });

        });
    </script>
@endsection
