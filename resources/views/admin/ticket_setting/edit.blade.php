@extends('admin.layout')

@section('content')
@php
    $ticketTypesOld  = old('ticket_types');
    $ticketTypesRows = (is_array($ticketTypesOld) && !empty($ticketTypesOld))
        ? array_values($ticketTypesOld)
        : ($ticketTypes ?? []);
    $selectedDefaultType = old('ticket_default_type', $defaultTicketTypeCode ?? ($ticketTypesRows[0]['code'] ?? ''));
@endphp

<style>
a.bn-btn-back, a.ts-btn-back { color:var(--text-muted) !important; text-decoration:none; }
a.bn-btn-back:hover, a.ts-btn-back:hover { color:var(--accent) !important; text-decoration:none; }

/* ── Fix layout: page-inner full-width ───────────────────────── */
.page-inner { padding: 20px 28px !important; max-width: 100% !important; }

/* ── Design tokens (đồng bộ sidebar) ─────────────────────────── */
:root {
    --accent:       #6366f1;
    --accent-light: #ede9fe;
    --accent-dark:  #4f46e5;
    --bg-page:      #f1f5f9;
    --bg-card:      #ffffff;
    --border:       #e2e8f0;
    --text-main:    #1e293b;
    --text-sub:     #64748b;
    --text-muted:   #94a3b8;
    --radius-card:  14px;
    --radius-input: 8px;
    --shadow-card:  0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
}

/* ── Page wrapper ─────────────────────────────────────────────── */
.ts-wrap { padding: 4px 0 48px; }

/* ── Page header ──────────────────────────────────────────────── */
.ts-page-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.ts-page-header-left { display: flex; align-items: center; gap: 14px; }
.ts-page-header-icon {
    width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(99,102,241,.3);
}
.ts-page-header h1 { font-size: 20px; font-weight: 700; color: var(--text-main); margin: 0; }
.ts-page-header p  { font-size: 13px; color: var(--text-sub); margin: 2px 0 0; }

/* ── Alert ────────────────────────────────────────────────────── */
.ts-alert {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 16px; border-radius: 10px; margin-bottom: 20px;
    font-size: 13px; font-weight: 500;
}
.ts-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.ts-alert.error   { background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; }

/* ── 2-column grid ────────────────────────────────────────────── */
.ts-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 20px;
    align-items: start;
}
@media (max-width: 1280px) { .ts-grid { grid-template-columns: 1fr; } }

/* ── Card ─────────────────────────────────────────────────────── */
.ts-card {
    background: var(--bg-card); border-radius: var(--radius-card);
    border: 1px solid var(--border); box-shadow: var(--shadow-card);
    overflow: hidden;
}
.ts-card + .ts-card { margin-top: 20px; }
.ts-card-header {
    padding: 16px 20px 13px; border-bottom: 1px solid #f8fafc;
    display: flex; align-items: center; gap: 10px;
}
.ts-card-header .ch-icon {
    width: 30px; height: 30px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ts-card-header h2 { font-size: 14px; font-weight: 600; color: var(--text-main); margin: 0; }
.ts-card-header p  { font-size: 12px; color: var(--text-muted); margin: 2px 0 0; }
.ts-card-body { padding: 18px 20px; }

/* ── Table ────────────────────────────────────────────────────── */
.ts-table { width: 100%; border-collapse: collapse; }
.ts-table thead th {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .5px; color: var(--text-muted);
    padding: 0 10px 10px; border-bottom: 2px solid #f1f5f9;
}
.ts-table tbody tr { border-bottom: 1px solid #f8fafc; transition: background .12s; }
.ts-table tbody tr:last-child { border-bottom: none; }
.ts-table tbody tr:hover { background: #fafbff; }
.ts-table td { padding: 8px 10px; vertical-align: middle; }

/* ── Inputs ───────────────────────────────────────────────────── */
.ts-input {
    border: 1px solid var(--border); border-radius: var(--radius-input);
    padding: 8px 11px; font-size: 13px; color: var(--text-main);
    background: #fff; width: 100%; box-sizing: border-box;
    transition: border .18s, box-shadow .18s;
}
.ts-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,.12); }
.ts-input.mono { font-family: 'Courier New', monospace; font-size: 12px; font-weight: 700; letter-spacing: .5px; }
.ts-input-wrap { position: relative; }
.ts-input-wrap .suffix {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    font-size: 11px; color: var(--text-muted); font-weight: 600; pointer-events: none;
}
.ts-input-wrap .prefix {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--text-muted); font-size: 13px; pointer-events: none;
}
.ts-input-wrap .ts-input.has-prefix { padding-left: 32px; }
.ts-input-wrap .ts-input.has-suffix { padding-right: 36px; }

