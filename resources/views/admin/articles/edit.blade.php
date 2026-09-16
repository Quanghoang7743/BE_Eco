@extends('admin.layout')

@section('content')
    <!-- Custom CSS for Article Edit Page -->
    <style>
        .article-container {
            font-family: 'Public Sans', sans-serif;
            color: #3f4254;
        }
        .article-header {
            margin-bottom: 30px;
        }
        .article-header h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1e1e2d;
            margin-top: 5px;
            margin-bottom: 5px;
        }
        .article-header p {
            color: #7e8299;
            font-size: 14px;
            margin: 0;
        }
        .custom-breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #7e8299;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .custom-breadcrumb a {
            color: #7e8299;
            text-decoration: none;
            transition: color 0.2s;
        }
        .custom-breadcrumb a:hover {
            color: #1880f9;
        }
        .custom-breadcrumb .separator {
            color: #b5b5c3;
        }
        .custom-breadcrumb .active {
            color: #464e5f;
            font-weight: 500;
        }

        /* Card Styles */
        .article-card {
            background-color: #ffffff;
            border-radius: 12px;
            border: none;
            box-shadow: 0 0 20px 0 rgba(76, 87, 125, 0.03);
            margin-bottom: 25px;
        }
        .article-card .card-body {
            padding: 25px;
        }
        .card-title-custom {
            font-size: 14px;
            font-weight: 700;
            color: #1e1e2d;
            margin: 0;
        }

        /* Form Controls */
        .form-label-custom {
            font-size: 13px;
            font-weight: 600;
            color: #3f4254;
            margin-bottom: 8px;
            display: block;
        }
        .form-control-custom,
        .form-select-custom {
            width: 100%;
            border: 1px solid #e4e6ef;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 14px;
            color: #3f4254;
            background-color: #ffffff;
            transition: border-color 0.2s, background-color 0.2s;
            box-shadow: none !important;
            outline: none;
        }
        .form-control-custom:focus,
        .form-select-custom:focus {
            border-color: #1880f9;
            background-color: #ffffff;
        }
        .form-control-custom::placeholder {
            color: #a1a5b7;
        }

        /* Character Counter */
        .counter-wrapper {
            position: relative;
        }
        .char-counter {
            position: absolute;
            bottom: -20px;
            right: 5px;
            font-size: 11px;
            color: #a1a5b7;
        }

        /* Right Settings Sidebar Group */
        .settings-sidebar-card {
            border: 1px solid #eff2f5;
            border-radius: 12px;
            background-color: #ffffff;
            margin-bottom: 20px;
            box-shadow: 0 0 20px 0 rgba(76, 87, 125, 0.02);
            overflow: hidden;
        }
        .settings-header {
            padding: 15px 20px;
            background-color: #ffffff;
            border-bottom: 1px solid #f1f1f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }
        .settings-header h6 {
            font-size: 14px;
            font-weight: 700;
            color: #1e1e2d;
            margin: 0;
        }
        .settings-body {
            padding: 20px;
        }

        /* Switch toggle style */
        .switch-custom {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .form-switch .form-check-input {
            width: 40px;
            height: 20px;
            margin-left: 0;
            cursor: pointer;
        }

        /* Drag/Drop Image upload */
        .upload-dashed-container {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        .upload-dashed-box-sm {
            flex: 1;
            border: 2px dashed #e4e6ef;
            border-radius: 8px;
            background-color: #f5f8fa;
            padding: 18px 10px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background-color 0.2s;
            position: relative;
            min-height: 95px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .upload-dashed-box-sm:hover {
            border-color: #1880f9;
            background-color: #f1f8ff;
        }
        .upload-dashed-box-sm i {
            font-size: 20px;
            color: #1880f9;
            margin-bottom: 5px;
        }
        .upload-dashed-box-sm p {
            font-size: 12px;
            font-weight: 700;
            color: #1f78d1;
            margin-bottom: 2px;
        }
        .upload-dashed-box-sm span {
            font-size: 10px;
            color: #a1a5b7;
        }

        .upload-preview-box {
            width: 100px;
            height: 95px;
            border-radius: 8px;
            border: 1px solid #eff2f5;
            position: relative;
            overflow: hidden;
            display: none; /* Hidden by default */
        }
        .upload-preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .btn-remove-preview {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: rgba(241, 65, 108, 0.9);
            color: #ffffff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
            transition: background-color 0.2s;
        }
        .btn-remove-preview:hover {
            background-color: rgba(217, 36, 76, 1);
        }

        /* Status indicator block */
        .publish-status-box {
            border: 1px solid #eff2f5;
            border-radius: 8px;
            background-color: #f9fafb;
            padding: 15px;
            margin-top: 15px;
        }
        .publish-status-title {
            font-size: 12px;
            font-weight: 700;
            color: #5e6278;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .publish-status-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: #3f4254;
            margin-bottom: 4px;
        }
        .publish-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #a1a5b7; /* Gray default for draft */
        }
        .publish-status-dot.active {
            background-color: #0bb783; /* Green for published */
        }
        .publish-status-text {
            font-size: 11.5px;
            color: #7e8299;
        }

        /* CKEditor Custom Design integration */
        .ck-editor__editable {
            min-height: 350px;
            max-height: 500px;
            border-bottom-left-radius: 8px !important;
            border-bottom-right-radius: 8px !important;
            border-color: #e4e6ef !important;
            font-family: inherit;
        }
        .ck-toolbar {
            border-top-left-radius: 8px !important;
            border-top-right-radius: 8px !important;
            border-color: #e4e6ef !important;
            background-color: #f5f8fa !important;
        }
        .ck.ck-editor__main>.ck-editor__editable:focus {
            border-color: #1880f9 !important;
        }

        /* Footer buttons styling */
        .form-footer-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 30px;
            margin-bottom: 40px;
            padding-top: 20px;
            border-top: 1px solid #f1f1f4;
        }
        .btn-action-back {
            background-color: #ffffff;
            border: 1px solid #e4e6ef;
            color: #5e6278;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .btn-action-back:hover {
            background-color: #f5f8fa;
            color: #3f4254;
        }
        .btn-action-draft {
            background-color: #ffffff;
            border: 1px solid #e4e6ef;
            color: #5e6278;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.2s;
            margin-right: 12px;
        }
        .btn-action-draft:hover {
            background-color: #f5f8fa;
            color: #3f4254;
        }
        .btn-action-publish {
            background-color: #1880f9;
            border: none;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 8px 0 0 8px;
            transition: background-color 0.2s;
        }
        .btn-action-publish:hover {
            background-color: #116dd5;
        }
        .btn-action-dropdown {
            background-color: #1880f9;
            border: none;
            border-left: 1px solid rgba(255, 255, 255, 0.15);
            color: #ffffff;
            padding: 10px 12px;
            border-radius: 0 8px 8px 0;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-action-dropdown:hover {
            background-color: #116dd5;
        }
    </style>

    <div class="article-container">
        <form action="{{ route('admin.articles.update', $article->id) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            
            <!-- Hidden field for delete image -->
            <input type="hidden" name="delete_image" id="delete_image" value="0">
            <input type="hidden" name="delete_pdf" id="delete_pdf" value="0">
            <input type="hidden" name="delete_additional_image" id="delete_additional_image" value="0">

            <!-- Breadcrumbs & Title -->
            <div class="article-header d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <ul class="custom-breadcrumb mb-2">
                        <li><a href="{{ route('admin.articles.index') }}">Bài viết</a></li>
                        <li class="separator">></li>
                        <li class="active">Chỉnh sửa</li>
                    </ul>
                    <h2>Chỉnh sửa bài viết</h2>
                    <p>Cập nhật thông tin và nội dung của bài viết.</p>
                </div>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <a href="{{ route('admin.articles.index') }}" class="btn-action-back">
                        <i class="fas fa-arrow-left me-2"></i> Quay lại
                    </a>
                    <!--
                    <button type="submit" name="action_draft" value="1" class="btn-action-draft">
                        <i class="far fa-file-alt me-2"></i> Lưu nháp
                    </button>
                    -->
                    <div class="btn-group">
                        <button type="submit" class="btn-action-publish">
                            <i class="fas fa-save me-2"></i> Cập nhật bài viết
                        </button>
                        <button type="button" class="btn-action-dropdown dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><button type="submit" class="dropdown-item">Đăng ngay lập tức</button></li>
                            <li><button type="submit" class="dropdown-item">Lên lịch đăng bài</button></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Left Column: Main Article Fields -->
                <div class="col-lg-8">
                    <!-- Title & Summary Input Card -->
                    <div class="article-card mb-4">
                        <div class="card-body">
                            <!-- Tiêu đề bài viết -->
                            <div class="mb-5 counter-wrapper">
                                <label for="title" class="form-label-custom">Tiêu đề bài viết <span class="text-danger">*</span></label>
                                <input type="text" class="form-control-custom @error('title') is-invalid @enderror" 
                                       id="title" name="title" value="{{ old('title', $article->title) }}" 
                                       placeholder="Nhập tiêu đề bài viết tại đây..." maxlength="255" required>
                                <span class="char-counter" id="title-counter">0 / 255</span>
                                @error('title')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Tóm tắt nội dung -->
                            <div class="mb-2 counter-wrapper">
                                <label for="summary" class="form-label-custom">Tóm tắt nội dung</label>
                                <textarea class="form-control-custom @error('summary') is-invalid @enderror" 
                                          id="summary" name="summary" rows="3" 
                                          placeholder="Viết tóm tắt ngắn gọn về nội dung bài viết (hiển thị ở trang danh sách và chia sẻ)..." 
                                          maxlength="300">{{ old('summary', $article->summary) }}</textarea>
                                <span class="char-counter" id="summary-counter">0 / 300</span>
                                @error('summary')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- CKEditor Content Card -->
                    <div class="article-card mb-4">
                        <div class="card-body">
                            <div class="mb-1">
                                <label for="content" class="form-label-custom">Nội dung chi tiết <span class="text-danger">*</span></label>
                                <textarea class="form-control @error('content') is-invalid @enderror" id="content" name="content">{{ old('content', $article->content) }}</textarea>
                                @error('content')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Settings Sidebar -->
                <div class="col-lg-4">
                    <!-- Card 1: Cài đặt bài viết -->
                    <div class="settings-sidebar-card">
                        <div class="settings-header" data-bs-toggle="collapse" data-bs-target="#settingsCollapse" aria-expanded="true">
                            <h6>Cài đặt bài viết</h6>
                            <i class="fas fa-chevron-down text-muted" style="font-size: 11px;"></i>
                        </div>
                        <div class="collapse show" id="settingsCollapse">
                            <div class="settings-body">
                                <!-- Trạng thái (dummy) -->
                                <!--
                                <div class="mb-3">
                                    <label class="form-label-custom">Trạng thái</label>
                                    <select class="form-select-custom" id="status_dummy">
                                        <option value="draft" {{ !$article->published_at || \Carbon\Carbon::parse($article->published_at)->isFuture() ? 'selected' : '' }}>Bản nháp</option>
                                        <option value="published" {{ $article->published_at && \Carbon\Carbon::parse($article->published_at)->isPast() ? 'selected' : '' }}>Công khai</option>
                                    </select>
                                </div>
                                -->

                                <!-- Chế độ hiển thị (dummy) -->
                                <!--
                                <div class="mb-3">
                                    <label class="form-label-custom">Chế độ hiển thị</label>
                                    <select class="form-select-custom" id="visibility_dummy">
                                        <option value="public" selected>Công khai</option>
                                        <option value="private">Riêng tư</option>
                                    </select>
                                </div>
                                -->

                                <!-- Đánh dấu là Tin Hot -->
                                <div class="mb-3 switch-custom">
                                    <span class="form-label-custom mb-0">Đánh dấu là Tin Hot</span>
                                    <div class="form-check form-switch pe-0">
                                        <input type="checkbox" class="form-check-input" id="is_hot" name="is_hot" value="1" {{ old('is_hot', $article->is_hot) ? 'checked' : '' }}>
                                    </div>
                                </div>

                                <!-- Đường dẫn (slug) -->
                                <div class="mb-1">
                                    <label for="slug" class="form-label-custom">Đường dẫn (slug)</label>
                                    <input type="text" class="form-control-custom @error('slug') is-invalid @enderror" 
                                           id="slug" name="slug" value="{{ old('slug', $article->slug) }}" placeholder="Tự động tạo từ tiêu đề nếu để trống">
                                    @error('slug')
                                        <div class="text-danger small mt-1">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Card 2: Khu hiển thị -->
                    <div class="settings-sidebar-card">
                        <div class="settings-header">
                            <h6>Khu hiển thị</h6>
                        </div>
                        <div class="settings-body">
                            <select class="form-select-custom @error('display_area') is-invalid @enderror" id="display_area" name="display_area" required>
                                @foreach ($displayAreas as $value => $label)
                                    <option value="{{ $value }}" {{ old('display_area', $article->display_area ?? 'article') === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('display_area')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Card 3: Chuyên mục -->
                    <div class="settings-sidebar-card">
                        <div class="settings-header">
                            <h6>Chuyên mục</h6>
                        </div>
                        <div class="settings-body">
                            <select class="form-select-custom @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required>
                                <option value="">Chọn chuyên mục...</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}" data-slug="{{ $cat->slug }}" {{ old('category_id', $article->category_id) == $cat->id ? 'selected' : '' }}>
                                        {{ $cat->title }}
                                    </option>
                                @endforeach
                            </select>
                            @error('category_id')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Card 4: Thời gian đăng -->
                    <div class="settings-sidebar-card">
                        <div class="settings-header">
                            <h6>Thời gian đăng</h6>
                        </div>
                        <div class="settings-body">
                            <div class="position-relative">
                                <input type="datetime-local" class="form-control-custom @error('published_at') is-invalid @enderror"
                                       id="published_at" name="published_at" value="{{ old('published_at', $article->published_at ? \Carbon\Carbon::parse($article->published_at)->format('Y-m-d\TH:i') : '') }}">
                            </div>
                            @error('published_at')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Card 5: Ảnh đại diện -->
                    <div class="settings-sidebar-card">
                        <div class="settings-header">
                            <h6>Ảnh đại diện</h6>
                        </div>
                        <div class="settings-body">
                            <div class="upload-dashed-container">
                                <!-- Hộp kéo thả -->
                                <div class="upload-dashed-box-sm" id="drag-drop-zone">
                                    <input type="file" name="image" id="image_input" 
                                           style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 5;" 
                                           accept="image/*">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Tải ảnh lên</p>
                                    <span>JPG, PNG, WEBP. Tối đa 2MB.</span>
                                </div>

                                <!-- Box Preview hình ảnh -->
                                <div class="upload-preview-box" id="preview-box" style="{{ $article->image ? 'display: block;' : '' }}">
                                    <img src="{{ $article->image ? asset('storage/' . $article->image) : '' }}" alt="Preview" class="upload-preview-img" id="preview-img">
                                    <button type="button" class="btn-remove-preview" id="btn-clear-image" title="Xóa ảnh">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                            @error('image')
                                <div class="text-danger small mt-2">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <!-- Card 5b: Tài liệu & Ảnh chính sách (Chính sách) -->
                    <div class="settings-sidebar-card" id="policy-files-card" style="display: none;">
                        <div class="settings-header">
                            <h6>Tài liệu & Ảnh chính sách</h6>
                        </div>
                        <div class="settings-body">
                            <!-- File PDF chi tiết -->
                            <div class="mb-4">
                                <label for="pdf_file" class="form-label-custom">File PDF chi tiết</label>
                                
                                @if($article->pdf_path)
                                    <div class="d-flex align-items-center justify-content-between p-2 mb-2 border rounded bg-light" id="pdf-preview-container">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden" style="max-width: 80%;">
                                            <i class="far fa-file-pdf text-danger" style="font-size: 20px;"></i>
                                            <a href="{{ asset('storage/' . $article->pdf_path) }}" target="_blank" class="text-truncate text-dark fw-medium small" style="text-decoration: none;" title="Xem file PDF">
                                                {{ basename($article->pdf_path) }}
                                            </a>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger border-0 p-1" id="btn-delete-pdf" title="Xóa file PDF">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                @endif

                                <input type="file" class="form-control-custom @error('pdf_file') is-invalid @enderror" id="pdf_file" name="pdf_file" accept=".pdf">
                                <span class="text-muted" style="font-size: 11px;">Định dạng: .pdf. Tối đa 10MB.</span>
                                @error('pdf_file')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <!-- Ảnh bổ sung -->
                            <div>
                                <label for="additional_image" class="form-label-custom">Ảnh bổ sung</label>
                                
                                @if($article->additional_image)
                                    <div class="position-relative mb-2 rounded border overflow-hidden" id="additional-image-preview-container" style="width: 100px; height: 95px;">
                                        <img src="{{ asset('storage/' . $article->additional_image) }}" alt="Ảnh bổ sung" class="w-100 h-100" style="object-fit: cover;">
                                        <button type="button" class="btn-remove-preview" id="btn-delete-additional-image" title="Xóa ảnh bổ sung">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                @endif

                                <input type="file" class="form-control-custom @error('additional_image') is-invalid @enderror" id="additional_image" name="additional_image" accept="image/*">
                                <span class="text-muted" style="font-size: 11px;">JPG, PNG, WEBP. Tối đa 5MB.</span>
                                @error('additional_image')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <!-- Card 6: Trạng thái xuất bản (Màu xám/xanh lá ở dưới) -->
                    <div class="publish-status-box">
                        <div class="publish-status-title">Trạng thái xuất bản</div>
                        <div class="publish-status-indicator">
                            <div class="publish-status-dot" id="status-dot"></div>
                            <span id="status-label">Chưa đăng</span>
                        </div>
                        <div class="publish-status-text" id="status-desc">Bài viết sẽ được lưu dưới dạng bản nháp.</div>
                    </div>
                </div>
            </div>


        </form>
    </div>

    <!-- CKEditor 5 & Character counters, Drag drop JavaScript -->
    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Khởi tạo CKEditor 5 giống nguyên bản
            ClassicEditor.create(document.querySelector('#content'), {
                ckfinder: {
                    uploadUrl: '{{ route('upload') . '?_token=' . csrf_token() }}'
                }
            }).then(editor => {
                editor.editing.view.change(writer => {
                    writer.setStyle('min-height', '350px', editor.editing.view.document.getRoot());
                    writer.setStyle('max-height', '500px', editor.editing.view.document.getRoot());
                });
            }).catch(error => {
                console.error(error);
            });

            // Tiêu đề: Đếm số ký tự
            const titleInput = document.getElementById('title');
            const titleCounter = document.getElementById('title-counter');
            if (titleInput && titleCounter) {
                const updateTitleCounter = () => {
                    titleCounter.textContent = `${titleInput.value.length} / 255`;
                };
                titleInput.addEventListener('input', updateTitleCounter);
                updateTitleCounter(); // Init
            }

            // Tóm tắt: Đếm số ký tự
            const summaryInput = document.getElementById('summary');
            const summaryCounter = document.getElementById('summary-counter');
            if (summaryInput && summaryCounter) {
                const updateSummaryCounter = () => {
                    summaryCounter.textContent = `${summaryInput.value.length} / 300`;
                };
                summaryInput.addEventListener('input', updateSummaryCounter);
                updateSummaryCounter(); // Init
            }

            // Xử lý kéo thả và xem trước ảnh đại diện
            const imageInput = document.getElementById('image_input');
            const previewBox = document.getElementById('preview-box');
            const previewImg = document.getElementById('preview-img');
            const btnClearImage = document.getElementById('btn-clear-image');
            const dragDropZone = document.getElementById('drag-drop-zone');
            const deleteImageInput = document.getElementById('delete_image');

            if (imageInput) {
                imageInput.addEventListener('change', function () {
                    const file = this.files[0];
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function (e) {
                            if (previewImg) previewImg.src = e.target.result;
                            if (previewBox) previewBox.style.display = 'block';
                        };
                        reader.readAsDataURL(file);
                        if (deleteImageInput) deleteImageInput.value = '0';
                    }
                });
            }

            // Nút Clear/Xóa ảnh xem trước
            if (btnClearImage) {
                btnClearImage.addEventListener('click', function (e) {
                    e.stopPropagation();
                    e.preventDefault();
                    if (imageInput) imageInput.value = '';
                    if (previewImg) previewImg.src = '';
                    if (previewBox) previewBox.style.display = 'none';
                    if (deleteImageInput) deleteImageInput.value = '1';
                });
            }

            // Kéo thả file ảnh
            if (dragDropZone && imageInput) {
                ['dragenter', 'dragover'].forEach(eventName => {
                    dragDropZone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        dragDropZone.style.borderColor = '#1880f9';
                        dragDropZone.style.backgroundColor = '#f1f8ff';
                    }, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    dragDropZone.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        dragDropZone.style.borderColor = '#e4e6ef';
                        dragDropZone.style.backgroundColor = '#f5f8fa';
                    }, false);
                });

                dragDropZone.addEventListener('drop', (e) => {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    if (files.length > 0) {
                        imageInput.files = files;
                        const event = new Event('change');
                        imageInput.dispatchEvent(event);
                    }
                }, false);
            }

            // Đồng bộ trạng thái xuất bản dựa trên thời gian đăng bài
            const publishedInput = document.getElementById('published_at');
            const statusDot = document.getElementById('status-dot');
            const statusLabel = document.getElementById('status-label');
            const statusDesc = document.getElementById('status-desc');

            if (publishedInput && statusDot && statusLabel && statusDesc) {
                const updateStatusIndicator = () => {
                    const val = publishedInput.value;
                    if (!val) {
                        statusDot.className = 'publish-status-dot';
                        statusLabel.textContent = 'Chưa đăng';
                        statusDesc.textContent = 'Bài viết sẽ được lưu dưới dạng bản nháp.';
                    } else {
                        const publishTime = new Date(val);
                        const now = new Date();
                        if (publishTime > now) {
                            statusDot.className = 'publish-status-dot';
                            statusLabel.textContent = 'Đã lên lịch';
                            statusDesc.textContent = `Lên lịch đăng bài vào lúc: ${publishTime.toLocaleString('vi-VN')}`;
                        } else {
                            statusDot.className = 'publish-status-dot active';
                            statusLabel.textContent = 'Công khai';
                            statusDesc.textContent = `Bài viết đã xuất bản công khai.`;
                        }
                    }
                };

                publishedInput.addEventListener('change', updateStatusIndicator);
                updateStatusIndicator(); // Init
            }

            // Hiển thị/ẩn mục upload PDF & Ảnh bổ sung dựa trên Chuyên mục / Khu hiển thị
            const categorySelect = document.getElementById('category_id');
            const displayAreaSelect = document.getElementById('display_area');
            const policyCard = document.getElementById('policy-files-card');

            if (categorySelect && displayAreaSelect && policyCard) {
                const togglePolicyCard = () => {
                    const selectedOption = categorySelect.options[categorySelect.selectedIndex];
                    const categorySlug = selectedOption ? selectedOption.getAttribute('data-slug') : '';
                    const displayArea = displayAreaSelect.value;

                    if (categorySlug === 'chinh-sach' || displayArea === 'policy') {
                        policyCard.style.display = 'block';
                    } else {
                        policyCard.style.display = 'none';
                    }
                };

                categorySelect.addEventListener('change', togglePolicyCard);
                displayAreaSelect.addEventListener('change', togglePolicyCard);
                
                // Chạy kiểm tra ngay khi load trang
                togglePolicyCard();
            }

            // Xử lý nút xóa file PDF
            const btnDeletePdf = document.getElementById('btn-delete-pdf');
            const pdfPreviewContainer = document.getElementById('pdf-preview-container');
            const deletePdfInput = document.getElementById('delete_pdf');
            if (btnDeletePdf && pdfPreviewContainer && deletePdfInput) {
                btnDeletePdf.addEventListener('click', function(e) {
                    e.preventDefault();
                    pdfPreviewContainer.style.display = 'none';
                    deletePdfInput.value = '1';
                });
            }

            // Xử lý nút xóa ảnh bổ sung
            const btnDeleteAdditionalImage = document.getElementById('btn-delete-additional-image');
            const additionalImagePreviewContainer = document.getElementById('additional-image-preview-container');
            const deleteAdditionalImageInput = document.getElementById('delete_additional_image');
            if (btnDeleteAdditionalImage && additionalImagePreviewContainer && deleteAdditionalImageInput) {
                btnDeleteAdditionalImage.addEventListener('click', function(e) {
                    e.preventDefault();
                    additionalImagePreviewContainer.style.display = 'none';
                    deleteAdditionalImageInput.value = '1';
                });
            }
        });
    </script>
@endsection
