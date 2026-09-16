{{-- ────────────────────────────────────────────────────────
     TAB — Nạp hạn mức (có bảo mật 2 lớp: Mã khóa + OTP)
──────────────────────────────────────────────────────────── --}}
<div class="se-tab-pane {{ $activeTab === 'store-limit-topup' ? 'active' : '' }}" id="pane-store-limit-topup" role="tabpanel">
    <div class="se-tab-header">
        <span class="se-tab-header-text">Thông tin tài chính &amp; Nạp hạn mức</span>
    </div>

    {{-- ── Financial Stats ─────────────────────────────────────── --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px;">
        <div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:15px;">
            <div style="font-size:12px;color:#666;font-weight:500;margin-bottom:5px;text-transform:uppercase;">Số dư ví</div>
            <div style="font-size:20px;font-weight:600;color:#1a6636;">
                {{ number_format((float)($store->wallet_balance ?? 0), 0, ',', '.') }}<span style="font-size:12px;font-weight:normal;color:#888;"> đ</span>
            </div>
        </div>
        <div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:15px;">
            <div style="font-size:12px;color:#666;font-weight:500;margin-bottom:5px;text-transform:uppercase;">Nợ phí Ticket</div>
            <div style="font-size:20px;font-weight:600;color:#c0392b;">
                {{ number_format((float)($store->ticket_debt ?? 0), 0, ',', '.') }}<span style="font-size:12px;font-weight:normal;color:#888;"> đ</span>
            </div>
        </div>
        <div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:15px;">
            <div style="font-size:12px;color:#666;font-weight:500;margin-bottom:5px;text-transform:uppercase;">Hạn mức nợ cho phép</div>
            <div style="font-size:20px;font-weight:600;color:#2980b9;">
                {{ number_format((float)($store->credit_limit ?? 0), 0, ',', '.') }}<span style="font-size:12px;font-weight:normal;color:#888;"> đ</span>
            </div>
        </div>
        <div style="background:#fafafa;border:1px solid #eee;border-radius:8px;padding:15px;">
            <div style="font-size:12px;color:#666;font-weight:500;margin-bottom:5px;text-transform:uppercase;">Trạng thái khóa nợ</div>
            <div style="margin-top:5px;">
                @if ($store->is_debt_locked)
                    <span style="display:inline-block;background:#fadbd8;color:#c0392b;font-size:13px;font-weight:600;padding:4px 10px;border-radius:4px;">Đang khóa nợ</span>
                @else
                    <span style="display:inline-block;background:#d4efdf;color:#1a6636;font-size:13px;font-weight:600;padding:4px 10px;border-radius:4px;">Đang hoạt động</span>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Security Flow Wrapper ───────────────────────────────── --}}
    <div id="topup-security-wrapper" style="max-width:620px;margin-bottom:30px;">

        {{-- STAGE 0: Locked state (always visible initially) --}}
        <div id="stage-locked" style="background:#fff;border:2px solid #e0e6ed;border-radius:10px;padding:28px 24px;text-align:center;">
            <div style="font-size:42px;margin-bottom:12px;">🔒</div>
            <h4 style="font-size:16px;font-weight:700;color:#333;margin:0 0 8px;">Chức năng được bảo vệ</h4>
            <p style="font-size:13px;color:#666;margin:0 0 20px;">Để thực hiện nạp hạn mức thủ công, bạn cần nhập <strong>Mã khóa truy cập</strong> do Admin tổng cấp.</p>

            <div style="max-width:320px;margin:0 auto;">
                <div style="position:relative;margin-bottom:12px;">
                    <input type="password"
                           id="topup-access-code"
                           autocomplete="off"
                           placeholder="Nhập mã khóa truy cập…"
                           style="width:100%;padding:10px 44px 10px 14px;border:1px solid #ccc;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    <span id="toggle-access-code"
                          style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:#888;font-size:16px;"
                          title="Hiện/Ẩn mã">👁</span>
                </div>
                <div id="access-code-error" style="color:#e74c3c;font-size:12.5px;margin-bottom:10px;display:none;"></div>
                <button id="btn-verify-access-code"
                        style="width:100%;background:#1a6636;color:#fff;border:none;padding:10px 0;font-weight:700;border-radius:6px;font-size:14px;cursor:pointer;transition:background 0.2s;">
                    Mở khóa &amp; Tiếp tục
                </button>
            </div>
        </div>

        {{-- STAGE 1: Top-up form + Send OTP --}}
        <div id="stage-form" style="display:none;">
            <div style="background:#fff;border:2px solid #27ae60;border-radius:10px;padding:24px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
                    <span style="font-size:22px;">🔓</span>
                    <h4 style="font-size:15px;font-weight:700;color:#1a6636;margin:0;">Thực hiện nạp hạn mức thủ công</h4>
                </div>
                <p style="font-size:13px;color:#555;margin:0 0 16px;">Điền thông tin bên dưới. Sau khi nhấn <strong>Gửi mã OTP</strong>, hệ thống sẽ gửi mã xác minh 1 lần đến email admin.</p>

                <div style="margin-bottom:15px;">
                    <label style="font-weight:600;color:#444;font-size:13px;display:block;margin-bottom:6px;">Số tiền nạp (VND) <span style="color:#e74c3c;">*</span></label>
                    <input type="number" id="topup-amount" min="1000" placeholder="Ví dụ: 500000"
                           style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px;box-sizing:border-box;">
                    <span style="font-size:11.5px;color:#888;margin-top:4px;display:block;">Tối thiểu 1.000 đ. Ưu tiên khấu trừ nợ phí ticket trước, dư sẽ cộng vào ví.</span>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="font-weight:600;color:#444;font-size:13px;display:block;margin-bottom:6px;">Lý do / Ghi chú <span style="color:#e74c3c;">*</span></label>
                    <textarea id="topup-note" rows="3"
                              style="width:100%;padding:9px 12px;border:1px solid #ccc;border-radius:5px;font-size:14px;resize:vertical;box-sizing:border-box;"
                              placeholder="Nhập lý do nạp tiền thủ công…"></textarea>
                </div>

                <div id="form-error" style="color:#e74c3c;font-size:12.5px;margin-bottom:10px;display:none;"></div>

                <button id="btn-send-otp"
                        style="background:#2980b9;color:#fff;border:none;padding:10px 22px;font-weight:700;border-radius:6px;font-size:14px;cursor:pointer;transition:background 0.2s;">
                    📨 Gửi mã OTP đến Email
                </button>
                <button id="btn-reset-form"
                        style="background:none;border:1px solid #ccc;color:#666;padding:10px 18px;font-size:13px;border-radius:6px;cursor:pointer;margin-left:10px;transition:all 0.2s;">
                    Hủy / Khóa lại
                </button>
            </div>
        </div>

        {{-- STAGE 2: OTP verification --}}
        <div id="stage-otp" style="display:none;">
            <div style="background:#fff;border:2px solid #2980b9;border-radius:10px;padding:24px;">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;">
                    <span style="font-size:22px;">📨</span>
                    <h4 style="font-size:15px;font-weight:700;color:#2980b9;margin:0;">Nhập mã OTP xác minh</h4>
                </div>

                <div id="otp-countdown-wrap" style="background:#eaf4fb;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px;color:#1a5276;">
                    Mã OTP đã được gửi đến email của bạn. Hiệu lực: <strong id="otp-timer">--:--</strong>
                </div>

                <div style="max-width:280px;margin-bottom:14px;">
                    <label style="font-weight:600;color:#444;font-size:13px;display:block;margin-bottom:6px;">Mã OTP <span style="color:#e74c3c;">*</span></label>
                    <input type="text" id="topup-otp" maxlength="10" autocomplete="off"
                           placeholder="Nhập mã OTP…"
                           style="width:100%;padding:10px 14px;border:1px solid #ccc;border-radius:5px;font-size:18px;letter-spacing:6px;text-align:center;box-sizing:border-box;">
                </div>

                <div id="otp-error" style="color:#e74c3c;font-size:12.5px;margin-bottom:12px;display:none;"></div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button id="btn-verify-otp"
                            style="background:#27ae60;color:#fff;border:none;padding:10px 22px;font-weight:700;border-radius:6px;font-size:14px;cursor:pointer;transition:background 0.2s;">
                        ✅ Xác nhận OTP &amp; Nạp hạn mức
                    </button>
                    <button id="btn-resend-otp"
                            style="background:none;border:1px solid #2980b9;color:#2980b9;padding:10px 16px;font-size:13px;border-radius:6px;cursor:pointer;transition:all 0.2s;">
                        🔄 Gửi lại OTP
                    </button>
                    <button id="btn-back-to-form"
                            style="background:none;border:1px solid #ccc;color:#666;padding:10px 16px;font-size:13px;border-radius:6px;cursor:pointer;transition:all 0.2s;">
                        ← Quay lại
                    </button>
                </div>
            </div>
        </div>

        {{-- Hidden final form (submitted programmatically) --}}
        <form id="final-topup-form" action="{{ route('admin.stores.manual-topup', $store->id) }}" method="POST" style="display:none;">
            @csrf
            <input type="hidden" name="amount"      id="hid-amount">
            <input type="hidden" name="note"        id="hid-note">
            <input type="hidden" name="otp_session" id="hid-otp-session">
        </form>
    </div>

    {{-- ── Transaction History ─────────────────────────────────── --}}
    <div class="se-tab-header" style="margin-top:10px;">
        <span class="se-tab-header-text">Lịch sử giao dịch nạp hạn mức thủ công</span>
    </div>

    <div class="se-table-card">
        <table class="se-table">
            <thead>
                <tr>
                    <th>Mã giao dịch</th>
                    <th>Số tiền nạp</th>
                    <th>Nợ trước/sau</th>
                    <th>Ví trước/sau</th>
                    <th>Ghi chú</th>
                    <th>Người thực hiện</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($manualTransactions as $tx)
                    @php
                        $walletBefore = (float)$tx->wallet_balance_before;
                        $walletAfter  = (float)$tx->wallet_balance_after;
                        $amount       = (float)$tx->amount;

                        $ticketTx        = $ticketTxMap[$tx->reference_code] ?? null;
                        $ticketDebtAfter = $ticketTx ? (float)$ticketTx->ticket_debt_after : null;
                        $debtDelta       = $ticketTx ? (float)$ticketTx->debt_delta : 0.0;
                        $ticketDebtBefore = $ticketDebtAfter !== null ? $ticketDebtAfter - $debtDelta : null;
                    @endphp
                    <tr>
                        <td style="color:#aaa;font-size:12px;font-family:monospace;">{{ $tx->id }}</td>
                        <td style="font-weight:600;color:#1a6636;">+{{ number_format($amount, 0, ',', '.') }} đ</td>
                        <td>
                            @if($ticketDebtBefore !== null && $ticketDebtAfter !== null)
                                {{ number_format($ticketDebtBefore, 0, ',', '.') }} đ &rarr; {{ number_format($ticketDebtAfter, 0, ',', '.') }} đ
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ number_format($walletBefore, 0, ',', '.') }} đ &rarr; {{ number_format($walletAfter, 0, ',', '.') }} đ</td>
                        <td style="max-width:250px;white-space:normal;word-break:break-word;">{{ $tx->note }}</td>
                        <td style="font-size:12.5px;">{{ $tx->createdBy ? ($tx->createdBy->full_name ?: $tx->createdBy->name) : 'Hệ thống' }}</td>
                        <td style="font-size:12px;color:#666;">{{ $tx->created_at ? $tx->created_at->format('d/m/Y H:i') : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="se-empty">
                                <span class="se-empty-icon">💸</span>
                                Chưa có giao dịch nạp hạn mức thủ công nào.
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ──────────────────────────────────────────────────────────────────────────
     JavaScript — 3-stage security flow
────────────────────────────────────────────────────────────────────────────── --}}

