@extends('admin.layout')

@section('styles')
    <style>
        .rts-page {
            color: #12213f;
        }

        .rts-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .rts-muted {
            color: #6b7890;
        }

        .rts-panel {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.06);
            margin-bottom: 18px;
        }

        .rts-panel__body {
            padding: 22px;
        }

        .rts-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .rts-summary-item {
            border: 1px solid #dfe7f1;
            border-radius: 8px;
            padding: 15px;
        }

        .rts-label {
            color: #6b7890;
            font-size: 0.84rem;
            margin-bottom: 5px;
        }

        .rts-value {
            font-weight: 700;
        }

        .rts-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 999px;
            background: #eef5ff;
            color: #0d6efd;
            font-size: 0.76rem;
            font-weight: 700;
        }

        .rts-badge.is-green {
            background: #e9f9ef;
            color: #16a34a;
        }

        .rts-badge.is-warn {
            background: #fff7e6;
            color: #b45309;
        }

        .rts-table-wrap {
            max-height: 560px;
            overflow: auto;
            border: 1px solid #dfe7f1;
            border-radius: 8px;
        }

        .rts-table-wrap::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .rts-table-wrap::-webkit-scrollbar-thumb {
            background: #c7d2e2;
            border-radius: 999px;
        }

        .rts-table-wrap::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .rts-table {
            min-width: 1100px;
            margin-bottom: 0;
        }

        .rts-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #f8fafc;
            color: #12213f;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
            padding: 12px 14px;
        }

        .rts-table td {
            padding: 12px 14px;
            vertical-align: middle;
        }

        @media (max-width: 991px) {
            .rts-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 575px) {
            .rts-summary {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $templates = collect($roomTemplate->roomTemplates ?? [])->sortBy('room_number')->values();
        $templateCount = (int) ($roomTemplate->room_templates_count ?? $templates->count());
        $isComplete = $templateCount === (int) $expectedRooms;
        $isSelected = (string) ($selectedTemplateSetId ?? '') === (string) $roomTemplate->id;
        $distribution = $templates
            ->groupBy(function ($template) {
                return $template->prizeTemplate?->prize_name ?: ($template->prize_type ?: 'N/A');
            })
            ->map(fn ($rows) => $rows->count())
            ->sortDesc();
    @endphp

    <div class="container-fluid rts-page">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
            <div>
                <div class="rts-muted small mb-2">
                    Phòng quay /
                    <a href="{{ route('admin.room-templates.index') }}">Danh sách phòng mẫu</a> /
                    <strong class="text-dark">Xem chi tiết</strong>
                </div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h2 class="rts-title mb-0">{{ $roomTemplate->name }}</h2>
                    <span class="rts-badge">{{ $roomTemplate->status ?: 'WAITING' }}</span>
                    <span class="rts-badge {{ $isComplete ? 'is-green' : 'is-warn' }}">
                        {{ $isComplete ? 'Đủ ' . number_format($expectedRooms) . ' phòng' : 'Thiếu mẫu' }}
                    </span>
                    @if ($isSelected)
                        <span class="rts-badge is-green">Đang được chọn</span>
                    @endif
                </div>
                <div class="rts-muted mt-2">Kiểm tra toàn bộ phòng, ticket và giải thưởng trong bộ mẫu.</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="{{ route('admin.room-schedules.index', ['step' => 2]) }}" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-1"></i> Quay lại Step 2
                </a>
                <a href="{{ route('admin.room-templates.edit', $roomTemplate->id) }}" class="btn btn-primary">
                    <i class="fa-solid fa-pen me-1"></i> Sửa phòng mẫu
                </a>
            </div>
        </div>

        <div class="rts-panel">
            <div class="rts-panel__body">
                <div class="rts-summary">
                    <div class="rts-summary-item">
                        <div class="rts-label">Tổng số phòng</div>
                        <div class="rts-value">{{ number_format($templateCount) }}</div>
                    </div>
                    <div class="rts-summary-item">
                        <div class="rts-label">Ticket tối thiểu/phòng</div>
                        <div class="rts-value">{{ number_format((int) $roomTemplate->required_votes) }} vé</div>
                    </div>
                    <div class="rts-summary-item">
                        <div class="rts-label">Loại vé</div>
                        <div class="rts-value">{{ $roomTemplate->ticket_type_code ?: 'ALL_TYPES' }}</div>
                    </div>
                    <div class="rts-summary-item">
                        <div class="rts-label">Ngày chạy gần nhất</div>
                        <div class="rts-value">{{ optional($roomTemplate->last_target_date)->format('Y-m-d') ?? 'Chưa chạy' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="rts-panel">
            <div class="rts-panel__body">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-3">
                    <div>
                        <h5 class="mb-1 fw-bold">Danh sách phòng mẫu</h5>
                        <div class="rts-muted">Hiển thị đầy đủ {{ number_format($templateCount) }} phòng trong bộ mẫu.</div>
                    </div>
                </div>

                <div class="rts-table-wrap">
                    <table class="table table-sm rts-table align-middle">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Phòng</th>
                                <th>Tên mẫu</th>
                                <th style="width: 120px;">Trạng thái</th>
                                <th style="width: 140px;">Loại vé</th>
                                <th style="width: 150px;">Ticket/phòng</th>
                                <th>Giải thưởng</th>
                                <th class="text-end" style="width: 160px;">Giá trị</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($templates as $template)
                                <tr>
                                    <td><strong>#{{ (int) $template->room_number }}</strong></td>
                                    <td>{{ $template->room_name ?: ('Phòng mẫu ' . (int) $template->room_number) }}</td>
                                    <td><span class="rts-badge">{{ $template->status ?: 'WAITING' }}</span></td>
                                    <td>{{ $template->ticket_type_code ?: 'ALL_TYPES' }}</td>
                                    <td>{{ number_format((int) $template->required_votes) }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $template->prizeTemplate?->prize_name ?: $template->prize_type }}</div>
                                        @if (!empty($template->prize_description))
                                            <div class="rts-muted small">{{ $template->prize_description }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        {{ $template->prize_value !== null ? number_format((float) $template->prize_value, 0, ',', '.') . ' đ' : 'N/A' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="text-center rts-muted py-4">Chưa có phòng mẫu.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="rts-panel">
            <div class="rts-panel__body">
                <h5 class="mb-3 fw-bold">Giải thưởng mẫu</h5>
                <div class="d-flex flex-wrap gap-2">
                    @forelse ($distribution as $prizeName => $count)
                        <span class="rts-badge">{{ number_format((int) $count) }} x {{ $prizeName }}</span>
                    @empty
                        <span class="rts-muted">Chưa có dữ liệu giải thưởng mẫu.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
