@extends('admin.layout')

@section('content')

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap');

        :root {
            --pa-bg: unset;
            --pa-surface: #FFFFFF;
            --pa-border: #E4E2DA;
            --pa-border-strong: #C8C5BB;
            --pa-text: #1A1916;
            --pa-muted: #78756C;
            --pa-subtle: #A8A49B;
            --pa-accent: #2A6041;
            --pa-accent-bg: #EDF3EF;
            --pa-accent-text: #1C4730;
            --pa-warn-bg: #FDF6E3;
            --pa-warn-text: #7A5C1E;
            --pa-warn-border: #EDD98A;
            --pa-danger-bg: #FCF0EE;
            --pa-danger-text: #8B2A1E;
            --pa-danger-border: #F0B9B3;
            --pa-mono: 'DM Mono', monospace;
            --pa-radius: 10px;
            --pa-radius-lg: 14px;
        }

        .pa-wrap {
            /* font-family: 'Plus Jakarta Sans', sans-serif; */
            background: var(--pa-bg);
            min-height: 100vh;
            /* padding: 2rem 1.5rem 3rem; */
            color: var(--pa-text);
        }

        /* ── Header ── */
        .pa-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .pa-header h1 {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.3px;
            margin-bottom: 4px;
        }

        .pa-header p {
            font-size: 13px;
            color: var(--pa-muted);
        }

        .pa-btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            color: var(--pa-muted);
            background: var(--pa-surface);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            cursor: pointer;
            text-decoration: none;
            transition: background .15s, color .15s, border-color .15s;
            white-space: nowrap;
        }

        .pa-btn-back:hover {
            background: var(--pa-border);
            color: var(--pa-text);
            border-color: var(--pa-border-strong);
        }

        .pa-btn-back svg {
            flex-shrink: 0;
        }

        /* ── Alert ── */
        .pa-alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: var(--pa-radius);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 1.25rem;
        }

        .pa-alert-success {
            background: var(--pa-accent-bg);
            color: var(--pa-accent-text);
            border: 1px solid #B4D4C0;
        }

        .pa-alert-danger {
            background: var(--pa-danger-bg);
            color: var(--pa-danger-text);
            border: 1px solid var(--pa-danger-border);
        }

        /* ── Layout grid ── */
        .pa-layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 1.25rem;
            align-items: start;
        }

        @media (max-width: 900px) {
            .pa-layout {
                grid-template-columns: 1fr;
            }
        }

        /* ── Card ── */
        .pa-card {
            background: var(--pa-surface);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius-lg);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
        }

        .pa-card:last-child {
            margin-bottom: 0;
        }

        .pa-card-title {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--pa-subtle);
            margin-bottom: 1rem;
        }

        /* ── Product header ── */
        .pa-product-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .pa-product-name {
            font-size: 20px;
            font-weight: 600;
            letter-spacing: -0.3px;
            margin-bottom: 4px;
        }

        .pa-sku {
            font-size: 12px;
            color: var(--pa-subtle);
            margin-bottom: 8px;
        }

        .pa-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .3px;
            padding: 4px 10px;
            border-radius: 20px;
        }

        .pa-badge-pending {
            background: var(--pa-warn-bg);
            color: var(--pa-warn-text);
            border: 1px solid var(--pa-warn-border);
        }

        .pa-badge-success {
            background: var(--pa-accent-bg);
            color: var(--pa-accent-text);
            border: 1px solid #B4D4C0;
        }

        .pa-badge-danger {
            background: var(--pa-danger-bg);
            color: var(--pa-danger-text);
            border: 1px solid var(--pa-danger-border);
        }

        .pa-badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }

        .pa-price-block {
            text-align: right;
        }

        .pa-price {
            font-size: 18px;
            font-weight: 600;
            letter-spacing: -0.3px;
        }

        .pa-price-sub {
            font-size: 12px;
            color: var(--pa-muted);
            margin-top: 2px;
        }

        /* ── Divider ── */
        .pa-divider {
            border: none;
            border-top: 1px solid var(--pa-border);
            margin: 1.1rem 0;
        }

        /* ── Info boxes ── */
        .pa-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .875rem;
        }

        @media (max-width: 600px) {
            .pa-info-grid {
                grid-template-columns: 1fr;
            }
        }

        .pa-info-box {
            background: var(--pa-bg);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            padding: .875rem 1rem;
        }

        .pa-info-box-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--pa-subtle);
            margin-bottom: .5rem;
        }

        .pa-info-box-val {
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 2px;
        }

        .pa-info-box-sub {
            font-size: 12px;
            color: var(--pa-muted);
            line-height: 1.5;
        }

        .pa-info-box-danger {
            font-size: 12px;
            color: var(--pa-danger-text);
            margin-top: 4px;
        }

        /* ── Metric cards ── */
        .pa-metric-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .875rem;
        }

        @media (max-width: 600px) {
            .pa-metric-grid {
                grid-template-columns: 1fr;
            }
        }

        .pa-metric {
            background: var(--pa-bg);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            padding: .875rem 1rem;
            text-align: center;
        }

        .pa-metric-label {
            font-size: 11px;
            color: var(--pa-muted);
            font-weight: 500;
            margin-bottom: 6px;
        }

        .pa-metric-val {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }

        .pa-metric-sub {
            font-size: 11px;
            color: var(--pa-subtle);
            margin-top: 3px;
        }

        .pa-metric-val.green {
            color: var(--pa-accent);
        }

        /* ── Content preview ── */
        .pa-content-view {
            background: var(--pa-bg);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            padding: .875rem 1rem;
            min-height: 80px;
            max-height: 240px;
            overflow-y: auto;
            font-size: 13.5px;
            line-height: 1.75;
            color: var(--pa-text);
        }

        /* ── Image grid ── */
        .pa-img-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: .75rem;
        }

        @media (max-width: 500px) {
            .pa-img-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .pa-img-thumb {
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            padding: .5rem;
            background: var(--pa-bg);
            text-align: center;
        }

        .pa-img-thumb-label {
            font-size: 11px;
            color: var(--pa-subtle);
            margin-bottom: .4rem;
        }

        .pa-img-thumb img {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 7px;
            display: block;
        }

        /* ── Action panel ── */
        .pa-action-btn {
            width: 100%;
            padding: 11px 16px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            border-radius: var(--pa-radius);
            cursor: pointer;
            transition: opacity .15s, transform .1s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }

        .pa-action-btn:hover {
            opacity: .88;
        }

        .pa-action-btn:active {
            transform: scale(.98);
        }

        .pa-btn-approve {
            background: var(--pa-accent);
            color: #fff;
            margin-bottom: .875rem;
        }

        .pa-btn-reject {
            background: var(--pa-danger-bg);
            color: var(--pa-danger-text);
            border: 1px solid var(--pa-danger-border);
            margin-top: .625rem;
        }

        .pa-form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--pa-muted);
            margin-bottom: 6px;
        }

        .pa-textarea {
            width: 100%;
            padding: 9px 12px;
            font-size: 13px;
            color: var(--pa-text);
            background: var(--pa-bg);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            resize: vertical;
            transition: border-color .15s;
            line-height: 1.6;
            box-sizing: border-box;
        }

        .pa-textarea:focus {
            outline: none;
            border-color: var(--pa-border-strong);
        }

        .pa-alert-info {
            background: #EEF2FF;
            color: #3B4BB3;
            border: 1px solid #C5CBEC;
            border-radius: var(--pa-radius);
            padding: 11px 14px;
            font-size: 13px;
            font-weight: 500;
        }

        /* ── Trace card ── */
        .pa-trace-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: .5rem;
            padding: .45rem 0;
            font-size: 12.5px;
            border-bottom: 1px solid var(--pa-border);
        }

        .pa-trace-row:last-child {
            border-bottom: none;
        }

        .pa-trace-key {
            color: var(--pa-muted);
        }

        .pa-trace-val {
            font-size: 11.5px;
            color: var(--pa-text);
            text-align: right;
            max-width: 60%;
            word-break: break-all;
        }

        .pa-history-current {
            background: var(--pa-accent-bg);
            border: 1px solid #B4D4C0;
            color: var(--pa-accent-text);
            border-radius: var(--pa-radius);
            padding: .75rem .875rem;
            font-size: 13px;
            margin-bottom: .875rem;
        }

        .pa-history-list {
            display: flex;
            flex-direction: column;
            gap: .75rem;
        }

        .pa-history-item {
            background: var(--pa-bg);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius);
            padding: .875rem;
        }

        .pa-history-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            margin-bottom: .5rem;
        }

        .pa-history-attempt {
            font-size: 13px;
            font-weight: 700;
            color: var(--pa-text);
        }

        .pa-history-status {
            font-size: 11px;
            font-weight: 700;
            border-radius: 999px;
            padding: 3px 8px;
            white-space: nowrap;
        }

        .pa-history-status.pending {
            background: var(--pa-warn-bg);
            color: var(--pa-warn-text);
            border: 1px solid var(--pa-warn-border);
        }

        .pa-history-status.approved {
            background: var(--pa-accent-bg);
            color: var(--pa-accent-text);
            border: 1px solid #B4D4C0;
        }

        .pa-history-status.rejected {
            background: var(--pa-danger-bg);
            color: var(--pa-danger-text);
            border: 1px solid var(--pa-danger-border);
        }

        .pa-history-meta {
            font-size: 12px;
            color: var(--pa-muted);
            line-height: 1.55;
        }

        .pa-history-reason {
            font-size: 12px;
            color: var(--pa-danger-text);
            background: var(--pa-danger-bg);
            border-radius: 8px;
            padding: .5rem .625rem;
            margin-top: .5rem;
            line-height: 1.55;
        }

        /* ── Variants table ── */
        .pa-full-card {
            background: var(--pa-surface);
            border: 1px solid var(--pa-border);
            border-radius: var(--pa-radius-lg);
            overflow: hidden;
            margin-top: 1.25rem;
        }

        .pa-full-card-head {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--pa-border);
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--pa-subtle);
        }

        .pa-tbl-wrap {
            overflow-x: auto;
        }

        .pa-tbl {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .pa-tbl th {
            padding: 10px 14px;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--pa-subtle);
            background: var(--pa-bg);
            border-bottom: 1px solid var(--pa-border);
            white-space: nowrap;
        }

        .pa-tbl td {
            padding: 11px 14px;
            border-bottom: 1px solid var(--pa-border);
            vertical-align: middle;
        }

        .pa-tbl tr:last-child td {
            border-bottom: none;
        }

        .pa-tbl tr:hover td {
            background: var(--pa-bg);
        }

        .pa-tbl-variant {
            font-weight: 500;
            min-width: 200px;
        }

        .pa-tbl-price {
            font-size: 12.5px;
        }

        .pa-tbl-stock {
            font-size: 12.5px;
            font-weight: 500;
        }

        .pa-tbl-commission {
            font-size: 12.5px;
            color: var(--pa-accent);
            font-weight: 500;
        }

        .pa-ticket-qty {
            font-weight: 600;
            display: block;
        }

        .pa-ticket-sub {
            font-size: 11px;
            color: var(--pa-muted);
            display: block;
            margin-top: 1px;
        }

        .pa-ticket-val {
            font-size: 11px;
            color: var(--pa-accent);
            display: block;
        }

        .pa-tbl-img {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--pa-border);
            display: block;
        }

        .pa-empty {
            padding: 2.5rem;
            text-align: center;
            color: var(--pa-muted);
            font-size: 13px;
        }

        /* ── Scrollbar ── */
        .pa-content-view::-webkit-scrollbar,
        .pa-tbl-wrap::-webkit-scrollbar {
            width: 5px;
            height: 5px;
        }

        .pa-content-view::-webkit-scrollbar-track,
        .pa-tbl-wrap::-webkit-scrollbar-track {
            background: transparent;
        }

        .pa-content-view::-webkit-scrollbar-thumb,
        .pa-tbl-wrap::-webkit-scrollbar-thumb {
            background: var(--pa-border-strong);
            border-radius: 99px;
        }
    </style>

    <div class="pa-wrap">

        {{-- Header --}}
        <div class="pa-header">
            <div>
                <h1>Duyệt sản phẩm</h1>
                <p>Kiểm tra nội dung, hình ảnh và biến thể trước khi quyết định duyệt hoặc từ chối.</p>
            </div>
            <a href="{{ request('return_store_id') ? route('admin.stores.edit', ['store' => request('return_store_id'), 'active_tab' => request('active_tab', 'store-product-approvals')]) : route('admin.stores.index') }}"
                class="pa-btn-back">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                    <path d="M19 12H5M12 5l-7 7 7 7" />
                </svg>
                Quay lại danh sách
            </a>
        </div>

        {{-- Alerts --}}
        @if (session('success'))
            <div class="pa-alert pa-alert-success">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5">
                    <path d="M20 6L9 17l-5-5" />
                </svg>
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="pa-alert pa-alert-danger">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2.5">
                    <circle cx="12" cy="12" r="10" />
                    <line x1="12" y1="8" x2="12" y2="12" />
                    <line x1="12" y1="16" x2="12.01" y2="16" />
                </svg>
                <div>
                    @foreach ($errors->all() as $error)
                        <div>{{ $error }}</div>
                    @endforeach
                </div>
            </div>
        @endif

        @php
            $approvalAttemptNumber = (int) ($product->approval_attempt_number ?? 1);
            $approvalHistories = $product->approvalHistories ?? collect();
        @endphp

        <div class="pa-layout">

            {{-- ── LEFT COLUMN ── --}}
            <div>

                {{-- Product summary --}}
                <div class="pa-card">
                    <div class="pa-product-head">
                        <div>
                            <div class="pa-product-name">{{ $product->name }}</div>
                            <div class="pa-sku">SKU: {{ $product->sku ?? 'N/A' }}</div>
                            @if ($product->status === 'pending_approval')
                                <span class="pa-badge pa-badge-pending"><span class="pa-badge-dot"></span> Chờ duyệt</span>
                            @elseif ($product->status === 'published')
                                <span class="pa-badge pa-badge-success"><span class="pa-badge-dot"></span> Đã duyệt</span>
                            @elseif ($product->status === 'rejected')
                                <span class="pa-badge pa-badge-danger"><span class="pa-badge-dot"></span> Từ chối</span>
                            @endif
                            <span class="pa-badge pa-badge-pending">L&#7847;n duy&#7879;t {{ $approvalAttemptNumber }}</span>
                        </div>
                        <div class="pa-price-block">
                            <div class="pa-price">{{ $product->price_label ?? 'N/A' }}</div>
                            <div class="pa-price-sub">{{ $product->variants->count() }} biến thể</div>
                        </div>
                    </div>

                    <hr class="pa-divider">

                    <div class="pa-info-grid">
                        <div class="pa-info-box">
                            <div class="pa-info-box-label">Cửa hàng</div>
                            <div class="pa-info-box-val">{{ optional($product->store)->name ?? 'N/A' }}</div>
                            <div class="pa-info-box-sub">
                                Mã: {{ optional($product->store)->store_code ?? 'N/A' }}<br>
                                Chủ:
                                {{ optional(optional($product->store)->owner)->full_name ?? (optional(optional($product->store)->owner)->name ?? 'N/A') }}
                            </div>
                        </div>
                        <div class="pa-info-box">
                            <div class="pa-info-box-label">Danh mục</div>
                            <div class="pa-info-box-val">{{ $product->category->name ?? 'N/A' }}</div>
                        </div>
                        <div class="pa-info-box">
                            <div class="pa-info-box-label">Trạng thái duyệt</div>
                            <div class="pa-info-box-val">{{ $product->approved_by ?? 'Chưa duyệt' }}</div>
                            <div class="pa-info-box-sub">
                                Ngày lên kệ: {{ optional($product->published_at)->format('d/m/Y H:i') ?? 'N/A' }}
                            </div>
                            @if ($product->rejection_reason)
                                <div class="pa-info-box-danger">{{ 'Lý do từ chối: ' . $product->rejection_reason }}</div>
                            @endif
                        </div>
                    </div>
                </div>

                @php
                    $approvalCommissionRate =
                        (float) ($product->approval_commission_rate ?? ($product->commission_rate ?? 0));
                    $approvalCommissionMode =
                        $product->approval_commission_mode ?? ($product->commission_rate_mode ?? 'percent');
                    $approvalTicketQuantity =
                        (int) ($product->approval_ticket_reward_quantity ?? ($product->ticket_reward_quantity ?? 0));
                    $approvalTicketTypeCode =
                        $product->approval_ticket_type_code ?? ($product->ticket_type_code ?? 'default');
                @endphp

                <div class="pa-card">
                    <div class="pa-card-title">Thông tin kinh doanh</div>
                    <div class="pa-metric-grid">
                        <div class="pa-metric">
                            <div class="pa-metric-label">Hoa hồng AFF</div>
                            <div class="pa-metric-val green">
                                @if ($approvalCommissionMode === 'fixed')
                                    {{ number_format($approvalCommissionRate, 0, ',', '.') }} đ
                                @else
                                    {{ number_format($approvalCommissionRate, 2, ',', '.') }}%
                                @endif
                            </div>
                            <div class="pa-metric-sub">
                                {{ $approvalCommissionMode === 'fixed' ? 'Hoa hồng cố định' : 'Tỷ lệ commission' }}</div>
                        </div>
                        <div class="pa-metric">
                            <div class="pa-metric-label">Lucky Ticket</div>
                            @if ($approvalTicketQuantity > 0)
                                <div class="pa-metric-val">+{{ $approvalTicketQuantity }}</div>
                                <div class="pa-metric-sub">{{ $approvalTicketTypeCode }}</div>
                            @else
                                <div class="pa-metric-val" style="font-size:14px;color:var(--pa-subtle)">Không áp dụng
                                </div>
                            @endif
                        </div>
                        <div class="pa-metric">
                            <div class="pa-metric-label">Ranking Score</div>
                            <div class="pa-metric-val">{{ $product->base_ranking_score ?? 0 }}</div>
                            <div class="pa-metric-sub">Điểm ưu tiên hiển thị</div>
                        </div>
                    </div>
                </div>

                {{-- Description --}}
                <div class="pa-card">
                    <div class="pa-card-title">Mô tả sản phẩm</div>
                    <textarea id="content" style="display:none">{{ $product->content }}</textarea>
                </div>

                {{-- Images --}}
                <div class="pa-card">
                    <div class="pa-card-title">Hình ảnh sản phẩm</div>
                    <div class="pa-img-grid">
                        @if ($product->thumbnail_url)
                            <div class="pa-img-thumb">
                                <div class="pa-img-thumb-label">Ảnh đại diện</div>
                                <img src="{{ $product->thumbnail_url }}" alt="Thumbnail">
                            </div>
                        @endif

                        @forelse ($product->image_urls as $imageUrl)
                            <div class="pa-img-thumb">
                                <div class="pa-img-thumb-label">Ảnh {{ $loop->iteration }}</div>
                                <img src="{{ $imageUrl }}" alt="Product image">
                            </div>
                        @empty
                            @if (!$product->thumbnail_url)
                                <div class="pa-empty" style="grid-column:1/-1">Không có ảnh nào.</div>
                            @endif
                        @endforelse
                    </div>
                </div>

            </div>

            {{-- ── RIGHT COLUMN ── --}}
            <div>

                <div class="pa-card">
                    <div class="pa-card-title">L&#7883;ch s&#7917; duy&#7879;t</div>
                    <div class="pa-history-current">
                        L&#7847;n duy&#7879;t hi&#7879;n t&#7841;i: <strong>{{ $approvalAttemptNumber }}</strong>
                    </div>

                    <div class="pa-history-list">
                        @forelse ($approvalHistories as $history)
                            @php
                                $historyStatus = mb_strtolower(trim((string) ($history->status ?? 'pending')));
                                $statusLabels = [
                                    'pending' => 'Ch&#7901; duy&#7879;t',
                                    'approved' => '&#272;&#227; duy&#7879;t',
                                    'rejected' => '&#272;&#227; t&#7915; ch&#7889;i',
                                ];
                                $submitterName = optional($history->submitter)->full_name
                                    ?? optional($history->submitter)->name
                                    ?? optional($history->submitter)->email;
                                $reviewerName = optional($history->reviewer)->full_name
                                    ?? optional($history->reviewer)->name
                                    ?? optional($history->reviewer)->email;
                            @endphp
                            <div class="pa-history-item">
                                <div class="pa-history-top">
                                    <span class="pa-history-attempt">L&#7847;n {{ (int) $history->attempt_number }}</span>
                                    <span class="pa-history-status {{ $historyStatus }}">{!! $statusLabels[$historyStatus] ?? e($historyStatus) !!}</span>
                                </div>
                                <div class="pa-history-meta">
                                    G&#7917;i l&#250;c:
                                    {{ optional($history->submitted_at)->format('d/m/Y H:i') ?? 'N/A' }}
                                    @if ($submitterName)
                                        - {{ $submitterName }}
                                    @endif
                                </div>
                                @if ($history->reviewed_at)
                                    <div class="pa-history-meta">
                                        X&#7917; l&#253; l&#250;c:
                                        {{ optional($history->reviewed_at)->format('d/m/Y H:i') ?? 'N/A' }}
                                        @if ($reviewerName)
                                            - {{ $reviewerName }}
                                        @endif
                                    </div>
                                @endif
                                @if ($history->rejection_reason)
                                    <div class="pa-history-reason">
                                        L&#253; do: {{ $history->rejection_reason }}
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="pa-alert-info">Ch&#432;a c&#243; l&#7883;ch s&#7917; duy&#7879;t.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Approval actions --}}
                <div class="pa-card">
                    <div class="pa-card-title">Hành động duyệt</div>

                    @if ($product->status === 'pending_approval')
                        <form method="POST" action="{{ route('admin.product-approvals.approve', $product) }}"
                            onsubmit="return confirm('Duyệt sản phẩm {{ addslashes($product->name) }} và cho phép lên kệ?');">
                            @csrf
                            @method('PATCH')
                            @if (request('return_store_id'))
                                <input type="hidden" name="return_store_id" value="{{ request('return_store_id') }}">
                                <input type="hidden" name="active_tab"
                                    value="{{ request('active_tab', 'store-product-approvals') }}">
                            @endif
                            <button type="submit" class="pa-action-btn pa-btn-approve">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5">
                                    <path d="M20 6L9 17l-5-5" />
                                </svg>
                                Duyệt sản phẩm
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.product-approvals.reject', $product) }}"
                            onsubmit="return confirm('Từ chối sản phẩm {{ addslashes($product->name) }}?');">
                            @csrf
                            @method('PATCH')
                            @if (request('return_store_id'))
                                <input type="hidden" name="return_store_id" value="{{ request('return_store_id') }}">
                                <input type="hidden" name="active_tab"
                                    value="{{ request('active_tab', 'store-product-approvals') }}">
                            @endif
                            <div style="margin-top: .25rem;">
                                <label class="pa-form-label">Lý do từ chối <span
                                        style="color:var(--pa-danger-text)">*</span></label>
                                <textarea name="rejection_reason" class="pa-textarea" rows="5" placeholder="Nhập lý do từ chối..." required>{{ old('rejection_reason') }}</textarea>
                            </div>
                            <button type="submit" class="pa-action-btn pa-btn-reject">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2.5">
                                    <line x1="18" y1="6" x2="6" y2="18" />
                                    <line x1="6" y1="6" x2="18" y2="18" />
                                </svg>
                                Từ chối sản phẩm
                            </button>
                        </form>
                    @else
                        <div class="pa-alert-info">Sản phẩm này hiện không ở trạng thái chờ duyệt.</div>
                    @endif
                </div>

                {{-- Trace data --}}
                <div class="pa-card">
                    <div class="pa-card-title">Dữ liệu truy vết</div>
                    <div class="pa-trace-row">
                        <span class="pa-trace-key">Approved by</span>
                        <span class="pa-trace-val">{{ $product->approved_by ?? 'N/A' }}</span>
                    </div>
                    <div class="pa-trace-row">
                        <span class="pa-trace-key">Approved at</span>
                        <span
                            class="pa-trace-val">{{ optional($product->approved_at)->format('d/m/Y H:i') ?? 'N/A' }}</span>
                    </div>
                    <div class="pa-trace-row">
                        <span class="pa-trace-key">Published at</span>
                        <span
                            class="pa-trace-val">{{ optional($product->published_at)->format('d/m/Y H:i') ?? 'N/A' }}</span>
                    </div>
                    <div class="pa-trace-row">
                        <span class="pa-trace-key">Rejection reason</span>
                        <span class="pa-trace-val">{{ $product->rejection_reason ?: 'N/A' }}</span>
                    </div>
                </div>

                @if (($product->topping_rows ?? collect())->count())
                    <div class="pa-card">

                        <div class="pa-card-title">

                            Lựa chọn đi kèm

                        </div>

                        @foreach ($product->topping_rows as $topping)
                            <div class="pa-trace-row">

                                <span class="pa-trace-key">

                                    {{ $topping['name'] }}

                                </span>

                                <span class="pa-trace-val">

                                    {{ number_format($topping['sale_price'] ?? 0) }}đ

                                </span>

                            </div>
                        @endforeach
                    </div>
                @endif



            </div>
        </div>

        {{-- ── Variants table (full width) ── --}}
        <div class="pa-full-card">
            <div class="pa-full-card-head">Biến thể sản phẩm</div>
            <div class="pa-tbl-wrap">
                <table class="pa-tbl">
                    <thead>
                        <tr>
                            <th>Biến thể</th>
                            <th>Giá gốc</th>
                            <th>Giá bán</th>
                            <th>Tồn kho</th>
                            <th>Hoa hồng</th>
                            <th>Ticket reward</th>
                            <th>Ảnh</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($variantRows as $variant)
                            <tr>
                                <td class="pa-tbl-variant">{{ $variant['label'] }}</td>
                                <td class="pa-tbl-price">
                                    {{ $variant['price'] !== null ? number_format($variant['price'], 0, ',', '.') . ' đ' : 'N/A' }}
                                </td>
                                <td class="pa-tbl-price">
                                    {{ $variant['sale_price'] !== null ? number_format($variant['sale_price'], 0, ',', '.') . ' đ' : 'N/A' }}
                                </td>
                                <td class="pa-tbl-stock">{{ $variant['stock'] ?? 0 }}</td>
                                <td class="pa-tbl-commission">
                                    @if ($variant['commission_rate'] !== null)
                                        @if (($variant['commission_rate_mode'] ?? 'percent') === 'fixed')
                                            {{ number_format($variant['commission_rate'], 0, ',', '.') }} đ
                                        @else
                                            {{ number_format($variant['commission_rate'], 2, ',', '.') }}%
                                        @endif
                                    @else
                                        N/A
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $ticketQty = $variant['ticket_reward_quantity'] ?? 0;
                                        $ticketType = $variant['ticket_type_code'] ?? 'DEFAULT';
                                        $ticketValue = 5000;
                                    @endphp

                                    @if ($ticketQty > 0)
                                        <span class="pa-ticket-qty">+{{ number_format($ticketQty) }}
                                            {{ strtoupper($ticketType) }}</span>
                                        <span class="pa-ticket-sub">{{ number_format($ticketValue) }}đ / 1 vé</span>
                                        <span class="pa-ticket-val">≈ {{ number_format($ticketQty * $ticketValue) }}đ
                                            reward</span>
                                    @else
                                        <span style="color:var(--pa-subtle);font-size:12px">Không có</span>
                                    @endif
                                </td>
                                <td>
                                    @if (!empty($variant['image_url']))
                                        <img src="{{ $variant['image_url'] }}" alt="Variant" class="pa-tbl-img">
                                    @else
                                        <span style="color:var(--pa-subtle);font-size:12px">N/A</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="pa-empty">Không có biến thể nào.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
    <script>
        ClassicEditor
            .create(document.querySelector('#content'), {
                toolbar: []
            })
            .then(editor => {
                editor.enableReadOnlyMode('product-preview');
                editor.editing.view.change(writer => {
                    const root = editor.editing.view.document.getRoot();
                    writer.setStyle('min-height', '50px', root);
                    writer.setStyle('max-height', '300px', root);
                    writer.setStyle('overflow-y', 'auto', root);
                });
            })
            .catch(error => {
                console.error(error);
            });
    </script>

@endsection
