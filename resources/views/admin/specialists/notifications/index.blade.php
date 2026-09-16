@extends('admin.layout')

@section('styles')
    <style>
        /* Collapsible card arrow transition */
        .mobile-collapsible-card .card-header .transition-icon {
            transition: transform 0.2s ease-in-out;
        }
        .mobile-collapsible-card .card-header:not(.collapsed) .transition-icon {
            transform: rotate(180deg);
        }

        /* Adjust horizontal padding on mobile to make lists wider */
        @@media (max-width: 767.98px) {
            .page-inner {
                padding-left: 12px !important;
                padding-right: 12px !important;
                padding-top: 12px !important;
            }
            .container-fluid {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
        }

        /* Responsive Mobile Notification Card Styles (Hidden on desktop via Bootstrap classes) */
        .mobile-notification-card {
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(0, 0, 0, 0.05);
            padding: 16px;
            margin-bottom: 16px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            background-color: #ffffff;
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
        }

        .mobile-notification-card:active {
            transform: scale(0.98);
        }

        .mobile-notification-card-main {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .mobile-notification-icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .mobile-notification-content {
            flex-grow: 1;
            min-width: 0;
        }

        .mobile-notification-badge-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }

        .mobile-notification-type-badge {
            font-size: 0.72rem;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 6px;
            background-color: rgba(0, 0, 0, 0.04);
            color: #475569;
            text-transform: lowercase;
        }

        .mobile-notification-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 4px;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .mobile-notification-message {
            font-size: 0.85rem;
            color: #475569;
            margin-bottom: 0;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .mobile-notification-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 4px;
            padding-top: 10px;
            border-top: 1px solid rgba(0, 0, 0, 0.04);
        }

        .mobile-notification-time {
            font-size: 0.75rem;
            color: #94a3b8;
            font-weight: 500;
        }

        .mobile-notification-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mobile-unread-dot {
            width: 8px;
            height: 8px;
            background-color: #007bff;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.2);
        }

        .mobile-btn-action {
            font-size: 0.75rem;
            font-weight: 600;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .mobile-btn-action:focus {
            outline: none;
            box-shadow: none;
        }
        .mobile-btn-action-read {
            color: #16a34a;
        }
        .mobile-btn-action-unread {
            color: #64748b;
        }

        /* Default fallback styling */
        .mobile-notification-card.type-default {
            background-color: #ffffff;
            border-color: rgba(71, 85, 105, 0.12);
        }
        .mobile-notification-card.type-default .mobile-notification-icon-wrapper {
            background-color: #f1f5f9;
            color: #475569;
        }
        .mobile-notification-card.type-default .mobile-notification-type-badge {
            background-color: rgba(71, 85, 105, 0.08);
            color: #475569;
        }

        /* Type-specific Themes based on Mockup */
        .mobile-notification-card.type-credit_changed {
            background-color: #fff9eb;
            border-color: rgba(217, 119, 6, 0.12);
        }
        .mobile-notification-card.type-credit_changed .mobile-notification-icon-wrapper {
            background-color: #fef3c7;
            color: #d97706;
        }
        .mobile-notification-card.type-credit_changed .mobile-notification-type-badge {
            background-color: rgba(217, 119, 6, 0.08);
            color: #b45309;
        }

        .mobile-notification-card.type-settlement_created {
            background-color: #f5f8ff;
            border-color: rgba(37, 99, 235, 0.12);
        }
        .mobile-notification-card.type-settlement_created .mobile-notification-icon-wrapper {
            background-color: #eff6ff;
            color: #2563eb;
        }
        .mobile-notification-card.type-settlement_created .mobile-notification-type-badge {
            background-color: rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
        }

        .mobile-notification-card.type-income_paid {
            background-color: #f4fbf6;
            border-color: rgba(22, 163, 74, 0.12);
        }
        .mobile-notification-card.type-income_paid .mobile-notification-icon-wrapper {
            background-color: #e6f7eb;
            color: #16a34a;
        }
        .mobile-notification-card.type-income_paid .mobile-notification-type-badge {
            background-color: rgba(22, 163, 74, 0.08);
            color: #15803d;
        }

        .mobile-notification-card.type-income_approved {
            background-color: #f4fbf6;
            border-color: rgba(22, 163, 74, 0.12);
        }
        .mobile-notification-card.type-income_approved .mobile-notification-icon-wrapper {
            background-color: #e6f7eb;
            color: #16a34a;
        }
        .mobile-notification-card.type-income_approved .mobile-notification-type-badge {
            background-color: rgba(22, 163, 74, 0.08);
            color: #15803d;
        }

        .mobile-notification-card.type-store_assigned {
            background-color: #faf5ff;
            border-color: rgba(124, 58, 237, 0.12);
        }
        .mobile-notification-card.type-store_assigned .mobile-notification-icon-wrapper {
            background-color: #f3e8ff;
            color: #7c3aed;
        }
        .mobile-notification-card.type-store_assigned .mobile-notification-type-badge {
            background-color: rgba(124, 58, 237, 0.08);
            color: #6d28d9;
        }

        .mobile-notification-card.type-first_order {
            background-color: #f0fdfa;
            border-color: rgba(13, 148, 136, 0.12);
        }
        .mobile-notification-card.type-first_order .mobile-notification-icon-wrapper {
            background-color: #ccfbf1;
            color: #0d9488;
        }
        .mobile-notification-card.type-first_order .mobile-notification-type-badge {
            background-color: rgba(13, 148, 136, 0.08);
            color: #0f766e;
        }

        .mobile-notification-card.type-abnormal_ticket {
            background-color: #fff5f5;
            border-color: rgba(220, 38, 38, 0.12);
        }
        .mobile-notification-card.type-abnormal_ticket .mobile-notification-icon-wrapper {
            background-color: #fee2e2;
            color: #dc2626;
        }
        .mobile-notification-card.type-abnormal_ticket .mobile-notification-type-badge {
            background-color: rgba(220, 38, 38, 0.08);
            color: #b91c1c;
        }

        .mobile-notification-card.type-task_new {
            background-color: #f7feff;
            border-color: rgba(8, 145, 178, 0.12);
        }
        .mobile-notification-card.type-task_new .mobile-notification-icon-wrapper {
            background-color: #cffafe;
            color: #0891b2;
        }
        .mobile-notification-card.type-task_new .mobile-notification-type-badge {
            background-color: rgba(8, 145, 178, 0.08);
            color: #0e7490;
        }

        .mobile-notification-card.type-rating_new {
            background-color: #fefce8;
            border-color: rgba(202, 138, 4, 0.12);
        }
        .mobile-notification-card.type-rating_new .mobile-notification-icon-wrapper {
            background-color: #fef9c3;
            color: #ca8a04;
        }
        .mobile-notification-card.type-rating_new .mobile-notification-type-badge {
            background-color: rgba(202, 138, 4, 0.08);
            color: #a16207;
        }
    </style>
@endsection

@section('content')
    @php
        $notificationThemes = [
            'credit_changed' => [
                'icon' => 'fas fa-bell',
                'class' => 'type-credit_changed'
            ],
            'settlement_created' => [
                'icon' => 'fas fa-file-invoice',
                'class' => 'type-settlement_created'
            ],
            'income_paid' => [
                'icon' => 'fas fa-wallet',
                'class' => 'type-income_paid'
            ],
            'income_approved' => [
                'icon' => 'fas fa-check-circle',
                'class' => 'type-income_approved'
            ],
            'store_assigned' => [
                'icon' => 'fas fa-store',
                'class' => 'type-store_assigned'
            ],
            'first_order' => [
                'icon' => 'fas fa-shopping-cart',
                'class' => 'type-first_order'
            ],
            'abnormal_ticket' => [
                'icon' => 'fas fa-exclamation-circle',
                'class' => 'type-abnormal_ticket'
            ],
            'task_new' => [
                'icon' => 'fas fa-tasks',
                'class' => 'type-task_new'
            ],
            'rating_new' => [
                'icon' => 'fas fa-star',
                'class' => 'type-rating_new'
            ]
        ];
        $defaultTheme = [
            'icon' => 'fas fa-bell',
            'class' => 'type-default'
        ];
    @endphp

    <div class="container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h1 class="mb-1">{{ $isSaleUser ? 'Thông báo của tôi' : 'Thông báo chuyên viên' }}</h1>
                <div class="text-muted">Thông báo này chỉ dành cho chuyên viên sale, tách riêng với thông báo admin hệ thống.</div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge bg-warning text-dark">Chưa đọc: {{ $unreadCount ?? 0 }}</span>
                @if ($isSaleUser && ($unreadCount ?? 0) > 0)
                    <form method="POST" action="{{ route('admin.specialist-notifications.mark-all-read') }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Đánh dấu tất cả đã đọc</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card mb-3 mobile-collapsible-card">
            <div class="card-header d-flex justify-content-between align-items-center d-md-none collapsed" data-bs-toggle="collapse" data-bs-target="#filterFormCollapse" style="cursor: pointer; background: transparent; border: none; padding: 12px 16px;">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-sliders text-dark"></i>
                    <span class="fw-semibold text-dark">Bộ lọc</span>
                </div>
                <i class="fa-solid fa-chevron-down text-muted transition-icon"></i>
            </div>
            <div class="collapse d-md-block" id="filterFormCollapse">
                <div class="card-body pt-0 pt-md-3">
                    <form method="GET" action="{{ route('admin.specialist-notifications.index') }}" class="row g-3 align-items-end">
                        @if (! $isSaleUser)
                            <div class="col-md-3">
                                <label class="form-label">ID chuyên viên</label>
                                <input type="number" class="form-control" name="specialist_id" value="{{ $filters['specialist_id'] }}">
                            </div>
                        @endif
                        <div class="col-md-4">
                            <label class="form-label">Loại thông báo</label>
                            <select class="form-control" name="type">
                                <option value="all">Tất cả</option>
                                @foreach ($typeLabels as $type => $label)
                                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Trạng thái</label>
                            <select class="form-control" name="read_status">
                                <option value="all" @selected($filters['read_status'] === 'all')>Tất cả</option>
                                <option value="unread" @selected($filters['read_status'] === 'unread')>Chưa đọc</option>
                                <option value="read" @selected($filters['read_status'] === 'read')>Đã đọc</option>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-fill">Lọc</button>
                            <a href="{{ route('admin.specialist-notifications.index') }}" class="btn btn-outline-secondary flex-fill text-center">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        @if ($isSaleUser)
            <!-- Desktop Layout -->
            <div class="card d-none d-md-block">
                <div class="card-body table-responsive p-0">
                    <table class="table table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 180px;">Loại</th>
                                <th>Nội dung</th>
                                {{-- <th style="width: 160px;">Dữ liệu liên quan</th> --}}
                                <th style="width: 130px;">Trạng thái</th>
                                <th style="width: 170px;">Thời gian</th>
                                <th style="width: 240px;" class="text-end">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($notifications as $notification)
                                @php
                                    $isRead = (bool) ($notification->is_read ?? false);
                                    $data = is_array($notification->data ?? null) ? $notification->data : [];
                                    $dataSummary = collect($data)
                                        ->only(['store_id', 'order_id', 'settlement_id', 'task_id', 'rating_id'])
                                        ->filter(fn ($value) => $value !== null && $value !== '')
                                        ->map(fn ($value, $key) => $key . ': ' . $value)
                                        ->implode(' | ');
                                @endphp
                                <tr class="{{ ! $isRead ? 'table-warning' : '' }}">
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            {{ $typeLabels[$notification->type] ?? $notification->type }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $notification->title }}</div>
                                        <div class="text-muted">{{ $notification->message }}</div>
                                    </td>
                                    {{-- <td>
                                        @if ($dataSummary !== '')
                                            <small class="text-muted">{{ $dataSummary }}</small>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td> --}}
                                    <td>
                                        @if ($isRead)
                                            <span class="badge bg-success">Đã đọc</span>
                                        @else
                                            <span class="badge bg-warning text-dark">Chưa đọc</span>
                                        @endif
                                    </td>
                                    <td>{{ optional($notification->created_at)->format('d/m/Y H:i') }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                            <a href="{{ route('admin.specialist-notifications.open', $notification->id) }}" class="btn btn-sm btn-outline-primary">Mở</a>
                                            @if ($isSaleUser && ! $isRead)
                                                <form method="POST" action="{{ route('admin.specialist-notifications.mark-read', $notification->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Đã đọc</button>
                                                </form>
                                            @elseif ($isSaleUser)
                                                <form method="POST" action="{{ route('admin.specialist-notifications.mark-unread', $notification->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Chưa đọc</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Chưa có thông báo chuyên viên.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($notifications, 'links'))
                    <div class="card-footer">
                        {{ $notifications->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>

            <!-- Mobile Layout -->
            <div class="d-block d-md-none">
                <div class="mobile-notifications-list">
                    @forelse ($notifications as $notification)
                        @php
                            $isRead = (bool) ($notification->is_read ?? false);
                            $data = is_array($notification->data ?? null) ? $notification->data : [];
                            $dataSummary = collect($data)
                                ->only(['store_id', 'order_id', 'settlement_id', 'task_id', 'rating_id'])
                                ->filter(fn ($value) => $value !== null && $value !== '')
                                ->map(fn ($value, $key) => $key . ': ' . $value)
                                ->implode(' | ');
                            $theme = $notificationThemes[$notification->type] ?? $defaultTheme;
                        @endphp
                        <div class="mobile-notification-card {{ $theme['class'] }}">
                            <div class="mobile-notification-card-main">
                                <div class="mobile-notification-icon-wrapper">
                                    <i class="{{ $theme['icon'] }}"></i>
                                </div>
                                <div class="mobile-notification-content">
                                    <div class="mobile-notification-badge-row">
                                        <span class="mobile-notification-type-badge">
                                            {{ $typeLabels[$notification->type] ?? $notification->type }}
                                        </span>
                                    </div>
                                    <a href="{{ route('admin.specialist-notifications.open', $notification->id) }}" class="text-decoration-none text-dark d-block">
                                        <h5 class="mobile-notification-title">{{ $notification->title }}</h5>
                                        <p class="mobile-notification-message">{{ $notification->message }}</p>
                                    </a>
                                    {{-- @if ($dataSummary !== '')
                                        <div class="mt-1">
                                            <small class="text-muted" style="font-size: 0.72rem; word-break: break-all;">
                                                <i class="fas fa-info-circle me-1"></i>{{ $dataSummary }}
                                            </small>
                                        </div>
                                    @endif --}}
                                </div>
                            </div>
                            <div class="mobile-notification-footer">
                                <span class="mobile-notification-time">
                                    <i class="far fa-clock me-1"></i>{{ optional($notification->created_at)->format('H:i d/m/Y') }}
                                </span>
                                <div class="mobile-notification-actions">
                                    @if (!$isRead)
                                        <form method="POST" action="{{ route('admin.specialist-notifications.mark-read', $notification->id) }}" class="m-0">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                                            <button type="submit" class="mobile-btn-action mobile-btn-action-read">
                                                <i class="fas fa-check me-1"></i>Đã đọc
                                            </button>
                                        </form>
                                        <span class="mobile-unread-dot"></span>
                                    @else
                                        <form method="POST" action="{{ route('admin.specialist-notifications.mark-unread', $notification->id) }}" class="m-0">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                                            <button type="submit" class="mobile-btn-action mobile-btn-action-unread">
                                                Chưa đọc
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="card py-5 text-center text-muted">
                            Chưa có thông báo chuyên viên.
                        </div>
                    @endforelse
                </div>

                @if (!$notifications->hasMorePages() && $notifications->count() > 0)
                    <div class="text-center text-muted py-3 my-2" style="font-size: 0.85rem;">
                        Không còn thông báo
                    </div>
                @endif

                @if (method_exists($notifications, 'links') && $notifications->hasPages())
                    <div class="d-flex justify-content-center my-4">
                        {{ $notifications->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        @else
            <!-- Non-specialist view (Table everywhere) -->
            <div class="card">
                <div class="card-body table-responsive p-0">
                    <table class="table table-striped align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width: 180px;">Loại</th>
                                <th>Nội dung</th>
                                {{-- <th style="width: 160px;">Dữ liệu liên quan</th> --}}
                                <th style="width: 130px;">Trạng thái</th>
                                <th style="width: 170px;">Thời gian</th>
                                <th style="width: 240px;" class="text-end">Hành động</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($notifications as $notification)
                                @php
                                    $isRead = (bool) ($notification->is_read ?? false);
                                    $data = is_array($notification->data ?? null) ? $notification->data : [];
                                    $dataSummary = collect($data)
                                        ->only(['store_id', 'order_id', 'settlement_id', 'task_id', 'rating_id'])
                                        ->filter(fn ($value) => $value !== null && $value !== '')
                                        ->map(fn ($value, $key) => $key . ': ' . $value)
                                        ->implode(' | ');
                                @endphp
                                <tr class="{{ ! $isRead ? 'table-warning' : '' }}">
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            {{ $typeLabels[$notification->type] ?? $notification->type }}
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $notification->title }}</div>
                                        <div class="text-muted">{{ $notification->message }}</div>
                                    </td>
                                    {{-- <td>
                                        @if ($dataSummary !== '')
                                            <small class="text-muted">{{ $dataSummary }}</small>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td> --}}
                                    <td>
                                        @if ($isRead)
                                            <span class="badge bg-success">Đã đọc</span>
                                        @else
                                            <span class="badge bg-warning text-dark">Chưa đọc</span>
                                        @endif
                                    </td>
                                    <td>{{ optional($notification->created_at)->format('d/m/Y H:i') }}</td>
                                    <td class="text-end">
                                        <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                                            <a href="{{ route('admin.specialist-notifications.open', $notification->id) }}" class="btn btn-sm btn-outline-primary">Mở</a>
                                            @if ($isSaleUser && ! $isRead)
                                                <form method="POST" action="{{ route('admin.specialist-notifications.mark-read', $notification->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Đã đọc</button>
                                                </form>
                                            @elseif ($isSaleUser)
                                                <form method="POST" action="{{ route('admin.specialist-notifications.mark-unread', $notification->id) }}">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="redirect_url" value="{{ request()->fullUrl() }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Chưa đọc</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Chưa có thông báo chuyên viên.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($notifications, 'links'))
                    <div class="card-footer">
                        {{ $notifications->onEachSide(1)->links('pagination::bootstrap-5') }}
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection

