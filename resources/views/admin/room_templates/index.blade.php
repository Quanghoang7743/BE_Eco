@extends('admin.layout')

@section('styles')
    <style>
        .rt-page {
            color: #12213f;
        }

        .rt-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .rt-muted {
            color: #6b7890;
        }

        .rt-toolbar,
        .rt-panel {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
        }

        .rt-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0;
            padding: 18px;
        }

        .rt-stat {
            display: grid;
            grid-template-columns: 42px minmax(0, 1fr);
            gap: 12px;
            align-items: center;
            padding: 6px 18px;
            border-right: 1px solid #e5edf6;
        }

        .rt-stat:last-child {
            border-right: 0;
        }

        .rt-icon {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #e9f2ff;
            color: #0d6efd;
        }

        .rt-icon.is-green {
            background: #ebf9ef;
            color: #16a34a;
        }

        .rt-icon.is-purple {
            background: #f1ecff;
            color: #7c3aed;
        }

        .rt-icon.is-amber {
            background: #fff7e6;
            color: #d97706;
        }

        .rt-stat-label {
            color: #6b7890;
            font-size: 0.82rem;
            margin-bottom: 4px;
        }

        .rt-stat-value {
            font-size: 1rem;
            font-weight: 700;
            color: #12213f;
        }

        .rt-filter {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) 220px 180px auto;
            gap: 12px;
            padding: 18px 0;
            align-items: center;
        }

        .rt-panel {
            overflow: hidden;
        }

        .rt-table {
            margin-bottom: 0;
        }

        .rt-table th {
            color: #12213f;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
            background: #f8fafc;
            padding: 14px 16px;
        }

        .rt-table td {
            padding: 15px 16px;
            vertical-align: middle;
        }

        .rt-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 999px;
            background: #eef5ff;
            color: #0d6efd;
            font-weight: 700;
            font-size: 0.76rem;
        }

        .rt-badge.is-green {
            background: #e9f9ef;
            color: #16a34a;
        }

        .rt-badge.is-gray {
            background: #f1f5f9;
            color: #64748b;
        }

        .rt-action-row {
            display: inline-flex;
            gap: 6px;
            align-items: center;
        }

        .rt-icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dce5f1;
            background: #ffffff;
            color: #37506f;
        }

        .rt-icon-btn:hover {
            color: #0d6efd;
            border-color: #b9d3ff;
            background: #f5f9ff;
        }

        .rt-icon-btn.is-danger:hover {
            color: #dc2626;
            border-color: #fecaca;
            background: #fff5f5;
        }

        .rt-icon-btn:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .rt-icon-btn svg,
        .rt-icon svg {
            width: 16px;
            height: 16px;
        }

        .rt-pagination {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 16px 0;
            flex-wrap: wrap;
        }

        @media (max-width: 1199px) {
            .rt-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .rt-stat {
                border: 1px solid #e5edf6;
                border-radius: 8px;
            }

            .rt-filter {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 767px) {
            .rt-stats,
            .rt-filter {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $errors = $errors ?? new \Illuminate\Support\ViewErrorBag;
        $ticketTypes = collect($ticketTypes ?? []);
        $selectedTemplateSetId = (string) ($selectedTemplateSetId ?? '');
        $filters = $filters ?? ['q' => '', 'ticket_type_code' => '', 'status' => ''];
        $latestDate = $latestTemplateUpdate ? \Carbon\Carbon::parse($latestTemplateUpdate)->format('d/m/Y') : 'N/A';
    @endphp

    <div class="container-fluid rt-page">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <div class="rt-muted small mb-2">Phòng quay / <strong class="text-dark">Danh sách phòng mẫu</strong></div>
                <h2 class="rt-title">Danh sách phòng mẫu</h2>
                <div class="rt-muted">Quản lý và sử dụng các phòng mẫu để tạo phòng quay nhanh chóng.</div>
            </div>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                <i class="fa-solid fa-plus me-1"></i> Tạo phòng mẫu mới
            </button>
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

        <div class="rt-toolbar mb-4">
            <div class="rt-stats">
                <div class="rt-stat">
                    <span class="rt-icon"><i data-lucide="layout-template"></i></span>
                    <span>
                        <span class="rt-stat-label d-block">Tổng số phòng mẫu</span>
                        <span class="rt-stat-value">{{ number_format((int) ($expectedRooms ?? $schedule->rooms_per_day ?? 0)) }}</span>
                    </span>
                </div>
                <div class="rt-stat">
                    <span class="rt-icon is-green"><i data-lucide="ticket"></i></span>
                    <span>
                        <span class="rt-stat-label d-block">Ticket tối thiểu/phòng</span>
                        <span class="rt-stat-value">{{ number_format((int) $schedule->required_votes) }} vé</span>
                    </span>
                </div>
                <div class="rt-stat">
                    <span class="rt-icon is-purple"><i data-lucide="boxes"></i></span>
                    <span>
                        <span class="rt-stat-label d-block">Bộ mẫu mặc định</span>
                        <span class="rt-stat-value">{{ str_pad((string) ($selectedTemplateCount ?? 0), 2, '0', STR_PAD_LEFT) }}</span>
                    </span>
                </div>
                <div class="rt-stat">
                    <span class="rt-icon is-amber"><i data-lucide="calendar-clock"></i></span>
                    <span>
                        <span class="rt-stat-label d-block">Cập nhật gần nhất</span>
                        <span class="rt-stat-value">{{ $latestDate }}</span>
                    </span>
                </div>
            </div>
        </div>

        <form method="GET" action="{{ route('admin.room-templates.index') }}" class="rt-filter">
            <div class="input-group">
                <span class="input-group-text"><i data-lucide="search"></i></span>
                <input type="search" name="q" class="form-control" value="{{ $filters['q'] ?? '' }}"
                    placeholder="Tìm kiếm tên bộ mẫu...">
            </div>
            <select name="ticket_type_code" class="form-select">
                <option value="">Loại vé áp dụng: Tất cả</option>
                <option value="ALL_TYPES" {{ ($filters['ticket_type_code'] ?? '') === 'ALL_TYPES' ? 'selected' : '' }}>Tất cả loại vé</option>
                @foreach ($ticketTypes as $ticketType)
                    @php
                        $ticketTypeCode = strtoupper(trim((string) ($ticketType['code'] ?? '')));
                        $ticketTypeName = trim((string) ($ticketType['name'] ?? ''));
                    @endphp
                    @if ($ticketTypeCode !== '' && $ticketTypeName !== '')
                        <option value="{{ $ticketTypeCode }}" {{ ($filters['ticket_type_code'] ?? '') === $ticketTypeCode ? 'selected' : '' }}>
                            {{ $ticketTypeName }} ({{ $ticketTypeCode }})
                        </option>
                    @endif
                @endforeach
            </select>
            <select name="status" class="form-select">
                <option value="">Trạng thái: Tất cả</option>
                @foreach (['WAITING' => 'WAITING', 'READY' => 'READY', 'DRAFT' => 'DRAFT'] as $statusValue => $statusLabel)
                    <option value="{{ $statusValue }}" {{ ($filters['status'] ?? '') === $statusValue ? 'selected' : '' }}>{{ $statusLabel }}</option>
                @endforeach
            </select>
            <div class="d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-outline-primary">Lọc</button>
                <a href="{{ route('admin.room-templates.index') }}" class="btn btn-outline-secondary">
                    <i data-lucide="refresh-cw"></i>
                </a>
            </div>
        </form>

        <div class="d-flex justify-content-end align-items-center gap-2 mb-3">
            <label class="form-label mb-0" for="run_target_date">Ngày chạy mẫu</label>
            <input id="run_target_date" type="date" class="form-control" value="{{ $defaultTargetDate }}"
                style="max-width: 180px;" data-run-date-source>
        </div>

        <div class="rt-panel">
            <div class="table-responsive">
                <table class="table rt-table align-middle">
                    <thead>
                        <tr>
                            <th style="width: 70px;">STT</th>
                            <th>Tên bộ mẫu</th>
                            <th style="width: 180px;">Loại vé áp dụng</th>
                            <th style="width: 110px;">Số phòng</th>
                            <th style="width: 180px;">Ticket tối thiểu/phòng</th>
                            <th style="width: 160px;">Bộ mẫu mặc định</th>
                            <th style="width: 170px;">Cập nhật lần cuối</th>
                            <th class="text-end" style="width: 190px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($templateSets as $templateSet)
                            @php
                                $rowIndex = ($templateSets->currentPage() - 1) * $templateSets->perPage() + $loop->iteration;
                                $templateCount = (int) ($templateSet->room_templates_count ?? collect($templateSet->roomTemplates ?? [])->count());
                                $isComplete = $templateCount === (int) ($expectedRooms ?? $schedule->rooms_per_day);
                                $isSelected = (string) $selectedTemplateSetId === (string) $templateSet->id;
                                $ticketCode = $templateSet->ticket_type_code ?: 'ALL_TYPES';
                            @endphp
                            <tr>
                                <td>{{ $rowIndex }}</td>
                                <td>
                                    <div class="fw-bold">{{ $templateSet->name }}</div>
                                    <div class="mt-1">
                                        @if ($isComplete)
                                            <span class="rt-badge is-green"><i data-lucide="check"></i> Mẫu đủ</span>
                                        @else
                                            <span class="rt-badge is-gray">Thiếu mẫu</span>
                                        @endif
                                    </div>
                                </td>
                                <td>{{ $ticketCode }}</td>
                                <td><span class="rt-badge">{{ number_format($templateCount) }}</span></td>
                                <td>{{ number_format((int) $templateSet->required_votes) }} vé</td>
                                <td>
                                    @if ($isSelected)
                                        <span class="rt-badge is-green"><i data-lucide="check-circle"></i> Đã chọn</span>
                                    @else
                                        <span class="rt-badge is-gray"><i data-lucide="circle"></i> Chưa chọn</span>
                                    @endif
                                </td>
                                <td>{{ optional($templateSet->updated_at)->format('d/m/Y H:i') ?? 'N/A' }}</td>
                                <td class="text-end">
                                    <div class="rt-action-row">
                                        <a href="{{ route('admin.room-templates.show', $templateSet->id) }}" class="rt-icon-btn" title="Xem bộ mẫu">
                                            <i data-lucide="eye"></i>
                                        </a>
                                        <form method="POST" action="{{ route('admin.room-templates.select', $templateSet->id) }}">
                                            @csrf
                                            <button type="submit" class="rt-icon-btn" title="Chọn làm mặc định" {{ $isSelected || !$isComplete ? 'disabled' : '' }}>
                                                <i data-lucide="check-circle"></i>
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.room-templates.run', $templateSet->id) }}"
                                            onsubmit="return confirm('Chạy bộ mẫu {{ $templateSet->name }} để tạo phòng quay thật?');">
                                            @csrf
                                            <input type="hidden" name="target_date" value="{{ $defaultTargetDate }}" data-run-target-date>
                                            <button type="submit" class="rt-icon-btn" title="Chạy mẫu tạo phòng thật" {{ $isComplete ? '' : 'disabled' }}>
                                                <i data-lucide="play"></i>
                                            </button>
                                        </form>
                                        <a href="{{ route('admin.room-templates.edit', $templateSet->id) }}" class="rt-icon-btn" title="Sửa bộ mẫu">
                                            <i data-lucide="pencil"></i>
                                        </a>
                                        <form method="POST" action="{{ route('admin.room-templates.destroy', $templateSet->id) }}"
                                            onsubmit="return confirm('Xóa bộ phòng mẫu {{ $templateSet->name }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="rt-icon-btn is-danger" title="Xóa bộ mẫu">
                                                <i data-lucide="trash-2"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center rt-muted py-5">
                                    Chưa có bộ phòng mẫu. Hãy tạo bộ mẫu đầu tiên từ cấu hình hiện tại.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rt-pagination">
            <div class="rt-muted small">
                Hiển thị {{ $templateSets->firstItem() ?? 0 }} - {{ $templateSets->lastItem() ?? 0 }} trong tổng số {{ $templateSets->total() }} phòng mẫu
            </div>
            <div>
                {{ $templateSets->links('pagination::bootstrap-5') }}
            </div>
        </div>
    </div>

    <div class="modal fade" id="createTemplateModal" tabindex="-1" aria-labelledby="createTemplateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="{{ route('admin.room-templates.store') }}" method="POST">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="createTemplateModalLabel">Tạo phòng mẫu mới</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="template_name">Tên bộ mẫu</label>
                            <input id="template_name" type="text" name="name" class="form-control"
                                value="{{ old('name') }}" placeholder="VD: Mẫu phòng Ticket - Giải thưởng tiêu chuẩn">
                        </div>
                        <div>
                            <label class="form-label" for="template_ticket_type_code">Loại vé áp dụng</label>
                            <select id="template_ticket_type_code" name="ticket_type_code" class="form-select" required>
                                <option value="ALL_TYPES">Tất cả loại vé</option>
                                @foreach ($ticketTypes as $ticketType)
                                    @php
                                        $ticketTypeCode = strtoupper(trim((string) ($ticketType['code'] ?? '')));
                                        $ticketTypeName = trim((string) ($ticketType['name'] ?? ''));
                                    @endphp
                                    @if ($ticketTypeCode !== '' && $ticketTypeName !== '')
                                        <option value="{{ $ticketTypeCode }}" {{ old('ticket_type_code', $defaultTicketTypeCode) === $ticketTypeCode ? 'selected' : '' }}>
                                            {{ $ticketTypeName }} ({{ $ticketTypeCode }})
                                        </option>
                                    @endif
                                @endforeach
                            </select>
                            <div class="form-text">
                                Hệ thống sẽ tạo {{ number_format((int) ($expectedRooms ?? $schedule->rooms_per_day)) }} phòng mẫu theo cấu hình hiện tại.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Đóng</button>
                        <button type="submit" class="btn btn-primary">Tạo phòng mẫu</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('scripts')
    <script>
        (function() {
            var runDateSource = document.querySelector('[data-run-date-source]');

            if (runDateSource) {
                runDateSource.addEventListener('change', function() {
                    document.querySelectorAll('[data-run-target-date]').forEach(function(input) {
                        input.value = runDateSource.value;
                    });
                });
            }

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        })();
    </script>
@endsection
