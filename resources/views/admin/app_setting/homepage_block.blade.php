@extends('admin.layout')

@section('styles')
<style>
    .page-inner { padding: 20px 28px !important; max-width: 100% !important; }
    :root {
        --ac: #6366f1;
        --ac-lt: #ede9fe;
        --bd: #e2e8f0;
        --tx: #1e293b;
        --ts: #64748b;
        --tm: #94a3b8;
    }
    .block-row:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.05) !important;
    }
    .block-title-input {
        transition: all 0.2s ease;
    }
    .block-title-input:hover, .block-title-input:focus {
        background-color: #f1f5f9 !important;
        border-radius: 6px;
    }
    .edit-icon {
        transition: all 0.2s ease;
    }
    .block-row:hover .edit-icon {
        opacity: 0.8 !important;
        color: #6366f1 !important;
    }
    .drag-handles button {
        transition: color 0.2s;
    }
    .drag-handles button:hover {
        color: #6366f1 !important;
    }
    .block-row.dragging {
        opacity: 0.5;
        border: 2px dashed #6366f1 !important;
        background-color: #f8fafc;
    }
    .drag-handle {
        cursor: grab;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 40px;
        color: #94a3b8;
        font-size: 1.2rem;
        transition: color 0.2s;
        margin-right: 4px;
    }
    .drag-handle:hover {
        color: #6366f1;
    }
    .drag-handle:active {
        cursor: grabbing;
    }
