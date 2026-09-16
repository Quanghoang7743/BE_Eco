@extends('admin.layout')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Chỉnh sửa {{ $definition['type'] }} chính sách</h1>
            <p class="text-muted mb-0">Slug được cố định để frontend gọi đúng nội dung.</p>
        </div>
        <a href="{{ route('policy', $article->slug) }}" target="_blank" class="btn btn-outline-primary">Xem trước trên App</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form action="{{ route('admin.policies.update', $article->slug) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label for="title" class="form-label">Tiêu đề bài viết</label>
                    <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $article->title) }}" required>
                    @error('title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="summary" class="form-label">Tóm tắt ngắn</label>
                    <textarea class="form-control @error('summary') is-invalid @enderror" id="summary" name="summary" rows="3">{{ old('summary', $article->summary) }}</textarea>
                    @error('summary')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Nội dung chi tiết</label>
                    <textarea class="form-control @error('content') is-invalid @enderror" id="content" name="content" rows="14" required>{{ old('content', $article->content) }}</textarea>
                    @error('content')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-md-4">
                <div class="mb-3">
                    <label for="slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="slug" value="{{ $article->slug }}" readonly>
                </div>

                <div class="mb-3">
                    <label for="category_id" class="form-label">Danh mục</label>
                    <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id">
                        <option value="">-- Chọn danh mục --</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id', $article->category_id) == $category->id ? 'selected' : '' }}>{{ $category->title }}</option>
                        @endforeach
                    </select>
                    @error('category_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="published_at" class="form-label">Ngày cập nhật hiển thị</label>
                    <input type="datetime-local" class="form-control @error('published_at') is-invalid @enderror" id="published_at" name="published_at" value="{{ old('published_at', $article->published_at ? optional($article->published_at)->format('Y-m-d\\TH:i') : '') }}">
                    @error('published_at')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @php($isPublished = old('is_published', $article->published_at ? true : false))
                <div class="mb-3 form-check form-switch ps-0">
                    <input type="hidden" name="is_published" value="0">
                    <input class="form-check-input ms-0 @error('is_published') is-invalid @enderror" type="checkbox" role="switch" id="is_published" name="is_published" value="1" {{ $isPublished ? 'checked' : '' }}>
                    <label class="form-check-label ms-2" for="is_published">Công khai</label>
                    @error('is_published')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <input type="hidden" name="delete_pdf" id="delete_pdf" value="0">
                <input type="hidden" name="delete_additional_image" id="delete_additional_image" value="0">

                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Tài liệu & Ảnh chính sách</h6>
                    </div>
                    <div class="card-body">
                        <!-- File PDF -->
                        <div class="mb-3">
                            <label for="pdf_file" class="form-label fw-semibold">File PDF chi tiết</label>
                            
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

                            <input type="file" class="form-control @error('pdf_file') is-invalid @enderror" id="pdf_file" name="pdf_file" accept=".pdf">
                            <span class="text-muted" style="font-size: 11px;">Định dạng: .pdf. Tối đa 10MB.</span>
                            @error('pdf_file')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Ảnh bổ sung -->
                        <div>
                            <label for="additional_image" class="form-label fw-semibold">Ảnh bổ sung</label>
                            
                            @if($article->additional_image)
                                <div class="position-relative mb-2 rounded border overflow-hidden" id="additional-image-preview-container" style="width: 100px; height: 95px;">
                                    <img src="{{ asset('storage/' . $article->additional_image) }}" alt="Ảnh bổ sung" class="w-100 h-100" style="object-fit: cover;">
                                    <button type="button" class="btn-remove-preview" id="btn-delete-additional-image" title="Xóa ảnh bổ sung" style="position: absolute; top: 5px; right: 5px; width: 20px; height: 20px; border-radius: 50%; background: rgba(241, 65, 108, 0.9); color: white; border: none; display: flex; align-items: center; justify-content: center; font-size: 10px;">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            @endif

                            <input type="file" class="form-control @error('additional_image') is-invalid @enderror" id="additional_image" name="additional_image" accept="image/*">
                            <span class="text-muted" style="font-size: 11px;">JPG, PNG, WEBP. Tối đa 5MB.</span>
                            @error('additional_image')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="alert alert-info">
                    Bản ghi này phục vụ cho slug <strong>{{ $article->slug }}</strong> và không cho phép đổi slug.
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">Lưu thay đổi</button>
            <a href="{{ route('policy', $article->slug) }}" target="_blank" class="btn btn-outline-primary">Xem trước trên App</a>
            <a href="{{ route('admin.policies.index') }}" class="btn btn-secondary">Quay lại</a>
        </div>
    </form>

    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
    <script>
        (function () {
            const form = document.querySelector('form[action="{{ route('admin.policies.update', $article) }}"]');
            form.id = form.id || 'policyForm';
            let editorInstance = null;

            ClassicEditor.create(document.querySelector('#content'), {
                ckfinder: {
                    uploadUrl: '{{ route('upload') . '?_token=' . csrf_token() }}'
                }
            }).then(editor => {
                editorInstance = editor;
                editor.editing.view.change(writer => {
                    writer.setStyle('min-height', '320px', editor.editing.view.document.getRoot());
                });
            }).catch(error => {
                console.error(error);
            });

            // Ensure the editor content is synced into the textarea on submit
            form.addEventListener('submit', function (ev) {
                try {
                    if (editorInstance && typeof editorInstance.getData === 'function') {
                        const textarea = form.querySelector('textarea[name="content"]');
                        if (textarea) textarea.value = editorInstance.getData();
                    }
                } catch (e) {
                    console.error('Failed to sync editor content:', e);
                }
            });

            // Extra fallback: if submit seems blocked, force submission from the button
            const saveBtn = document.querySelector('#policyForm button[type="submit"]');
            if (saveBtn) {
                saveBtn.id = saveBtn.id || 'savePolicyBtn';
                saveBtn.addEventListener('click', function () {
                    // small delay to allow any other handlers to run
                    setTimeout(() => {
                        try { document.getElementById('policyForm').submit(); } catch (e) {}
                    }, 50);
                });
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
        })();
    </script>
@endsection