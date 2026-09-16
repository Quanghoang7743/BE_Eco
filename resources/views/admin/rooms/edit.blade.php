@extends('admin.layout')

@section('content')
<style>
    .bg-main { background-color: #f8f9fa; }
    .card-modern { border: none; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); background: #fff; }
    .card-dark { border: none; border-radius: 12px; background-color: #0b132b; color: #fff; position: relative; overflow: hidden; }
    .card-title-modern { font-size: 1.1rem; font-weight: 700; color: #1c2b46; display: flex; align-items: center; margin-bottom: 1.5rem; }
    .form-control-modern, .form-select-modern { border: 1px solid #e9ecef; border-radius: 8px; padding: 0.6rem 1rem; background-color: #fbfbfc; transition: all 0.2s; }
    .form-control-modern:focus, .form-select-modern:focus { border-color: #0d6efd; box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15); background-color: #fff; }
    .form-label-modern { font-size: 0.85rem; font-weight: 600; color: #6c757d; margin-bottom: 0.4rem; }
    .btn-modern { 
        border-radius: 8px; 
        font-weight: 600; 
        padding: 0.6rem 1.5rem; 
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    .btn-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .btn-modern:active {
        transform: translateY(0);
    }
    .btn-primary-modern { 
        background-color: #0d6efd; 
        border-color: #0d6efd; 
        color: #fff; 
        box-shadow: 0 4px 6px rgba(13, 110, 253, 0.15);
    }
    .btn-primary-modern:hover {
        background-color: #0b5ed7;
        border-color: #0a58ca;
        color: #fff;
        box-shadow: 0 8px 20px rgba(13, 110, 253, 0.3);
    }
    .btn-outline-danger.btn-modern:hover {
        background-color: #dc3545 !important;
        color: #fff !important;
        box-shadow: 0 8px 20px rgba(220, 53, 69, 0.3);
    }
    .btn-outline-secondary.btn-modern:hover {
        background-color: #f8f9fa !important;
        color: #212529 !important;
        border-color: #dee2e6 !important;
        box-shadow: 0 8px 20px rgba(0,0,0,0.08);
    }
    
    .table-header-modern { font-size: 0.75rem; font-weight: 700; color: #adb5bd; text-transform: uppercase; letter-spacing: 0.5px; padding-bottom: 0.5rem; border-bottom: 1px solid #e9ecef; margin-bottom: 1rem; }
    .prize-row { display: flex; align-items: center; border: 1px solid #e9ecef; border-radius: 8px; padding: 0.5rem; margin-bottom: 1rem; }
    .prize-icon-box { background: #f8f9fa; border-radius: 6px; padding: 0.5rem; color: #6c757d; margin-right: 1rem; }
    
    .banner-reorder-list { display: flex; flex-direction: column; gap: 0.75rem; }
    .banner-reorder-item { display: flex; gap: 0.75rem; align-items: center; padding: 0.5rem; border: 1px solid #e9ecef; border-radius: 8px; cursor: grab; background: #fff; transition: all 0.2s; }
    .banner-reorder-remove { margin-left: auto; }
    .banner-reorder-item.dragging { opacity: 0.55; transform: scale(0.98); }
    .banner-reorder-item.drag-over { border-color: #0d6efd; background: #f0f7ff; }
    .banner-reorder-handle { width: 40px; height: 40px; display: grid; place-items: center; border-radius: 8px; background: #ebeef1; color: #495057; font-size: 1.25rem; }
    .banner-reorder-thumb { width: 110px; height: 70px; border-radius: 6px; overflow: hidden; border: 1px solid #e9ecef; background: #f8f9fa; }
    .banner-reorder-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .banner-reorder-item-label { min-width: 0; }
</style>

<div class="container-fluid bg-main py-4">
    @php
        $isCreateMode = (bool) ($isCreate ?? false);
        $templates = $prizeTemplates->map(function ($template) {
            $imagePath = (string) ($template->image_path ?? '');
            $imageUrl = $imagePath;
            if ($imagePath !== '' && !\Illuminate\Support\Str::startsWith($imagePath, ['http://', 'https://'])) {
                $imageUrl = asset($imagePath);
            }

            return [
                'id' => (int) $template->id,
                'prize_name' => (string) ($template->prize_name ?? ''),
                'monetary_value' => (float) ($template->monetary_value ?? 0),
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
                'description' => (string) ($template->description ?? ''),
                'is_active' => (bool) ($template->is_active ?? false),
            ];
        })->values();

        $roomPrizeImageUrl = trim((string) ($room->prize_image_url ?? ''));
        if ($roomPrizeImageUrl !== '' && !\Illuminate\Support\Str::startsWith($roomPrizeImageUrl, ['http://', 'https://'])) {
            $roomPrizeImageUrl = asset($roomPrizeImageUrl);
        }

        $selectedTemplateId = old('prize_template_id', $room->prize_template_id);
        $selectedTicketTypeCode = old('ticket_type_code', $room->ticket_type_code);
        $roomDateValue = old('room_date', optional($room->room_date)->format('Y-m-d'));
        $roomNumberValue = old('room_number', $room->room_number);
        $requiredVotesValue = old('required_votes', (int) ($room->required_votes ?? 1000));
        $openAtValue = old('open_at', !empty($room->open_at) ? optional($room->open_at)->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i') : null);
        $closeAtValue = old('close_at', !empty($room->close_at) ? optional($room->close_at)->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i') : null);
        $isTemplateChecked = (bool) old('is_template', (bool) ($room->is_template ?? false));
        $prizeTypeValue = old('prize_type', $room->prize_type);
        $prizeValue = old('prize_value', $room->prize_value);
        $prizeDescriptionValue = old('prize_description', $room->prize_description);
        
        $existingBanners = [];
        if (!empty($room->banners) && is_iterable($room->banners)) {
            foreach ($room->banners as $banner) {
                $existingBanners[] = $banner;
            }
        }
        $existingBannersCount = count($existingBanners);
        $initialBannerItems = collect($existingBanners)->map(function ($banner) {
            return [
                'type' => 'existing',
                'id' => $banner->id,
                'order' => $banner->order,
                'image_url' => trim((string) ($banner->image_url ?? '')),
            ];
        })->values()->all();
        $cleanPreviewDescription = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) ($room->prize_description ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    @endphp

    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <div class="text-muted small mb-1 fw-semibold">
                <a href="{{ route('admin.rooms.index') }}" class="text-decoration-none text-muted">Phòng</a> 
                <span class="mx-1">&rsaquo;</span> 
                <span class="text-primary">Thiết lập phòng</span>
            </div>
            <h3 class="mb-0 fw-bold text-dark">{{ $isCreateMode ? 'Thiết lập phòng' : 'Sửa thiết lập phòng' }}</h3>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.rooms.index') }}" class="btn btn-modern btn-outline-secondary bg-white">Hủy bỏ</a>
            <button type="submit" form="room-form" class="btn btn-modern btn-primary-modern">Lưu phòng</button>
            @if (!$isCreateMode)
                <button type="submit" form="delete-room-form" class="btn btn-modern btn-outline-danger bg-white" onclick="return confirm('Xác nhận xóa phòng {{ $room->room_code }}?');">Xóa</button>
            @endif
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success rounded-3 border-0 shadow-sm">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger rounded-3 border-0 shadow-sm">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="room-form" action="{{ $isCreateMode ? route('admin.rooms.store') : route('admin.rooms.update', $room->id) }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf
        @if (!$isCreateMode)
            @method('PATCH')
        @endif

        <div class="row g-4">
            <div class="col-lg-8">
                <!-- CARD 1: THỜI GIAN QUAY -->
                <div class="card card-modern mb-4">
                    <div class="card-body p-4">
                        <div class="card-title-modern text-primary">
                            <i class="bi bi-clock me-2"></i> Thời gian quay
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label-modern">Ngày bắt đầu</label>
                                <input type="datetime-local" name="open_at" class="form-control form-control-modern" value="{{ $openAtValue }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern">Ngày kết thúc</label>
                                <input type="datetime-local" name="close_at" class="form-control form-control-modern" value="{{ $closeAtValue }}">
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label-modern">Ngày diễn ra (Room date)</label>
                                <input type="date" name="room_date" class="form-control form-control-modern" value="{{ $roomDateValue }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-modern">Giờ quay dự kiến</label>
                                <input type="time" name="draw_started_at" class="form-control form-control-modern" value="{{ old('draw_started_at', !empty($room->draw_started_at) ? optional($room->draw_started_at)->timezone('Asia/Ho_Chi_Minh')->format('H:i') : null) }}">
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-12">
                                <label class="form-label-modern">Tên phòng</label>
                                <input type="text" name="room_name" class="form-control form-control-modern" value="{{ old('room_name', $room->room_name) }}" placeholder="Nhập tên phòng nếu có...">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CARD 2: GIẢI THƯỞNG -->
                <div class="card card-modern mb-4">
                    <div class="card-body p-4">
                        <div class="card-title-modern text-warning mb-4">
                            <i class="bi bi-trophy me-2"></i> Giải thưởng
                        </div>
                        
                        <div class="mb-4">
                            <select id="prize_template_id" name="prize_template_id" class="form-select form-select-modern">
                                <option value="">-- Không dùng mẫu, nhập tay --</option>
                                @foreach ($templates as $template)
                                    <option
                                        value="{{ $template['id'] }}"
                                        data-prize-name="{{ $template['prize_name'] }}"
                                        data-prize-value="{{ $template['monetary_value'] }}"
                                        data-prize-image="{{ $template['image_url'] }}"
                                        data-prize-description="{{ e($template['description']) }}"
                                        {{ (string) $selectedTemplateId === (string) $template['id'] ? 'selected' : '' }}
                                    >
                                        {{ $template['prize_name'] }} - {{ number_format((float) $template['monetary_value'], 0, ',', '.') }} đ
                                        {{ $template['is_active'] ? '' : '(Ngừng hoạt động)' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="table-header-modern d-flex">
                            <div style="flex: 2;">Tên giải thưởng</div>
                            <div style="flex: 1;" class="text-center">Giá trị giải</div>
                            <div style="width: 40px;"></div>
                        </div>

                        <div class="prize-row">
                            <div class="d-flex align-items-center" style="flex: 2;">
                                <div class="prize-icon-box"><i class="bi bi-box-seam"></i></div>
                                <input type="text" id="prize_type" name="prize_type" class="form-control border-0 bg-transparent shadow-none p-0" maxlength="255" value="{{ $prizeTypeValue }}" placeholder="VD: iPhone 15 Pro Max 256GB" required>
                            </div>
                            <div style="flex: 1;" class="px-2 border-start">
                                <input type="text" id="prize_value_display" name="prize_value_display" class="form-control border-0 bg-transparent shadow-none p-0 text-center" placeholder="1" value="{{ $prizeValue !== null ? number_format((float)$prizeValue, 0, ',', '.') : '' }}">
                                <input type="hidden" id="prize_value" name="prize_value" value="{{ $prizeValue !== null ? (float)$prizeValue : '' }}">
                            </div>
                            <div style="width: 40px;" class="text-end">
                                <button type="button" class="btn btn-sm text-muted" title="Xóa"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>
                        
                        <div class="row g-3 mt-2">
                            <div class="col-12">
                                <label class="form-label-modern">Mô tả giải thưởng</label>
                                <textarea id="prize_description" name="prize_description" class="form-control form-control-modern" rows="4" placeholder="Nhập mô tả chi tiết cho phòng quay này...">{{ $prizeDescriptionValue }}</textarea>
                            </div>
                            <div class="col-12 mt-3" id="template-image-preview-container" style="{{ !empty($roomPrizeImageUrl) ? '' : 'display: none;' }}">
                                <label class="form-label-modern">Ảnh đang dùng (từ template)</label>
                                <div class="d-flex align-items-center gap-3 p-2 border rounded-3 bg-light">
                                    <img id="template-image-preview" src="{{ $roomPrizeImageUrl }}" alt="Template Image" class="rounded shadow-sm" style="height: 60px; width: 100px; object-fit: cover;">
                                    <div class="small text-muted">Ảnh từ mẫu đang áp dụng. Upload ảnh mới bên dưới nếu muốn thay đổi.</div>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label-modern">Upload ảnh giải thưởng mới (nếu không dùng ảnh template)</label>
                                <input type="file" id="prize_image_file" name="prize_image_file" class="form-control form-control-modern" accept="image/*">
                            </div>

                            <div class="col-12 mt-2" id="prize-image-new-preview-container" style="display: none;">
                                <div class="d-flex align-items-center gap-3 p-2 border border-primary border-opacity-25 rounded-3 bg-primary bg-opacity-10">
                                    <img id="prize-image-new-preview" src="" alt="New Preview" class="rounded shadow-sm" style="height: 60px; width: 100px; object-fit: cover;">
                                    <div class="small text-primary fw-semibold">Ảnh mới đã chọn. Sẽ được lưu khi bạn nhấn "Lưu phòng".</div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
                
            </div>

            <!-- Cột phải (Settings & Summary) -->
            <div class="col-lg-4">
                
                <!-- CARD 4: TICKET THAM GIA -->
                <div class="card card-modern mb-4">
                    <div class="card-body p-4">
                        <div class="card-title-modern text-success mb-4">
                            <i class="bi bi-ticket-perforated me-2"></i> Ticket tham gia
                        </div>

                        <div class="mb-3">
                            <label class="form-label-modern">Chọn loại Ticket áp dụng</label>
                            <select name="ticket_type_code" class="form-select form-select-modern" required>
                                @php
                                    $allTypeSelected = in_array(strtoupper((string) $selectedTicketTypeCode), ['ALL', 'ALL_TYPES', 'ALL_TICKETS', 'TAT_CA', '*'], true);
                                @endphp
                                <option value="ALL_TYPES" {{ $allTypeSelected ? 'selected' : '' }}>
                                    Tất cả loại Ticket
                                </option>
                                @foreach (($ticketTypes ?? []) as $ticketType)
                                    @php
                                        $ticketCode = strtoupper((string) ($ticketType['code'] ?? ''));
                                    @endphp
                                    <option value="{{ $ticketCode }}" {{ (string) $selectedTicketTypeCode === $ticketCode ? 'selected' : '' }}>
                                        {{ $ticketType['name'] ?? $ticketCode }} - {{ number_format((float) ($ticketType['price'] ?? 0), 0, ',', '.') }} đ
                                        {{ !empty($ticketType['is_default']) ? '(Mặc định)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label-modern">Số Ticket yêu cầu</label>
                            <input type="number" id="required_votes_input" name="required_votes" class="form-control form-control-modern" min="1" max="1000000" value="{{ $requiredVotesValue }}" required>
                        </div>
                        
                        <div class="mt-3 form-check">
                            <input class="form-check-input" type="checkbox" name="is_template" value="1" id="is_template" {{ $isTemplateChecked ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold text-muted small" for="is_template">Dùng làm phòng mẫu</label>
                        </div>

                    </div>
                </div>

                <!-- CARD 5: TÓM TẮT -->
                <div class="card card-dark mb-4">
                    <i class="bi bi-award position-absolute text-white opacity-10" style="font-size: 10rem; right: -2rem; bottom: -2rem;"></i>
                    <div class="card-body p-4 position-relative z-1">
                        <div class="small fw-bold text-uppercase text-secondary mb-4" style="letter-spacing: 1px;">Tóm tắt</div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-white-50 small fw-semibold">Tổng Ticket yêu cầu</span>
                            <span class="fw-bold fs-5" id="summary-tickets">{{ number_format((float) $requiredVotesValue, 0, ',', '.') }}</span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <span class="text-white-50 small fw-semibold">Giá trị giải thưởng</span>
                            <span class="fw-bold fs-5" id="summary-value">{{ number_format((float) $prizeValue, 0, ',', '.') }} đ</span>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center pt-3 border-top border-secondary border-opacity-25">
                            <span class="text-white-50 small fw-semibold">Trạng thái</span>
                            @php
                                $rawStatus = strtoupper((string) ($room->status ?? 'MỚI'));
                                if ($rawStatus === 'WAITING' || $rawStatus === 'MỚI') {
                                    $statusText = 'Đang chờ';
                                    $statusClass = 'bg-warning text-white border-warning';
                                } elseif ($rawStatus === 'ACTIVE') {
                                    $statusText = 'Đang diễn ra';
                                    $statusClass = 'bg-success text-white border-success';
                                } elseif ($rawStatus === 'FINISHED' || $rawStatus === 'CLOSED') {
                                    $statusText = 'Đã kết thúc';
                                    $statusClass = 'bg-secondary text-white border-secondary';
                                } else {
                                    $statusText = $rawStatus;
                                    $statusClass = 'bg-info text-white border-info';
                                }
                            @endphp
                            <span class="badge {{ $statusClass }} bg-opacity-25 border border-opacity-50 px-3 py-2 rounded-pill">
                                {{ $statusText }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- CARD 3: BANNER ẢNH & THÔNG TIN BỔ SUNG (Moved to Right Column) -->
                <div class="card card-modern mb-4 mt-4">
                    <div class="card-body p-4">
                        <div class="card-title-modern text-info mb-4">
                            <i class="bi bi-images me-2"></i> Banner
                        </div>
                        
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label-modern">Upload banner mới</label>
                                <input type="file" id="banner_files_input" name="banner_files[]" class="form-control form-control-modern" accept="image/*" multiple>
                            </div>
                            
                            <div class="col-12">
                                <div class="form-label-modern mb-2">Sắp xếp banner hiện tại</div>
                                <div id="banner-reorder-list" class="banner-reorder-list"></div>
                                @if ($existingBannersCount === 0)
                                    <div class="text-muted small mt-1">Chưa có banner nào.</div>
                                @endif
                                <div id="banner-reorder-error" class="alert alert-danger d-none mt-3"></div>
                                @if (!$isCreateMode)
                                <div class="d-flex justify-content-end gap-2 mt-3">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="banner-reorder-reset">Đặt lại</button>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </form>
    
    @if (!$isCreateMode)
        <form id="delete-room-form" method="POST" action="{{ route('admin.rooms.destroy', $room->id) }}" class="d-none">
            @csrf
            @method('DELETE')
        </form>
    @endif
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
<script>
    (function () {
        const roomForm = document.getElementById('room-form');
        const templateSelect = document.getElementById('prize_template_id');
        const prizeTypeInput = document.getElementById('prize_type');
        const prizeValueDisplayInput = document.getElementById('prize_value_display');
        const prizeValueHidden = document.getElementById('prize_value');
        const prizeDescriptionInput = document.getElementById('prize_description');
        const prizeImageInput = document.getElementById('prize_image_file');
        const prizeImageNewPreview = document.getElementById('prize-image-new-preview');
        const prizeImageNewContainer = document.getElementById('prize-image-new-preview-container');
        const previewImage = document.getElementById('room-prize-preview-image');
        const templateImagePreview = document.getElementById('template-image-preview');
        const templateImageContainer = document.getElementById('template-image-preview-container');
        const noPreviewPlaceholder = document.getElementById('no-preview-placeholder');
        const reqVotesInput = document.getElementById('required_votes_input');
        const summaryTickets = document.getElementById('summary-tickets');
        const summaryValue = document.getElementById('summary-value');
        let editorInstance = null;

        // Sync Summary
        if(reqVotesInput && summaryTickets) {
            reqVotesInput.addEventListener('input', function() {
                summaryTickets.textContent = Number(this.value || 0).toLocaleString('vi-VN');
            });
        }
        function parseNumeric(str) {
            // Remove any thousands separators (dots, commas, spaces) and keep digits only
            const cleaned = String(str || '').replace(/[^0-9\-]/g, '');
            if (cleaned === '' || cleaned === '-' ) return 0;
            const n = parseInt(cleaned, 10);
            return isNaN(n) ? 0 : n;
        }

        function updatePrizeFromDisplay() {
            if (!prizeValueDisplayInput || !prizeValueHidden || !summaryValue) return;
            const raw = prizeValueDisplayInput.value || '';
            const num = parseNumeric(raw);
            prizeValueHidden.value = num || '';
            summaryValue.textContent = (num || 0).toLocaleString('vi-VN') + ' đ';
            // format display as Vietnamese thousands (no decimals)
            prizeValueDisplayInput.value = (raw === '' ? '' : (num || 0).toLocaleString('vi-VN'));
        }

        if(prizeValueDisplayInput && summaryValue) {
            prizeValueDisplayInput.addEventListener('input', function() {
                // don't aggressively reformat while typing; update hidden and summary
                const raw = prizeValueDisplayInput.value || '';
                const num = parseNumeric(raw);
                prizeValueHidden.value = num || '';
                summaryValue.textContent = (num || 0).toLocaleString('vi-VN') + ' đ';
            });
            prizeValueDisplayInput.addEventListener('blur', updatePrizeFromDisplay);
        }

        function normalizeDescriptionText(value) {
            return String(value || '')
                .replace(/<[^>]*>/g, ' ')
                .replace(/&nbsp;/gi, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function getSelectedTemplateData() {
            const selectedOption = templateSelect ? templateSelect.selectedOptions[0] : null;
            if (!selectedOption || !selectedOption.value) {
                return null;
            }
            return {
                prize_name: selectedOption.getAttribute('data-prize-name') || '',
                monetary_value: selectedOption.getAttribute('data-prize-value') || '',
                image_url: selectedOption.getAttribute('data-prize-image') || '',
                description: selectedOption.getAttribute('data-prize-description') || '',
            };
        }

        function applyTemplateToFields() {
            const template = getSelectedTemplateData();
            if (!template) {
                if (templateImageContainer) templateImageContainer.style.display = 'none';
                if (templateImagePreview) templateImagePreview.src = '';
                return;
            }

            if (prizeTypeInput && template.prize_name) {
                prizeTypeInput.value = template.prize_name;
            }
            if (prizeValueHidden && prizeValueDisplayInput && template.monetary_value !== '') {
                prizeValueHidden.value = template.monetary_value;
                prizeValueDisplayInput.value = Number(template.monetary_value || 0).toLocaleString('vi-VN');
                if(summaryValue) summaryValue.textContent = Number(template.monetary_value || 0).toLocaleString('vi-VN') + ' đ';
            }
            if (template.image_url) {
                if (previewImage) {
                    previewImage.src = template.image_url;
                    previewImage.style.display = 'block';
                    if(noPreviewPlaceholder) noPreviewPlaceholder.style.display = 'none';
                }
                if (templateImagePreview) {
                    templateImagePreview.src = template.image_url;
                    if (templateImageContainer) templateImageContainer.style.display = 'block';
                }
            } else if (templateImageContainer) {
                templateImageContainer.style.display = 'none';
            }
            if (editorInstance && template.description) {
                editorInstance.setData(template.description);
            } else if (prizeDescriptionInput && template.description) {
                prizeDescriptionInput.value = template.description;
            }
        }

        if (templateSelect) {
            templateSelect.addEventListener('change', applyTemplateToFields);
        }

        ClassicEditor.create(prizeDescriptionInput, {
            ckfinder: {
                uploadUrl: '{{ route('upload') . '?_token=' . csrf_token() }}'
            }
        }).then((editor) => {
            editorInstance = editor;
            editor.editing.view.change((writer) => {
                writer.setStyle('min-height', '250px', editor.editing.view.document.getRoot());
                writer.setStyle('border-radius', '8px', editor.editing.view.document.getRoot());
            });
        }).catch((error) => console.error(error));

        if (roomForm) {
            roomForm.addEventListener('submit', function (event) {
                if (editorInstance && prizeDescriptionInput) {
                    prizeDescriptionInput.value = editorInstance.getData();
                }

                // ensure hidden prize_value is synced from display before submit
                if (prizeValueDisplayInput && prizeValueHidden) {
                    const num = parseNumeric(prizeValueDisplayInput.value || '');
                    prizeValueHidden.value = num ? num : '';
                }

                const descriptionText = normalizeDescriptionText(prizeDescriptionInput ? prizeDescriptionInput.value : '');
                if (descriptionText === '') {
                    event.preventDefault();
                    alert('Vui lòng nhập mô tả giải thưởng trước khi lưu.');
                    if (editorInstance) {
                        editorInstance.editing.view.focus();
                        document.querySelector('#prize_description')?.scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                    return;
                }

                // Before validation, inject banner ordering + deleted ids into the form so server can process together
                if (typeof bannerReorderItems !== 'undefined' && Array.isArray(bannerReorderItems)) {
                    // remove existing helper inputs
                    roomForm.querySelectorAll('input[name="ordered_items[]"], input[name="deleted_banner_ids[]"]').forEach(function(el){ el.remove(); });

                    bannerReorderItems.forEach(function(item) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'ordered_items[]';
                        if (item.type === 'existing') {
                            inp.value = 'existing:' + item.id;
                        } else {
                            inp.value = 'new:' + (typeof item.newIndex !== 'undefined' ? item.newIndex : item.newIndex);
                        }
                        roomForm.appendChild(inp);
                    });

                    if (typeof deletedBannerIds !== 'undefined' && deletedBannerIds instanceof Set) {
                        deletedBannerIds.forEach(function(bid){
                            var d = document.createElement('input');
                            d.type = 'hidden';
                            d.name = 'deleted_banner_ids[]';
                            d.value = bid;
                            roomForm.appendChild(d);
                        });
                    }
                }

                if (!roomForm.checkValidity()) {
                    event.preventDefault();
                    const invalidField = roomForm.querySelector(':invalid');
                    if (invalidField) {
                        if (typeof invalidField.reportValidity === 'function') {
                            invalidField.reportValidity();
                        }
                        invalidField.focus({ preventScroll: true });
                        invalidField.scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                    return;
                }

                // If there are new banner files added via the reorder UI, ensure they are appended to the submission.
                // Native form submit cannot programmatically add File objects, so use fetch with FormData when needed.
                const hasNewBannerFiles = Array.isArray(bannerReorderItems) && bannerReorderItems.some(i => i.type === 'new' && i.file instanceof File);
                if (hasNewBannerFiles) {
                    event.preventDefault();
                    // build FormData from the form (includes hidden ordered_items[] and deleted_banner_ids[] we appended above)
                    const fd = new FormData(roomForm);

                    // Append new banner files in the same order as bannerReorderItems where type === 'new'
                    bannerReorderItems.filter(i => i.type === 'new').forEach((item) => {
                        if (item.file instanceof File) {
                            // backend expects `banner_image_files` for room update/store
                            fd.append('banner_image_files[]', item.file, item.name || item.file.name);
                        }
                    });

                    // Send via fetch to the form action
                    const submitUrl = roomForm.action;
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

                    fetch(submitUrl, {
                        method: roomForm.method && roomForm.method.toUpperCase() === 'GET' ? 'GET' : 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrf
                        },
                        body: fd,
                        credentials: 'same-origin'
                    }).then(async (res) => {
                        // If server redirected, follow
                        if (res.redirected) {
                            window.location.href = res.url;
                            return;
                        }
                        // Try to parse JSON response for success message
                        const ct = res.headers.get('content-type') || '';
                        if (ct.indexOf('application/json') !== -1) {
                            const json = await res.json().catch(() => null);
                            if (json && json.redirect) {
                                window.location.href = json.redirect;
                                return;
                            }
                        }
                        // Fallback: reload page to show updated state
                        window.location.reload();
                    }).catch((err) => {
                        alert('Có lỗi khi lưu phòng: ' + (err.message || err));
                    });
                    return;
                }
            });
        }

        // Prize Image File Preview
        if (prizeImageInput) {
            prizeImageInput.addEventListener('change', function () {
                const file = this.files[0];
                if (file && prizeImageNewPreview && prizeImageNewContainer) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        prizeImageNewPreview.src = e.target.result;
                        prizeImageNewContainer.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else if (prizeImageNewContainer) {
                    prizeImageNewContainer.style.display = 'none';
                }
            });
        }

        // Banner Reorder Script
        const bannerReorderList = document.getElementById('banner-reorder-list');
        const bannerReorderInput = document.getElementById('banner_files_input');
        // const bannerReorderSave = document.getElementById('banner-reorder-save'); (removed - saving moved to main form)
        const bannerReorderReset = document.getElementById('banner-reorder-reset');
        const bannerReorderError = document.getElementById('banner-reorder-error');
        @if(!$isCreateMode)
        const bannerReorderUrl = '{{ route('admin.rooms.banners.reorder', $room->id) }}';
        @else
        const bannerReorderUrl = '';
        @endif
        const bannerCsrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
        const initialBannerItems = @json($initialBannerItems);
        let bannerReorderItems = [];
        let deletedBannerIds = new Set();
        let bannerDragIndex = null;

        function buildBannerReorderItems() {
            bannerReorderItems = initialBannerItems.slice();
        }

        function renderBannerReorderItems() {
            if (!bannerReorderList) return;
            bannerReorderList.innerHTML = '';
            bannerReorderItems.forEach((item, index) => {
                const itemEl = document.createElement('div');
                itemEl.className = 'banner-reorder-item';
                itemEl.draggable = true;
                itemEl.dataset.index = index;

                const handleEl = document.createElement('div');
                handleEl.className = 'banner-reorder-handle';
                handleEl.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16"><path d="M7 2a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 5a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0M7 8a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0m-3 3a1 1 0 1 1-2 0 1 1 0 0 1 2 0m3 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0"/></svg>`;

                const thumbEl = document.createElement('div');
                thumbEl.className = 'banner-reorder-thumb';
                const imgEl = document.createElement('img');
                imgEl.src = item.type === 'existing' ? item.image_url : item.previewUrl;
                imgEl.alt = item.type === 'existing' ? 'Banner ' + item.order : item.name;
                thumbEl.appendChild(imgEl);



                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn btn-sm btn-outline-danger banner-reorder-remove rounded-3';
                removeButton.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="margin-right:4px;"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z"/></svg> Xóa`;
                removeButton.addEventListener('click', function () {
                    if (item.type === 'existing') {
                        deletedBannerIds.add(item.id);
                    }
                    bannerReorderItems.splice(index, 1);
                    renderBannerReorderItems();
                });

                itemEl.appendChild(handleEl);
                itemEl.appendChild(thumbEl);
                itemEl.appendChild(removeButton);

                itemEl.addEventListener('dragstart', function () {
                    bannerDragIndex = Number(this.dataset.index);
                    this.classList.add('dragging');
                });
                itemEl.addEventListener('dragend', function () {
                    bannerDragIndex = null;
                    this.classList.remove('dragging');
                });
                itemEl.addEventListener('dragover', function (event) {
                    event.preventDefault();
                    this.classList.add('drag-over');
                });
                itemEl.addEventListener('dragleave', function () {
                    this.classList.remove('drag-over');
                });
                itemEl.addEventListener('drop', function (event) {
                    event.preventDefault();
                    this.classList.remove('drag-over');
                    const targetIndex = Number(this.dataset.index);
                    if (bannerDragIndex === null || targetIndex === bannerDragIndex) return;
                    const movedItem = bannerReorderItems.splice(bannerDragIndex, 1)[0];
                    bannerReorderItems.splice(targetIndex, 0, movedItem);
                    renderBannerReorderItems();
                });

                bannerReorderList.appendChild(itemEl);
            });
        }

        function resetBannerReorderList() {
            if (bannerReorderInput) bannerReorderInput.value = '';
            bannerReorderItems = initialBannerItems.slice();
            deletedBannerIds.clear();
            renderBannerReorderItems();
            bannerReorderError?.classList.add('d-none');
            window.location.reload();
        }

        function handleNewBannerFiles(fileList) {
            Array.from(fileList).forEach((file) => {
                const newIndex = bannerReorderItems.filter((item) => item.type === 'new').length;
                bannerReorderItems.push({
                    type: 'new',
                    newIndex,
                    name: file.name,
                    file,
                    previewUrl: URL.createObjectURL(file),
                });
            });
            renderBannerReorderItems();
        }

        async function submitBannerReorder(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
                event.stopPropagation();
            }
            if (!bannerReorderUrl) return;

            const formData = new FormData();
            formData.append('_token', bannerCsrfToken);
            formData.append('_method', 'PATCH');

            bannerReorderItems.forEach((item) => {
                if (item.type === 'existing') {
                    formData.append('ordered_items[]', `existing:${item.id}`);
                } else {
                    formData.append('ordered_items[]', `new:${item.newIndex}`);
                }
            });

            bannerReorderItems.filter((item) => item.type === 'new').forEach((item) => {
                formData.append('new_banner_files[]', item.file, item.name);
            });

            deletedBannerIds.forEach((bannerId) => {
                formData.append('deleted_banner_ids[]', bannerId);
            });

            try {
                const response = await fetch(bannerReorderUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                });

                if (!response.ok) {
                    const json = await response.json().catch(() => null);
                    throw new Error(json?.message || 'Lỗi khi gửi yêu cầu sắp xếp banner.');
                }

                const json = await response.json();
                if (!json.success) {
                    throw new Error(json.message || 'Lỗi khi lưu vị trí banner.');
                }

                if (bannerReorderError) {
                    bannerReorderError.textContent = json.message || 'Lưu banner thành công.';
                    bannerReorderError.classList.remove('d-none');
                    bannerReorderError.classList.remove('alert-danger');
                    bannerReorderError.classList.add('alert-success');
                }
            } catch (error) {
                if (bannerReorderError) {
                    bannerReorderError.textContent = String(error.message || 'Có lỗi xảy ra.');
                    bannerReorderError.classList.remove('d-none');
                    bannerReorderError.classList.remove('alert-success');
                    bannerReorderError.classList.add('alert-danger');
                }
            }
        }

        if (bannerReorderInput) {
            bannerReorderInput.addEventListener('change', function () {
                if (this.files && this.files.length) {
                    handleNewBannerFiles(this.files);
                }
            });
        }

        // Banner save is handled when the main `room-form` is submitted.

        if (bannerReorderReset) {
            bannerReorderReset.addEventListener('click', resetBannerReorderList);
        }

        buildBannerReorderItems();
        renderBannerReorderItems();

    })();
</script>
@endsection
