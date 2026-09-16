<style>
/* ── Design tokens (đồng bộ sidebar #0f172a / accent #6366f1) ── */
:root {
    --accent:       #6366f1;
    --accent-h:     #4f46e5;
    --accent-light: #ede9fe;
    --border:       #e2e8f0;
    --bg-card:      #ffffff;
    --text-main:    #1e293b;
    --text-sub:     #64748b;
    --text-muted:   #94a3b8;
    --rc:           14px;
    --ri:           8px;
    --sh:           0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
}

/* ── Fix layout: page-inner full-width với padding hợp lý ─────── */
.page-inner {
    padding: 20px 28px !important;
    max-width: 100% !important;
}

/* ── Wrapper ─────────────────────────────────────────────────── */
.bn-wrap { padding: 4px 0 56px; }

/* ── Page header ─────────────────────────────────────────────── */
.bn-ph { display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px; }
.bn-ph-left { display:flex;align-items:center;gap:14px; }
.bn-ph-icon {
    width:46px;height:46px;border-radius:12px;flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 4px 12px rgba(99,102,241,.3);
}
.bn-ph h1 { font-size:20px;font-weight:700;color:var(--text-main);margin:0; }
.bn-ph p  { font-size:13px;color:var(--text-sub);margin:2px 0 0; }