/* ── Select ───────────────────────────────────────────────────── */
.ts-select {
    border: 1px solid var(--border); border-radius: var(--radius-input);
    padding: 9px 32px 9px 11px; font-size: 13px; color: var(--text-main);
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center;
    width: 100%; appearance: none; box-sizing: border-box;
    transition: border .18s, box-shadow .18s;
}
.ts-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99,102,241,.12); }

/* ── Field ────────────────────────────────────────────────────── */
.ts-field { margin-bottom: 16px; }
.ts-field:last-child { margin-bottom: 0; }
.ts-field > label {
    display: block; font-size: 11px; font-weight: 700; color: #475569;
    margin-bottom: 6px; text-transform: uppercase; letter-spacing: .4px;
}
.ts-field .hint { font-size: 12px; color: var(--text-muted); margin-top: 5px; display: flex; align-items: center; gap: 5px; }

/* ── Buttons ──────────────────────────────────────────────────── */
.btn-add-row {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 16px; border-radius: 9px;
    border: 1.5px dashed #c7d2fe; background: #f5f3ff;
    color: var(--accent); font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .18s;
}
.btn-add-row:hover { background: var(--accent-light); border-color: var(--accent); }

.btn-del-row {
    width: 28px; height: 28px; border-radius: 7px;
    border: 1px solid #fecaca; background: #fff5f5; color: #ef4444;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .18s; font-size: 12px;
}
.btn-del-row:hover { background: #fee2e2; border-color: #ef4444; }

.btn-save {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 22px; border-radius: 10px;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    color: #fff; font-size: 14px; font-weight: 600; border: none;
    cursor: pointer; transition: opacity .18s, transform .1s;
    box-shadow: 0 4px 14px rgba(99,102,241,.35); width: 100%;
    justify-content: center;
}
.btn-save:hover { opacity: .9; transform: translateY(-1px); }
.btn-save:active { transform: translateY(0); }

/* ── Expiry preview chip ──────────────────────────────────────── */
.expiry-chip {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 12px; background: #eff6ff; border-radius: 20px;
    font-size: 12px; color: #3b82f6; font-weight: 500; margin-top: 8px;
}

/* ── Default badge ────────────────────────────────────────────── */
.default-badge {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 10px; font-weight: 700; background: #dcfce7;
    color: #16a34a; padding: 2px 8px; border-radius: 20px;
    text-transform: uppercase; letter-spacing: .4px;
}

/* ── Stats row ────────────────────────────────────────────────── */
.ts-stats {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px;
    margin-bottom: 20px;
}
.ts-stat {
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 12px; padding: 14px 16px;
    box-shadow: var(--shadow-card);
}
.ts-stat .stat-val { font-size: 22px; font-weight: 700; color: var(--text-main); }
.ts-stat .stat-lbl { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.ts-stat .stat-icon {
    width: 34px; height: 34px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; margin-bottom: 10px;
}
</style>

<div class="ts-wrap">

    {{-- ── Page header ── --}}
    <div class="ts-page-header">
        <div class="ts-page-header-left">
            <div class="ts-page-header-icon">
                <i class="fas fa-ticket-alt" style="color:#fff;font-size:18px;"></i>
            </div>
            <div>
                <h1>Cấu hình Ticket</h1>
                <p>Quản lý loại vé, giá bán và thời hạn sử dụng</p>
            </div>
        </div>
        <button type="submit" form="ticketSettingForm" class="btn-save" style="width:auto;padding:10px 24px;">
            <i class="fas fa-save"></i> Lưu cấu hình
        </button>
    </div>

    {{-- Flash --}}
    @if(session('success'))
        <div class="ts-alert success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="ts-alert error"><i class="fas fa-exclamation-circle"></i> {{ $errors->first() }}</div>
    @endif

    {{-- ── Stats ── --}}
    <div class="ts-stats">
        <div class="ts-stat">
            <div class="stat-icon" style="background:#ede9fe;">
                <i class="fas fa-layer-group" style="color:#7c3aed;"></i>
            </div>
            <div class="stat-val">{{ count($ticketTypesRows) }}</div>
            <div class="stat-lbl">Loại Ticket</div>
        </div>
        <div class="ts-stat">
            <div class="stat-icon" style="background:#fef9c3;">
                <i class="fas fa-star" style="color:#ca8a04;"></i>
            </div>
            <div class="stat-val" id="statDefault">{{ $selectedDefaultType ?: '—' }}</div>
            <div class="stat-lbl">Loại mặc định</div>
        </div>
        <div class="ts-stat">
            <div class="stat-icon" style="background:#eff6ff;">
                <i class="fas fa-clock" style="color:#3b82f6;"></i>
            </div>
            <div class="stat-val">{{ $setting->ticket_expiry_days ?? 30 }}<span style="font-size:13px;font-weight:400;color:var(--text-muted);"> ngày</span></div>
            <div class="stat-lbl">Thời hạn hiện tại</div>
        </div>
    </div>

    <form id="ticketSettingForm" action="{{ route('admin.ticket-settings.update') }}" method="POST">
        @csrf
        @method('PUT')

        <div class="ts-grid">

            {{-- ── Cột trái: Danh sách loại Ticket ── --}}
            <div>
                <div class="ts-card">
                    <div class="ts-card-header">
                        <div class="ch-icon" style="background:#ede9fe;">
                            <i class="fas fa-layer-group" style="color:#7c3aed;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Danh sách loại Ticket</h2>
                            <p>Mã định danh, tên hiển thị và giá bán</p>
                        </div>
                    </div>
                    <div class="ts-card-body" style="padding-bottom: 4px;">
                        <div style="overflow-x:auto;">
                            <table class="ts-table" id="ticketTypesTable">
                                <thead>
                                    <tr>
                                        <th style="width:22%">Mã loại</th>
                                        <th style="width:40%">Tên hiển thị</th>
                                        <th style="width:28%">Giá (VND)</th>
                                        <th style="width:10%;text-align:center;">Xóa</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($ticketTypesRows as $index => $ticketType)
                                    <tr class="ticket-type-row">
                                        <td>
                                            <input type="text"
                                                name="ticket_types[{{ $index }}][code]"
                                                class="ts-input mono ticket-code-input"
                                                maxlength="50"
                                                value="{{ $ticketType['code'] ?? '' }}"
                                                placeholder="VE_A">
                                        </td>
                                        <td>
                                            <input type="text"
                                                name="ticket_types[{{ $index }}][name]"
                                                class="ts-input"
                                                maxlength="120"
                                                value="{{ $ticketType['name'] ?? '' }}"
                                                placeholder="Ticket A - 10k">
                                        </td>
                                        <td>
                                            <div class="ts-input-wrap">
                                                <input type="number"
                                                    name="ticket_types[{{ $index }}][price]"
                                                    class="ts-input price-input has-suffix"
                                                    min="0" step="1"
                                                    value="{{ $ticketType['price'] ?? 0 }}">
                                                <span class="suffix">₫</span>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn-del-row remove-ticket-type" title="Xóa">
                                                <i class="fas fa-trash-alt" style="pointer-events:none;"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    @empty
                                    <tr class="ticket-type-row">
                                        <td><input type="text" name="ticket_types[0][code]" class="ts-input mono ticket-code-input" maxlength="50" placeholder="VE_A"></td>
                                        <td><input type="text" name="ticket_types[0][name]" class="ts-input" maxlength="120" placeholder="Ticket A - 10k"></td>
                                        <td>
                                            <div class="ts-input-wrap">
                                                <input type="number" name="ticket_types[0][price]" class="ts-input price-input has-suffix" min="0" step="1" placeholder="10000">
                                                <span class="suffix">₫</span>
                                            </div>
                                        </td>
                                        <td style="text-align:center;">
                                            <button type="button" class="btn-del-row remove-ticket-type" title="Xóa">
                                                <i class="fas fa-trash-alt" style="pointer-events:none;"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div style="padding:12px 20px 18px;">
                        <button type="button" class="btn-add-row" id="addTicketTypeBtn">
                            <i class="fas fa-plus"></i> Thêm loại Ticket
                        </button>
                    </div>
                </div>
            </div>

            {{-- ── Cột phải: Cài đặt ── --}}
            <div>
                {{-- Card: Loại mặc định --}}
                <div class="ts-card">
                    <div class="ts-card-header">
                        <div class="ch-icon" style="background:#fef9c3;">
                            <i class="fas fa-star" style="color:#ca8a04;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Loại Ticket mặc định</h2>
                            <p>Dùng khi cửa hàng chưa chọn loại</p>
                        </div>
                    </div>
                    <div class="ts-card-body">
                        <div class="ts-field">
                            <label>Chọn loại mặc định</label>
                            <select name="ticket_default_type" id="ticket_default_type" class="ts-select">
                                {{-- filled by JS --}}
                            </select>
                            <div class="hint">
                                <i class="fas fa-info-circle" style="color:var(--accent);"></i>
                                Áp dụng tự động khi không có loại nào được chỉ định.
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Card: Thời hạn --}}
                <div class="ts-card" style="margin-top:16px;">
                    <div class="ts-card-header">
                        <div class="ch-icon" style="background:#eff6ff;">
                            <i class="fas fa-clock" style="color:#3b82f6;font-size:12px;"></i>
                        </div>
                        <div>
                            <h2>Thời hạn Ticket</h2>
                            <p>Số ngày hiệu lực kể từ khi phát</p>
                        </div>
                    </div>
                    <div class="ts-card-body">
                        <div class="ts-field" style="margin-bottom:0;">
                            <label>Số ngày hiệu lực</label>
                            <div class="ts-input-wrap">
                                <input type="number" name="ticket_expiry_days" id="ticket_expiry_days"
                                    class="ts-input has-suffix"
                                    min="1" max="3650"
                                    value="{{ old('ticket_expiry_days', $setting->ticket_expiry_days ?? 30) }}">
                                <span class="suffix">ngày</span>
                            </div>
                            <div class="expiry-chip" id="expiryChip">
                                <i class="fas fa-calendar-check"></i>
                                <span id="expiryText">Hiệu lực 30 ngày</span>
                            </div>
                            <div class="hint" style="margin-top:8px;">
                                <i class="fas fa-info-circle" style="color:var(--accent);"></i>
                                Chỉ ảnh hưởng Ticket phát sau khi lưu.
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Nút lưu --}}
                <div style="margin-top:16px;">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Lưu cấu hình
                    </button>
                </div>

                {{-- Note --}}
                <div style="margin-top:12px;padding:12px 14px;background:#f8fafc;border-radius:10px;border:1px solid var(--border);">
                    <div style="font-size:12px;color:var(--text-muted);display:flex;align-items:flex-start;gap:7px;">
                        <i class="fas fa-shield-alt" style="color:var(--accent);margin-top:1px;flex-shrink:0;"></i>
                        <span>Thay đổi cấu hình chỉ ảnh hưởng đến Ticket mới. Ticket đã phát giữ nguyên loại và thời hạn cũ.</span>
                    </div>
                </div>
            </div>

        </div>{{-- /ts-grid --}}
    </form>