</style>
@endsection

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 fw-bold text-dark mb-1">Cấu hình khối Trang chủ</h1>
            <p class="text-muted mb-0">Quản lý và sắp xếp các khối hiển thị ở trang chủ ứng dụng.</p>
        </div>
        <a href="{{ route('admin.app-setting.index') }}" class="btn btn-outline-primary d-inline-flex align-items-center gap-2" style="border-radius: 8px;">
            <i class="fas fa-arrow-left"></i>
            Quay lại Cài đặt
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success border-0 shadow-sm mb-4" style="border-radius: 10px; background-color: #ecfdf5; color: #065f46;">
            <i class="fas fa-check-circle me-2"></i> {{ session('success') }}
        </div>
    @endif

    <div class="card border-0 shadow-sm" style="border-radius: 16px; border: 1px solid #e2e8f0 !important;">
        <div class="card-header bg-white py-3 border-0" style="border-radius: 16px 16px 0 0;">
            <h5 class="fw-bold mb-0 text-dark">Danh sách khối trang chủ</h5>
        </div>
        <div class="card-body p-4">
            <form action="{{ route('admin.app-setting.homepage-block.update') }}" method="POST" id="blocksForm">
                @csrf
                @method('PUT')

                <div id="blocksContainer">
                    <!-- Blocks loaded by JS -->
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <button type="button" class="btn btn-outline-primary px-4 py-2" id="addBlockBtn" style="border-radius: 8px; font-weight: 600;">
                        <i class="fas fa-plus me-2"></i> Thêm khối mới
                    </button>
                    <button type="submit" class="btn btn-primary px-5 py-2" style="border-radius: 8px; font-weight: 600; background-color: #6366f1; border-color: #6366f1;">
                        Lưu cấu hình
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const flashSales = @json($flashSales);
    const productCategories = @json($productCategories);
    const articleCategories = @json($articleCategories);
    const savedBlocks = @json($blocks);

    const criteriaOptions = [
        { id: 'by_newest', name: 'Sản phẩm mới nhất' },
        { id: 'by_sales', name: 'Bán chạy nhất' },
        { id: 'by_discount', name: 'Đang khuyến mãi' },
        { id: 'by_price_low', name: 'Giá thấp' },
        { id: 'by_price_high', name: 'Giá cao' }
    ];

    const blocksContainer = document.getElementById('blocksContainer');
    const addBlockBtn = document.getElementById('addBlockBtn');

    // Populate saved blocks
    if (savedBlocks && savedBlocks.length > 0) {
        savedBlocks.forEach((block, index) => {
            addBlockRow(block.title, block.type, block.value);
        });
    } else {
        // Add default empty row if none exists
        addBlockRow('Sản phẩm mới nhất', 'criteria', 'by_newest');
    }

    addBlockBtn.addEventListener('click', function() {
        addBlockRow('Khối mới', 'criteria', 'by_newest');
    });

    let dragEl = null;

    function addBlockRow(title, type, value) {
        const index = blocksContainer.querySelectorAll('.block-row').length;
        const html = `
            <div class="block-row card border-0 mb-3 shadow-sm" style="border: 1px solid #e2e8f0 !important; border-radius: 14px !important; transition: transform 0.2s, box-shadow 0.2s;">
                <div class="card-body p-3">
                    <div class="row align-items-center g-3">
                        <div class="col-auto d-flex align-items-center">
                            <div class="drag-handle" title="Kéo thả để sắp xếp">
                                <i class="fas fa-grip-vertical"></i>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="d-flex align-items-center gap-2">
                                <input type="text" name="blocks[${index}][title]" value="${title}" class="form-control border-0 fw-bold block-title-input px-2 py-1" style="font-size: 1.05rem; background: transparent;" placeholder="Tên khối..." required>
                                <i class="fas fa-pencil-alt text-muted small edit-icon" style="opacity: 0.4;"></i>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <select name="blocks[${index}][type]" class="form-select border-1 block-type-select" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                                <option value="flashsale" ${type === 'flashsale' ? 'selected' : ''}>Flashsale</option>
                                <option value="featured" ${type === 'featured' ? 'selected' : ''}>Sản phẩm nổi bật</option>
                                <option value="criteria" ${type === 'criteria' ? 'selected' : ''}>Sản phẩm theo tiêu chí</option>
                                <option value="news" ${type === 'news' ? 'selected' : ''}>Tin tức & Bài viết</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <select name="blocks[${index}][value]" class="form-select border-1 block-value-select" style="border-radius: 8px; border-color: #d1d5db; padding: 0.6rem 1rem;">
                                <!-- Populated dynamically -->
                            </select>
                        </div>

                        <div class="col-auto text-end">
                            <button type="button" class="btn btn-outline-danger border-0 remove-block-btn" style="border-radius: 8px;" title="Xóa khối">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        blocksContainer.insertAdjacentHTML('beforeend', html);
        const newRow = blocksContainer.lastElementChild;

        // Populate options for select 2
        const typeSelect = newRow.querySelector('.block-type-select');
        const valueSelect = newRow.querySelector('.block-value-select');

        updateValueOptions(typeSelect.value, valueSelect, value);

        typeSelect.addEventListener('change', function() {
            updateValueOptions(typeSelect.value, valueSelect, '');
            // Auto update default title based on selected type
            const titleInput = newRow.querySelector('.block-title-input');
            if (typeSelect.value === 'flashsale') {
                titleInput.value = 'Ưu đãi Flash Sale';
            } else if (typeSelect.value === 'featured') {
                titleInput.value = 'Sản phẩm nổi bật';
            } else if (typeSelect.value === 'criteria') {
                titleInput.value = 'Sản phẩm mới nhất';
            } else if (typeSelect.value === 'news') {
                titleInput.value = 'Tin tức nổi bật';
            }
        });

        // Attach buttons events
        newRow.querySelector('.remove-block-btn').addEventListener('click', function() {
            newRow.remove();
            reindexBlocks();
        });

        // Attach drag and drop events
        const handle = newRow.querySelector('.drag-handle');
        handle.addEventListener('mousedown', function() {
            newRow.draggable = true;
        });

        handle.addEventListener('mouseup', function() {
            newRow.draggable = false;
        });

        newRow.addEventListener('dragstart', function(e) {
            dragEl = this;
            e.dataTransfer.effectAllowed = 'move';
            this.classList.add('dragging');
        });

        newRow.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            dragEl = null;
            newRow.draggable = false;
            reindexBlocks();
        });

        newRow.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';

            if (this === dragEl) return;

            const bounding = this.getBoundingClientRect();
            const offset = e.clientY - bounding.top - bounding.height / 2;

            if (offset < 0) {
                this.parentNode.insertBefore(dragEl, this);
            } else {
                this.parentNode.insertBefore(dragEl, this.nextSibling);
            }
        });
    }

    function updateValueOptions(type, selectElement, selectedValue) {
        selectElement.innerHTML = '';
        let options = [];

        if (type === 'flashsale') {
            options.push({ id: 'all', name: 'Chọn chương trình Flash Sale' });
            flashSales.forEach(item => {
                options.push({ id: item.id, name: item.name });
            });
        } else if (type === 'featured') {
            options.push({ id: 'all', name: 'Mặc định (Tất cả sản phẩm nổi bật)' });
            productCategories.forEach(item => {
                options.push({ id: item.id, name: item.name });
            });
        } else if (type === 'criteria') {
            options = criteriaOptions;
        } else if (type === 'news') {
            options.push({ id: 'all', name: 'Mặc định (Tất cả bài viết)' });
            articleCategories.forEach(item => {
                options.push({ id: item.id, name: item.title || item.name });
            });
        }

        options.forEach(opt => {
            const isSelected = String(opt.id) === String(selectedValue) ? 'selected' : '';
            selectElement.insertAdjacentHTML('beforeend', `<option value="${opt.id}" ${isSelected}>${opt.name}</option>`);
        });
    }

    function reindexBlocks() {
        const rows = blocksContainer.querySelectorAll('.block-row');
        rows.forEach((row, index) => {
            // Update inputs name indices to ensure proper PHP array binding
            row.querySelector('.block-title-input').name = `blocks[${index}][title]`;
            row.querySelector('.block-type-select').name = `blocks[${index}][type]`;
            row.querySelector('.block-value-select').name = `blocks[${index}][value]`;
        });
    }
});
</script>
@endsection