<script>
(function () {
    'use strict';

    /* ── Helpers ──────────────────────────────────────────────────────────── */
    const $ = (id) => document.getElementById(id);
    const routes = {
        verifyAccessCode : '{{ route("admin.stores.topup-verify-access-code", $store->id) }}',
        sendOtp          : '{{ route("admin.stores.topup-send-otp",           $store->id) }}',
        verifyOtp        : '{{ route("admin.stores.topup-verify-otp",         $store->id) }}',
    };
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    async function postJson(url, body) {
        const r = await fetch(url, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body    : JSON.stringify(body),
        });
        return { ok: r.ok, status: r.status, data: await r.json() };
    }

    function showError(elId, msg) {
        const el = $(elId);
        if (el) { el.textContent = msg; el.style.display = msg ? 'block' : 'none'; }
    }

    function setLoading(btn, loading, label) {
        btn.disabled = loading;
        btn.textContent = loading ? 'Đang xử lý…' : label;
    }

    /* ── Countdown timer ─────────────────────────────────────────────────── */
    let countdownInterval = null;
    function startCountdown(minutes) {
        if (countdownInterval) clearInterval(countdownInterval);
        let secs = minutes * 60;
        const timerEl = $('otp-timer');
        const tick = () => {
            if (secs <= 0) { clearInterval(countdownInterval); timerEl.textContent = 'Hết hạn'; timerEl.style.color = '#e74c3c'; return; }
            const m = String(Math.floor(secs / 60)).padStart(2, '0');
            const s = String(secs % 60).padStart(2, '0');
            timerEl.textContent = `${m}:${s}`;
            secs--;
        };
        tick();
        countdownInterval = setInterval(tick, 1000);
    }

    /* ── Stage navigation ────────────────────────────────────────────────── */
    function showStage(name) {
        ['stage-locked', 'stage-form', 'stage-otp'].forEach(id => {
            $(id).style.display = id === `stage-${name}` ? '' : 'none';
        });
    }

    /* ── Stage 0 → Stage 1: Verify access code ───────────────────────────── */
    const btnVerifyCode = $('btn-verify-access-code');
    btnVerifyCode.addEventListener('click', async () => {
        const code = $('topup-access-code').value.trim();
        if (! code) { showError('access-code-error', 'Vui lòng nhập mã khóa.'); return; }
        showError('access-code-error', '');
        setLoading(btnVerifyCode, true, 'Mở khóa & Tiếp tục');

        const { ok, data } = await postJson(routes.verifyAccessCode, { access_code: code });
        setLoading(btnVerifyCode, false, 'Mở khóa & Tiếp tục');

        if (ok && data.success) {
            $('topup-access-code').value = '';
            showStage('form');
        } else {
            showError('access-code-error', data.message ?? 'Mã khóa không đúng.');
        }
    });

    // Allow pressing Enter in the access code field
    $('topup-access-code').addEventListener('keydown', e => { if (e.key === 'Enter') btnVerifyCode.click(); });

    // Toggle password visibility
    $('toggle-access-code').addEventListener('click', () => {
        const inp = $('topup-access-code');
        inp.type = inp.type === 'password' ? 'text' : 'password';
    });

    /* ── Stage 1 → Stage 2: Send OTP ────────────────────────────────────── */
    const btnSendOtp = $('btn-send-otp');
    btnSendOtp.addEventListener('click', async () => {
        const amount = parseFloat($('topup-amount').value);
        const note   = $('topup-note').value.trim();

        if (! amount || amount < 1000) { showError('form-error', 'Số tiền nạp phải ≥ 1.000 đ.'); return; }
        if (! note)                    { showError('form-error', 'Vui lòng nhập lý do / ghi chú.'); return; }
        showError('form-error', '');
        setLoading(btnSendOtp, true, '📨 Gửi mã OTP đến Email');

        const { ok, data } = await postJson(routes.sendOtp, {});
        setLoading(btnSendOtp, false, '📨 Gửi mã OTP đến Email');

        if (ok && data.success) {
            showStage('otp');
            startCountdown(data.expires_minutes ?? 5);
            showError('otp-error', '');
            $('topup-otp').value = '';
            $('topup-otp').focus();
        } else {
            showError('form-error', data.message ?? 'Không thể gửi OTP. Thử lại sau.');
        }
    });

    $('btn-reset-form').addEventListener('click', () => {
        if (countdownInterval) clearInterval(countdownInterval);
        showStage('locked');
    });

    /* ── Stage 2: Verify OTP & Submit ────────────────────────────────────── */
    const btnVerifyOtp = $('btn-verify-otp');
    btnVerifyOtp.addEventListener('click', async () => {
        const otp = $('topup-otp').value.trim();
        if (! otp) { showError('otp-error', 'Vui lòng nhập mã OTP.'); return; }
        showError('otp-error', '');
        setLoading(btnVerifyOtp, true, '✅ Xác nhận OTP & Nạp hạn mức');

        const { ok, data } = await postJson(routes.verifyOtp, { otp });
        setLoading(btnVerifyOtp, false, '✅ Xác nhận OTP & Nạp hạn mức');

        if (ok && data.success) {
            // Populate & submit the hidden form
            $('hid-amount').value       = $('topup-amount').value;
            $('hid-note').value         = $('topup-note').value;
            $('hid-otp-session').value  = data.otp_session;
            $('final-topup-form').submit();
        } else {
            showError('otp-error', data.message ?? 'OTP không đúng.');
        }
    });

    $('topup-otp').addEventListener('keydown', e => { if (e.key === 'Enter') btnVerifyOtp.click(); });

    /* ── Resend OTP ──────────────────────────────────────────────────────── */
    $('btn-resend-otp').addEventListener('click', async () => {
        showError('otp-error', '');
        const btn = $('btn-resend-otp');
        setLoading(btn, true, '🔄 Gửi lại OTP');

        const { ok, data } = await postJson(routes.sendOtp, {});
        setLoading(btn, false, '🔄 Gửi lại OTP');

        if (ok && data.success) {
            startCountdown(data.expires_minutes ?? 5);
            $('topup-otp').value = '';
            showError('otp-error', '✅ Đã gửi mã OTP mới đến email.');
            $('otp-error').style.color = '#27ae60';
            setTimeout(() => { showError('otp-error', ''); $('otp-error').style.color = '#e74c3c'; }, 4000);
        } else {
            showError('otp-error', data.message ?? 'Gửi OTP thất bại.');
        }
    });

    /* ── Back to form ────────────────────────────────────────────────────── */
    $('btn-back-to-form').addEventListener('click', () => {
        if (countdownInterval) clearInterval(countdownInterval);
        showStage('form');
    });

})();
</script>

