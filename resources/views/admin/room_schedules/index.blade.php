@extends('admin.layout')

@section('styles')
    <style>
        .room-config-wizard {
            color: #111827;
        }

        .room-page-title {
            font-size: 1.65rem;
            font-weight: 700;
            margin: 0;
        }

        .room-stepper {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin: 26px 0 24px;
        }

        .room-step {
            display: grid;
            grid-template-columns: 46px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            min-width: 0;
            position: relative;
        }

        .room-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 23px;
            left: calc(100% - 54px);
            width: 92px;
            height: 1px;
            background: #dbe3ef;
        }

        .room-step__number {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #526173;
            background: #e9eef5;
            flex: 0 0 auto;
        }

        .room-step.is-active .room-step__number {
            color: #ffffff;
            background: #0d6efd;
            box-shadow: 0 10px 22px rgba(13, 110, 253, 0.26);
        }

        .room-step.is-completed .room-step__number {
            color: #ffffff;
            background: #22c55e;
        }

        .room-step__title {
            font-weight: 700;
            line-height: 1.25;
            color: #243247;
        }

        .room-step.is-active .room-step__title,
        .room-step.is-completed .room-step__title {
            color: #0d6efd;
        }

        .room-step__desc {
            color: #64748b;
            font-size: 0.88rem;
            margin-top: 3px;
        }

        .room-panel {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 14px 30px rgba(15, 23, 42, 0.07);
            margin-bottom: 22px;
        }

        .room-panel__body {
            padding: 26px;
        }

        .room-panel__footer {
            border-top: 1px solid #e5edf6;
            padding: 18px 26px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .room-section-title {
            font-size: 1.08rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .room-section-subtitle {
            color: #64748b;
            margin-bottom: 22px;
        }

        .room-form-label {
            font-weight: 700;
            color: #26364b;
            margin-bottom: 8px;
        }

        .room-stat-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-top: 26px;
            padding-top: 26px;
            border-top: 1px solid #e5edf6;
        }

        .room-stat {
            display: grid;
            grid-template-columns: 48px minmax(0, 1fr);
            gap: 14px;
            align-items: center;
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            padding: 16px;
            min-height: 86px;
        }

        .room-stat__icon,
        .room-summary-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e8f1ff;
            color: #0d6efd;
        }

        .room-stat__icon.is-purple {
            background: #f0e9ff;
            color: #7c3aed;
        }

        .room-stat__icon.is-green {
            background: #e8f8ee;
            color: #16a34a;
        }

        .room-muted {
            color: #64748b;
        }

        .room-tabbar {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            border-bottom: 1px solid #e5edf6;
            margin-bottom: 22px;
        }

        .room-tab {
            border: 0;
            background: transparent;
            color: #526173;
            font-weight: 700;
            padding: 12px 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid transparent;
        }

        .room-tab.is-active {
            color: #0d6efd;
            border-bottom-color: #0d6efd;
        }

        .room-template-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 18px;
        }

        .room-template-layout > div:first-child {
            display: none;
        }

        .room-template-picker {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            padding: 16px;
            margin-bottom: 18px;
        }

        .room-template-picker .form-select {
            min-height: 48px;
            font-weight: 700;
        }

        [data-room-tab-panel="rooms"] {
            display: flex;
            flex-direction: column;
        }

        [data-room-tab-panel="rooms"] .room-template-picker {
            order: 1;
        }

        [data-room-tab-panel="rooms"] .room-template-layout {
            order: 2;
        }

        [data-room-tab-panel="rooms"] .room-create-box {
            order: 3;
            margin-top: 18px;
        }

        .room-template-list {
            display: grid;
            gap: 12px;
            align-content: start;
        }

        .room-template-card {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            padding: 16px;
            text-align: left;
            width: 100%;
            min-height: 118px;
            cursor: pointer;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .room-template-card:hover,
        .room-template-card.is-previewed {
            border-color: #0d6efd;
            box-shadow: 0 10px 24px rgba(13, 110, 253, 0.12);
        }

        .room-template-card.is-selected {
            background: #f5f9ff;
            border-color: #0d6efd;
        }

        .room-template-title {
            font-weight: 700;
            color: #172033;
            margin-bottom: 9px;
            word-break: break-word;
        }

        .room-mini-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            color: #526173;
            font-size: 0.9rem;
        }

        .room-detail-box,
        .room-create-box,
        .room-summary-box {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
        }

        .room-detail-box__header,
        .room-create-box__header {
            padding: 18px 20px;
            border-bottom: 1px solid #e5edf6;
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .room-detail-box__body,
        .room-create-box__body,
        .room-summary-box {
            padding: 20px;
        }

        .room-badge-soft {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 5px 10px;
            background: #eef5ff;
            color: #0d6efd;
            font-weight: 700;
            font-size: 0.78rem;
        }

        .room-badge-soft.is-green {
            background: #e9f9ef;
            color: #16a34a;
        }

        .room-badge-soft.is-warn {
            background: #fff7e6;
            color: #b45309;
        }

        .room-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .room-summary-item {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            padding: 15px;
            min-height: 82px;
        }

        .room-summary-label {
            color: #64748b;
            font-size: 0.88rem;
            margin-bottom: 6px;
        }

        .room-summary-value {
            font-weight: 700;
            color: #172033;
            word-break: break-word;
        }

        .room-preview-table {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            overflow: hidden;
        }

        .room-preview-table--scroll {
            max-height: 360px;
            overflow: auto;
        }

        .room-preview-table--scroll thead th {
            position: sticky;
            top: 0;
            z-index: 2;
        }

        .room-preview-table--scroll::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .room-preview-table--scroll::-webkit-scrollbar-thumb {
            background: #c7d2e2;
            border-radius: 999px;
        }

        .room-preview-table--scroll::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .room-preview-table .table {
            margin-bottom: 0;
        }

        .room-empty {
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 34px;
            text-align: center;
            color: #64748b;
            background: #f8fafc;
        }

        .room-action-bar {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            padding: 18px 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.06);
            margin-bottom: 10px;
        }

        .room-unsaved-notice {
            display: none;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            border: 1px solid #bfdbfe;
            border-left: 4px solid #0d6efd;
            border-radius: 8px;
            background: #eff6ff;
            color: #172033;
            padding: 14px 16px;
            margin: 18px 0;
            box-shadow: 0 12px 28px rgba(13, 110, 253, 0.1);
        }

        .room-unsaved-notice.is-visible {
            display: flex;
        }

        .room-unsaved-notice__content {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            min-width: 0;
        }

        .room-unsaved-notice__icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #dbeafe;
            color: #0d6efd;
            flex: 0 0 auto;
        }

        .room-unsaved-notice__title {
            font-weight: 700;
            margin-bottom: 3px;
        }

        .room-unsaved-notice__message {
            color: #526173;
            font-size: 0.92rem;
        }

        .room-unsaved-notice__actions {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            flex: 0 0 auto;
        }

        .room-btn-icon {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .room-btn-icon svg,
        .room-tab svg,
        .room-stat__icon svg,
        .room-summary-icon svg {
            width: 18px;
            height: 18px;
        }

        @media (max-width: 1199px) {
            .room-step:not(:last-child)::after {
                display: none;
            }

            .room-template-layout {
                grid-template-columns: 1fr;
            }

            .room-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {
            .room-stepper,
            .room-stat-grid,
            .room-summary-grid {
                grid-template-columns: 1fr;
            }

            .room-panel__body,
            .room-panel__footer,
            .room-action-bar,
            .room-unsaved-notice {
                padding: 18px;
            }

            .room-unsaved-notice {
                flex-direction: column;
            }

            .room-page-title {
                font-size: 1.35rem;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
        $ticketTypes = collect($ticketTypes ?? []);
        $templateSets = collect($templateSets ?? []);
        $selectedTemplateSetId = (string) ($selectedTemplateSetId ?? '');
        $previewTemplateSet = $previewTemplateSet ?? $activeTemplateSet ?? $templateSets->first();
        $previewTemplateSetId = (string) optional($previewTemplateSet)->id;
        $selectedTicketTypeCode = old('ticket_type_code', $defaultTicketTypeCode ?? 'ALL_TYPES');
        $expectedRooms = max(1, (int) ($schedule->rooms_per_day ?? 40));
        $requiredVotes = max(1, (int) ($schedule->required_votes ?? 1000));
        $lastRunStatus = strtoupper(trim((string) ($schedule->last_run_status ?? 'N/A')));
        $currentStep = (int) request('step', 1);

        if ($errors->hasAny(['template_set_id', 'ticket_type_code', 'name'])) {
            $currentStep = 2;
        } elseif ($errors->any()) {
            $currentStep = 1;
        }

        if (!in_array($currentStep, [1, 2, 3], true)) {
            $currentStep = 1;
        }

        $steps = [
            1 => ['title' => 'Thiết lập cấu hình', 'desc' => 'Thiết lập các thông số tạo phòng tự động'],
            2 => ['title' => 'Chọn phòng mẫu / tạo mới', 'desc' => 'Chọn bộ mẫu hiện có hoặc tạo mới'],
            3 => ['title' => 'Xác nhận', 'desc' => 'Kiểm tra và xác nhận cấu hình'],
        ];

        $formatDateTime = function ($value, string $fallback = 'Chưa có') {
            return optional($value)->format('Y-m-d H:i:s') ?? $fallback;
        };

        $templateDistribution = function ($templateSet) {
            $templates = collect(optional($templateSet)->roomTemplates ?? []);

            return $templates
                ->groupBy(function ($template) {
                    return $template->prizeTemplate?->prize_name ?: ($template->prize_type ?: 'N/A');
                })
                ->map(fn ($rows) => $rows->count())
                ->sortDesc();
        };

        $isTemplateComplete = function ($templateSet) use ($expectedRooms) {
            return (int) ($templateSet->room_templates_count ?? collect($templateSet->roomTemplates ?? [])->count()) === $expectedRooms;
        };

        $activeTemplates = collect(optional($activeTemplateSet)->roomTemplates ?? [])->sortBy('room_number')->values();
        $activeDistribution = $templateDistribution($activeTemplateSet);
        $statusBadgeClass = match (strtolower($lastRunStatus)) {
            'success' => 'is-green',
            'failed', 'skipped', 'inactive' => 'is-warn',
            default => '',
        };
    @endphp

    <div class="container-fluid room-config-wizard">
        <h2 class="room-page-title">Cấu hình phòng quay</h2>

        @if (session('success'))
            <div class="alert alert-success mt-3">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger mt-3">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="room-stepper">
            @foreach ($steps as $stepNumber => $step)
                <a href="{{ route('admin.room-schedules.index', ['step' => $stepNumber]) }}"
                    data-step-link="{{ $stepNumber }}"
                    class="room-step text-decoration-none {{ $currentStep === $stepNumber ? 'is-active' : '' }} {{ $currentStep > $stepNumber ? 'is-completed' : '' }}">
                    <span class="room-step__number">
                        @if ($currentStep > $stepNumber)
                            <i data-lucide="check"></i>
                        @else
                            {{ $stepNumber }}
                        @endif
                    </span>
                    <span>
                        <span class="room-step__title">{{ $stepNumber }}. {{ $step['title'] }}</span>
                        <span class="room-step__desc d-block">{{ $step['desc'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>

        <div class="room-unsaved-notice" data-unsaved-config-notice role="status" aria-live="polite">
            <div class="room-unsaved-notice__content">
                <span class="room-unsaved-notice__icon"><i data-lucide="info"></i></span>
                <span>
                    <span class="room-unsaved-notice__title d-block">Cấu hình Step 1 chưa được lưu</span>
                    <span class="room-unsaved-notice__message d-block">
                        Bạn đã thay đổi cấu hình phòng quay. Vui lòng bấm Lưu cấu hình trước khi chuyển sang bước tiếp theo.
                    </span>
                </span>
            </div>
            <div class="room-unsaved-notice__actions">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-unsaved-dismiss>Đóng</button>
                <button type="button" class="btn btn-sm btn-primary room-btn-icon" data-unsaved-save>
                    <i data-lucide="save"></i>
                    Lưu cấu hình
                </button>
            </div>
        </div>

        <div class="{{ $currentStep === 1 ? '' : 'd-none' }}" data-step-panel="1">
            <div class="room-panel">
                <div class="room-panel__body">
                    <div class="room-section-title">Thiết lập cấu hình phòng quay tự động</div>
                    <div class="room-section-subtitle">Thiết lập các thông số để hệ thống tạo phòng theo lịch trình.</div>

                    <form id="schedule-form" action="{{ route('admin.room-schedules.update', $schedule->id) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="redirect_step" id="schedule_redirect_step" value="2">

                        <div class="row g-3">
                            <div class="col-xl-3 col-md-6">
                                <label class="room-form-label" for="rooms_per_day">Số phòng mỗi ngày</label>
                                <input id="rooms_per_day" type="number" name="rooms_per_day" class="form-control"
                                    value="{{ old('rooms_per_day', $schedule->rooms_per_day) }}" min="1" max="1000" required>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <label class="room-form-label" for="required_votes">Số ticket tối thiểu/phòng</label>
                                <input id="required_votes" type="number" name="required_votes" class="form-control"
                                    value="{{ old('required_votes', $schedule->required_votes) }}" min="1" max="1000000" required>
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <label class="room-form-label" for="cron_expression">Cron Expression</label>
                                <input id="cron_expression" type="text" name="cron_expression" class="form-control"
                                    value="{{ old('cron_expression', $schedule->cron_expression) }}" placeholder="5 0 * * *">
                            </div>

                            <div class="col-xl-3 col-md-6">
                                <label class="room-form-label" for="timezone">Timezone</label>
                                <input id="timezone" type="text" name="timezone" class="form-control"
                                    value="{{ old('timezone', $schedule->timezone) }}" placeholder="Asia/Ho_Chi_Minh">
                            </div>

                            <div class="col-12">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1"
                                        id="is_active" {{ old('is_active', $schedule->is_active) ? 'checked' : '' }}>
                                    <label class="form-check-label fw-semibold" for="is_active">Bật lịch tạo phòng tự động</label>
                                    <div class="room-muted small mt-1">Hệ thống chỉ tự động tạo phòng khi đã có bộ phòng mẫu được chọn ở Step 2.</div>
                                </div>
                            </div>
                        </div>
                    <div class="room-stat-grid">
                        <div class="room-stat">
                            <span class="room-stat__icon"><i data-lucide="calendar-days"></i></span>
                            <span>
                                <span class="room-muted d-block">Lần chạy gần nhất</span>
                                <strong>{{ $formatDateTime($schedule->last_run_at) }}</strong>
                            </span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat__icon is-purple"><i data-lucide="clock"></i></span>
                            <span>
                                <span class="room-muted d-block">Lần chạy tiếp theo</span>
                                <strong>{{ $formatDateTime($schedule->next_run_at, 'Chưa xác định') }}</strong>
                            </span>
                        </div>
                        <div class="room-stat">
                            <span class="room-stat__icon is-green"><i data-lucide="circle-check"></i></span>
                            <span>
                                <span class="room-muted d-block">Trạng thái lần chạy gần nhất</span>
                                <span class="room-badge-soft {{ $statusBadgeClass }}">{{ $lastRunStatus }}</span>
                            </span>
                        </div>
                    </div>
                    <div class="room-panel__footer">
                        <button type="submit" class="btn btn-primary room-btn-icon" data-redirect-step="2">
                            <i data-lucide="save"></i>
                            Lưu cấu hình
                            <i data-lucide="arrow-right"></i>
                        </button>
                    </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="{{ $currentStep === 2 ? '' : 'd-none' }}" data-step-panel="2">
            <div class="room-panel">
                <div class="room-panel__body">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                        <div>
                            <div class="room-section-title">Chọn phòng mẫu / tạo mới</div>
                            <div class="room-section-subtitle mb-0">Bộ mẫu được chọn sẽ là nguồn để cron tạo phòng tự động.</div>
                        </div>
                        <a href="{{ route('admin.room-templates.index') }}" class="btn btn-outline-primary btn-sm room-btn-icon">
                            <i data-lucide="external-link"></i>
                            Quản lý phòng mẫu
                        </a>
                    </div>

                    <div class="room-tabbar" role="tablist">
                        <button type="button" class="room-tab is-active" data-room-tab="rooms">
                            <i data-lucide="layout-template"></i>
                            Phòng mẫu
                        </button>
                        <button type="button" class="room-tab" data-room-tab="prizes">
                            <i data-lucide="trophy"></i>
                            Giải thưởng mẫu
                        </button>
                        <button type="button" class="room-tab" data-room-tab="tickets">
                            <i data-lucide="ticket"></i>
                            Ticket
                        </button>
                    </div>

                    <div data-room-tab-panel="rooms">
                        @if ($templateSets->isNotEmpty())
                            <div class="room-template-picker">
                                <label class="room-form-label" for="room_template_select">Danh sách phòng mẫu</label>
                                <select id="room_template_select" class="form-select" data-template-select>
                                    @foreach ($templateSets as $templateSet)
                                        @php
                                            $templateCount = (int) ($templateSet->room_templates_count ?? collect($templateSet->roomTemplates ?? [])->count());
                                            $complete = $isTemplateComplete($templateSet);
                                            $isPreviewed = (string) $previewTemplateSetId === (string) $templateSet->id;
                                        @endphp
                                        <option value="{{ $templateSet->id }}" {{ $isPreviewed ? 'selected' : '' }}>
                                            {{ $templateSet->name }} - {{ $templateSet->status ?: 'WAITING' }} - {{ $complete ? 'Đủ ' . number_format($expectedRooms) . ' phòng' : 'Thiếu mẫu' }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="room-muted small mt-2">
                                    Chọn một bộ mẫu để xem đầy đủ phòng mẫu, giải thưởng mẫu và ticket trước khi áp dụng.
                                </div>
                            </div>
                        @endif

                        <div class="room-create-box mb-4">
                            <div class="room-create-box__header">
                                <div>
                                    <div class="fw-bold">Tạo mới bộ mẫu từ cấu hình hiện tại</div>
                                    <div class="room-muted small">Tạo {{ number_format($expectedRooms) }} phòng mẫu với {{ number_format($requiredVotes) }} ticket/phòng và cấu hình giải thưởng hiện tại.</div>
                                </div>
                            </div>
                            <div class="room-create-box__body">
                                <form action="{{ route('admin.room-templates.store') }}" method="POST" class="row g-3">
                                    @csrf
                                    <input type="hidden" name="redirect_to" value="room_schedules">
                                    <input type="hidden" name="redirect_step" value="3">
                                    <input type="hidden" name="select_after_create" value="1">
                                    <div class="col-lg-5">
                                        <label class="room-form-label" for="template_name">Tên bộ mẫu</label>
                                        <input id="template_name" type="text" name="name" class="form-control"
                                            value="{{ old('name') }}" placeholder="VD: Mẫu phòng ngày mai">
                                    </div>
                                    <div class="col-lg-4">
                                        <label class="room-form-label" for="template_ticket_type_code">Loại vé áp dụng</label>
                                        <select id="template_ticket_type_code" name="ticket_type_code" class="form-select" required>
                                            <option value="ALL_TYPES" {{ $selectedTicketTypeCode === 'ALL_TYPES' ? 'selected' : '' }}>Tất cả loại vé</option>
                                            @foreach ($ticketTypes as $ticketType)
                                                @php
                                                    $ticketTypeCode = strtoupper(trim((string) ($ticketType['code'] ?? '')));
                                                    $ticketTypeName = trim((string) ($ticketType['name'] ?? ''));
                                                @endphp
                                                @if ($ticketTypeCode !== '' && $ticketTypeName !== '')
                                                    <option value="{{ $ticketTypeCode }}" {{ $selectedTicketTypeCode === $ticketTypeCode ? 'selected' : '' }}>
                                                        {{ $ticketTypeName }} ({{ $ticketTypeCode }})
                                                    </option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-lg-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary w-100 room-btn-icon justify-content-center">
                                            <i data-lucide="plus-circle"></i>
                                            Tạo mới & chọn
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="d-none">
                            <div class="input-group" style="max-width: 360px;">
                                <span class="input-group-text"><i data-lucide="search"></i></span>
                                <input type="search" class="form-control" placeholder="Tìm kiếm mẫu phòng..." data-template-search>
                            </div>
                        </div>

                        @if ($templateSets->isEmpty())
                            <div class="room-empty">
                                Chưa có bộ phòng mẫu. Hãy tạo bộ mẫu đầu tiên từ cấu hình hiện tại.
                            </div>
                        @else
                            <div class="room-template-layout">
                                <div>
                                    <div class="room-template-list">
                                        @foreach ($templateSets as $templateSet)
                                            @php
                                                $templateCount = (int) ($templateSet->room_templates_count ?? collect($templateSet->roomTemplates ?? [])->count());
                                                $complete = $isTemplateComplete($templateSet);
                                                $isSelected = (string) $selectedTemplateSetId === (string) $templateSet->id;
                                                $isPreviewed = (string) $previewTemplateSetId === (string) $templateSet->id;
                                            @endphp
                                            <button type="button"
                                                class="room-template-card {{ $isSelected ? 'is-selected' : '' }} {{ $isPreviewed ? 'is-previewed' : '' }}"
                                                data-template-card
                                                data-template-id="{{ $templateSet->id }}"
                                                data-template-search-text="{{ strtolower($templateSet->name . ' ' . ($templateSet->ticket_type_code ?: 'ALL_TYPES')) }}">
                                                <div class="d-flex justify-content-between gap-3">
                                                    <div class="room-template-title">{{ $templateSet->name }}</div>
                                                    @if ($isSelected)
                                                        <span class="room-badge-soft is-green"><i data-lucide="check"></i> Đang chọn</span>
                                                    @endif
                                                </div>
                                                <div class="room-mini-meta">
                                                    <span><i data-lucide="layers"></i> {{ number_format($templateCount) }} phòng</span>
                                                    <span><i data-lucide="ticket"></i> {{ $templateSet->ticket_type_code ?: 'ALL_TYPES' }}</span>
                                                    <span><i data-lucide="file-text"></i> {{ number_format((int) $templateSet->required_votes) }}</span>
                                                </div>
                                                <div class="room-muted small mt-2">
                                                    Cập nhật: {{ optional($templateSet->updated_at)->format('Y-m-d H:i') ?? 'N/A' }}
                                                </div>
                                                @unless ($complete)
                                                    <div class="mt-2">
                                                        <span class="room-badge-soft is-warn">Thiếu mẫu so với {{ number_format($expectedRooms) }} phòng</span>
                                                    </div>
                                                @endunless
                                            </button>
                                        @endforeach
                                    </div>
                                    <div class="room-empty mt-3 d-none" data-template-empty-search>Không tìm thấy bộ mẫu phù hợp.</div>
                                </div>

                                <div>
                                    @foreach ($templateSets as $templateSet)
                                        @php
                                            $templates = collect($templateSet->roomTemplates ?? [])->sortBy('room_number')->values();
                                            $templateCount = (int) ($templateSet->room_templates_count ?? $templates->count());
                                            $complete = $isTemplateComplete($templateSet);
                                            $distribution = $templateDistribution($templateSet);
                                        @endphp
                                        <div class="room-detail-box {{ (string) $previewTemplateSetId === (string) $templateSet->id ? '' : 'd-none' }}"
                                            data-template-detail="{{ $templateSet->id }}">
                                            <div class="room-detail-box__header">
                                                <div>
                                                    <div class="d-flex align-items-center flex-wrap gap-2">
                                                        <h5 class="mb-0 fw-bold">{{ $templateSet->name }}</h5>
                                                        <span class="room-badge-soft">WAITING</span>
                                                        @if ($complete)
                                                            <span class="room-badge-soft is-green">Đủ {{ number_format($expectedRooms) }} phòng</span>
                                                        @else
                                                            <span class="room-badge-soft is-warn">Thiếu mẫu</span>
                                                        @endif
                                                    </div>
                                                    <div class="room-muted small mt-2">
                                                        Loại vé: {{ $templateSet->ticket_type_code ?: 'ALL_TYPES' }} · Vé/phòng: {{ number_format((int) $templateSet->required_votes) }}
                                                    </div>
                                                </div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                                    <a href="{{ route('admin.room-templates.show', $templateSet->id) }}"
                                                        class="btn btn-outline-secondary room-btn-icon">
                                                        <i data-lucide="eye"></i>
                                                        Xem
                                                    </a>
                                                    <a href="{{ route('admin.room-templates.edit', $templateSet->id) }}"
                                                        class="btn btn-outline-primary room-btn-icon">
                                                        <i data-lucide="pencil"></i>
                                                        Sửa
                                                    </a>
                                                    <form method="POST" action="{{ route('admin.room-schedules.template-set', $schedule->id) }}">
                                                        @csrf
                                                        <input type="hidden" name="template_set_id" value="{{ $templateSet->id }}">
                                                        <input type="hidden" name="redirect_step" value="3">
                                                        <button type="submit" class="btn btn-primary room-btn-icon" {{ $complete ? '' : 'disabled' }}>
                                                            <i data-lucide="circle-check"></i>
                                                            Chọn mẫu này
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                            <div class="room-detail-box__body">
                                                <div class="room-summary-grid mb-3">
                                                    <div class="room-summary-item">
                                                        <div class="room-summary-label">Tổng số phòng</div>
                                                        <div class="room-summary-value">{{ number_format($templateCount) }}</div>
                                                    </div>
                                                    <div class="room-summary-item">
                                                        <div class="room-summary-label">Vé/phòng</div>
                                                        <div class="room-summary-value">{{ number_format((int) $templateSet->required_votes) }}</div>
                                                    </div>
                                                    <div class="room-summary-item">
                                                        <div class="room-summary-label">Loại vé</div>
                                                        <div class="room-summary-value">{{ $templateSet->ticket_type_code ?: 'ALL_TYPES' }}</div>
                                                    </div>
                                                    <div class="room-summary-item">
                                                        <div class="room-summary-label">Ngày chạy gần nhất</div>
                                                        <div class="room-summary-value">{{ optional($templateSet->last_target_date)->format('Y-m-d') ?? 'Chưa chạy' }}</div>
                                                    </div>
                                                </div>

                                                <div class="fw-bold mb-2">Phòng mẫu</div>
                                                <div class="room-preview-table room-preview-table--scroll mb-3">
                                                    <div class="table-responsive">
                                                        <table class="table table-sm align-middle">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th style="width: 80px;">Phòng</th>
                                                                    <th>Tên mẫu</th>
                                                                    <th style="width: 130px;">Trạng thái</th>
                                                                    <th style="width: 140px;">Loại vé</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                @forelse ($templates as $template)
                                                                    <tr>
                                                                        <td>#{{ (int) $template->room_number }}</td>
                                                                        <td>{{ $template->room_name ?: ('Phòng mẫu ' . (int) $template->room_number) }}</td>
                                                                        <td><span class="room-badge-soft">{{ $template->status ?: 'WAITING' }}</span></td>
                                                                        <td>{{ $template->ticket_type_code ?: 'ALL_TYPES' }}</td>
                                                                    </tr>
                                                                @empty
                                                                    <tr>
                                                                        <td colspan="4" class="text-center room-muted py-3">Chưa có phòng mẫu.</td>
                                                                    </tr>
                                                                @endforelse
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                <div class="fw-bold mb-2">Giải thưởng mẫu</div>
                                                <div class="d-flex flex-wrap gap-2">
                                                    @forelse ($distribution->take(5) as $prizeName => $count)
                                                        <span class="room-badge-soft">{{ number_format((int) $count) }} x {{ $prizeName }}</span>
                                                    @empty
                                                        <span class="room-muted">Chưa có dữ liệu giải thưởng mẫu.</span>
                                                    @endforelse
                                                    @if ($distribution->count() > 5)
                                                        <span class="room-badge-soft">+{{ $distribution->count() - 5 }} giải thưởng khác</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="d-none" data-room-tab-panel="prizes">
                        @if ($templateSets->isEmpty())
                            <div class="room-empty">Chưa có bộ mẫu để xem giải thưởng mẫu.</div>
                        @else
                            @foreach ($templateSets as $templateSet)
                                @php
                                    $templates = collect($templateSet->roomTemplates ?? [])->sortBy('room_number')->values();
                                    $distribution = $templateDistribution($templateSet);
                                @endphp
                                <div class="{{ (string) $previewTemplateSetId === (string) $templateSet->id ? '' : 'd-none' }}"
                                    data-template-dependent="{{ $templateSet->id }}">
                                    <div class="room-detail-box">
                                        <div class="room-detail-box__header">
                                            <div>
                                                <h5 class="mb-1 fw-bold">Giải thưởng mẫu: {{ $templateSet->name }}</h5>
                                                <div class="room-muted">Dữ liệu lấy từ các phòng mẫu trong bộ mẫu đang xem.</div>
                                            </div>
                                            <a href="{{ route('admin.prize-settings.index') }}" class="btn btn-outline-primary btn-sm room-btn-icon">
                                                <i data-lucide="settings"></i>
                                                Cấu hình giải thưởng
                                            </a>
                                        </div>
                                        <div class="room-detail-box__body">
                                            <div class="d-flex flex-wrap gap-2 mb-3">
                                                @forelse ($distribution as $prizeName => $count)
                                                    <span class="room-badge-soft">{{ number_format((int) $count) }} x {{ $prizeName }}</span>
                                                @empty
                                                    <span class="room-muted">Chưa có dữ liệu giải thưởng mẫu.</span>
                                                @endforelse
                                            </div>

                                            <div class="room-preview-table">
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th style="width: 80px;">Phòng</th>
                                                                <th>Giải thưởng</th>
                                                                <th class="text-end" style="width: 150px;">Giá trị</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @forelse ($templates->take(8) as $template)
                                                                <tr>
                                                                    <td>#{{ (int) $template->room_number }}</td>
                                                                    <td>
                                                                        <div class="fw-semibold">{{ $template->prizeTemplate?->prize_name ?: $template->prize_type }}</div>
                                                                        @if (!empty($template->prize_description))
                                                                            <div class="room-muted small">{{ $template->prize_description }}</div>
                                                                        @endif
                                                                    </td>
                                                                    <td class="text-end">
                                                                        {{ $template->prize_value !== null ? number_format((float) $template->prize_value, 0, ',', '.') . ' đ' : 'N/A' }}
                                                                    </td>
                                                                </tr>
                                                            @empty
                                                                <tr>
                                                                    <td colspan="3" class="text-center room-muted py-3">Chưa có phòng mẫu.</td>
                                                                </tr>
                                                            @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>

                    <div class="d-none" data-room-tab-panel="tickets">
                        @if ($templateSets->isEmpty())
                            <div class="room-empty">Chưa có bộ mẫu để xem cấu hình ticket.</div>
                        @else
                            @foreach ($templateSets as $templateSet)
                                @php
                                    $templateCount = (int) ($templateSet->room_templates_count ?? collect($templateSet->roomTemplates ?? [])->count());
                                    $totalRequiredTickets = $templateCount * max(1, (int) $templateSet->required_votes);
                                @endphp
                                <div class="{{ (string) $previewTemplateSetId === (string) $templateSet->id ? '' : 'd-none' }}"
                                    data-template-dependent="{{ $templateSet->id }}">
                                    <div class="room-detail-box">
                                        <div class="room-detail-box__header">
                                            <div>
                                                <h5 class="mb-1 fw-bold">Ticket: {{ $templateSet->name }}</h5>
                                                <div class="room-muted">Cấu hình ticket áp dụng cho bộ phòng mẫu đang xem.</div>
                                            </div>
                                            <a href="{{ route('admin.ticket-settings.index') }}" class="btn btn-outline-primary btn-sm room-btn-icon">
                                                <i data-lucide="settings"></i>
                                                Cấu hình ticket
                                            </a>
                                        </div>
                                        <div class="room-detail-box__body">
                                            <div class="room-summary-grid mb-4">
                                                <div class="room-summary-item">
                                                    <div class="room-summary-label">Loại vé</div>
                                                    <div class="room-summary-value">{{ $templateSet->ticket_type_code ?: 'ALL_TYPES' }}</div>
                                                </div>
                                                <div class="room-summary-item">
                                                    <div class="room-summary-label">Ticket tối thiểu/phòng</div>
                                                    <div class="room-summary-value">{{ number_format((int) $templateSet->required_votes) }}</div>
                                                </div>
                                                <div class="room-summary-item">
                                                    <div class="room-summary-label">Tổng số phòng</div>
                                                    <div class="room-summary-value">{{ number_format($templateCount) }}</div>
                                                </div>
                                                <div class="room-summary-item">
                                                    <div class="room-summary-label">Tổng ticket tối thiểu</div>
                                                    <div class="room-summary-value">{{ number_format($totalRequiredTickets) }}</div>
                                                </div>
                                            </div>

                                            <div class="fw-bold mb-2">Loại ticket đang hoạt động</div>
                                            <div class="room-preview-table">
                                                <div class="table-responsive">
                                                    <table class="table table-sm align-middle">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th>Mã vé</th>
                                                                <th>Tên vé</th>
                                                                <th class="text-end">Giá trị</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @forelse ($ticketTypes as $ticketType)
                                                                <tr>
                                                                    <td>{{ $ticketType['code'] ?? 'N/A' }}</td>
                                                                    <td>{{ $ticketType['name'] ?? 'N/A' }}</td>
                                                                    <td class="text-end">{{ number_format((float) ($ticketType['price'] ?? 0), 0, ',', '.') }} đ</td>
                                                                </tr>
                                                            @empty
                                                                <tr>
                                                                    <td colspan="3" class="text-center room-muted py-3">Chưa có cấu hình ticket.</td>
                                                                </tr>
                                                            @endforelse
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>

            <div class="room-action-bar">
                <a href="{{ route('admin.room-schedules.index', ['step' => 1]) }}" class="btn btn-outline-secondary room-btn-icon">
                    <i data-lucide="arrow-left"></i>
                    Quay lại
                </a>
                <a href="{{ route('admin.room-schedules.index', ['step' => 3]) }}"
                    class="btn btn-primary room-btn-icon {{ $activeTemplateSet ? '' : 'disabled' }}"
                    aria-disabled="{{ $activeTemplateSet ? 'false' : 'true' }}">
                    Tiếp tục
                    <i data-lucide="arrow-right"></i>
                </a>
            </div>
        </div>

        <div class="{{ $currentStep === 3 ? '' : 'd-none' }}" data-step-panel="3">
            <div class="room-panel">
                <div class="room-panel__body">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
                        <div>
                            <div class="room-section-title">1. Cấu hình tự động</div>
                            <div class="room-section-subtitle mb-0">Kiểm tra lại cấu hình trước khi áp dụng.</div>
                        </div>
                        <a href="{{ route('admin.room-schedules.index', ['step' => 1]) }}" class="btn btn-link room-btn-icon">
                            <i data-lucide="pencil"></i>
                            Chỉnh sửa
                        </a>
                    </div>

                    <div class="room-summary-grid">
                        <div class="room-summary-item">
                            <div class="room-summary-label">Số phòng mỗi ngày</div>
                            <div class="room-summary-value">{{ number_format($expectedRooms) }}</div>
                        </div>
                        <div class="room-summary-item">
                            <div class="room-summary-label">Ticket tối thiểu/phòng</div>
                            <div class="room-summary-value">{{ number_format($requiredVotes) }}</div>
                        </div>
                        <div class="room-summary-item">
                            <div class="room-summary-label">Cron Expression</div>
                            <div class="room-summary-value">{{ $schedule->cron_expression ?: '5 0 * * *' }}</div>
                        </div>
                        <div class="room-summary-item">
                            <div class="room-summary-label">Timezone</div>
                            <div class="room-summary-value">{{ $schedule->timezone ?: 'Asia/Ho_Chi_Minh' }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="room-panel">
                <div class="room-panel__body">
                    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
                        <div>
                            <div class="room-section-title">2. Phòng mẫu đã chọn</div>
                            <div class="room-section-subtitle mb-0">Bộ mẫu này là nguồn dữ liệu cho cron tự động.</div>
                        </div>
                        <a href="{{ route('admin.room-schedules.index', ['step' => 2]) }}" class="btn btn-outline-secondary btn-sm room-btn-icon">
                            <i data-lucide="eye"></i>
                            Xem chi tiết
                        </a>
                    </div>

                    @if ($activeTemplateSet)
                        <div class="room-summary-box mb-4">
                            <div class="row g-3 align-items-center">
                                <div class="col-lg-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <span class="room-summary-icon"><i data-lucide="package-check"></i></span>
                                        <div>
                                            <div class="fw-bold">{{ $activeTemplateSet->name }}</div>
                                            <span class="room-badge-soft">{{ $activeTemplateSet->status ?: 'WAITING' }}</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <div class="room-summary-label">Loại vé</div>
                                    <div class="room-summary-value">{{ $activeTemplateSet->ticket_type_code ?: 'ALL_TYPES' }}</div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <div class="room-summary-label">Vé/phòng</div>
                                    <div class="room-summary-value">{{ number_format((int) $activeTemplateSet->required_votes) }}</div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <div class="room-summary-label">Tổng số phòng</div>
                                    <div class="room-summary-value">{{ number_format((int) ($activeTemplateSet->room_templates_count ?? $activeTemplates->count())) }}</div>
                                </div>
                                <div class="col-lg-2 col-md-6">
                                    <div class="room-summary-label">Giải thưởng nổi bật</div>
                                    <div class="d-flex flex-wrap gap-1">
                                        @forelse ($activeDistribution->take(2) as $prizeName => $count)
                                            <span class="room-badge-soft">{{ number_format((int) $count) }} x {{ $prizeName }}</span>
                                        @empty
                                            <span class="room-muted">N/A</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="alert alert-warning mb-0">
                            Chưa chọn bộ phòng mẫu. Vui lòng quay lại Step 2 để chọn hoặc tạo mới trước khi bật cron tự động.
                        </div>
                    @endif
                </div>
            </div>

            <div class="room-panel">
                <div class="room-panel__body">
                    <div class="room-section-title">3. Xem nhanh nội dung áp dụng</div>
                    <div class="room-section-subtitle">Dữ liệu hiển thị là các dòng đầu tiên từ bộ mẫu đang chọn.</div>

                    <div class="row g-3 mb-3">
                        <div class="col-lg-4">
                            <div class="room-summary-item">
                                <div class="room-summary-label">Phòng mẫu</div>
                                <div class="room-summary-value">{{ $activeTemplateSet?->name ?? 'Chưa chọn' }}</div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="room-summary-item">
                                <div class="room-summary-label">Giải thưởng mẫu</div>
                                <div class="room-summary-value">Tổng: {{ number_format($activeDistribution->sum()) }} giải</div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="room-summary-item">
                                <div class="room-summary-label">Ticket</div>
                                <div class="room-summary-value">{{ $activeTemplateSet?->ticket_type_code ?: 'ALL_TYPES' }} · {{ number_format((int) ($activeTemplateSet?->required_votes ?? $requiredVotes)) }} vé/phòng</div>
                            </div>
                        </div>
                    </div>

                    <div class="room-preview-table room-preview-table--scroll">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 70px;">#</th>
                                        <th>Phòng ví dụ</th>
                                        <th>Giải thưởng ví dụ</th>
                                        <th>Ticket ví dụ</th>
                                        <th style="width: 150px;">Số vé tối thiểu</th>
                                        <th style="width: 130px;">Trạng thái</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($activeTemplates as $template)
                                        <tr>
                                            <td>#{{ (int) $template->room_number }}</td>
                                            <td>{{ $template->room_name ?: ('Phòng mẫu ' . (int) $template->room_number) }}</td>
                                            <td>{{ $template->prizeTemplate?->prize_name ?: $template->prize_type }}</td>
                                            <td>{{ $template->ticket_type_code ?: 'ALL_TYPES' }}</td>
                                            <td>{{ number_format((int) $template->required_votes) }}</td>
                                            <td><span class="room-badge-soft">{{ $template->status ?: 'WAITING' }}</span></td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="text-center room-muted py-3">Chưa có dữ liệu phòng mẫu để xem trước.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="room-action-bar">
                <div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirm_reviewed" value="1" data-confirm-review>
                        <label class="form-check-label fw-semibold" for="confirm_reviewed">
                            Tôi đã kiểm tra và xác nhận cấu hình trên
                        </label>
                    </div>
                    <div class="room-muted small mt-1">Hệ thống sẽ áp dụng cấu hình sau khi bạn xác nhận.</div>
                </div>
                <div class="d-flex gap-2 flex-wrap justify-content-end">
                    <a href="{{ route('admin.room-schedules.index', ['step' => 2]) }}" class="btn btn-outline-secondary room-btn-icon">
                        <i data-lucide="arrow-left"></i>
                        Quay lại
                    </a>
                    <button type="submit" class="btn btn-primary room-btn-icon" form="schedule-form"
                        data-redirect-step="3"
                        data-confirm-submit {{ $activeTemplateSet ? '' : 'disabled' }}>
                        <i data-lucide="check"></i>
                        Xác nhận & lưu cấu hình
                    </button>
                </div>
            </div>

            <div class="room-panel">
                <div class="room-panel__body">
                    <div class="room-section-title">Tạo phòng thủ công theo ngày</div>
                    <div class="room-section-subtitle">Giữ chức năng tạo phòng thủ công hiện có. Nếu đã chọn bộ mẫu, hệ thống sẽ tạo theo bộ mẫu đó.</div>
                    <form action="{{ route('admin.rooms.generate') }}" method="POST" class="row g-3">
                        @csrf
                        <div class="col-lg-4">
                            <label class="room-form-label" for="manual_date">Ngày tạo phòng</label>
                            <input id="manual_date" type="date" name="date" class="form-control" value="{{ old('date', $defaultTargetDate ?? '') }}" required>
                        </div>
                        <div class="col-lg-4">
                            <label class="room-form-label" for="manual_ticket_type_code">Loại vé</label>
                            <select id="manual_ticket_type_code" name="ticket_type_code" class="form-select" required>
                                <option value="ALL_TYPES" {{ $selectedTicketTypeCode === 'ALL_TYPES' ? 'selected' : '' }}>Tất cả loại vé</option>
                                @foreach ($ticketTypes as $ticketType)
                                    @php
                                        $ticketTypeCode = strtoupper(trim((string) ($ticketType['code'] ?? '')));
                                        $ticketTypeName = trim((string) ($ticketType['name'] ?? ''));
                                    @endphp
                                    @if ($ticketTypeCode !== '' && $ticketTypeName !== '')
                                        <option value="{{ $ticketTypeCode }}" {{ $selectedTicketTypeCode === $ticketTypeCode ? 'selected' : '' }}>
                                            {{ $ticketTypeName }} ({{ $ticketTypeCode }})
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100 room-btn-icon justify-content-center">
                                <i data-lucide="play"></i>
                                Tạo phòng
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function() {
            var tabButtons = document.querySelectorAll('[data-room-tab]');
            var tabPanels = document.querySelectorAll('[data-room-tab-panel]');
            var templateSelect = document.querySelector('[data-template-select]');
            var templateCards = document.querySelectorAll('[data-template-card]');
            var templateDetails = document.querySelectorAll('[data-template-detail]');
            var templateDependents = document.querySelectorAll('[data-template-dependent]');
            var searchInput = document.querySelector('[data-template-search]');
            var emptySearch = document.querySelector('[data-template-empty-search]');
            var confirmReview = document.querySelector('[data-confirm-review]');
            var confirmSubmit = document.querySelector('[data-confirm-submit]');
            var scheduleForm = document.getElementById('schedule-form');
            var redirectStepInput = document.getElementById('schedule_redirect_step');
            var stepLinks = document.querySelectorAll('[data-step-link]');
            var unsavedNotice = document.querySelector('[data-unsaved-config-notice]');
            var unsavedDismiss = document.querySelector('[data-unsaved-dismiss]');
            var unsavedSave = document.querySelector('[data-unsaved-save]');
            var hasActiveTemplate = @json((bool) $activeTemplateSet);
            var currentStep = @json((int) $currentStep);
            var scheduleFormSubmitted = false;

            function getScheduleSnapshot() {
                if (!scheduleForm) {
                    return '';
                }

                var values = [];
                scheduleForm.querySelectorAll('input, select, textarea').forEach(function(field) {
                    if (!field.name || field.name === '_token' || field.name === '_method' || field.name === 'redirect_step') {
                        return;
                    }

                    if (field.type === 'checkbox' || field.type === 'radio') {
                        values.push(field.name + '=' + (field.checked ? '1' : '0'));
                        return;
                    }

                    values.push(field.name + '=' + field.value);
                });

                return values.join('&');
            }

            var initialScheduleSnapshot = getScheduleSnapshot();

            function isScheduleDirty() {
                return scheduleForm && !scheduleFormSubmitted && getScheduleSnapshot() !== initialScheduleSnapshot;
            }

            function showUnsavedNotice() {
                if (!unsavedNotice) {
                    return;
                }

                unsavedNotice.classList.add('is-visible');
                unsavedNotice.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }

            function hideUnsavedNotice() {
                if (unsavedNotice) {
                    unsavedNotice.classList.remove('is-visible');
                }
            }

            document.querySelectorAll('[data-redirect-step]').forEach(function(button) {
                button.addEventListener('click', function() {
                    if (redirectStepInput) {
                        redirectStepInput.value = button.getAttribute('data-redirect-step') || '1';
                    }
                });
            });

            if (scheduleForm) {
                scheduleForm.addEventListener('submit', function() {
                    scheduleFormSubmitted = true;
                });

                scheduleForm.addEventListener('input', function() {
                    if (!isScheduleDirty()) {
                        hideUnsavedNotice();
                    }
                });

                scheduleForm.addEventListener('change', function() {
                    if (!isScheduleDirty()) {
                        hideUnsavedNotice();
                    }
                });
            }

            if (unsavedDismiss) {
                unsavedDismiss.addEventListener('click', hideUnsavedNotice);
            }

            if (unsavedSave) {
                unsavedSave.addEventListener('click', function() {
                    if (!scheduleForm) {
                        return;
                    }

                    if (redirectStepInput) {
                        redirectStepInput.value = '2';
                    }

                    if (typeof scheduleForm.requestSubmit === 'function') {
                        scheduleForm.requestSubmit();
                        return;
                    }

                    scheduleForm.submit();
                });
            }

            stepLinks.forEach(function(link) {
                link.addEventListener('click', function(event) {
                    var targetStep = Number(link.getAttribute('data-step-link') || 1);

                    if (currentStep === 1 && targetStep > 1 && isScheduleDirty()) {
                        event.preventDefault();
                        showUnsavedNotice();
                    }
                });
            });

            function activateTab(tabName) {
                tabButtons.forEach(function(button) {
                    var active = button.getAttribute('data-room-tab') === tabName;
                    button.classList.toggle('is-active', active);
                });

                tabPanels.forEach(function(panel) {
                    var active = panel.getAttribute('data-room-tab-panel') === tabName;
                    panel.classList.toggle('d-none', !active);
                });
            }

            function previewTemplate(templateId) {
                templateCards.forEach(function(card) {
                    card.classList.toggle('is-previewed', card.getAttribute('data-template-id') === templateId);
                });

                if (templateSelect && templateSelect.value !== templateId) {
                    templateSelect.value = templateId;
                }

                templateDetails.forEach(function(detail) {
                    detail.classList.toggle('d-none', detail.getAttribute('data-template-detail') !== templateId);
                });

                templateDependents.forEach(function(detail) {
                    detail.classList.toggle('d-none', detail.getAttribute('data-template-dependent') !== templateId);
                });
            }

            tabButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    activateTab(button.getAttribute('data-room-tab'));
                });
            });

            templateCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    previewTemplate(card.getAttribute('data-template-id'));
                });
            });

            if (templateSelect) {
                templateSelect.addEventListener('change', function() {
                    previewTemplate(templateSelect.value);
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    var keyword = searchInput.value.trim().toLowerCase();
                    var visibleCount = 0;

                    templateCards.forEach(function(card) {
                        var text = card.getAttribute('data-template-search-text') || '';
                        var visible = keyword === '' || text.indexOf(keyword) !== -1;
                        card.classList.toggle('d-none', !visible);
                        if (visible) {
                            visibleCount += 1;
                        }
                    });

                    if (emptySearch) {
                        emptySearch.classList.toggle('d-none', visibleCount !== 0);
                    }
                });
            }

            function updateConfirmButton() {
                if (!confirmSubmit) {
                    return;
                }

                confirmSubmit.disabled = !hasActiveTemplate || !confirmReview || !confirmReview.checked;
            }

            if (confirmReview) {
                confirmReview.addEventListener('change', updateConfirmButton);
                updateConfirmButton();
            }

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        })();
    </script>
@endsection