</div>

<script>
(function () {
    const tableBody     = document.querySelector('#ticketTypesTable tbody');
    const addBtn        = document.getElementById('addTicketTypeBtn');
    const defaultSelect = document.getElementById('ticket_default_type');
    const expiryInput   = document.getElementById('ticket_expiry_days');
    const expiryText    = document.getElementById('expiryText');
    const statDefault   = document.getElementById('statDefault');

    const INITIAL_DEFAULT = '{{ $selectedDefaultType }}';

    // ── Expiry preview ──────────────────────────────────────────
    function updateExpiry() {
        const d = parseInt(expiryInput.value, 10);
        if (!d || d < 1) { expiryText.textContent = 'Nhập số ngày hợp lệ'; return; }
        expiryText.textContent = d >= 365
            ? `Hiệu lực ~${(d/365).toFixed(1).replace('.0','')} năm`
            : `Hiệu lực ${d} ngày`;
    }
    expiryInput.addEventListener('input', updateExpiry);
    updateExpiry();

    // ── Default select ──────────────────────────────────────────
    function refreshDefaultOptions() {
        const cur = defaultSelect.value || INITIAL_DEFAULT;
        const codes = [];
        tableBody.querySelectorAll('.ticket-code-input').forEach(inp => {
            const c = (inp.value || '').trim().toUpperCase();
            if (c && !codes.includes(c)) codes.push(c);
        });
        defaultSelect.innerHTML = '';
        if (!codes.length) {
            const o = document.createElement('option');
            o.value = ''; o.textContent = 'Chưa có loại Ticket';
            defaultSelect.appendChild(o); return;
        }
        codes.forEach(c => {
            const o = document.createElement('option');
            o.value = c; o.textContent = c;
            if (c === cur) o.selected = true;
            defaultSelect.appendChild(o);
        });
        if (statDefault) statDefault.textContent = defaultSelect.value || '—';
    }

    defaultSelect.addEventListener('change', () => {
        if (statDefault) statDefault.textContent = defaultSelect.value || '—';
    });

    // ── Row template ────────────────────────────────────────────
    function rowTpl(i) {
        return `<tr class="ticket-type-row">
            <td><input type="text" name="ticket_types[${i}][code]" class="ts-input mono ticket-code-input" maxlength="50" placeholder="VE_A"></td>
            <td><input type="text" name="ticket_types[${i}][name]" class="ts-input" maxlength="120" placeholder="Ticket A - 10k"></td>
            <td><div class="ts-input-wrap"><input type="number" name="ticket_types[${i}][price]" class="ts-input price-input has-suffix" min="0" step="1" placeholder="10000"><span class="suffix">₫</span></div></td>
            <td style="text-align:center;"><button type="button" class="btn-del-row remove-ticket-type" title="Xóa"><i class="fas fa-trash-alt" style="pointer-events:none;"></i></button></td>
        </tr>`;
    }

    function reindex() {
        tableBody.querySelectorAll('tr.ticket-type-row').forEach((row, idx) => {
            row.querySelectorAll('input').forEach(inp => {
                if (inp.name) inp.name = inp.name.replace(/ticket_types\[\d+\]/, `ticket_types[${idx}]`);
            });
        });
    }

    // ── Events ──────────────────────────────────────────────────
    addBtn.addEventListener('click', () => {
        const idx = tableBody.querySelectorAll('tr.ticket-type-row').length;
        tableBody.insertAdjacentHTML('beforeend', rowTpl(idx));
        refreshDefaultOptions();
        tableBody.querySelector('tr.ticket-type-row:last-child .ticket-code-input').focus();
    });

    tableBody.addEventListener('click', e => {
        const btn = e.target.closest('.remove-ticket-type');
        if (!btn) return;
        if (tableBody.querySelectorAll('tr.ticket-type-row').length <= 1) return;
        btn.closest('tr.ticket-type-row').remove();
        reindex(); refreshDefaultOptions();
    });

    tableBody.addEventListener('input', e => {
        if (e.target.classList.contains('ticket-code-input')) {
            e.target.value = e.target.value.toUpperCase().replace(/\s+/g, '_');
            refreshDefaultOptions();
        }
    });

    tableBody.addEventListener('blur', e => {
        if (e.target.classList.contains('price-input')) {
            const v = parseFloat(e.target.value);
            if (!isNaN(v)) e.target.value = Math.round(v);
        }
    }, true);

    refreshDefaultOptions();
})();
</script>
@endsection
