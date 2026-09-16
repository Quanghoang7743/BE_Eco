@extends('admin.layout')

@section('styles')
    <style>
        .rte-page {
            color: #12213f;
        }

        .rte-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .rte-muted {
            color: #6b7890;
        }

        .rte-panel {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
            margin-bottom: 18px;
        }

        .rte-panel__body {
            padding: 22px;
        }

        .rte-table-wrap {
            max-height: 620px;
            overflow: auto;
            border: 1px solid #dfe7f1;
            border-radius: 8px;
        }

        .rte-table-wrap::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .rte-table-wrap::-webkit-scrollbar-thumb {
            background: #c7d2e2;
            border-radius: 999px;
        }

        .rte-table-wrap::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .rte-table {
            margin-bottom: 0;
            min-width: 1420px;
        }

        .rte-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            color: #12213f;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
            padding: 12px;
        }

        .rte-table td {
            padding: 10px 12px;
            vertical-align: top;
        }

        .rte-table .form-control,
        .rte-table .form-select {
            min-width: 120px;
        }

        .rte-room-name {
            min-width: 210px !important;
        }

        .rte-prize-select {
            min-width: 240px !important;
        }

        .rte-description {
            min-width: 260px !important;
        }

        .rte-action-bar {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
    </style>
@endsection

@section('content')
    @php
        $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
        $ticketTypes = collect($ticketTypes ?? []);
        $prizeTemplates = collect($prizeTemplates ?? []);
        $templates = collect($roomTemplate->roomTemplates ?? [])->sortBy('room_number')->values();
        $isSelected = (string) ($selectedTemplateSetId ?? '') === (string) $roomTemplate->id;
        $ticketOptions = $ticketTypes
            ->map(function ($ticketType) {
                return [
                    'code' => strtoupper(trim((string) ($ticketType['code'] ?? ''))),
                    'name' => trim((string) ($ticketType['name'] ?? '')),
                ];
            })
            ->filter(fn ($ticketType) => $ticketType['code'] !== '' && $ticketType['name'] !== '')
            ->values();
    @endphp

    <div class="container-fluid rte-page">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <div class="rte-muted small mb-2">
                    Phòng quay / <a href="{{ route('admin.room-templates.index') }}">Danh sách phòng mẫu</a> / <strong class="text-dark">Sửa</strong>
                </div>
                <h2 class="rte-title">Sửa bộ phòng mẫu</h2>
                <div class="rte-muted">Cập nhật bộ mẫu và từng phòng mẫu dùng cho cron tạo phòng tự động.</div>
            </div>
            <a href="{{ route('admin.room-templates.index') }}" class="btn btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Quay lại
            </a>
        </div>

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

        <form method="POST" action="{{ route('admin.room-templates.update', $roomTemplate->id) }}" data-template-edit-form>
            @csrf
            @method('PATCH')

            <div class="rte-panel">
                <div class="rte-panel__body">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-4">
                            <label class="form-label" for="name">Tên bộ mẫu</label>
                            <input id="name" type="text" name="name" class="form-control"
                                value="{{ old('name', $roomTemplate->name) }}" maxlength="255" required>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="ticket_type_code">Loại vé mặc định</label>
                            <select id="ticket_type_code" name="ticket_type_code" class="form-select" data-set-ticket required>
                                <option value="ALL_TYPES" {{ old('ticket_type_code', $roomTemplate->ticket_type_code ?: 'ALL_TYPES') === 'ALL_TYPES' ? 'selected' : '' }}>Tất cả loại vé</option>
                                @foreach ($ticketOptions as $ticketType)
                                    <option value="{{ $ticketType['code'] }}" {{ old('ticket_type_code', $roomTemplate->ticket_type_code) === $ticketType['code'] ? 'selected' : '' }}>
                                        {{ $ticketType['name'] }} ({{ $ticketType['code'] }})
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label" for="required_votes">Ticket/phòng mặc định</label>
                            <input id="required_votes" type="number" name="required_votes" class="form-control"
                                value="{{ old('required_votes', $roomTemplate->required_votes) }}" min="1" max="1000000" data-set-required-votes required>
                        </div>
                        <div class="col-lg-3">
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-outline-primary" data-apply-ticket>Áp dụng loại vé</button>
                                <button type="button" class="btn btn-outline-primary" data-apply-votes>Áp dụng ticket</button>
                            </div>
                        </div>
                    </div>

                    <div class="rte-muted small mt-3">
                        @if ($isSelected)
                            Bộ mẫu này đang được chọn làm mặc định. Các thay đổi sẽ áp dụng cho lần cron tạo phòng tiếp theo.
                        @else
                            Bộ mẫu này chưa được chọn làm mặc định.
                        @endif
                    </div>
                </div>
            </div>

            <div class="rte-panel">
                <div class="rte-panel__body">
                    <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                        <div>
                            <h5 class="mb-1 fw-bold">Danh sách phòng trong bộ mẫu</h5>
                            <div class="rte-muted">Có {{ number_format($templates->count()) }} phòng mẫu. Bảng có thanh cuộn nhỏ để sửa đầy đủ các phòng.</div>
                        </div>
                    </div>

                    <div class="rte-table-wrap">
                        <table class="table table-sm rte-table align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 80px;">Phòng</th>
                                    <th>Tên mẫu</th>
                                    <th style="width: 130px;">Trạng thái</th>
                                    <th style="width: 170px;">Loại vé</th>
                                    <th style="width: 150px;">Ticket/phòng</th>
                                    <th>Mẫu giải thưởng</th>
                                    <th style="width: 170px;">Loại giải</th>
                                    <th style="width: 150px;">Giá trị</th>
                                    <th>Mô tả</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($templates as $index => $template)
                                    @php
                                        $fieldPrefix = 'templates.' . $index . '.';
                                        $rowTicketType = old($fieldPrefix . 'ticket_type_code', $template->ticket_type_code ?: ($roomTemplate->ticket_type_code ?: 'ALL_TYPES'));
                                        $rowStatus = old($fieldPrefix . 'status', $template->status ?: 'WAITING');
                                        $rowPrizeTemplateId = old($fieldPrefix . 'prize_template_id', $template->prize_template_id);
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>#{{ (int) $template->room_number }}</strong>
                                            <input type="hidden" name="templates[{{ $index }}][id]" value="{{ $template->id }}">
                                        </td>
                                        <td>
                                            <input type="text" name="templates[{{ $index }}][room_name]" class="form-control form-control-sm rte-room-name"
                                                value="{{ old($fieldPrefix . 'room_name', $template->room_name ?: ('Phòng mẫu ' . (int) $template->room_number)) }}"
                                                maxlength="255">
                                        </td>
                                        <td>
                                            <select name="templates[{{ $index }}][status]" class="form-select form-select-sm">
                                                @foreach (['WAITING', 'READY', 'DRAFT'] as $status)
                                                    <option value="{{ $status }}" {{ $rowStatus === $status ? 'selected' : '' }}>{{ $status }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <select name="templates[{{ $index }}][ticket_type_code]" class="form-select form-select-sm" data-row-ticket>
                                                <option value="ALL_TYPES" {{ $rowTicketType === 'ALL_TYPES' ? 'selected' : '' }}>ALL_TYPES</option>
                                                @foreach ($ticketOptions as $ticketType)
                                                    <option value="{{ $ticketType['code'] }}" {{ $rowTicketType === $ticketType['code'] ? 'selected' : '' }}>
                                                        {{ $ticketType['code'] }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="templates[{{ $index }}][required_votes]" class="form-control form-control-sm"
                                                value="{{ old($fieldPrefix . 'required_votes', $template->required_votes ?: $roomTemplate->required_votes) }}"
                                                min="1" max="1000000" data-row-required-votes required>
                                        </td>
                                        <td>
                                            <select name="templates[{{ $index }}][prize_template_id]" class="form-select form-select-sm rte-prize-select">
                                                <option value="">Nhập tay / giữ fallback</option>
                                                @foreach ($prizeTemplates as $prizeTemplate)
                                                    <option value="{{ $prizeTemplate->id }}" {{ (string) $rowPrizeTemplateId === (string) $prizeTemplate->id ? 'selected' : '' }}>
                                                        {{ $prizeTemplate->prize_name }}{{ $prizeTemplate->is_active ? '' : ' (Inactive)' }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="templates[{{ $index }}][prize_type]" class="form-control form-control-sm"
                                                value="{{ old($fieldPrefix . 'prize_type', $template->prize_type) }}"
                                                maxlength="100" required>
                                        </td>
                                        <td>
                                            <input type="number" name="templates[{{ $index }}][prize_value]" class="form-control form-control-sm"
                                                value="{{ old($fieldPrefix . 'prize_value', $template->prize_value !== null ? number_format((float) $template->prize_value, 2, '.', '') : '') }}"
                                                min="0" step="0.01">
                                        </td>
                                        <td>
                                            <input type="text" name="templates[{{ $index }}][prize_description]" class="form-control form-control-sm rte-description"
                                                value="{{ old($fieldPrefix . 'prize_description', $template->prize_description) }}"
                                                maxlength="2000">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="rte-panel">
                <div class="rte-panel__body rte-action-bar">
                    <a href="{{ route('admin.room-templates.index') }}" class="btn btn-outline-secondary">Quay lại</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Lưu thay đổi
                    </button>
                </div>
            </div>
        </form>
    </div>
@endsection

@section('scripts')
    <script>
        (function() {
            var setTicket = document.querySelector('[data-set-ticket]');
            var setRequiredVotes = document.querySelector('[data-set-required-votes]');
            var applyTicket = document.querySelector('[data-apply-ticket]');
            var applyVotes = document.querySelector('[data-apply-votes]');

            if (applyTicket && setTicket) {
                applyTicket.addEventListener('click', function() {
                    document.querySelectorAll('[data-row-ticket]').forEach(function(select) {
                        select.value = setTicket.value;
                    });
                });
            }

            if (applyVotes && setRequiredVotes) {
                applyVotes.addEventListener('click', function() {
                    document.querySelectorAll('[data-row-required-votes]').forEach(function(input) {
                        input.value = setRequiredVotes.value;
                    });
                });
            }

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        })();
    </script>
@endsection
