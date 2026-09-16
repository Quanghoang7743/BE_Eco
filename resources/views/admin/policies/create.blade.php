@extends('admin.layout')

@section('content')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="mb-1">Thêm mới chính sách</h1>
            <p class="text-muted mb-0">Tạo bản ghi cho một trong hai slug cố định: platform-terms hoặc store-policy.</p>
        </div>
        <a href="{{ route('admin.policies.index') }}" class="btn btn-outline-secondary">Quay lại</a>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form action="{{ route('admin.policies.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label for="policy_slug" class="form-label">Loại chính sách</label>
                    <select class="form-select @error('policy_slug') is-invalid @enderror" id="policy_slug" name="policy_slug" required>
                        <option value="platform-terms" {{ old('policy_slug', $definition['slug']) === 'platform-terms' ? 'selected' : '' }}>Điều khoản sử dụng sàn</option>
                        <option value="store-policy" {{ old('policy_slug', $definition['slug']) === 'store-policy' ? 'selected' : '' }}>Chính sách cửa hàng</option>
                    </select>
                    @error('policy_slug')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="title" class="form-label">Tiêu đề bài viết</label>
                    <input type="text" class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ old('title', $definition['title']) }}" required>
                    @error('title')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="summary" class="form-label">Tóm tắt ngắn</label>
                    <textarea class="form-control @error('summary') is-invalid @enderror" id="summary" name="summary" rows="3">{{ old('summary') }}</textarea>
                    @error('summary')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="content" class="form-label">Nội dung chi tiết</label>
                    <textarea class="form-control @error('content') is-invalid @enderror" id="content" name="content" rows="14" required>{{ old('content') }}</textarea>
                    @error('content')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="col-md-4">
                <div class="mb-3">
                    <label for="slug" class="form-label">Slug cố định</label>
                    <input type="text" class="form-control" id="slug" value="{{ $definition['slug'] }}" readonly>
                </div>

                <div class="mb-3">
                    <label for="category_id" class="form-label">Danh mục</label>
                    <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id">
                        <option value="">-- Chọn danh mục --</option>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" {{ old('category_id') == $category->id ? 'selected' : '' }}>{{ $category->title }}</option>
                        @endforeach
                    </select>
                    @error('category_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-3">
                    <label for="published_at" class="form-label">Ngày hiển thị</label>
                    <input type="datetime-local" class="form-control @error('published_at') is-invalid @enderror" id="published_at" name="published_at" value="{{ old('published_at') }}">
                    @error('published_at')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                @php($isPublished = old('is_published', $definition['published_at'] ?? true ? true : false))
                <div class="mb-3 form-check form-switch ps-0">
                    <input type="hidden" name="is_published" value="0">
                    <input class="form-check-input ms-0 @error('is_published') is-invalid @enderror" type="checkbox" role="switch" id="is_published" name="is_published" value="1" {{ old('is_published', true) ? 'checked' : '' }}>
                    <label class="form-check-label ms-2" for="is_published">Công khai</label>
                    @error('is_published')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>

                <div class="card mb-3">
                    <div class="card-header py-2">
                        <h6 class="mb-0">Tài liệu & Ảnh chính sách</h6>
                    </div>
                    <div class="card-body">
                        <!-- File PDF -->
                        <div class="mb-3">
                            <label for="pdf_file" class="form-label fw-semibold">File PDF chi tiết</label>
                            <input type="file" class="form-control @error('pdf_file') is-invalid @enderror" id="pdf_file" name="pdf_file" accept=".pdf">
                            <span class="text-muted" style="font-size: 11px;">Định dạng: .pdf. Tối đa 10MB.</span>
                            @error('pdf_file')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Ảnh bổ sung -->
                        <div>
                            <label for="additional_image" class="form-label fw-semibold">Ảnh bổ sung</label>
                            <input type="file" class="form-control @error('additional_image') is-invalid @enderror" id="additional_image" name="additional_image" accept="image/*">
                            <span class="text-muted" style="font-size: 11px;">JPG, PNG, WEBP. Tối đa 5MB.</span>
                            @error('additional_image')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="alert alert-info">
                    Sau khi lưu, bản ghi sẽ đi vào luồng chỉnh sửa cố định theo slug tương ứng.
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">Lưu chính sách</button>
            <a href="{{ route('admin.policies.index') }}" class="btn btn-secondary">Hủy</a>
        </div>
    </form>

    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
    <script>
        (function () {
            const form = document.querySelector('form[action="{{ route('admin.policies.store') }}"]');
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
        })();
    </script>
@endsection