/* ── Alert ───────────────────────────────────────────────────── */
.bn-alert { display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500; }
.bn-alert.success { background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d; }
.bn-alert.error   { background:#fef2f2;border:1px solid #fecaca;color:#dc2626; }
.bn-alert.warning { background:#fffbeb;border:1px solid #fde68a;color:#92400e; }

/* ── Card ────────────────────────────────────────────────────── */
.bn-card { background:var(--bg-card);border-radius:var(--rc);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden; }
.bn-card + .bn-card { margin-top:20px; }
.bn-ch { padding:15px 20px 12px;border-bottom:1px solid #f8fafc;display:flex;align-items:center;gap:10px; }
.bn-ch .chi { width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.bn-ch h2 { font-size:14px;font-weight:600;color:var(--text-main);margin:0; }
.bn-ch p  { font-size:12px;color:var(--text-muted);margin:2px 0 0; }
.bn-cb { padding:18px 20px; }

/* ── 2-col grid — breakpoint cao hơn vì sidebar chiếm 280px ──── */
.bn-grid { display:grid;grid-template-columns:1fr 280px;gap:20px;align-items:start; }
@media(max-width:1280px){ .bn-grid{grid-template-columns:1fr;} }
.bn-row2 { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
@media(max-width:640px){ .bn-row2{grid-template-columns:1fr;} }

/* ── Field ───────────────────────────────────────────────────── */
.bn-f { margin-bottom:15px; }
.bn-f:last-child { margin-bottom:0; }
.bn-f > label { display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px; }
.bn-f .hint { font-size:12px;color:var(--text-muted);margin-top:5px;display:flex;align-items:flex-start;gap:5px; }
.bn-f .err  { font-size:12px;color:#dc2626;margin-top:4px;display:flex;align-items:center;gap:4px; }

/* ── Inputs ──────────────────────────────────────────────────── */
.bn-in, .bn-ta, .bn-sel {
    border:1px solid var(--border);border-radius:var(--ri);
    padding:9px 12px;font-size:13px;color:var(--text-main);
    background:#fff;width:100%;box-sizing:border-box;
    transition:border .18s,box-shadow .18s;
}
.bn-in:focus,.bn-ta:focus,.bn-sel:focus { outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.12); }
.bn-in.err,.bn-ta.err,.bn-sel.err { border-color:#ef4444; }
.bn-ta { resize:vertical;min-height:88px; }
.bn-sel {
    padding-right:32px;appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 10px center;
}

/* ── Image upload zone ───────────────────────────────────────── */
.bn-upload {
    border:2px dashed #c7d2fe;border-radius:12px;background:#f8f7ff;
    padding:28px 20px;display:flex;flex-direction:column;align-items:center;
    justify-content:center;cursor:pointer;transition:all .2s;text-align:center;
    position:relative;min-height:160px;
}
.bn-upload:hover { border-color:var(--accent);background:var(--accent-light); }
.bn-upload.has-img { padding:0;border-style:solid;border-color:var(--border);background:#fff; }
.bn-upload input[type=file] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
.bn-upload-icon { width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--accent),#8b5cf6);display:flex;align-items:center;justify-content:center;margin-bottom:10px;box-shadow:0 4px 12px rgba(99,102,241,.25); }
.bn-upload-title { font-size:14px;font-weight:600;color:var(--text-main);margin-bottom:3px; }
.bn-upload-sub   { font-size:12px;color:var(--text-muted); }
.bn-img-preview  { width:100%;border-radius:10px;display:block;max-height:240px;object-fit:cover; }
.bn-img-change   { position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;backdrop-filter:blur(4px); }

/* ── Type selector cards ─────────────────────────────────────── */
.bn-type-cards { display:flex;flex-direction:column;gap:8px; }
.bn-tc-in { display:none; }
.bn-tc-lb {
    display:flex;align-items:flex-start;gap:12px;padding:12px 14px;
    border-radius:11px;border:1.5px solid var(--border);background:#fff;
    cursor:pointer;transition:all .18s;user-select:none;
}
.bn-tc-lb:hover { border-color:var(--accent);background:#fafbff; }
.bn-tc-in:checked + .bn-tc-lb { border-color:var(--accent);background:var(--accent-light);box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.bn-tc-icon { width:34px;height:34px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px; }
.bn-tc-title { font-size:13px;font-weight:600;color:var(--text-main); }
.bn-tc-desc  { font-size:11px;color:var(--text-muted);margin-top:2px; }
.bn-tc-in:checked + .bn-tc-lb .bn-tc-title { color:var(--accent); }

/* ── Status toggle ───────────────────────────────────────────── */
.bn-status-grp { display:flex;gap:8px; }
.bn-st-in { display:none; }
.bn-st-lb {
    flex:1;display:flex;align-items:center;justify-content:center;gap:7px;
    padding:9px 12px;border-radius:9px;border:1.5px solid var(--border);background:#fff;
    font-size:13px;font-weight:500;color:var(--text-sub);cursor:pointer;transition:all .18s;user-select:none;
}
.bn-st-in[value="1"]:checked + .bn-st-lb { border-color:#22c55e;background:#f0fdf4;color:#16a34a;font-weight:600; }
.bn-st-in[value="0"]:checked + .bn-st-lb { border-color:#94a3b8;background:#f8fafc;color:#64748b;font-weight:600; }

/* ── Buttons ─────────────────────────────────────────────────── */
.bn-btn-save {
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:10px 22px;border-radius:10px;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    color:#fff;font-size:14px;font-weight:600;border:none;
    cursor:pointer;transition:opacity .18s,transform .1s;
    box-shadow:0 4px 14px rgba(99,102,241,.35);
}
.bn-btn-save:hover { opacity:.9;transform:translateY(-1px); }
.bn-btn-save:active { transform:translateY(0); }
.bn-btn-save.full { width:100%; }

.bn-btn-back {
    display:inline-flex;align-items:center;gap:6px;
    font-size:13px;color:var(--text-muted);text-decoration:none;
    padding:8px 14px;border-radius:9px;border:1px solid var(--border);
    background:#fff;transition:all .18s;
}
.bn-btn-back:hover { color:var(--accent);border-color:var(--accent);background:var(--accent-light); }

.bn-btn-add {
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 18px;border-radius:10px;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    color:#fff;font-size:13px;font-weight:600;border:none;
    cursor:pointer;transition:opacity .18s,transform .1s;
    box-shadow:0 4px 12px rgba(99,102,241,.3);text-decoration:none;
}
.bn-btn-add:hover { opacity:.9;color:#fff; }

/* ── Index: banner grid ──────────────────────────────────────── */
.bn-list { display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px; }
.bn-item {
    background:#fff;border-radius:14px;border:1px solid var(--border);
    box-shadow:var(--sh);overflow:hidden;transition:box-shadow .2s,transform .15s;
    display:flex;flex-direction:column;
}
.bn-item:hover { box-shadow:0 8px 24px rgba(0,0,0,.1);transform:translateY(-2px); }
.bn-item-img { width:100%;height:150px;object-fit:cover;display:block;background:#f1f5f9;flex-shrink:0; }
.bn-item-img-placeholder { width:100%;height:150px;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:32px;flex-shrink:0; }
.bn-item-body { padding:12px 14px;flex:1; }
.bn-item-type { display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:3px 8px;border-radius:20px;margin-bottom:7px; }
.bn-item-title { font-size:13px;font-weight:600;color:var(--text-main);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.bn-item-meta  { font-size:12px;color:var(--text-muted); }
.bn-item-footer { padding:10px 14px;border-top:1px solid #f8fafc;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-shrink:0; }

/* ── Status badge ────────────────────────────────────────────── */
.bn-badge { display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px; }
.bn-badge.on  { background:#dcfce7;color:#16a34a; }
.bn-badge.off { background:#f1f5f9;color:#64748b; }

/* ── Switch display type ─────────────────────────────────────── */
.bn-switch-cards { display:grid;grid-template-columns:repeat(3,1fr);gap:12px; }
@media(max-width:800px){ .bn-switch-cards{grid-template-columns:1fr;} }
.bn-sw-card {
    border:2px solid var(--border);border-radius:12px;padding:14px 16px;
    cursor:pointer;transition:all .18s;background:#fff;text-align:center;
    width:100%;font-family:inherit;
}
.bn-sw-card.active { border-color:var(--accent);background:var(--accent-light); }
.bn-sw-card:hover:not(.active) { border-color:#a5b4fc;background:#fafbff; }
.bn-sw-card .sw-icon { font-size:22px;margin-bottom:6px; }
.bn-sw-card .sw-label { font-size:13px;font-weight:600;color:var(--text-main); }
.bn-sw-card .sw-stats { font-size:12px;color:var(--text-muted);margin-top:3px; }
.bn-sw-card.active .sw-label { color:var(--accent); }
.bn-sw-card .sw-active-badge { display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:var(--accent);color:#fff;padding:2px 8px;border-radius:20px;margin-top:5px; }

/* ── Action buttons in list ──────────────────────────────────── */
.bn-act-btn {
    display:inline-flex;align-items:center;gap:5px;
    padding:6px 11px;border-radius:8px;font-size:12px;font-weight:600;
    border:none;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap;
}
.bn-act-btn.edit                { background:#eff6ff;color:#3b82f6 !important;border:1px solid #bfdbfe; }
.bn-act-btn.edit:hover          { background:#dbeafe;color:#2563eb !important;text-decoration:none; }
.bn-act-btn.del                 { background:#fef2f2;color:#ef4444 !important;border:1px solid #fecaca; }
.bn-act-btn.del:hover           { background:#fee2e2;color:#dc2626 !important;text-decoration:none; }
.bn-act-btn.toggle-on           { background:#f0fdf4;color:#16a34a !important;border:1px solid #bbf7d0; }
.bn-act-btn.toggle-on:hover     { background:#dcfce7;color:#15803d !important;text-decoration:none; }
.bn-act-btn.toggle-off          { background:#f8fafc;color:#64748b !important;border:1px solid #e2e8f0; }
.bn-act-btn.toggle-off:hover    { background:#f1f5f9;color:#475569 !important;text-decoration:none; }

/* ── Back button — override Bootstrap a:hover ────────────────── */
a.bn-btn-back                   { color:var(--text-muted) !important;text-decoration:none; }
a.bn-btn-back:hover             { color:var(--accent) !important;border-color:var(--accent);background:var(--accent-light);text-decoration:none; }

/* ── Add button ──────────────────────────────────────────────── */
a.bn-btn-add                    { color:#fff !important;text-decoration:none; }
a.bn-btn-add:hover              { opacity:.9;color:#fff !important;text-decoration:none; }

/* ── Room mode radio labels ──────────────────────────────────── */
.bn-room-mode-label {
    display:flex;align-items:center;gap:10px;
    padding:10px 14px;border-radius:9px;
    border:1.5px solid var(--border);background:#fff;
    cursor:pointer;transition:border .18s,background .18s;
    font-size:13px;font-weight:500;color:var(--text-main);
}
.bn-room-mode-label:hover       { border-color:#a5b4fc;background:#fafbff; }
.bn-room-mode-label.active-mode { border-color:var(--accent);background:var(--accent-light); }
.bn-room-mode-label input       { width:16px;height:16px;flex-shrink:0;accent-color:var(--accent); }
.bn-room-mode-label span        { font-size:13px;font-weight:500;color:var(--text-main); }
</style>

/* ── Wrapper ─────────────────────────────────────────────────── */
.bn-wrap { padding: 4px 0 56px; }

/* ── Page header ─────────────────────────────────────────────── */
.bn-ph { display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px; }
.bn-ph-left { display:flex;align-items:center;gap:14px; }
.bn-ph-icon {
    width:46px;height:46px;border-radius:12px;flex-shrink:0;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 4px 12px rgba(99,102,241,.3);
}
.bn-ph h1 { font-size:20px;font-weight:700;color:var(--text-main);margin:0; }
.bn-ph p  { font-size:13px;color:var(--text-sub);margin:2px 0 0; }

/* ── Alert ───────────────────────────────────────────────────── */
.bn-alert { display:flex;align-items:center;gap:10px;padding:11px 16px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500; }
.bn-alert.success { background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d; }
.bn-alert.error   { background:#fef2f2;border:1px solid #fecaca;color:#dc2626; }
.bn-alert.warning { background:#fffbeb;border:1px solid #fde68a;color:#92400e; }

/* ── Card ────────────────────────────────────────────────────── */
.bn-card { background:var(--bg-card);border-radius:var(--rc);border:1px solid var(--border);box-shadow:var(--sh);overflow:hidden; }
.bn-card + .bn-card { margin-top:20px; }
.bn-ch { padding:15px 20px 12px;border-bottom:1px solid #f8fafc;display:flex;align-items:center;gap:10px; }
.bn-ch .chi { width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.bn-ch h2 { font-size:14px;font-weight:600;color:var(--text-main);margin:0; }
.bn-ch p  { font-size:12px;color:var(--text-muted);margin:2px 0 0; }
.bn-cb { padding:18px 20px; }

/* ── 2-col grid ──────────────────────────────────────────────── */
.bn-grid { display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start; }
@media(max-width:1100px){ .bn-grid{grid-template-columns:1fr;} }
.bn-row2 { display:grid;grid-template-columns:1fr 1fr;gap:14px; }
@media(max-width:640px){ .bn-row2{grid-template-columns:1fr;} }

/* ── Field ───────────────────────────────────────────────────── */
.bn-f { margin-bottom:15px; }
.bn-f:last-child { margin-bottom:0; }
.bn-f > label { display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px; }
.bn-f .hint { font-size:12px;color:var(--text-muted);margin-top:5px;display:flex;align-items:flex-start;gap:5px; }
.bn-f .err  { font-size:12px;color:#dc2626;margin-top:4px;display:flex;align-items:center;gap:4px; }

/* ── Inputs ──────────────────────────────────────────────────── */
.bn-in, .bn-ta, .bn-sel {
    border:1px solid var(--border);border-radius:var(--ri);
    padding:9px 12px;font-size:13px;color:var(--text-main);
    background:#fff;width:100%;box-sizing:border-box;
    transition:border .18s,box-shadow .18s;
}
.bn-in:focus,.bn-ta:focus,.bn-sel:focus { outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(99,102,241,.12); }
.bn-in.err,.bn-ta.err,.bn-sel.err { border-color:#ef4444; }
.bn-ta { resize:vertical;min-height:88px; }
.bn-sel {
    padding-right:32px;appearance:none;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat:no-repeat;background-position:right 10px center;
}

/* ── Image upload zone ───────────────────────────────────────── */
.bn-upload {
    border:2px dashed #c7d2fe;border-radius:12px;background:#f8f7ff;
    padding:28px 20px;display:flex;flex-direction:column;align-items:center;
    justify-content:center;cursor:pointer;transition:all .2s;text-align:center;
    position:relative;min-height:160px;
}
.bn-upload:hover { border-color:var(--accent);background:var(--accent-light); }
.bn-upload.has-img { padding:0;border-style:solid;border-color:var(--border);background:#fff; }
.bn-upload input[type=file] { position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%; }
.bn-upload-icon { width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,var(--accent),#8b5cf6);display:flex;align-items:center;justify-content:center;margin-bottom:10px;box-shadow:0 4px 12px rgba(99,102,241,.25); }
.bn-upload-title { font-size:14px;font-weight:600;color:var(--text-main);margin-bottom:3px; }
.bn-upload-sub   { font-size:12px;color:var(--text-muted); }
.bn-img-preview  { width:100%;border-radius:10px;display:block;max-height:240px;object-fit:cover; }
.bn-img-change   { position:absolute;bottom:10px;right:10px;background:rgba(0,0,0,.55);color:#fff;border:none;border-radius:8px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;backdrop-filter:blur(4px); }

/* ── Type selector cards ─────────────────────────────────────── */
.bn-type-cards { display:flex;flex-direction:column;gap:8px; }
.bn-tc-in { display:none; }
.bn-tc-lb {
    display:flex;align-items:flex-start;gap:12px;padding:12px 14px;
    border-radius:11px;border:1.5px solid var(--border);background:#fff;
    cursor:pointer;transition:all .18s;user-select:none;
}
.bn-tc-lb:hover { border-color:var(--accent);background:#fafbff; }
.bn-tc-in:checked + .bn-tc-lb { border-color:var(--accent);background:var(--accent-light);box-shadow:0 0 0 3px rgba(99,102,241,.1); }
.bn-tc-icon { width:34px;height:34px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px; }
.bn-tc-title { font-size:13px;font-weight:600;color:var(--text-main); }
.bn-tc-desc  { font-size:11px;color:var(--text-muted);margin-top:2px; }
.bn-tc-in:checked + .bn-tc-lb .bn-tc-title { color:var(--accent); }

/* ── Status toggle ───────────────────────────────────────────── */
.bn-status-grp { display:flex;gap:8px; }
.bn-st-in { display:none; }
.bn-st-lb {
    flex:1;display:flex;align-items:center;justify-content:center;gap:7px;
    padding:9px 12px;border-radius:9px;border:1.5px solid var(--border);background:#fff;
    font-size:13px;font-weight:500;color:var(--text-sub);cursor:pointer;transition:all .18s;user-select:none;
}
.bn-st-in[value="1"]:checked + .bn-st-lb { border-color:#22c55e;background:#f0fdf4;color:#16a34a;font-weight:600; }
.bn-st-in[value="0"]:checked + .bn-st-lb { border-color:#94a3b8;background:#f8fafc;color:#64748b;font-weight:600; }

/* ── Buttons ─────────────────────────────────────────────────── */
.bn-btn-save {
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    padding:10px 22px;border-radius:10px;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    color:#fff;font-size:14px;font-weight:600;border:none;
    cursor:pointer;transition:opacity .18s,transform .1s;
    box-shadow:0 4px 14px rgba(99,102,241,.35);
}
.bn-btn-save:hover { opacity:.9;transform:translateY(-1px); }
.bn-btn-save:active { transform:translateY(0); }
.bn-btn-save.full { width:100%; }

.bn-btn-back {
    display:inline-flex;align-items:center;gap:6px;
    font-size:13px;color:var(--text-muted);text-decoration:none;
    padding:8px 14px;border-radius:9px;border:1px solid var(--border);
    background:#fff;transition:all .18s;
}
.bn-btn-back:hover { color:var(--accent);border-color:var(--accent);background:var(--accent-light); }

.bn-btn-add {
    display:inline-flex;align-items:center;gap:7px;
    padding:9px 18px;border-radius:10px;
    background:linear-gradient(135deg,var(--accent),#8b5cf6);
    color:#fff;font-size:13px;font-weight:600;border:none;
    cursor:pointer;transition:opacity .18s,transform .1s;
    box-shadow:0 4px 12px rgba(99,102,241,.3);text-decoration:none;
}
.bn-btn-add:hover { opacity:.9;color:#fff; }

/* ── Index: banner grid ──────────────────────────────────────── */
.bn-list { display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px; }
.bn-item {
    background:#fff;border-radius:14px;border:1px solid var(--border);
    box-shadow:var(--sh);overflow:hidden;transition:box-shadow .2s,transform .15s;
}
.bn-item:hover { box-shadow:0 8px 24px rgba(0,0,0,.1);transform:translateY(-2px); }
.bn-item-img { width:100%;height:160px;object-fit:cover;display:block;background:#f1f5f9; }
.bn-item-img-placeholder { width:100%;height:160px;background:linear-gradient(135deg,#f1f5f9,#e2e8f0);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:32px; }
.bn-item-body { padding:14px 16px; }
.bn-item-type { display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:3px 9px;border-radius:20px;margin-bottom:8px; }
.bn-item-title { font-size:14px;font-weight:600;color:var(--text-main);margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.bn-item-meta  { font-size:12px;color:var(--text-muted); }
.bn-item-footer { padding:10px 16px;border-top:1px solid #f8fafc;display:flex;align-items:center;justify-content:space-between;gap:8px; }

/* ── Status badge ────────────────────────────────────────────── */
.bn-badge { display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px; }
.bn-badge.on  { background:#dcfce7;color:#16a34a; }
.bn-badge.off { background:#f1f5f9;color:#64748b; }

/* ── Switch display type ─────────────────────────────────────── */
.bn-switch-cards { display:grid;grid-template-columns:repeat(3,1fr);gap:12px; }
@media(max-width:700px){ .bn-switch-cards{grid-template-columns:1fr;} }
.bn-sw-card {
    border:2px solid var(--border);border-radius:12px;padding:14px 16px;
    cursor:pointer;transition:all .18s;background:#fff;text-align:center;
}
.bn-sw-card.active { border-color:var(--accent);background:var(--accent-light); }
.bn-sw-card:hover:not(.active) { border-color:#a5b4fc;background:#fafbff; }
.bn-sw-card .sw-icon { font-size:22px;margin-bottom:6px; }
.bn-sw-card .sw-label { font-size:13px;font-weight:600;color:var(--text-main); }
.bn-sw-card .sw-stats { font-size:12px;color:var(--text-muted);margin-top:3px; }
.bn-sw-card.active .sw-label { color:var(--accent); }
.bn-sw-card .sw-active-badge { display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;background:var(--accent);color:#fff;padding:2px 8px;border-radius:20px;margin-top:5px; }

/* ── Action buttons in list ──────────────────────────────────── */
.bn-act-btn {
    display:inline-flex;align-items:center;gap:5px;
    padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;
    border:none;cursor:pointer;transition:all .18s;text-decoration:none;
}
.bn-act-btn.edit   { background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe; }
.bn-act-btn.edit:hover { background:#dbeafe; }
.bn-act-btn.del    { background:#fef2f2;color:#ef4444;border:1px solid #fecaca; }
.bn-act-btn.del:hover { background:#fee2e2; }
.bn-act-btn.toggle-on  { background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0; }
.bn-act-btn.toggle-off { background:#f8fafc;color:#64748b;border:1px solid #e2e8f0; }
</style>
