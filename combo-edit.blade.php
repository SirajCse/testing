{{-- ═══════════════════════════════════════════════════════════════════════════
     COMBO EDIT — lab.lab_report.combo-edit
     One screen for every test of a receipt: v23 shell (sidebar · Single / Bulk /
     Easy · KPI chips · save bar) wrapped around the SAME server-rendered form
     pattern used by bulk-edit-receipt.blade.php.

     Requires (paste, no further config):
       routes   : lab.report.combo.edit / combo.update / combo.lis / combo.shift
       methods  : LabReportController@comboEdit, comboUpdate, comboLis, comboShift
       vendors  : select2 + flatpickr (same as bulk edit page)
       pages js : lab-report.js, test-custom-function.js, bookmark-manager.js,
                  dynamic.min.js  (loaded again below — the inline combo script
                  loads LAST and overrides the bulk save/UI helpers it needs)

     The form keeps the exact bulk-edit payload contract:
       tests[i][id] · tests[i][complete] · tests[i][prepared_by|reviewed_by|authorized_by|comment]
       tests[i][components][COMPONENT_ID][result|result_text|unit|status|color|hide|mic_value|zone_value|reference_text|result_file]
   ═══════════════════════════════════════════════════════════════════════════ --}}

@extends('layouts/contentLayoutHideNavbar')

@section('title', 'Combo Edit - ' . ($receipt->sl_no ?? ''))

@section('vendor-style')
    <link rel="stylesheet" href="{{ asset(mix('vendors/css/forms/select/select2.min.css')) }}">
@endsection

@section('page-style')
    <style>
        /* ═══════════════════════════════════════════════════════════
           V23 COMBO SHELL — scoped to .combo-app (demo blue theme)
           ═══════════════════════════════════════════════════════════ */
        .combo-app{
            --p:#1f5fbf; --pd:#174a96; --ps:#e9f1fc; --ink:#0b1f3a; --mut:#5b6b82;
            --bd:#d9e1ec; --bg:#f1f4f9; --ok:#168a4a; --warn:#c9730a; --bad:#c23030;
            --radius:12px; --shadow-sm:0 1px 2px rgba(11,31,58,.07); --shadow-md:0 6px 16px rgba(11,31,58,.08);
            background:var(--bg); color:#1c2b42; border-radius:var(--radius);
            padding:0 0 2px; margin-bottom:1.5rem; overflow:clip;
            font-feature-settings:"tnum" 1, "cv11" 1;
        }
        .combo-app button{font-family:inherit; cursor:pointer}
        .combo-app a{text-decoration:none}
        .combo-app :focus-visible{outline:2px solid var(--p); outline-offset:2px}
        .combo-app ::-webkit-scrollbar{width:8px; height:8px}
        .combo-app ::-webkit-scrollbar-thumb{background:#c3cddc; border-radius:8px}

        /* ── top bar ─────────────────────────────────────────────── */
        .combo-app .top{background:#fff; border-bottom:1px solid var(--bd); padding:10px 18px;
            display:flex; flex-wrap:wrap; gap:10px 20px; align-items:center; justify-content:space-between;
            border-radius:var(--radius) var(--radius) 0 0}
        .combo-app .pt-strip{display:flex; align-items:center; gap:12px; min-width:0}
        .combo-app .av{width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,var(--p),var(--pd));
            color:#fff; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0}
        .combo-app .pn{font-size:1.05rem; font-weight:800; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis}
        .combo-app .tag{font-size:inherit; font-weight:800; color:var(--ink); padding:1px 10px;
            background:#f1f5f9; border-radius:10px; margin-left:6px; vertical-align:2px; white-space:nowrap}
        .combo-app .pm{color:var(--mut); font-size:.82rem; display:flex; gap:4px 18px; flex-wrap:wrap; margin-top:2px}
        .combo-app .pm i{color:var(--p); margin-right:4px}
        .combo-app .ctl{display:flex; flex-wrap:wrap; gap:10px 16px; align-items:center}
        .combo-app .cg-x{display:flex; align-items:center; gap:8px}
        .combo-app .cl{font-size:.74rem; font-weight:700; color:var(--mut); white-space:nowrap}
        .combo-app .seg{display:inline-flex; background:#e8edf5; border-radius:10px; padding:3px}
        .combo-app .seg button{border:0; background:transparent; padding:5px 14px; border-radius:8px;
            font-weight:600; font-size:.82rem; color:#44566f; white-space:nowrap}
        .combo-app .seg button.on{background:#fff; color:var(--pd); box-shadow:0 1px 3px rgba(11,31,58,.18)}
        .combo-app .btn-x{border:1px solid #c9d4e3; background:#fff; color:#26405f; border-radius:9px;
            padding:6px 14px; font-weight:600; font-size:.84rem; display:inline-flex; align-items:center; gap:6px;
            transition:.15s; white-space:nowrap; line-height:1.4}
        .combo-app .btn-x:hover{border-color:var(--p); color:var(--pd); background:var(--ps)}
        .combo-app .btn-x.pri{background:var(--p); border-color:var(--p); color:#fff; box-shadow:0 1px 2px rgba(31,95,191,.35)}
        .combo-app .btn-x.pri:hover{background:var(--pd); color:#fff}
        .combo-app .btn-x.ok{background:#eefaf3; border-color:#a7dcbc; color:var(--ok)}
        .combo-app .btn-x.sm{padding:2px 11px; font-size:.78rem}
        .combo-app .btn-x.icon{padding:6px 10px}
        .combo-app .pgrp{display:inline-flex; gap:6px; align-items:center}
        .combo-app .rpt-search{flex:0 0 170px; min-width:0}
        .combo-app .rpt-search .form-control{font-size:.85rem; padding:5px 10px; height:34px; border-radius:8px;
            border-color:var(--bd); color:var(--ink); background:#fff}
        .combo-app .rpt-search .form-control:focus{border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.16)}

        /* ── KPI chips ───────────────────────────────────────────── */
        .combo-app .kpi{display:flex; flex-wrap:wrap; align-items:center; gap:6px; padding:8px 18px;
            background:#fff; border-bottom:1px solid var(--bd)}
        .combo-app .kpi .kchip{display:inline-flex; align-items:center; gap:6px; padding:2px 6px 2px 12px;
            border:1px solid #d3dce9; border-radius:20px; line-height:1.6; background:#fff; font-size:.78rem}
        .combo-app .kpi .kchip i{font-size:.7rem; color:var(--mut)}
        .combo-app .kpi .kchip b{min-width:26px; text-align:center; padding:0 8px; border-radius:12px;
            background:#eef2f7; color:var(--ink); font-size:.72rem; font-weight:800}
        .combo-app .kpi .kchip.okc b{background:#dcfce7; color:var(--ok)}
        .combo-app .kpi .kchip.lisc b{background:#fef3c7; color:#b45309}
        .combo-app .kpi .kchip.dirt b{background:#fee2e2; color:var(--bad)}
        .combo-app .kpi button.kchip{cursor:pointer}
        .combo-app .kpi button.kchip:hover{border-color:var(--p)}

        /* ── shell: sidebar + main ───────────────────────────────── */
        .combo-app .shell{display:flex; align-items:stretch; min-height:0; position:relative}
        .combo-app .side{width:272px; flex:none; background:#fff; border-right:1px solid var(--bd);
            position:sticky; top:0; align-self:flex-start;
            max-height:calc(100vh - 24px); min-height:420px; display:flex; flex-direction:column; z-index:25}
        .combo-app .sb-top{padding:10px; border-bottom:1px solid var(--bd)}
        .combo-app .sb-top input[type=search]{width:100%; border:1px solid #c9d4e3; border-radius:8px;
            padding:6px 10px; font-size:.86rem; color:var(--ink); background:#fff}
        .combo-app .sb-top input[type=search]:focus{outline:0; border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.15)}
        .combo-app .sb-flt{display:flex; gap:4px; margin-top:8px}
        .combo-app .sb-flt button{border:0; background:#f1f5f9; border-radius:6px; font-size:.74rem; font-weight:700;
            padding:3px 9px; color:#44566f; display:inline-flex; gap:5px; align-items:center}
        .combo-app .sb-flt button.on{background:var(--p); color:#fff}
        .combo-app #sbl{flex:1; overflow:auto; padding:6px 8px 20px}
        .combo-app .sb-cat{display:flex; justify-content:space-between; align-items:center; font-size:.72rem; font-weight:800;
            color:var(--mut); padding:12px 6px 4px; border-left:4px solid var(--cc,var(--p)); margin-top:6px;
            background:#f8fafc; border-radius:0 6px 6px 0; padding-left:8px}
        .combo-app .sb-cat:first-child{margin-top:2px}
        .combo-app .sb-i{display:grid; grid-template-columns:18px 1fr auto; gap:2px 8px; align-items:center;
            padding:7px 8px; border-radius:8px; cursor:pointer; border:1px solid transparent;
            border-left:4px solid var(--cc,transparent); margin-top:3px}
        .combo-app .sb-i:hover{background:#f8fafc}
        .combo-app .sb-i.on{background:var(--ps); border-color:#c5d8f3}
        .combo-app .sb-i .sdot{width:8px; height:8px; border-radius:50%; background:#98a2b3; grid-row:1; justify-self:center}
        .combo-app .sb-i.done .sdot{background:var(--ok)}
        .combo-app .sb-i .sb-n{font-weight:700; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; grid-column:2}
        .combo-app .sb-i .sb-n small{color:var(--mut); font-weight:600; margin-left:4px}
        .combo-app .sb-i .sb-s{font-size:.72rem; font-weight:700; color:var(--mut); white-space:nowrap}
        .combo-app .sb-i .sb-p{grid-column:2/4; height:4px; background:#e2e8f0; border-radius:3px; overflow:hidden}
        .combo-app .sb-i .sb-p i{display:block; height:100%; background:var(--warn); width:0}
        .combo-app .sb-i.done .sb-p i{background:var(--ok)}
        .combo-app .sb-i.dirty .sb-n::after{content:""; display:inline-block; width:7px; height:7px; border-radius:50%;
            background:var(--warn); margin-left:6px}
        .combo-app .sb-i.dim{opacity:.35}
        .combo-app .sb-none{padding:20px; text-align:center; color:var(--mut)}

        .combo-app .main{flex:1; min-width:0; position:relative}
        .combo-app .sub{position:sticky; top:0; z-index:20; background:rgba(241,244,249,.96); backdrop-filter:blur(4px);
            border-bottom:1px solid var(--bd); padding:7px 18px; display:flex; flex-wrap:wrap; gap:8px 14px; align-items:center}
        .combo-app .sub .l{display:flex; gap:6px; align-items:center; flex-wrap:wrap; flex:1; min-width:0}
        .combo-app .chips{display:flex; gap:4px; flex-wrap:wrap}
        .combo-app .chip{height:26px; display:inline-flex; align-items:center; padding:0 11px; border:1px solid #c9d4e3;
            background:#fff; border-radius:20px; font-size:.74rem; font-weight:600; color:#334155; cursor:pointer; white-space:nowrap}
        .combo-app .chip small{opacity:.75; margin-left:4px}
        .combo-app .chip.on{background:var(--p); border-color:var(--p); color:#fff}
        .combo-app .prog{display:flex; align-items:center; gap:8px; min-width:150px}
        .combo-app .prog .bar{flex:1; height:6px; background:#e2e8f0; border-radius:4px; overflow:hidden}
        .combo-app .prog .bar i{display:block; height:100%; background:var(--ok); width:0; transition:width .25s}
        .combo-app .prog small{font-weight:800; color:var(--mut)}
        .combo-app .ecs{height:26px; border:1px solid #c9d4e3; border-radius:7px; font-size:.74rem; padding:0 6px; background:#fff; color:var(--ink)}
        .combo-app .eqi{height:26px; width:150px; border:1px solid #c9d4e3; border-radius:7px; font-size:.74rem; padding:0 8px; background:#fff; color:var(--ink)}
        .combo-app #body{padding:14px 20px 30px}
        /* ── category + test cards (v23 look over their classes) ── */
        .combo-app .cat{--cc:var(--p); margin-bottom:20px; background:transparent; border:0; padding:0; box-shadow:none; border-radius:0}
        .combo-app .cat-h{margin:0 0 10px; padding:10px 16px; background:#fff; border:1px solid var(--bd);
            border-left:4px solid var(--cc); border-radius:var(--radius); box-shadow:var(--shadow-sm); cursor:pointer}
        .combo-app .cat-h h5{margin:0; font-weight:800; color:var(--ink); font-size:1rem; display:flex; align-items:center; flex-wrap:wrap; gap:6px}
        .combo-app .cat-h h5 i.fa-folder{color:var(--cc); margin-right:4px}
        .combo-app .cat-h .badge{font-weight:700; border-radius:10px; padding:.25em .65em}
        .combo-app .cat-h .badge-primary{background:var(--ps); color:var(--pd); border:1px solid #c5d8f3}
        .combo-app .cat-h .badge-success{background:#dcfce7; color:var(--ok); border:1px solid #bbf7d0}
        .combo-app .cat-h .badge-warning{background:#fef3c7; color:#b45309; border:1px solid #fcd34d}
        .combo-app .cat-h .fa-chevron-up.toggle-icon{color:var(--mut); transition:transform .3s}
        .combo-app .cat-actions .btn{border-radius:8px; border:0; padding:6px 10px; font-size:.8rem}
        .combo-app .category-save-btn{background:var(--p); color:#fff}
        .combo-app .category-complete-btn{background:var(--ok); color:#fff}
        .combo-app .category-print-btn{background:#e9f1fc; color:var(--pd)}
        .combo-app .cat-actions .btn:hover{filter:brightness(.92)}
        .combo-app .category-content{transition:.3s}

        .combo-app .tc{background:#fff; border:1px solid var(--bd); border-left:6px solid var(--cc,var(--p));
            border-radius:10px; margin:0 0 14px; box-shadow:var(--shadow-sm); overflow:hidden}
        .combo-app .tc.completed.done{border-left-color:var(--ok)}
        .combo-app .tc.pending{border-left-color:var(--warn)}
        .combo-app .tc:focus-within{box-shadow:0 0 0 2px rgba(31,95,191,.25)}
        .combo-app .tc.is-invalid{border-left-color:#dc2626; box-shadow:inset 0 0 0 2px rgba(220,38,38,.15)}
        .combo-app .th{display:flex; align-items:center; gap:10px; padding:11px 16px; background:linear-gradient(90deg,
            color-mix(in srgb,var(--cc,var(--p)) 8%,#fff),#fff 60%); border-bottom:1px solid var(--bd); cursor:pointer; flex-wrap:wrap}
        .combo-app .th .tt{font-size:1rem; color:var(--ink)}
        .combo-app .th .tt b{font-weight:800}
        .combo-app .th .tt .mut{color:var(--mut); margin-left:6px; font-weight:600; font-size:.86rem}
        .combo-app .th .sp{flex:1}
        .combo-app .pill{font-size:.72rem; font-weight:800; border-radius:12px; padding:2px 10px; background:#f1f5f9;
            color:#445669; white-space:nowrap; border:1px solid transparent}
        .combo-app .pill.st.ok{background:#dcfce7; color:var(--ok); border-color:#bbf7d0}
        .combo-app .pill.st.wait{background:#fef3c7; color:#b45309; border-color:#fcd34d}
        .combo-app .pill.abn{background:#fff7ed; color:#c2410c; border-color:#fed7aa}
        .combo-app .ib{border:1px solid #c9d4e3; background:#fff; border-radius:8px; width:32px; height:30px;
            color:var(--pd); display:inline-flex; align-items:center; justify-content:center}
        .combo-app .ib:hover{background:var(--ps); border-color:var(--p)}
        .combo-app .ib.save-on{background:var(--p); border-color:var(--p); color:#fff}
        .combo-app .toggle-icon-sm{color:var(--p); transition:transform .3s}
        .combo-app .test-validation-badge{align-items:center; gap:5px; padding:3px 10px; border-radius:12px; font-size:.72rem;
            font-weight:700; white-space:nowrap; margin-left:6px}
        .combo-app .test-validation-badge.valid{background:#d1fae5; color:#065f46; border:1px solid #a7f3d0}
        .combo-app .test-validation-badge.invalid{background:#fee2e2; color:#991b1b; border:1px solid #fecaca}

        /* ── component table (their classes, v23 colors) ─────────── */
        .combo-app .component-wrap{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch}
        .combo-app .component-table{width:100%; border-collapse:collapse; font-size:.98rem; color:#1c2b42;
            table-layout:fixed; background:#fff}
        .combo-app .component-table th{background:#f6f8fc; font-weight:700; font-size:.74rem; text-transform:none;
            letter-spacing:0; padding:9px 10px; text-align:left; color:#5b6b82; border-bottom:1px solid var(--bd); white-space:nowrap}
        .combo-app .component-table td{padding:5px 10px; border-bottom:1px solid #eef2f7; vertical-align:middle;
            font-size:.95rem; line-height:1.35; color:#1c2b42; word-break:break-word}
        .combo-app .component-table tbody tr:hover td{background:#fafaff}
        .combo-app .component-table .col-sl{width:3%; text-align:center; font-weight:700; color:var(--mut)}
        .combo-app .component-table .col-hide{width:5%; text-align:center}
        .combo-app .component-table .col-name{width:24%; font-weight:700; color:var(--ink)}
        .combo-app .component-table .col-result{width:24%}
        .combo-app .component-table .col-unit{width:10%}
        .combo-app .component-table .col-ref{width:20%; font-size:.85rem; line-height:1.25}
        .combo-app .component-table .col-status{width:10%}
        .combo-app .component-table .col-color{width:11%; text-align:center}
        .combo-app .component-table.no-color .col-name{width:26%}
        .combo-app .component-table.no-color .col-result{width:26%}
        .combo-app .component-table.no-color .col-unit{width:12%}
        .combo-app .component-table.no-color .col-ref{width:22%}
        .combo-app .component-table.no-status .col-name{width:26%}
        .combo-app .component-table.no-status .col-result{width:26%}
        .combo-app .component-table.no-status .col-unit{width:12%}
        .combo-app .component-table.no-status .col-ref{width:22%}
        .combo-app .component-table.no-status.no-color .col-name{width:28%}
        .combo-app .component-table.no-status.no-color .col-result{width:30%}
        .combo-app .component-table.no-status.no-color .col-unit{width:14%}
        .combo-app .component-table.no-status.no-color .col-ref{width:26%}
        .combo-app .component-table th.col-ref,
        .combo-app .component-table td.col-ref{font-size:.85rem}
        .combo-app .hide-check{width:18px; height:18px; accent-color:var(--p); cursor:pointer}
        .combo-app .table-input,
        .combo-app .table-select,
        .combo-app .table-textarea{width:100%; min-width:0; padding:6px 10px; border:1px solid #cfd9e6; border-radius:8px;
            font-size:.95rem; font-family:inherit; color:var(--ink); font-weight:600; background:#fff; transition:.15s; height:34px}
        .combo-app textarea.table-textarea{height:auto; min-height:44px; resize:vertical}
        .combo-app .table-input::placeholder,
        .combo-app .table-textarea::placeholder{color:#a0a3b1; font-weight:500}
        .combo-app .table-input:focus,
        .combo-app .table-select:focus,
        .combo-app .table-textarea:focus{background:#fffef5; border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.16); outline:none}
        .combo-app .status-input{width:100%; min-width:0; padding:5px 6px; border:1px solid #d0d3df; border-radius:6px;
            font-size:.82rem; font-weight:600; color:var(--ink); background:#fff; text-align:center; height:30px}
        .combo-app .status-input:focus{background:#fffef5; border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.16); outline:none}
        .combo-app .color-cell-wrap{display:flex; align-items:center; gap:4px; justify-content:center}
        .combo-app .color-swatch{width:18px; height:18px; border-radius:50%; border:2px solid #fff;
            box-shadow:0 0 0 1px #cfd2dc, 0 1px 3px rgba(0,0,0,.12); flex-shrink:0}
        .combo-app .color-swatch.empty{background:transparent !important; box-shadow:0 0 0 1px #d0d3df inset; border:2px dashed #d0d3df}
        .combo-app .color-select{flex:1; min-width:0; padding:4px; border:1px solid #d0d3df; border-radius:6px;
            font-size:.7rem; font-weight:600; color:var(--ink); background:#fff; cursor:pointer}
        .combo-app .row-title td{background:#f1f5f9; padding:7px 12px; font-weight:800; color:var(--ink);
            font-size:.9rem; border-bottom:1px solid var(--bd); letter-spacing:.2px}
        .combo-app tr.row-manually-hidden td:not(.col-hide):not(.col-sl){opacity:.4}
        .combo-app tr.row-manually-hidden .col-name{text-decoration:line-through}
        .combo-app tr.row-parent-hidden td:not(.col-hide):not(.col-sl){opacity:.3}
        .combo-app tr.row-parent-hidden .col-name{text-decoration:line-through; color:#9ca3af}
        .combo-app .row-full .col-result-full{padding:5px 10px}
        .combo-app .ref-text{display:inline-block; max-width:100%; padding:3px 9px; background:var(--ps);
            border-left:3px solid var(--p); border-radius:6px; font-size:.84rem; font-weight:600; color:var(--pd);
            line-height:1.3; word-break:break-word}
        .combo-app .ref-picker{display:flex; flex-direction:column; gap:4px}
        .combo-app .ref-picker .ref-display{cursor:pointer; display:inline-block; max-width:100%; padding:3px 9px;
            border-radius:6px; background:var(--ps); border-left:3px solid var(--p); font-size:.84rem; font-weight:600;
            color:var(--pd); line-height:1.3; word-break:break-word; transition:.15s}
        .combo-app .ref-picker .ref-display:hover{background:#dcebfb; border-left-color:var(--pd)}
        .combo-app .ref-picker .ref-display:empty::before{content:'—'; color:#a0a3b1}
        .combo-app .ref-picker .ref-editor{width:100%; padding:4px 6px; border:1px solid var(--p); border-radius:6px;
            font-size:.82rem; font-weight:600; color:var(--ink); background:#fffbe6; outline:none;
            box-shadow:0 0 0 3px rgba(31,95,191,.18)}
        .combo-app .ref-select{width:100%; padding:4px 6px; border:1px solid #c5c8d4; border-radius:6px;
            font-size:.82rem; font-weight:600; color:var(--ink); background:#fff}
        .combo-app .culture-input-group{display:flex; gap:6px; align-items:stretch; min-width:0}
        .combo-app .culture-input-group .culture-mic,
        .combo-app .culture-input-group .culture-zone{flex:1 1 0; min-width:45px; text-align:center;
            font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-weight:600}
        .combo-app .culture-input-group .culture-mic.is-default,
        .combo-app .culture-input-group .culture-zone.is-default{color:#6b7280; font-style:italic; background:#f9fafb}
        .combo-app .culture-interp{flex:0 0 78px; min-width:78px; padding:5px 8px; border:1px solid #c5c8d4;
            border-radius:8px; font-size:1rem; font-weight:800; text-align:center; text-align-last:center; cursor:pointer; background:#fff; height:34px}
        .combo-app .culture-interp:focus{outline:none; border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.18)}
        .combo-app .culture-interp.interp-s{background:#dcfce7; color:#15803d; border-color:#bbf7d0}
        .combo-app .culture-interp.interp-i{background:#fef3c7; color:#b45309; border-color:#fcd34d}
        .combo-app .culture-interp.interp-r{background:#fee2e2; color:#b91c1c; border-color:#fecaca}
        .combo-app .culture-interp.interp-nt{background:#eef2f6; color:#64748b; border-color:#e2e8f0}
        .combo-app .file-upload-inline{display:flex; align-items:center; gap:10px; flex-wrap:wrap}
        .combo-app .file-upload-inline .file-input{flex:1 1 180px; min-width:0; font-size:.84rem; border:1px dashed #c5c8d4;
            padding:5px 8px; border-radius:8px; background:#fafbff; color:var(--ink)}
        .combo-app .download-link{display:inline-flex; align-items:center; gap:4px; font-size:.8rem; color:var(--pd);
            padding:4px 10px; border-radius:20px; background:var(--ps); border:1px solid #c5d8f3; font-weight:700; white-space:nowrap}
        .combo-app .download-link:hover{background:#dcebfb}
        .combo-app .img-preview{display:flex; align-items:center; gap:5px; white-space:nowrap}
        .combo-app .img-preview img{width:34px; height:34px; object-fit:cover; border-radius:6px; border:1px solid var(--bd)}

        /* ── LIS badge (edit map) ────────────────────────────────── */
        .combo-app .cnw{display:inline-flex; flex-wrap:wrap; align-items:center; gap:4px 8px; vertical-align:middle}
        .combo-app .cnw .cnn{white-space:normal}
        .combo-app .lis{display:inline-flex; align-items:center; gap:4px; padding:0 8px; border-radius:10px;
            border:1px solid; font-size:.68rem; font-weight:700; line-height:1.6; background:#fff; cursor:pointer}
        .combo-app .lis.ok{color:var(--ok); border-color:#bbf7d0; background:#f0fdf4}
        .combo-app .lis.new{color:var(--pd); border-color:#c5d8f3; background:var(--ps)}
        .combo-app .lis.diff{color:#b45309; border-color:#fcd34d; background:#fffbeb}
        .combo-app .lis:disabled{cursor:default; opacity:.85}

        /* ── test footer: comment + sign-off ─────────────────────── */
        .combo-app .tfoot{background:#fff; border-top:1px solid var(--bd)}
        .combo-app .tfoot>.sec{padding:12px 16px; border-top:1px solid var(--bd)}
        .combo-app .tfoot>.sec:first-child{border-top:0; background:#fbfcfe}
        .combo-app .sec-t{display:flex; align-items:center; gap:6px; font-size:.74rem; font-weight:800;
            color:var(--ink); margin-bottom:8px; text-transform:none}
        .combo-app .sec-t i{width:24px; height:24px; border-radius:50%; background:var(--ps); color:var(--p);
            display:inline-flex; align-items:center; justify-content:center; font-size:.66rem; flex:none}
        .combo-app .f-c{width:100%; min-height:64px; resize:vertical; border:1px solid #cdd8e6; border-radius:9px;
            padding:8px 10px; font:500 .9rem/1.5 inherit; color:var(--ink); background:#fff}
        .combo-app .f-c:focus{outline:0; border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.15)}
        .combo-app .signoff-panel{background:transparent; border:0; border-radius:0; padding:12px 16px; margin-top:0;
            display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px 16px}
        .combo-app .signoff-group{display:flex; flex-direction:column; gap:3px; flex:1 1 150px; min-width:0}
        .combo-app .signoff-group label{font-size:.7rem; font-weight:800; color:var(--mut); margin:0}
        .combo-app .signoff-group label i{color:var(--p); margin-right:4px}
        .combo-app .signoff-group input{width:100%; min-width:0; padding:6px 9px; border:1px solid #c5c8d4;
            border-radius:7px; font-size:.9rem; color:var(--ink); height:34px; background:#fff}
        .combo-app .signoff-check{display:flex; align-items:center; gap:7px; font-size:.9rem; font-weight:700;
            color:var(--ink); padding:6px 14px; background:#fff; border:1.5px solid #c9d4e3; border-radius:20px; margin:0}
        .combo-app .signoff-check input{width:16px; height:16px; accent-color:var(--ok)}
        .combo-app .btn-comment-toggle{display:inline-flex; align-items:center; gap:5px; padding:5px 13px; font-size:.8rem;
            border-radius:20px; border:1px dashed #c9d4e3; background:#fff; color:#334155; cursor:pointer;
            transition:.15s; font-weight:700}
        .combo-app .btn-comment-toggle:hover,
        .combo-app .btn-comment-toggle.active{background:var(--ps); color:var(--pd); border-color:var(--p); border-style:solid}
        .combo-app .category-panel{border-top:1px solid var(--bd); background:linear-gradient(135deg,#f6f8fc,#eef3fb);
            padding:10px 16px; display:flex; flex-wrap:wrap; align-items:flex-end; gap:8px 12px}
        .combo-app .category-panel .btn-apply{background:var(--p); color:#fff; border:0; border-radius:8px; padding:7px 16px; font-weight:700; font-size:.82rem}
        .combo-app .category-panel .btn-apply:hover{background:var(--pd)}
        .combo-app .category-panel .btn-reset{background:#fff; border:1px solid #c9d4e3; color:#334155; border-radius:8px; padding:7px 16px; font-weight:700; font-size:.82rem}
        .combo-app .select2-container--default .select2-selection--single{border:1px solid #cfd9e6 !important; border-radius:7px !important; min-height:34px}
        .combo-app .select2-container--default.select2-container--focus .select2-selection--single{border-color:var(--p) !important; box-shadow:0 0 0 3px rgba(31,95,191,.16)}
        .combo-app .select2-container--default .select2-selection--single .select2-selection__rendered{color:var(--ink) !important; font-weight:600; line-height:32px}

        /* ── easy mode (flat worklist, mirror inputs) ────────────── */
        .combo-app.mode-easy .cat,
        .combo-app.mode-easy .tc{display:none !important}
        .combo-app.mode-single .cat{display:none}
        .combo-app.mode-single .cat.current{display:block}
        .combo-app.mode-single .cat.current .cat-h{cursor:default}
        .combo-app.mode-single .cat.current .tc{display:none}
        .combo-app.mode-single .cat.current .tc.current{display:block}
        .combo-app #easyHost{display:none}
        .combo-app.mode-easy #easyHost{display:block}
        .combo-app .easy-card{background:#fff; border:1px solid var(--bd); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm)}
        .combo-app .easy-card .wrap{overflow-x:auto}
        .combo-app .ct-easy{width:100%; table-layout:fixed; border-collapse:collapse; font-size:.95rem}
        .combo-app .ct-easy th{font-size:.72rem; font-weight:700; color:#5b6b82; background:#f6f8fc; padding:9px 10px;
            border-bottom:1px solid var(--bd); text-align:left; white-space:nowrap}
        .combo-app .ct-easy td{padding:5px 10px; border-bottom:1px solid #eef2f7; vertical-align:middle}
        .combo-app .ct-easy tbody tr:nth-child(even) td{background:#fbfcfe}
        .combo-app .ct-easy .e-sl{width:4%; text-align:center; color:var(--mut); font-weight:700}
        .combo-app .ct-easy .e-n{width:34%; font-weight:700; color:var(--ink)}
        .combo-app .ct-easy .e-r{width:26%}
        .combo-app .ct-easy .e-u{width:12%}
        .combo-app .ct-easy .e-s{width:24%}
        .combo-app .ct-easy .f{width:100%; height:32px; padding:3px 9px; border:1px solid #cfd9e6; border-radius:7px;
            background:#fff; color:var(--ink); font-size:.92rem; font-weight:600}
        .combo-app .ct-easy textarea.f{height:auto; min-height:44px}
        .combo-app .ct-easy .f:focus{outline:0; border-color:var(--p); box-shadow:0 0 0 3px rgba(31,95,191,.16); background:#fffef5}
        .combo-app .ct-easy tr.eb td{background:var(--ps); border-top:1px solid #cfe0f6; padding:8px 12px}
        .combo-app .ct-easy tr.eb{cursor:default}
        .combo-app .eb-in{display:flex; align-items:center; gap:10px; flex-wrap:wrap}
        .combo-app .eb-n{display:flex; align-items:center; gap:8px; color:var(--ink); font-weight:700}
        .combo-app .eb-n i{color:var(--cc,var(--p)); font-size:.6rem}
        .combo-app .eb-n small{color:var(--mut); font-weight:600}
        .combo-app .eb-in .sp{flex:1}
        .combo-app .ct-easy tr.r-title td{background:#f1f5f9; font-weight:800; color:var(--ink); font-size:.88rem; padding:6px 12px}

        /* ── save bar ────────────────────────────────────────────── */
        .combo-app .savebar{position:sticky; bottom:0; z-index:30; background:#fff; border-top:1px solid var(--bd);
            padding:9px 18px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;
            box-shadow:0 -4px 14px rgba(11,31,58,.06); border-radius:0 0 var(--radius) var(--radius)}
        .combo-app .savebar .hint{margin-left:auto; color:var(--mut); font-size:.76rem; display:flex; align-items:center; gap:4px; flex-wrap:wrap}
        .combo-app kbd{background:#f1f5f9; border:1px solid #cbd5e1; border-bottom-width:2px; border-radius:4px;
            padding:0 5px; font-size:.72rem; color:#334155; font-family:inherit}
        .combo-app .dirty{display:none; color:#b45309; font-weight:800; font-size:.82rem; align-items:center; gap:5px}
        .combo-app .dirty.show{display:inline-flex}

        /* ── save indicator (reused classes from bulk page) ──────── */
        .combo-app .save-indicator .alert{margin:0}

        /* ── responsive ──────────────────────────────────────────── */
        @media (max-width: 1200px){
            .combo-app .component-table .col-name{width:24%}
            .combo-app .component-table .col-result{width:26%}
            .combo-app .component-table .col-ref{width:16%}
        }
        @media (max-width: 992px){
            .combo-app .side{position:absolute; z-index:40; top:0; bottom:0; left:0; transform:translateX(-102%);
                transition:transform .2s; box-shadow:6px 0 24px rgba(11,31,58,.18); max-height:none}
            .combo-app .side.open{transform:none}
            .combo-app #btnSide{display:inline-flex}
            .combo-app .ctl{gap:8px}
            .combo-app .rpt-search{flex:1 1 140px}
            .combo-app #body{padding:12px 12px 24px}
        }
        @media (max-width: 768px){
            .combo-app .top{padding:8px 10px; gap:6px}
            .combo-app .pn{font-size:.95rem}
            .combo-app .kpi{padding:6px 10px; flex-wrap:nowrap; overflow-x:auto}
            .combo-app .kpi .kchip{flex:none}
            .combo-app .sub{padding:6px 10px}
            .combo-app .seg button{padding:5px 9px; font-size:.76rem}
            .combo-app .component-table{min-width:640px}
            .combo-app .component-table td{padding:4px 6px; font-size:.88rem}
            .combo-app .savebar{padding:7px 10px; gap:6px}
            .combo-app .savebar .hint{display:none}
            .combo-app .btn-x{padding:5px 10px; font-size:.8rem}
            .combo-app .signoff-panel{flex-direction:column; align-items:stretch}
            .combo-app .signoff-group{flex:1 1 100%}
        }
        @media (max-width: 480px){
            .combo-app .component-table{min-width:560px}
            .combo-app .cat-h{padding:8px 10px}
            .combo-app .th{padding:9px 10px}
        }

        /* ── compat & state helpers ─────────────────────────────── */
        .combo-app #btnSide{display:none}
        .combo-app .category-container.is-hidden{display:none !important}
        .combo-app .chip.on,
        .combo-app .chip.active{background:var(--p); border-color:var(--p); color:#fff}
        .combo-app .chip.active small{opacity:.8}
        .combo-app .tc.is-dirty .th{box-shadow:inset 3px 0 0 var(--warn)}
        .combo-app.tight .table-input,
        .combo-app.tight .table-select,
        .combo-app.tight .table-textarea,
        .combo-app.tight .status-input,
        .combo-app.tight .culture-interp{height:28px; font-size:.86rem; padding:2px 7px}
        .combo-app.tight .component-table td{padding:2px 8px}
        .combo-app.tight .component-table th{padding:6px 8px}
        .combo-app.tight .th{padding:7px 12px}
        .combo-app.tight .tfoot>.sec{padding:8px 12px}
        .combo-app.tight textarea.table-textarea{min-height:36px}
    </style>
@endsection

@section('content')
    @php
        $initials = mb_strtoupper(mb_substr(preg_replace('/\s+/', '', (string) ($receipt->patient->name ?? 'NP')), 0, 2));
        $catPalette = [
            '#1f5fbf', '#168a4a', '#c9730a', '#0e7490', '#c23030',
            '#7c3aed', '#0284c7', '#059669', '#d97706', '#4c3fd9',
        ];
        $ci = 0;
    @endphp

    <section class="invoice-add-wrapper">
        <div class="combo-app mode-bulk" id="comboApp"
             data-receipt-id="{{ $receipt->id }}"
             data-update-url="{{ route('lab.report.combo.update') }}"
             data-lis-url="{{ route('lab.report.combo.lis', $receipt->id) }}">

            {{-- ═══════════ TOP BAR ═══════════ --}}
            <header class="top">
                <div class="pt-strip">
                    <button class="btn-x icon" id="btnSide" type="button" title="Tests"><i class="fas fa-list"></i></button>
                    <div class="av">{{ $initials }}</div>
                    <div class="pt-info">
                        <div class="pn" id="patient-info">
                            {{ $receipt->patient->name ?? 'No Patient' }}
                            @if (! empty($receipt->patient->age_format) || ! empty($receipt->patient->gender))
                                <span class="tag">
                                    {{ trim(($receipt->patient->age_format ?? '') . ' · ' . ($receipt->patient->gender ?? ''), ' ·') }}
                                </span>
                            @endif
                        </div>
                        <div class="pm">
                            @if (! empty($receipt->barcode))
                                <span><i class="fas fa-barcode"></i>{{ $receipt->barcode }}</span>
                            @endif
                            @if (! empty($receipt->sl_no))
                                <span><i class="fas fa-receipt"></i>{{ $receipt->sl_no }}</span>
                            @endif
                            @if (! empty($receipt->referral?->name))
                                <span><i class="fas fa-user-md"></i>{{ $receipt->referral->name }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="ctl">
                    <div class="cg-x"><span class="cl">View</span>
                        <div class="seg" id="segMode">
                            <button type="button" data-m="single"><i class="fas fa-file-medical"></i> Single</button>
                            <button type="button" data-m="bulk" class="on"><i class="fas fa-th-list"></i> Bulk</button>
                            <button type="button" data-m="easy" title="One flat worklist for all tests"><i class="fas fa-table"></i> Easy</button>
                        </div>
                    </div>
                    <div class="cg-x"><span class="cl">Density</span>
                        <div class="seg" id="segDen">
                            <button type="button" data-d="cozy" class="on">Cozy</button>
                            <button type="button" data-d="tight">Compact</button>
                        </div>
                    </div>
                    <div class="rpt-search">
                        <label class="has-float-label mb-0">
                            <input type="search"
                                   class="form-control form-control-sm search-receipt"
                                   id="find"
                                   data-redirect="lab/report/combo-edit/"
                                   autocomplete="off"
                                   title="Lab Receipt No">
                            <span>Receipt No</span>
                        </label>
                    </div>
                    <div class="pgrp">
                        <a class="btn-x icon" title="Previous receipt"
                           href="{{ route('lab.report.combo.shift', ['id' => $receipt->id, 'd' => 'previous']) }}">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        <a class="btn-x icon" title="Next receipt"
                           href="{{ route('lab.report.combo.shift', ['id' => $receipt->id, 'd' => 'next']) }}">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                        <button type="button" class="btn-x icon" id="btnLisRefresh"
                                title="Reload LIS values"><i class="fas fa-satellite-dish"></i></button>
                        <a class="btn-x icon" title="Grid bulk edit"
                           href="{{ route('lab.report.bulk.edit.receipt', $receipt->id) }}">
                            <i class="fas fa-layer-group"></i>
                        </a>
                    </div>
                </div>
            </header>

            {{-- ═══════════ KPI CHIPS ═══════════ --}}
            <div class="kpi" id="kpi">
                <span class="kchip okc"><i class="fas fa-flask"></i> Tests <b id="kpiDone">{{ $stats['completed_tests'] ?? 0 }}/{{ $stats['total_tests'] ?? 0 }}</b></span>
                <span class="kchip"><i class="fas fa-pen"></i> Filled <b id="kpiFilled">0</b></span>
                <button type="button" class="kchip lisc" id="kpiLisBtn" title="Accept all LIS values"><i class="fas fa-satellite-dish"></i> LIS <b id="kpiLis">0</b></button>
                <span class="kchip dirt"><i class="fas fa-circle"></i> Unsaved <b id="kpiDirty">0</b></span>
                <span class="kchip"><i class="fas fa-user-edit"></i> Prepared <b>{{ $stats['prepared_count'] ?? 0 }}</b></span>
                <span class="kchip"><i class="fas fa-search"></i> Reviewed <b>{{ $stats['reviewed_count'] ?? 0 }}</b></span>
                <span class="kchip"><i class="fas fa-stamp"></i> Authorized <b>{{ $stats['authorized_count'] ?? 0 }}</b></span>
            </div>

            <div class="shell">
                {{-- ═══════════ SIDEBAR ═══════════ --}}
                <aside class="side" id="side">
                    <div class="sb-top">
                        <input type="search" id="q" autocomplete="off" placeholder="Search test...">
                        <div class="sb-flt" id="sbf">
                            <button type="button" data-f="all" class="on"><i class="fas fa-list"></i> All</button>
                            <button type="button" data-f="pending"><i class="far fa-clock"></i> Pending</button>
                            <button type="button" data-f="done"><i class="fas fa-check"></i> Done</button>
                        </div>
                    </div>
                    <div id="sbl">
                        @if($groupedTests->count())
                            @foreach($groupedTests as $category => $tests)
                                @php
                                    $pal = $catPalette[$ci % count($catPalette)];
                                    $ci++;
                                @endphp
                                <div class="sb-cat" data-cat="{{ $category }}" style="--cc: {{ $pal }};">
                                    <span>{{ $category }}</span>
                                    <span>{{ $tests->where('done', 1)->count() }}/{{ $tests->count() }}</span>
                                </div>
                                @foreach($tests as $test)
                                    @php
                                        $compsVisible = $test->components->where('type', '!=', 'title')->where('hide', 0);
                                        $filled = $compsVisible->filter(function ($c) {
                                            $v = $c->type === 'textarea' ? ($c->result_text ?? '') : ($c->result ?? '');
                                            if ($c->type === 'culture') {
                                                $v = trim(($c->result ?? '') . ($c->mic_value ?? '') . ($c->zone_value ?? ''));
                                            }
                                            return trim((string) $v) !== '';
                                        })->count();
                                        $pct = $compsVisible->count() > 0 ? round(($filled / $compsVisible->count()) * 100) : 0;
                                    @endphp
                                    <div class="sb-i {{ $test->done ? 'done' : '' }}"
                                         data-target="{{ $test->id }}" data-cat="{{ $category }}"
                                         data-name="{{ strtolower(($test->test->name ?? '') . ' ' . ($test->test->short_name ?? '')) }}"
                                         style="--cc: {{ $pal }};">
                                        <span class="sdot"></span>
                                        <span class="sb-n">{{ $test->test->name ?? 'Unknown' }}<small>{{ $test->test->short_name ?? '' }}</small></span>
                                        <span class="sb-s">{{ $test->done ? 'Done' : 'Pending' }}</span>
                                        <span class="sb-p"><i style="width: {{ $pct }}%;"></i></span>
                                    </div>
                                @endforeach
                            @endforeach
                        @else
                            <div class="sb-none">No tests</div>
                        @endif
                    </div>
                </aside>

                {{-- ═══════════ MAIN ═══════════ --}}
                <main class="main">
                    <div class="sub" id="sub">
                        <div class="l">
                            <div class="chips" id="catChips">
                                <button type="button" class="chip cat-filter-btn on" data-category="__all__">All <small>{{ $stats['total_tests'] ?? 0 }}</small></button>
                                @foreach($groupedTests as $category => $tests)
                                    <button type="button" class="chip cat-filter-btn" data-category="{{ $category }}">
                                        {{ $category }} <small>{{ $tests->count() }}</small>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        <div class="prog">
                            <div class="bar"><i id="pbar"></i></div>
                            <small id="ptxt">0%</small>
                        </div>
                    </div>

                    <div id="body">
                        <form id="combo-form"
                              action="{{ route('lab.report.combo.update') }}"
                              method="post" enctype="multipart/form-data" autocomplete="off">
                            @csrf
                            <input type="hidden" name="receipt_id" value="{{ $receipt->id }}">
                            @if($groupedTests->count())
                                @php $globalTestIndex = 0; @endphp
                                @foreach($groupedTests as $category => $tests)
                                    @php
                                        $pal = $catPalette[$ci % count($catPalette)];
                                        $ci++;
                                    @endphp
                                    <div class="card card-primary mt-1 category-container cat"
                                         data-category="{{ $category }}" style="--cc: {{ $pal }};">

                                        {{-- ── category header ── --}}
                                        <div class="card-header py-h category-header cat-h">
                                            <div class="row col-12 m-0">
                                                <div class="col-12 col-md-6 px-0"
                                                     onclick="toggleCategory(this)" style="cursor:pointer;">
                                                    <h5 class="mb-0">
                                                        <i class="fas fa-folder"></i> {{ $category }}
                                                        <span class="badge badge-primary">{{ $tests->count() }}</span>
                                                        @php $doneCat = $tests->where('done', 1)->count(); @endphp
                                                        <span class="badge badge-{{ $doneCat === $tests->count() ? 'success' : 'warning' }}">
                                                            {{ $doneCat }}/{{ $tests->count() }}
                                                        </span>
                                                    </h5>
                                                </div>
                                                <div class="col-12 col-md-6 text-right px-0">
                                                    <div class="actions-wrapper justify-content-end">
                                                        <div class="category-actions cat-actions">
                                                            <button type="button" class="btn category-save-btn"
                                                                    onclick="saveCategory(this, '{{ $category }}')" title="Save category">
                                                                <i class="fas fa-save"></i>
                                                            </button>
                                                            <button type="button" class="btn category-complete-btn"
                                                                    onclick="completeCategory(this, '{{ $category }}')" title="Complete category">
                                                                <i class="fas fa-check-double"></i>
                                                            </button>
                                                            <button type="button" class="btn category-print-btn"
                                                                    onclick="printCategory('{{ $category }}')" title="Print category">
                                                                <i class="fas fa-print"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-secondary"
                                                                    style="background:#fff; border:1px solid #c9d4e3; color:#334155;"
                                                                    onclick="toggleCategory(this.closest('.category-header'))">
                                                                <i class="fas fa-chevron-up toggle-icon"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card-body p-0 category-content cat-b">
                                            @foreach($tests as $test)
                                                @php
                                                    $tConfig      = $test->test?->config;
                                                    $tShowStatus  = (bool) ($tConfig?->show_status ?? false);
                                                    $tShowColor   = (bool) ($tConfig?->result_color ?? false);
                                                    $tShowMic     = (bool) ($tConfig?->culture_show_mic ?? false);
                                                    $tShowZone    = (bool) ($tConfig?->culture_show_zone ?? false);
                                                    $tTailColspan = 3 + ($tShowStatus ? 1 : 0) + ($tShowColor ? 1 : 0);
                                                    $tTotalCols   = 6 + ($tShowStatus ? 1 : 0) + ($tShowColor ? 1 : 0);
                                                    $tTableClass  = 'component-table'
                                                                  . ($tShowStatus ? '' : ' no-status')
                                                                  . ($tShowColor ? '' : ' no-color');
                                                    $customJsKey  = $tConfig?->custom_js;
                                                @endphp
                                                <div class="card card-primary m-1 test-card tc {{ $test->done ? 'completed done' : 'pending' }}"
                                                     data-test-id="{{ $test->id }}"
                                                     data-test-name="{{ $test->test->name ?? '' }}"
                                                     data-cat="{{ $category }}"
                                                     @if($customJsKey) data-custom-js="{{ $customJsKey }}" @endif>
                                                    <div class="card-body p-0">

                                                        {{-- ── test header ── --}}
                                                        <div class="py-h px-2 test-header th" style="cursor:pointer;" onclick="toggleTest(this)">
                                                            <div class="row col-12 m-0">
                                                                <div class="col-12 col-md-8 px-0">
                                                                    <span class="tt"><b>{{ $loop->iteration }}) {{ $test->test->name ?? 'Unknown' }}</b><span class="mut">({{ $test->test->short_name ?? '' }})</span></span>
                                                                    <span class="pill st {{ $test->done ? 'ok' : 'wait' }}">{{ $test->done ? 'Completed' : 'Pending' }}</span>
                                                                    @if($test->prepared_at)<span class="pill" title="Prepared">P</span>@endif
                                                                    @if($test->reviewed_at)<span class="pill" title="Reviewed">R</span>@endif
                                                                    @if($test->authorized_at)<span class="pill" title="Authorized">A</span>@endif
                                                                    <button type="button" class="btn-x sm lis-accept" data-act="lis1"
                                                                            data-t="{{ $test->id }}" style="display:none;">
                                                                        <i class="fas fa-satellite-dish"></i> <span>Accept LIS</span>
                                                                    </button>
                                                                    <span class="test-validation-badge" style="display:none;"></span>
                                                                </div>
                                                                <div class="col-12 col-md-4 text-right px-0">
                                                                    <div class="d-inline-flex gap-1 align-items-center">
                                                                        <button type="button" class="ib" data-act="tsave"
                                                                                data-t="{{ $test->id }}" title="Save this test">
                                                                            <i class="fas fa-save"></i>
                                                                        </button>
                                                                        <a class="ib" title="Print"
                                                                           href="{{ route('lab.report.print', ['rid' => $test->lab_id, 'tid' => $test->id]) }}"
                                                                           onclick="event.stopPropagation(); windowsPrint(this.href); return false;">
                                                                            <i class="fas fa-print"></i>
                                                                        </a>
                                                                        <a class="ib" title="Blank print"
                                                                           href="{{ route('lab.report.blank.print', ['rid' => $test->lab_id, 'tid' => $test->id]) }}"
                                                                           onclick="event.stopPropagation(); windowsPrint(this.href); return false;">
                                                                            <i class="far fa-file-word"></i>
                                                                        </a>
                                                                        <i class="fas fa-chevron-down toggle-icon-sm"></i>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <input type="hidden" name="tests[{{ $globalTestIndex }}][id]" value="{{ $test->id }}">
                                                        <input type="hidden" name="tests[{{ $globalTestIndex }}][complete]"
                                                               value="{{ $test->done ? 1 : 0 }}"
                                                               class="test-complete"
                                                               data-test-id="{{ $test->id }}">

                                                        <div class="test-details tb">
                                                            <div class="component-wrap wrap">
                                                                <table class="{{ $tTableClass }}">
                                                                    <thead>
                                                                        <tr>
                                                                            <th class="col-sl">SL</th>
                                                                            <th class="col-hide"><i class="fas fa-eye-slash"></i></th>
                                                                            <th class="col-name">Component</th>
                                                                            <th class="col-result">Result</th>
                                                                            <th class="col-unit">Unit</th>
                                                                            <th class="col-ref">Reference</th>
                                                                            @if($tShowStatus)<th class="col-status">Status</th>@endif
                                                                            @if($tShowColor)<th class="col-color">Color</th>@endif
                                                                        </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                        @php $currentTitleId = null; @endphp
                                                                        @if($test->components->count())
                                                                            @foreach($test->components as $comp)
                                                                                @if($comp->type == 'title')
                                                                                    @php $currentTitleId = 'title-' . $comp->id; @endphp
                                                                                    <tr class="row-title"
                                                                                        data-title-id="{{ $currentTitleId }}"
                                                                                        data-component-id="{{ $comp->id }}">
                                                                                        <td class="col-sl"></td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-check-title" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hide) checked @endif value="1"
                                                                                                   onchange="handleTitleHideChange(this)">
                                                                                        </td>
                                                                                        <td colspan="{{ $tTotalCols - 2 }}"><b>{{ $comp->name }}</b></td>
                                                                                    </tr>

                                                                                @elseif($comp->type == 'number')
                                                                                    <tr class="component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result">
                                                                                            <input type="text" class="table-input component-value changeStatus"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result]"
                                                                                                   value="{{ $comp->result ?? '' }}" autocomplete="off">
                                                                                        </td>
                                                                                        <td class="col-unit">
                                                                                            <input type="text" class="table-input"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][unit]"
                                                                                                   value="{{ $comp->unit ?? '' }}" placeholder="Unit">
                                                                                        </td>
                                                                                        <td class="col-ref">
                                                                                            @if(!empty($comp->available_references))
                                                                                                <div class="ref-picker" data-ref-picker>
                                                                                                    <input type="hidden" class="ref-hidden"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                           value="{{ $comp->reference_text ?? '' }}">
                                                                                                    <span class="ref-text ref-display" title="Click to change reference"
                                                                                                          onclick="openRefSelect(this)">
                                                                                                        {{ $comp->reference_text ?: '—' }}
                                                                                                    </span>
                                                                                                    <select class="ref-select ref-editor" style="display:none;"
                                                                                                            onchange="applyRefSelect(this)" onblur="closeRefSelect(this)">
                                                                                                        <option value="">—</option>
                                                                                                        @foreach($comp->available_references as $ref)
                                                                                                            <option value="{{ $ref }}" @selected($comp->reference_text === $ref)>{{ $ref }}</option>
                                                                                                        @endforeach
                                                                                                    </select>
                                                                                                </div>
                                                                                            @elseif($comp->reference_text)
                                                                                                <input type="hidden"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                       value="{{ $comp->reference_text }}">
                                                                                                <span class="ref-text">{{ $comp->reference_text }}</span>
                                                                                            @endif
                                                                                        </td>
                                                                                        @if($tShowStatus)
                                                                                            <td class="col-status">
                                                                                                <input type="text" class="status-input"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][status]"
                                                                                                       value="{{ $comp->status ?? '' }}" list="status-list" placeholder="—">
                                                                                            </td>
                                                                                        @endif
                                                                                        @if($tShowColor)
                                                                                            <td class="col-color">
                                                                                                <div class="color-cell-wrap">
                                                                                                    <span class="color-swatch {{ $comp->color ? '' : 'empty' }}"
                                                                                                          style="{{ $comp->color ? 'background:#'.$comp->color : '' }}"></span>
                                                                                                    <select class="color-select"
                                                                                                            name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][color]"
                                                                                                            onchange="updateColorPreview(this)">
                                                                                                        <option value="" {{ !$comp->color ? 'selected' : '' }}>—</option>
                                                                                                        <option value="28c76f" {{ $comp->color == '28c76f' ? 'selected' : '' }}>Normal</option>
                                                                                                        <option value="ea5455" {{ $comp->color == 'ea5455' ? 'selected' : '' }}>Critical</option>
                                                                                                        <option value="ff9f43" {{ $comp->color == 'ff9f43' ? 'selected' : '' }}>Warning</option>
                                                                                                        <option value="ffd93d" {{ $comp->color == 'ffd93d' ? 'selected' : '' }}>Caution</option>
                                                                                                        <option value="00cfe8" {{ $comp->color == '00cfe8' ? 'selected' : '' }}>Info</option>
                                                                                                        <option value="1f5fbf" {{ $comp->color == '1f5fbf' ? 'selected' : '' }}>Special</option>
                                                                                                        <option value="ff6b9d" {{ $comp->color == 'ff6b9d' ? 'selected' : '' }}>Note</option>
                                                                                                        <option value="a0a3b1" {{ $comp->color == 'a0a3b1' ? 'selected' : '' }}>Neutral</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                            </td>
                                                                                        @endif
                                                                                    </tr>

                                                                                @elseif($comp->type == 'select' || $comp->type == 'pattern')
                                                                                    <tr class="component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result" colspan="2">
                                                                                            <input type="search" class="table-input component-value dynamic-input"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result]"
                                                                                                   value="{{ $comp->result ?? '' }}" autocomplete="off"
                                                                                                   data-dynamic="id" data-id="{{ $comp->id }}"
                                                                                                   multiple data-multiple="true"
                                                                                                   data-key="option_value" data-value="option_value"
                                                                                                   data-table="test_components"
                                                                                                   data-url="ajax/get-option-separate">
                                                                                        </td>
                                                                                        <td class="col-ref">
                                                                                            @if(!empty($comp->available_references))
                                                                                                <div class="ref-picker" data-ref-picker>
                                                                                                    <input type="hidden" class="ref-hidden"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                           value="{{ $comp->reference_text ?? '' }}">
                                                                                                    <span class="ref-text ref-display" title="Click to change reference"
                                                                                                          onclick="openRefSelect(this)">
                                                                                                        {{ $comp->reference_text ?: '—' }}
                                                                                                    </span>
                                                                                                    <select class="ref-select ref-editor" style="display:none;"
                                                                                                            onchange="applyRefSelect(this)" onblur="closeRefSelect(this)">
                                                                                                        <option value="">—</option>
                                                                                                        @foreach($comp->available_references as $ref)
                                                                                                            <option value="{{ $ref }}" @selected($comp->reference_text === $ref)>{{ $ref }}</option>
                                                                                                        @endforeach
                                                                                                    </select>
                                                                                                </div>
                                                                                            @elseif($comp->reference_text)
                                                                                                <input type="hidden"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                       value="{{ $comp->reference_text }}">
                                                                                                <span class="ref-text">{{ $comp->reference_text }}</span>
                                                                                            @endif
                                                                                        </td>
                                                                                        @if($tShowStatus)
                                                                                            <td class="col-status">
                                                                                                <input type="text" class="status-input"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][status]"
                                                                                                       value="{{ $comp->status ?? '' }}" list="status-list" placeholder="—">
                                                                                            </td>
                                                                                        @endif
                                                                                        @if($tShowColor)
                                                                                            <td class="col-color">
                                                                                                <div class="color-cell-wrap">
                                                                                                    <span class="color-swatch {{ $comp->color ? '' : 'empty' }}"
                                                                                                          style="{{ $comp->color ? 'background:#'.$comp->color : '' }}"></span>
                                                                                                    <select class="color-select"
                                                                                                            name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][color]"
                                                                                                            onchange="updateColorPreview(this)">
                                                                                                        <option value="" {{ !$comp->color ? 'selected' : '' }}>—</option>
                                                                                                        <option value="28c76f" {{ $comp->color == '28c76f' ? 'selected' : '' }}>Normal</option>
                                                                                                        <option value="ea5455" {{ $comp->color == 'ea5455' ? 'selected' : '' }}>Critical</option>
                                                                                                        <option value="ff9f43" {{ $comp->color == 'ff9f43' ? 'selected' : '' }}>Warning</option>
                                                                                                        <option value="ffd93d" {{ $comp->color == 'ffd93d' ? 'selected' : '' }}>Caution</option>
                                                                                                        <option value="00cfe8" {{ $comp->color == '00cfe8' ? 'selected' : '' }}>Info</option>
                                                                                                        <option value="1f5fbf" {{ $comp->color == '1f5fbf' ? 'selected' : '' }}>Special</option>
                                                                                                        <option value="ff6b9d" {{ $comp->color == 'ff6b9d' ? 'selected' : '' }}>Note</option>
                                                                                                        <option value="a0a3b1" {{ $comp->color == 'a0a3b1' ? 'selected' : '' }}>Neutral</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                            </td>
                                                                                        @endif
                                                                                    </tr>

                                                                                @elseif($comp->type == 'text')
                                                                                    <tr class="component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result" colspan="2">
                                                                                            <input type="search" class="table-input component-value dynamic-input"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result]"
                                                                                                   value="{{ $comp->result ?? '' }}" autocomplete="off"
                                                                                                   data-dynamic="test_id,component_id"
                                                                                                   data-test_id="{{ $comp->test_id ?? '' }}"
                                                                                                   data-component_id="{{ $comp->id ?? '' }}"
                                                                                                   data-key="result" data-value="result"
                                                                                                   data-table="test_reports"
                                                                                                   data-url="ajax/get-dynamic-option">
                                                                                        </td>
                                                                                        <td class="col-ref">
                                                                                            @if(!empty($comp->available_references))
                                                                                                <div class="ref-picker" data-ref-picker>
                                                                                                    <input type="hidden" class="ref-hidden"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                           value="{{ $comp->reference_text ?? '' }}">
                                                                                                    <span class="ref-text ref-display" title="Click to change reference"
                                                                                                          onclick="openRefSelect(this)">
                                                                                                        {{ $comp->reference_text ?: '—' }}
                                                                                                    </span>
                                                                                                    <select class="ref-select ref-editor" style="display:none;"
                                                                                                            onchange="applyRefSelect(this)" onblur="closeRefSelect(this)">
                                                                                                        <option value="">—</option>
                                                                                                        @foreach($comp->available_references as $ref)
                                                                                                            <option value="{{ $ref }}" @selected($comp->reference_text === $ref)>{{ $ref }}</option>
                                                                                                        @endforeach
                                                                                                    </select>
                                                                                                </div>
                                                                                            @elseif($comp->reference_text)
                                                                                                <input type="hidden"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                       value="{{ $comp->reference_text }}">
                                                                                                <span class="ref-text">{{ $comp->reference_text }}</span>
                                                                                            @endif
                                                                                        </td>
                                                                                        @if($tShowStatus)
                                                                                            <td class="col-status">
                                                                                                <input type="text" class="status-input"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][status]"
                                                                                                       value="{{ $comp->status ?? '' }}" list="status-list" placeholder="—">
                                                                                            </td>
                                                                                        @endif
                                                                                        @if($tShowColor)
                                                                                            <td class="col-color">
                                                                                                <div class="color-cell-wrap">
                                                                                                    <span class="color-swatch {{ $comp->color ? '' : 'empty' }}"
                                                                                                          style="{{ $comp->color ? 'background:#'.$comp->color : '' }}"></span>
                                                                                                    <select class="color-select"
                                                                                                            name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][color]"
                                                                                                            onchange="updateColorPreview(this)">
                                                                                                        <option value="" {{ !$comp->color ? 'selected' : '' }}>—</option>
                                                                                                        <option value="28c76f" {{ $comp->color == '28c76f' ? 'selected' : '' }}>Normal</option>
                                                                                                        <option value="ea5455" {{ $comp->color == 'ea5455' ? 'selected' : '' }}>Critical</option>
                                                                                                        <option value="ff9f43" {{ $comp->color == 'ff9f43' ? 'selected' : '' }}>Warning</option>
                                                                                                        <option value="ffd93d" {{ $comp->color == 'ffd93d' ? 'selected' : '' }}>Caution</option>
                                                                                                        <option value="00cfe8" {{ $comp->color == '00cfe8' ? 'selected' : '' }}>Info</option>
                                                                                                        <option value="1f5fbf" {{ $comp->color == '1f5fbf' ? 'selected' : '' }}>Special</option>
                                                                                                        <option value="ff6b9d" {{ $comp->color == 'ff6b9d' ? 'selected' : '' }}>Note</option>
                                                                                                        <option value="a0a3b1" {{ $comp->color == 'a0a3b1' ? 'selected' : '' }}>Neutral</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                            </td>
                                                                                        @endif
                                                                                    </tr>

                                                                                @elseif($comp->type == 'culture')
                                                                                    @php
                                                                                        /* italic only when no report saved yet but a resolved value exists */
                                                                                        $micIsDefault  = empty($comp->report_id) && ! empty($comp->mic_value);
                                                                                        $zoneIsDefault = empty($comp->report_id) && ! empty($comp->zone_value);
                                                                                    @endphp
                                                                                    <tr class="component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result" colspan="2">
                                                                                            <div class="culture-input-group">
                                                                                                @if($tShowMic)
                                                                                                    <input type="text"
                                                                                                           class="table-input culture-mic {{ $micIsDefault ? 'is-default' : '' }}"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][mic_value]"
                                                                                                           value="{{ old('tests.'.$globalTestIndex.'.components.'.$comp->id.'.mic_value', $comp->mic_value ?? '') }}"
                                                                                                           placeholder="MIC" autocomplete="off">
                                                                                                @endif
                                                                                                @if($tShowZone)
                                                                                                    <input type="text"
                                                                                                           class="table-input culture-zone {{ $zoneIsDefault ? 'is-default' : '' }}"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][zone_value]"
                                                                                                           value="{{ old('tests.'.$globalTestIndex.'.components.'.$comp->id.'.zone_value', $comp->zone_value ?? '') }}"
                                                                                                           placeholder="Zone" autocomplete="off">
                                                                                                @endif
                                                                                                <select class="culture-interp interp-{{ strtolower($comp->result ?? 'nt') }}"
                                                                                                        name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result]"
                                                                                                        onchange="styleInterpSelect(this)">
                                                                                                    <option value=""   {{ !$comp->result ? 'selected' : '' }}>—</option>
                                                                                                    <option value="S"  {{ $comp->result === 'S' ? 'selected' : '' }}>S</option>
                                                                                                    <option value="I"  {{ $comp->result === 'I' ? 'selected' : '' }}>I</option>
                                                                                                    <option value="R"  {{ $comp->result === 'R' ? 'selected' : '' }}>R</option>
                                                                                                    <option value="NT" {{ $comp->result === 'NT' ? 'selected' : '' }}>NT</option>
                                                                                                </select>
                                                                                            </div>
                                                                                        </td>
                                                                                        <td class="col-ref">
                                                                                            @if(!empty($comp->available_references))
                                                                                                <div class="ref-picker" data-ref-picker>
                                                                                                    <input type="hidden" class="ref-hidden"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                           value="{{ $comp->reference_text ?? '' }}">
                                                                                                    <span class="ref-text ref-display" title="Click to change reference"
                                                                                                          onclick="openRefSelect(this)">
                                                                                                        {{ $comp->reference_text ?: '—' }}
                                                                                                    </span>
                                                                                                    <select class="ref-select ref-editor" style="display:none;"
                                                                                                            onchange="applyRefSelect(this)" onblur="closeRefSelect(this)">
                                                                                                        <option value="">—</option>
                                                                                                        @foreach($comp->available_references as $ref)
                                                                                                            <option value="{{ $ref }}" @selected($comp->reference_text === $ref)>{{ $ref }}</option>
                                                                                                        @endforeach
                                                                                                    </select>
                                                                                                </div>
                                                                                            @elseif($comp->reference_text)
                                                                                                <input type="hidden"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                       value="{{ $comp->reference_text }}">
                                                                                                <span class="ref-text">{{ $comp->reference_text }}</span>
                                                                                            @endif
                                                                                        </td>
                                                                                        @if($tShowStatus)
                                                                                            <td class="col-status">
                                                                                                <input type="text" class="status-input"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][status]"
                                                                                                       value="{{ $comp->status ?? '' }}" list="status-list" placeholder="—">
                                                                                            </td>
                                                                                        @endif
                                                                                        @if($tShowColor)
                                                                                            <td class="col-color">
                                                                                                <div class="color-cell-wrap">
                                                                                                    <span class="color-swatch {{ $comp->color ? '' : 'empty' }}"
                                                                                                          style="{{ $comp->color ? 'background:#'.$comp->color : '' }}"></span>
                                                                                                    <select class="color-select"
                                                                                                            name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][color]"
                                                                                                            onchange="updateColorPreview(this)">
                                                                                                        <option value="" {{ !$comp->color ? 'selected' : '' }}>—</option>
                                                                                                        <option value="28c76f" {{ $comp->color == '28c76f' ? 'selected' : '' }}>Normal</option>
                                                                                                        <option value="ea5455" {{ $comp->color == 'ea5455' ? 'selected' : '' }}>Critical</option>
                                                                                                        <option value="ff9f43" {{ $comp->color == 'ff9f43' ? 'selected' : '' }}>Warning</option>
                                                                                                        <option value="ffd93d" {{ $comp->color == 'ffd93d' ? 'selected' : '' }}>Caution</option>
                                                                                                        <option value="00cfe8" {{ $comp->color == '00cfe8' ? 'selected' : '' }}>Info</option>
                                                                                                        <option value="1f5fbf" {{ $comp->color == '1f5fbf' ? 'selected' : '' }}>Special</option>
                                                                                                        <option value="ff6b9d" {{ $comp->color == 'ff6b9d' ? 'selected' : '' }}>Note</option>
                                                                                                        <option value="a0a3b1" {{ $comp->color == 'a0a3b1' ? 'selected' : '' }}>Neutral</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                            </td>
                                                                                        @endif
                                                                                    </tr>

                                                                                @elseif($comp->type == 'textarea')
                                                                                    <tr class="row-full component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result-full" colspan="{{ $tTailColspan }}">
                                                                                            <textarea class="table-textarea component-value" rows="2"
                                                                                                      name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result_text]">{{ $comp->result_text ?? $comp->result ?? '' }}</textarea>
                                                                                        </td>
                                                                                    </tr>

                                                                                @elseif($comp->type == 'file')
                                                                                    <tr class="row-full component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result-full" colspan="{{ $tTailColspan }}">
                                                                                            <div class="file-upload-inline">
                                                                                                <input type="file" class="file-input"
                                                                                                       id="file_{{ $globalTestIndex }}_{{ $comp->id }}"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result_file]"
                                                                                                       @if($comp->default_value == 'image') accept="image/*" @else accept="application/pdf" @endif
                                                                                                       onchange="handleFileChange(this)">
                                                                                                @if($comp->document)
                                                                                                    <a href="{{ $comp->document }}" class="download-link" target="_blank">
                                                                                                        <i class="fas fa-download"></i> Download
                                                                                                    </a>
                                                                                                @endif
                                                                                            </div>
                                                                                        </td>
                                                                                    </tr>

                                                                                @else
                                                                                    <tr class="component-row"
                                                                                        data-component-id="{{ $comp->id }}"
                                                                                        data-parent-title="{{ $currentTitleId }}">
                                                                                        <td class="col-sl">{{ $loop->iteration }}</td>
                                                                                        <td class="col-hide">
                                                                                            <input class="hide-check hide-checkbox" type="checkbox"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][hide]"
                                                                                                   @if($comp->hidden ?? false) checked @endif value="1"
                                                                                                   onchange="handleHideCheckboxChange(this)">
                                                                                        </td>
                                                                                        <td class="col-name"><div class="cnw"><span class="cnn">{{ $comp->name }}</span></div></td>
                                                                                        <td class="col-result" colspan="2">
                                                                                            <input type="text" class="table-input component-value"
                                                                                                   name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][result]"
                                                                                                   value="{{ $comp->result ?? '' }}">
                                                                                        </td>
                                                                                        <td class="col-ref">
                                                                                            @if(!empty($comp->available_references))
                                                                                                <div class="ref-picker" data-ref-picker>
                                                                                                    <input type="hidden" class="ref-hidden"
                                                                                                           name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                           value="{{ $comp->reference_text ?? '' }}">
                                                                                                    <span class="ref-text ref-display" title="Click to change reference"
                                                                                                          onclick="openRefSelect(this)">
                                                                                                        {{ $comp->reference_text ?: '—' }}
                                                                                                    </span>
                                                                                                    <select class="ref-select ref-editor" style="display:none;"
                                                                                                            onchange="applyRefSelect(this)" onblur="closeRefSelect(this)">
                                                                                                        <option value="">—</option>
                                                                                                        @foreach($comp->available_references as $ref)
                                                                                                            <option value="{{ $ref }}" @selected($comp->reference_text === $ref)>{{ $ref }}</option>
                                                                                                        @endforeach
                                                                                                    </select>
                                                                                                </div>
                                                                                            @elseif($comp->reference_text)
                                                                                                <input type="hidden"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][reference_text]"
                                                                                                       value="{{ $comp->reference_text }}">
                                                                                                <span class="ref-text">{{ $comp->reference_text }}</span>
                                                                                            @endif
                                                                                        </td>
                                                                                        @if($tShowStatus)
                                                                                            <td class="col-status">
                                                                                                <input type="text" class="status-input"
                                                                                                       name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][status]"
                                                                                                       value="{{ $comp->status ?? '' }}" list="status-list" placeholder="—">
                                                                                            </td>
                                                                                        @endif
                                                                                        @if($tShowColor)
                                                                                            <td class="col-color">
                                                                                                <div class="color-cell-wrap">
                                                                                                    <span class="color-swatch {{ $comp->color ? '' : 'empty' }}"
                                                                                                          style="{{ $comp->color ? 'background:#'.$comp->color : '' }}"></span>
                                                                                                    <select class="color-select"
                                                                                                            name="tests[{{ $globalTestIndex }}][components][{{ $comp->id }}][color]"
                                                                                                            onchange="updateColorPreview(this)">
                                                                                                        <option value="" {{ !$comp->color ? 'selected' : '' }}>—</option>
                                                                                                        <option value="28c76f" {{ $comp->color == '28c76f' ? 'selected' : '' }}>Normal</option>
                                                                                                        <option value="ea5455" {{ $comp->color == 'ea5455' ? 'selected' : '' }}>Critical</option>
                                                                                                        <option value="ff9f43" {{ $comp->color == 'ff9f43' ? 'selected' : '' }}>Warning</option>
                                                                                                        <option value="ffd93d" {{ $comp->color == 'ffd93d' ? 'selected' : '' }}>Caution</option>
                                                                                                        <option value="00cfe8" {{ $comp->color == '00cfe8' ? 'selected' : '' }}>Info</option>
                                                                                                        <option value="1f5fbf" {{ $comp->color == '1f5fbf' ? 'selected' : '' }}>Special</option>
                                                                                                        <option value="ff6b9d" {{ $comp->color == 'ff6b9d' ? 'selected' : '' }}>Note</option>
                                                                                                        <option value="a0a3b1" {{ $comp->color == 'a0a3b1' ? 'selected' : '' }}>Neutral</option>
                                                                                                    </select>
                                                                                                </div>
                                                                                            </td>
                                                                                        @endif
                                                                                    </tr>
                                                                                @endif
                                                                            @endforeach
                                                                        @endif
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                            <div class="tfoot">
                                                                {{-- comment --}}
                                                                <div class="sec">
                                                                    <div class="sec-t"><i class="fas fa-comment"></i> Comment</div>
                                                                    <div class="signoff-panel" style="padding:0;">
                                                                        <div class="signoff-group comment-group"
                                                                             @if(empty($test->comment)) style="display:none;" @endif>
                                                                            <label>Optional note</label>
                                                                            <input type="text" name="tests[{{ $globalTestIndex }}][comment]"
                                                                                   value="{{ $test->comment ?? '' }}" placeholder="Optional note">
                                                                        </div>
                                                                        <div class="signoff-group comment-toggle-group" style="flex:0 0 auto; min-width:0;">
                                                                            <button type="button"
                                                                                    class="btn-comment-toggle {{ !empty($test->comment) ? 'active' : '' }}"
                                                                                    onclick="toggleTestComment(this)">
                                                                                <i class="fas fa-comment"></i>
                                                                                <span>{{ !empty($test->comment) ? 'Hide' : 'Add' }} Comment</span>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                </div>

                                                                {{-- sign-off (same fields your bulkUpdateReceipt accepts) --}}
                                                                <div class="sec">
                                                                    <div class="sec-t"><i class="fas fa-user-check"></i> Sign-off</div>
                                                                    <div class="signoff-panel" style="padding:0;">
                                                                        <div class="signoff-group">
                                                                            <label><i class="fas fa-user-edit"></i> Prepared By</label>
                                                                            <select class="dynamic-select select2"
                                                                                    name="tests[{{ $globalTestIndex }}][prepared_by]"
                                                                                    data-placeholder="Prepared By" data-container="select-sm"
                                                                                    data-tenant="true" data-table="users"
                                                                                    data-value="id" data-column_names="name,phone,code"
                                                                                    data-multi_value="name,code" data-key="id"
                                                                                    data-url="ajax/get-dynamic-option">
                                                                                @if($test->prepared)
                                                                                    <option value="{{ $test->prepared->id }}" selected>{{ $test->prepared->name }}</option>
                                                                                @else
                                                                                    <option value="">Select</option>
                                                                                @endif
                                                                            </select>
                                                                        </div>
                                                                        <div class="signoff-group">
                                                                            <label><i class="fas fa-search"></i> Reviewed By</label>
                                                                            <select class="dynamic-select select2"
                                                                                    name="tests[{{ $globalTestIndex }}][reviewed_by]"
                                                                                    data-placeholder="Reviewed By" data-container="select-sm"
                                                                                    data-tenant="true" data-table="users"
                                                                                    data-value="id" data-column_names="name,phone,code"
                                                                                    data-multi_value="name,phone,code" data-key="id"
                                                                                    data-url="ajax/get-dynamic-option">
                                                                                @if($test->reviewed)
                                                                                    <option value="{{ $test->reviewed->id }}" selected>{{ $test->reviewed->name }}</option>
                                                                                @else
                                                                                    <option value="">Select</option>
                                                                                @endif
                                                                            </select>
                                                                        </div>
                                                                        <div class="signoff-group">
                                                                            <label><i class="fas fa-stamp"></i> Authorized By</label>
                                                                            <select class="dynamic-select select2"
                                                                                    name="tests[{{ $globalTestIndex }}][authorized_by]"
                                                                                    data-placeholder="Authorized By" data-container="select-sm"
                                                                                    data-tenant="true" data-table="users"
                                                                                    data-value="id" data-column_names="name,phone,code"
                                                                                    data-multi_value="name,phone,code" data-key="id"
                                                                                    data-url="ajax/get-dynamic-option">
                                                                                @if($test->authorized)
                                                                                    <option value="{{ $test->authorized->id }}" selected>{{ $test->authorized->name }}</option>
                                                                                @else
                                                                                    <option value="">Select</option>
                                                                                @endif
                                                                            </select>
                                                                        </div>
                                                                        <div class="signoff-check">
                                                                            <input class="test-complete-chk" type="checkbox"
                                                                                   data-id="{{ $test->id }}"
                                                                                   {{ $test->done ? 'checked' : '' }}
                                                                                   onchange="toggleComplete(this, '{{ $test->id }}')">
                                                                            <label style="margin:0; text-transform:none; font-weight:700;">Report is Completed</label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                @php $globalTestIndex++; @endphp
                                            @endforeach

                                            {{-- ── category sign-off panel ── --}}
                                            <div class="category-panel" data-category="{{ $category }}">
                                                <div class="signoff-group">
                                                    <label>Prepared By</label>
                                                    <select class="dynamic-select select2 category-prepared-by"
                                                            data-placeholder="Prepared By" data-container="select-sm"
                                                            data-tenant="true" data-table="users"
                                                            data-value="id" data-column_names="name,phone,code"
                                                            data-multi_value="name,code" data-key="id"
                                                            data-url="ajax/get-dynamic-option">
                                                        <option value="">Select</option>
                                                    </select>
                                                </div>
                                                <div class="signoff-group">
                                                    <label>Reviewed By</label>
                                                    <select class="dynamic-select select2 category-reviewed-by"
                                                            data-placeholder="Reviewed By" data-container="select-sm"
                                                            data-tenant="true" data-table="users"
                                                            data-value="id" data-column_names="name,phone,code"
                                                            data-multi_value="name,phone,code" data-key="id"
                                                            data-url="ajax/get-dynamic-option">
                                                        <option value="">Select</option>
                                                    </select>
                                                </div>
                                                <div class="signoff-group">
                                                    <label>Authorized By</label>
                                                    <select class="dynamic-select select2 category-authorized-by"
                                                            data-placeholder="Authorized By" data-container="select-sm"
                                                            data-tenant="true" data-table="users"
                                                            data-value="id" data-column_names="name,phone,code"
                                                            data-multi_value="name,phone,code" data-key="id"
                                                            data-url="ajax/get-dynamic-option">
                                                        <option value="">Select</option>
                                                    </select>
                                                </div>
                                                <button type="button" class="btn-apply"
                                                        onclick="applyCategorySignoff('{{ $category }}')">
                                                    <i class="fas fa-check"></i> Apply to all
                                                </button>
                                                <button type="button" class="btn-reset"
                                                        onclick="resetCategorySignoff('{{ $category }}')">
                                                    <i class="fas fa-undo"></i> Reset
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            @else
                                <div class="easy-card"><div class="sb-none"><i class="fas fa-vial fa-2x"></i>
                                    <p class="mt-2 mb-0">No editable tests found in this receipt.</p></div></div>
                            @endif
                        </form>
                        <div id="easyHost"></div>
                    </div>
                </main>
            </div>
            {{-- ═══════════ SAVE BAR ═══════════ --}}
            <footer class="savebar">
                <button class="btn-x pri" id="btnSave" type="button">
                    <i class="fas fa-save"></i> Save <span id="saveScope">all</span>
                </button>
                <button class="btn-x ok" id="btnAllDone" type="button">
                    <i class="fas fa-check-double"></i> Complete all
                </button>
                <button class="btn-x" id="btnExpand" type="button"><i class="fas fa-plus-circle"></i> Expand</button>
                <button class="btn-x" id="btnCollapse" type="button"><i class="fas fa-minus-circle"></i> Collapse</button>
                <button class="btn-x" id="btnPrintAll" type="button"><i class="fas fa-print"></i> Print All</button>
                <span class="dirty" id="dirty"><i class="fas fa-circle"></i> <span id="dirtyN">0</span> unsaved</span>
                <span class="hint">
                    <kbd>Enter</kbd>/<kbd>&darr;</kbd> next &middot; <kbd>Ctrl</kbd>+<kbd>S</kbd> save &middot; click <i class="fas fa-satellite-dish"></i> to accept LIS
                </span>
            </footer>
        </div>
    </section>

    <datalist id="status-list">
        <option value="Normal"><option value="High"><option value="Low">
        <option value="Abnormal"><option value="Critical"><option value="Info">
        <option value="Pending">
    </datalist>

    <div class="save-indicator" id="saveInd">
        <div class="alert alert-success" id="saveMsg"
             style="display:none; position:fixed; bottom:20px; right:20px; z-index:9999; padding:10px 18px;">
            <i class="fas fa-spinner fa-spin"></i> Saving...
        </div>
    </div>
@endsection
@section('vendor-script')
    <script src="{{ asset(mix('vendors/js/pickers/flatpickr/flatpickr.min.js')) }}"></script>
    <script src="{{ asset(mix('vendors/js/forms/select/select2.full.min.js')) }}"></script>
@endsection

@section('page-script')
    <script src="{{ asset(mix('js/scripts/pages/lab-report.js')) }}"></script>
    <script src="{{ asset(mix('js/scripts/pages/test-custom-function.js')) }}"></script>
    <script src="{{ asset(mix('js/scripts/pages/dynamic.min.js')) }}"></script>
    <script src="{{ asset(mix('/vendors/js/bookmark-editor/bookmark-manager.js')) }}"></script>

    {{-- combo JS loads LAST: it overrides the bulk helpers of lab-report.js
         (saveAll, saveCategory, completeCategory, markAllCompleted, toggleComplete,
         updateProgress) and adds modes / sidebar / dirty tracking / LIS map / easy --}}
    <script>
    if (window.__comboScriptLoaded) {
        console.warn('[ComboEdit] duplicate script load detected — skipping.');
    } else {
        window.__comboScriptLoaded = true;

        (function () {
            var ROOT = document.getElementById('comboApp');
            if (!ROOT) return;

            var RID        = ROOT.dataset.receiptId || '';
            var UPDATE_URL = ROOT.dataset.updateUrl || '';
            var LIS_URL    = ROOT.dataset.lisUrl || '';
            var CSRF       = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

            var MODE   = 'bulk';
            var CURRENT = null;              // current test id (single mode)
            var DIRTY  = {};                 // testId -> true
            var LIS    = {};                 // labTestId -> {machine, at, version, components:{cid:{value,code,instrument,at,version}}}
            var saving = false;
            var EASY   = { filter: 'all', cat: '', q: '' };
            var SUB_BULK_HTML = null;

            /* ═══════════ helpers ═══════════ */
            function esc(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
                });
            }
            function $card(tid)  { return ROOT.querySelector('.test-card[data-test-id="' + tid + '"]'); }
            function $cards()    { return Array.prototype.slice.call(ROOT.querySelectorAll('.test-card')); }
            function tidOf(card) { return card.dataset.testId; }
            function firstCardId() { var c = $cards()[0]; return c ? tidOf(c) : null; }
            function countDone()   { return $cards().filter(function (c) { return c.classList.contains('done'); }).length; }
            function valueInputOf(row) {
                return row.querySelector('.col-result input[name$="[result]"], .col-result select[name$="[result]"], .col-result-full textarea[name$="[result_text]"]');
            }

            /* ═══════════ dirty tracking (per test) ═══════════ */
            function markDirty(tid, on) {
                if (on === undefined) on = true;
                if (on) DIRTY[tid] = true; else delete DIRTY[tid];
                var card = $card(tid);
                if (card) card.classList.toggle('is-dirty', on);
                var sb = ROOT.querySelector('.sb-i[data-target="' + tid + '"]');
                if (sb) sb.classList.toggle('dirty', on);
                var n = Object.keys(DIRTY).length;
                $('#dirty').toggleClass('show', n > 0);
                $('#dirtyN').text(n);
                $('#kpiDirty').text(n);
            }
            function clearDirty(tid) { markDirty(tid, false); }

            $(document).on('input change',
                '#combo-form input:not([type=hidden]), #combo-form select, #combo-form textarea',
                function () {
                    var card = this.closest('.test-card');
                    if (card && card.dataset.testId) markDirty(card.dataset.testId, true);
                });

            $(window).on('beforeunload', function (e) {
                if (Object.keys(DIRTY).length) {
                    e.preventDefault();
                    e.returnValue = 'You have unsaved changes.';
                    return 'You have unsaved changes.';
                }
            });

            /* ═══════════ save engine (FormData, re-indexed scope) ═══════════ */
            function collectScope($scope) {
                var fd = new FormData();
                fd.append('receipt_id', RID);
                fd.append('_token', CSRF);
                var idx = 0;
                $scope.each(function () {
                    var card = this;
                    var pre = 'tests[' + idx + ']';
                    $(card).find('input[name], select[name], textarea[name]').each(function () {
                        var el = this;
                        var m = el.name.match(/^tests\[\d+\](.+)$/);
                        var suffix = m ? m[1] : '[' + el.name + ']';
                        if (el.type === 'file') {
                            if (el.files && el.files.length) fd.append(pre + suffix, el.files[0], el.files[0].name);
                        } else if (el.type === 'checkbox') {
                            if (el.checked) fd.append(pre + suffix, el.value);
                        } else if (el.type === 'select-multiple') {
                            var vals = $(el).val() || [];
                            for (var i = 0; i < vals.length; i++) fd.append(pre + suffix + '[]', vals[i]);
                        } else {
                            fd.append(pre + suffix, el.value);
                        }
                    });
                    idx++;
                });
                /* promote the component-id array key to a component_id field —
                   same trick lab-report.js doSave() uses for the JSON path */
                var seen = {};
                var toAdd = [];
                fd.forEach(function (value, name) {
                    var mm = name.match(/^tests\[(\d+)\]\[components\]\[([^\]]+)\]\[/);
                    if (mm) {
                        var key = mm[1] + '|' + mm[2];
                        if (!seen[key]) {
                            seen[key] = true;
                            toAdd.push(['tests[' + mm[1] + '][components][' + mm[2] + '][component_id]', mm[2]]);
                        }
                    }
                });
                toAdd.forEach(function (p) { fd.append(p[0], p[1]); });
                return fd;
            }

            function testHasContent(card) {
                var $c = $(card);
                var complete = ($c.find('.test-complete').val() === '1') || $c.find('.test-complete-chk').prop('checked');
                var hasSignoff = false;
                $c.find('select[name$="[prepared_by]"], select[name$="[reviewed_by]"], select[name$="[authorized_by]"]').each(function () {
                    if ($(this).val()) hasSignoff = true;
                });
                var cm = $c.find('input[name$="[comment]"]').first();
                if (cm.length && String(cm.val() || '').trim() !== '') hasSignoff = true;
                var hasComp = false;
                $c.find('input[name*="[components]"]').each(function () {
                    var el = this;
                    if (el.type === 'file') { if (el.files && el.files.length) hasComp = true; return; }
                    if (el.type === 'checkbox') return;
                    if (/\[(result|result_text|mic_value|zone_value|status)\]$/.test(el.name || '') &&
                        String(el.value || '').trim() !== '') hasComp = true;
                });
                return !!(complete || hasSignoff || hasComp);
            }

            function restoreBtns($btns) {
                $btns.each(function () {
                    var b = $(this);
                    if (b.data('html')) b.html(b.data('html'));
                    b.prop('disabled', false);
                });
            }

            function postSave(fd, $btns) {
                if (saving) return;
                saving = true;
                $btns.each(function () {
                    var b = $(this);
                    b.data('html', b.html());
                    b.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
                });
                $('#saveMsg').show().removeClass('alert-success alert-danger').addClass('alert-warning')
                    .html('<i class="fas fa-spinner fa-spin"></i> Saving...');

                $.ajax({
                    url: UPDATE_URL, type: 'POST', data: fd, dataType: 'json',
                    processData: false, contentType: false, cache: false,
                    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    success: function (res) {
                        saving = false;
                        restoreBtns($btns);
                        if (res && (res.type === 'success' || res.type === 'partial')) {
                            $('#saveMsg').removeClass('alert-warning').addClass('alert-success')
                                .html('<i class="fas fa-check"></i> Saved');
                            setTimeout(function () { $('#saveMsg').fadeOut(500); }, 1500);
                            if (window.toastr) toastr.success(res.note || 'Saved', 'Save', { timeOut: 2000, positionClass: 'toast-bottom-right' });
                            (res.tests || []).forEach(function (t) { clearDirty(String(t.id)); applyTestState(t); });
                            window.updateProgress();
                            if (MODE === 'easy') renderEasy();
                        } else {
                            $('#saveMsg').removeClass('alert-warning').addClass('alert-danger')
                                .html('<i class="fas fa-exclamation-triangle"></i> Error');
                            setTimeout(function () { $('#saveMsg').fadeOut(500); }, 3000);
                            if (window.toastr) toastr.error((res && res.note) || 'Failed to save', 'Error');
                            if (res && res.failed) {
                                Object.keys(res.failed).forEach(function (tid) {
                                    if (window.toastr) toastr.error(res.failed[tid], 'Test ' + tid);
                                });
                            }
                        }
                    },
                    error: function (xhr, s, err) {
                        saving = false;
                        restoreBtns($btns);
                        $('#saveMsg').removeClass('alert-warning').addClass('alert-danger')
                            .html('<i class="fas fa-exclamation-triangle"></i> Network Error');
                        setTimeout(function () { $('#saveMsg').fadeOut(500); }, 3000);
                        if (window.toastr) toastr.error('Network error: ' + err, 'Error');
                        console.error('[ComboEdit] save failed', xhr.responseText);
                    }
                });
            }

            function saveScope($scope, label) {
                if (saving) return;
                if (!$scope.length) {
                    if (window.toastr) toastr.info('Nothing to save in "' + label + '"', 'Info');
                    return;
                }
                var invalid = $scope.filter('.is-invalid');
                if (invalid.length) {
                    invalid.each(function () {
                        var badge = this.querySelector('.test-validation-badge.invalid');
                        var name  = this.querySelector('.test-header b');
                        if (window.toastr) toastr.error(
                            (badge ? badge.textContent.replace(/^\u26a0\s*/, '') : 'Fix validation errors before saving'),
                            name ? name.textContent : 'Test', { timeOut: 5000 });
                    });
                    invalid[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                var withData = $scope.filter(function () { return testHasContent(this); });
                if (!withData.length) {
                    if (window.toastr) toastr.info('No changes to save in "' + label + '"', 'Info');
                    return;
                }
                postSave(collectScope(withData), withData.find('[data-act="tsave"], .category-save-btn'));
            }

            /* ═══════════ state sync ═══════════ */
            function applyTestState(t) {
                var card = $card(String(t.id));
                if (!card) return;
                var done = parseInt(t.done, 10) === 1;
                card.classList.toggle('completed', done);
                card.classList.toggle('done', done);
                card.classList.toggle('pending', !done);
                var hidden = card.querySelector('.test-complete');
                if (hidden) hidden.value = done ? 1 : 0;
                var chk = card.querySelector('.test-complete-chk');
                if (chk) chk.checked = done;
                var pill = card.querySelector('.pill.st');
                if (pill) {
                    pill.classList.toggle('ok', done);
                    pill.classList.toggle('wait', !done);
                    pill.textContent = done ? 'Completed' : 'Pending';
                }
                var sb = ROOT.querySelector('.sb-i[data-target="' + t.id + '"]');
                if (sb) {
                    sb.classList.toggle('done', done);
                    var sbS = sb.querySelector('.sb-s');
                    if (sbS) sbS.textContent = done ? 'Done' : 'Pending';
                }
            }

            function updateFilled() {
                var n = 0;
                $cards().forEach(function (card) {
                    $(card).find('input[name*="[components]"]').each(function () {
                        var el = this;
                        if (el.type === 'file') { if (el.files && el.files.length) n++; return; }
                        if (el.type === 'checkbox') return;
                        if (/\[(result|result_text|mic_value|zone_value)\]$/.test(el.name || '') &&
                            String(el.value || '').trim() !== '') n++;
                    });
                });
                $('#kpiFilled').text(n);
                return n;
            }

            /* ═══════════ overrides of lab-report.js bulk helpers ═══════════ */
            window.saveAll = function () { saveScope($($cards()), 'all'); };

            window.saveCategory = function (btn, categoryName) {
                var $container = $(btn).closest('.category-container');
                saveScope($container.find('.test-card'), categoryName);
            };

            window.toggleComplete = function (checkbox, testId) {
                var checked = $(checkbox).is(':checked');
                var $cardEl = $(checkbox).closest('.test-card');
                if (!$cardEl.length && testId) $cardEl = $($card(testId));
                if (!$cardEl.length) return;
                var tid = $cardEl.attr('data-test-id');
                $cardEl.find('.test-complete').val(checked ? 1 : 0);
                applyTestState({ id: tid, done: checked ? 1 : 0 });
                markDirty(tid, true);
                window.updateProgress();
            };

            window.completeCategory = function (btn, categoryName) {
                var $container = $(btn).closest('.category-container');
                $container.find('.test-complete-chk').each(function () {
                    if (!this.checked) { this.checked = true; window.toggleComplete(this, $(this).data('id')); }
                });
                if (window.toastr) toastr.success('All tests in "' + categoryName + '" completed', 'Success');
            };

            window.markAllCompleted = function () {
                $('#combo-form .test-complete-chk').each(function () {
                    if (!this.checked) { this.checked = true; window.toggleComplete(this, $(this).data('id')); }
                });
            };

            window.updateProgress = function () {
                var total = $cards().length;
                var done = countDone();
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                $('#pbar').css('width', pct + '%');
                $('#ptxt').text(pct + '%');
                $('#kpiDone').text(done + '/' + total);
                updateFilled();

                ROOT.querySelectorAll('.category-container').forEach(function (container) {
                    var ts = container.querySelectorAll('.test-card');
                    var d = 0;
                    ts.forEach(function (c) { if (c.classList.contains('done')) d++; });
                    var badges = container.querySelectorAll('.cat-h .badge');
                    if (badges.length >= 2) {
                        var b = badges[badges.length - 1];
                        b.textContent = d + '/' + ts.length;
                        var allDone = ts.length > 0 && d === ts.length;
                        b.classList.toggle('badge-success', allDone);
                        b.classList.toggle('badge-warning', !allDone);
                    }
                    var cat = container.dataset.category;
                    var sc = ROOT.querySelector('.sb-cat[data-cat="' + cat + '"]');
                    if (sc) {
                        var sp = sc.querySelectorAll('span');
                        if (sp.length > 1) sp[1].textContent = d + '/' + ts.length;
                    }
                });
            };

            /* ═══════════ save bar + per-test save ═══════════ */
            $(document).on('click', '#btnSave', function () {
                if (MODE === 'single' && CURRENT) saveScope($($card(CURRENT)), 'test');
                else window.saveAll();
            });
            $(document).on('click', '#btnAllDone', function () { window.markAllCompleted(); });
            $(document).on('click', '#btnExpand', function () { if (window.expandAll) window.expandAll(); });
            $(document).on('click', '#btnCollapse', function () { if (window.collapseAll) window.collapseAll(); });
            $(document).on('click', '#btnPrintAll', function () { if (window.batchPrint) window.batchPrint(); });

            $(document).on('click', '#combo-form [data-act="tsave"]', function (e) {
                e.stopPropagation();
                var tid = String($(this).data('t') || $(this).closest('.test-card').attr('data-test-id'));
                saveScope($($card(tid)), 'test');
            });

            /* ═══════════ modes ═══════════ */
            function setCurrent(tid) {
                CURRENT = tid;
                ROOT.querySelectorAll('.test-card.current, .category-container.current').forEach(function (el) {
                    el.classList.remove('current');
                });
                ROOT.querySelectorAll('.sb-i').forEach(function (sb) {
                    sb.classList.toggle('on', sb.dataset.target === tid);
                });
                var card = tid && $card(tid);
                if (card) {
                    card.classList.add('current');
                    var cat = card.closest('.category-container');
                    if (cat) cat.classList.add('current');
                    var det = card.querySelector('.test-details');
                    if (det) det.style.display = 'block';
                    var icon = card.querySelector('.toggle-icon-sm');
                    if (icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-up'); }
                }
            }

            function focusCurrent() {
                var card = CURRENT && $card(CURRENT);
                if (!card) return;
                var first = card.querySelector('.component-row .col-result input, .component-row .col-result-full textarea');
                if (first) first.focus();
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            function setMode(m) {
                if (MODE === 'easy' && m !== 'easy') { $('#easyHost').empty(); }
                MODE = m;
                ROOT.classList.remove('mode-single', 'mode-bulk', 'mode-easy');
                ROOT.classList.add('mode-' + m);
                $('#segMode button').removeClass('on').filter('[data-m="' + m + '"]').addClass('on');
                $('#saveScope').text(m === 'single' ? 'test' : 'all');
                if (m === 'easy') buildEasy();
                else if (SUB_BULK_HTML) $('#sub').html(SUB_BULK_HTML);
                if (m === 'single') {
                    if (!CURRENT || !$card(CURRENT)) setCurrent(firstCardId());
                    focusCurrent();
                } else {
                    setCurrent(null);
                }
                window.updateProgress();
            }

            $(document).on('click', '#segMode button', function () { setMode(String(this.dataset.m)); });

            $(document).on('click', '#segDen button', function () {
                $('#segDen button').removeClass('on');
                $(this).addClass('on');
                ROOT.classList.toggle('tight', this.dataset.d === 'tight');
            });

            /* ═══════════ sidebar ═══════════ */
            function filterSidebar() {
                var q = ($('#q').val() || '').toLowerCase();
                var f = $('#sbf button.on').data('f') || 'all';
                ROOT.querySelectorAll('.sb-i').forEach(function (sb) {
                    var okQ = !q || (sb.dataset.name || '').indexOf(q) > -1;
                    var done = sb.classList.contains('done');
                    var okF = f === 'all' || (f === 'done' ? done : !done);
                    sb.style.display = (okQ && okF) ? '' : 'none';
                });
            }
            $('#q').on('input', filterSidebar);
            $(document).on('click', '#sbf button', function () {
                $('#sbf button').removeClass('on');
                $(this).addClass('on');
                filterSidebar();
            });

            $(document).on('click', '#sbl .sb-i', function () {
                var tid = this.dataset.target;
                $('#side').removeClass('open');
                if (MODE === 'single') {
                    setCurrent(tid);
                    focusCurrent();
                    return;
                }
                var card = $card(tid);
                if (!card) return;
                var cat = card.closest('.category-container');
                if (cat && cat.classList.contains('is-hidden')) {
                    var allBtn = ROOT.querySelector('.cat-filter-btn[data-category="__all__"]');
                    if (allBtn) allBtn.click();
                }
                card.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setCurrent(tid);
            });

            $(document).on('click', '#btnSide', function (e) {
                e.stopPropagation();
                $('#side').toggleClass('open');
            });
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#side, #btnSide').length) $('#side').removeClass('open');
            });

            /* ═══════════ LIS edit map ═══════════ */
            function lisPendingCount(tid) {
                var t = LIS[tid];
                if (!t || !t.components) return 0;
                var card = $card(tid);
                if (!card) return 0;
                var n = 0;
                Object.keys(t.components).forEach(function (cid) {
                    var row = card.querySelector('.component-row[data-component-id="' + cid + '"]');
                    var input = row && valueInputOf(row);
                    if (input && input.tagName === 'INPUT' &&
                        String(input.value || '').trim() !== String(t.components[cid].value)) n++;
                });
                return n;
            }
            function lisTotalPending() {
                var n = 0;
                $cards().forEach(function (c) { n += lisPendingCount(tidOf(c)); });
                return n;
            }
            function lisBadge(tid, cid, info, input) {
                var cur = input ? String(input.value || '').trim() : '';
                var m = cur === String(info.value);
                var cls = m ? 'ok' : (cur === '' ? 'new' : 'diff');
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'lis ' + cls;
                b.dataset.act = 'lis';
                b.dataset.t = tid;
                b.dataset.c = cid;
                b.title = (info.instrument || 'LIS') + (info.at ? ' · ' + info.at : '') + (info.code ? ' · ' + info.code : '');
                b.innerHTML = '<i class="fas fa-satellite-dish"></i> LIS ' + esc(info.value) + (m ? ' OK' : ' · Use');
                if (m) b.disabled = true;
                return b;
            }
            function updateTestLisBtn(tid) {
                var card = $card(tid);
                if (!card) return;
                var n = lisPendingCount(tid);
                var btn = card.querySelector('.lis-accept');
                if (btn) {
                    btn.style.display = n ? '' : 'none';
                    var s = btn.querySelector('span');
                    if (s) s.textContent = 'Accept LIS (' + n + ')';
                }
            }
            function renderLis() {
                $cards().forEach(function (card) {
                    var tid = tidOf(card);
                    $(card).find('.lis').remove();
                    var t = LIS[tid];
                    if (t && t.components) {
                        Object.keys(t.components).forEach(function (cid) {
                            var row = card.querySelector('.component-row[data-component-id="' + cid + '"]');
                            if (!row) return;
                            var cnw = row.querySelector('.cnw');
                            var input = valueInputOf(row);
                            if (!cnw || !input || input.tagName !== 'INPUT') return;
                            cnw.appendChild(lisBadge(tid, cid, t.components[cid], input));
                        });
                    }
                    updateTestLisBtn(tid);
                });
                $('#kpiLis').text(lisTotalPending());
            }
            function syncEasyMirror(tid, cid, kind, val) {
                var $m = $('#easyHost tr.e-row[data-t="' + tid + '"][data-c="' + cid + '"] [data-mirror="' + kind + '"]');
                if ($m.length) $m.val(val);
            }
            function acceptLis(tid, cid, silent) {
                var t = LIS[tid];
                if (!t || !t.components || !t.components[cid]) return;
                var card = $card(tid);
                if (!card) return;
                var row = card.querySelector('.component-row[data-component-id="' + cid + '"]');
                if (!row) return;
                var input = valueInputOf(row);
                if (!input || input.tagName !== 'INPUT') return;
                var info = t.components[cid];
                input.value = info.value;
                var hchk = row.querySelector('.hide-checkbox');
                if (hchk && hchk.checked) {
                    hchk.checked = false;
                    if (window.handleHideCheckboxChange) window.handleHideCheckboxChange(hchk);
                }
                markDirty(tid, true);
                var badge = row.querySelector('.lis');
                if (badge) badge.replaceWith(lisBadge(tid, cid, info, input));
                syncEasyMirror(tid, cid, 'result', info.value);
                updateTestLisBtn(tid);
                $('#kpiLis').text(lisTotalPending());
                updateFilled();
                if (!silent && window.toastr) toastr.success('LIS value ' + info.value + ' applied', 'LIS');
            }
            function acceptTestLis(tid, silent) {
                var t = LIS[tid];
                if (!t || !t.components) return;
                Object.keys(t.components).forEach(function (cid) { acceptLis(tid, cid, true); });
                if (!silent && window.toastr) toastr.success('LIS values accepted', 'LIS');
            }

            $(document).on('click', '#comboApp .lis', function (e) {
                e.preventDefault();
                e.stopPropagation();
                acceptLis(String(this.dataset.t), String(this.dataset.c));
            });
            $(document).on('click', '#comboApp .lis-accept', function (e) {
                e.preventDefault();
                e.stopPropagation();
                acceptTestLis(String($(this).data('t') || this.dataset.t));
            });
            $(document).on('click', '#kpiLisBtn', function () {
                var n = lisTotalPending();
                if (!n) { if (window.toastr) toastr.info('No LIS value to accept', 'LIS'); return; }
                $cards().forEach(function (c) { acceptTestLis(tidOf(c), true); });
                if (window.toastr) toastr.success(n + ' LIS value(s) accepted', 'LIS');
                if (MODE === 'easy') renderEasy();
            });
            $(document).on('click', '#btnLisRefresh', function () { loadLis(true); });

            /* keep badge state fresh while typing */
            $(document).on('input change', '#combo-form input[name$="[result]"]', function () {
                var row = this.closest('.component-row');
                var card = this.closest('.test-card');
                if (!row || !card) return;
                var tid = card.dataset.testId;
                var cid = row.dataset.componentId;
                var t = LIS[tid];
                var info = t && t.components && t.components[cid];
                var badge = row.querySelector('.lis');
                if (badge && info) badge.replaceWith(lisBadge(tid, cid, info, this));
                if (info) {
                    updateTestLisBtn(tid);
                    $('#kpiLis').text(lisTotalPending());
                }
            });

            function loadLis(manual) {
                if (!LIS_URL) return;
                $.getJSON(LIS_URL, function (res) {
                    LIS = (res && res.tests) || {};
                    renderLis();
                    if (MODE === 'easy') renderEasy();
                    if (manual && window.toastr) toastr.success('LIS values reloaded', 'LIS');
                }).fail(function () {
                    LIS = {};
                    $('#kpiLis').text(0);
                    if (manual && window.toastr) toastr.warning('Unable to load LIS values', 'LIS');
                });
            }

            /* ═══════════ easy mode (mirror inputs — no duplicate names) ═══════════ */
            function easyResultVal(row) {
                var input = valueInputOf(row);
                return input ? String(input.value || '').trim() : '';
            }
            function rowPassesEasy(row) {
                if (EASY.filter === 'empty') return easyResultVal(row) === '';
                if (EASY.filter === 'lis')  return !!row.querySelector('.lis:not(.ok)');
                return true;
            }
            function easyVisibleCards() {
                return $cards().filter(function (card) {
                    if (EASY.cat && card.dataset.cat !== EASY.cat) return false;
                    if (EASY.q) {
                        var ql = EASY.q.toLowerCase();
                        var hit = false;
                        card.querySelectorAll('.component-row .cnn').forEach(function (n) {
                            if (n.textContent.toLowerCase().indexOf(ql) > -1) hit = true;
                        });
                        if (!hit) return false;
                    }
                    if (EASY.filter === 'pending' && card.classList.contains('done')) return false;
                    return true;
                });
            }
            function buildEasy() {
                SUB_BULK_HTML = SUB_BULK_HTML || $('#sub').html();
                var cats = [];
                $cards().forEach(function (c) {
                    var k = c.dataset.cat;
                    if (cats.indexOf(k) < 0) cats.push(k);
                });
                var filters = [['all', 'All'], ['empty', 'Empty'], ['lis', 'LIS'], ['pending', 'Pending']];
                var html = '<div class="l"><div class="chips">' +
                    filters.map(function (f) {
                        return '<button type="button" class="chip' + (EASY.filter === f[0] ? ' on' : '') +
                            '" data-easy-filter="' + f[0] + '">' + f[1] + '</button>';
                    }).join('') +
                    '</div><select class="ecs" id="easyCat"><option value="">All categories</option>' +
                    cats.map(function (c) {
                        return '<option value="' + esc(c) + '"' + (EASY.cat === c ? ' selected' : '') + '>' + esc(c) + '</option>';
                    }).join('') +
                    '</select><input type="search" class="eqi" id="easyQ" placeholder="Find component..." value="' + esc(EASY.q) + '">' +
                    '<button type="button" class="btn-x sm" id="easyAcceptAll"><i class="fas fa-satellite-dish"></i> Accept all LIS</button>' +
                    '<button type="button" class="btn-x sm" id="easyNextEmpty"><i class="fas fa-keyboard"></i> Next empty</button></div>' +
                    '<div class="prog"><div class="bar"><i id="pbar"></i></div><small id="ptxt">0%</small></div>';
                $('#sub').html(html);
                renderEasy();
            }
            function statusMirror($row) {
                var s = $row.find('.col-status input').first();
                if (!s.length) return '<span class="pill">—</span>';
                return '<input type="text" class="f" data-mirror="status" list="status-list" value="' + esc(s.val()) + '" placeholder="—">';
            }
            function mirrorCell(row) {
                var $row = $(row);
                if ($row.hasClass('row-full')) {
                    var ta = $row.find('textarea[name$="[result_text]"]').first();
                    if (ta.length) {
                        return '<td class="e-r" colspan="3"><textarea class="f" data-mirror="result_text" rows="2">' + esc(ta.val()) + '</textarea></td>';
                    }
                    return '<td class="e-r" colspan="3"><span class="pill">File upload available in Single / Bulk view</span></td>';
                }
                var culture = $row.find('.culture-input-group').first();
                if (culture.length) {
                    var mic = culture.find('input.culture-mic').first();
                    var zone = culture.find('input.culture-zone').first();
                    var interp = culture.find('select.culture-interp').first();
                    var h = '<td class="e-r"><div class="culture-input-group">';
                    if (mic.length)   h += '<input type="text" class="f" data-mirror="mic" style="min-width:55px;" placeholder="MIC" value="' + esc(mic.val()) + '">';
                    if (zone.length)  h += '<input type="text" class="f" data-mirror="zone" style="min-width:55px;" placeholder="Zone" value="' + esc(zone.val()) + '">';
                    if (interp.length) {
                        h += '<select class="f" data-mirror="interp">' + interp.find('option').map(function () {
                            return '<option value="' + esc(this.value) + '"' + (this.selected ? ' selected' : '') + '>' + esc(this.textContent) + '</option>';
                        }).get().join('') + '</select>';
                    }
                    h += '</div></td>';
                    return h + '<td class="e-u"></td>' + '<td class="e-s">' + statusMirror($row) + '</td>';
                }
                var input = $row.find('.col-result input[name$="[result]"], .col-result select[name$="[result]"]').first();
                if (!input.length) return null;
                var isSel = input.is('select');
                var res = '<td class="e-r">' + (isSel
                    ? '<select class="f" data-mirror="result">' + input.find('option').map(function () {
                        return '<option value="' + esc(this.value) + '"' + (this.selected ? ' selected' : '') + '>' + esc(this.textContent) + '</option>';
                    }).get().join('') + '</select>'
                    : '<input type="text" class="f" data-mirror="result" value="' + esc($(input).val()) + '">') + '</td>';
                var unitInput = $row.find('.col-unit input').first();
                var unit = '<td class="e-u">' + (unitInput.length
                    ? '<input type="text" class="f" data-mirror="unit" value="' + esc(unitInput.val()) + '" placeholder="Unit">'
                    : '') + '</td>';
                return res + unit + '<td class="e-s">' + statusMirror($row) + '</td>';
            }
            function renderEasy() {
                if (MODE !== 'easy') return;
                var rows = '', sl = 0, cnt = 0;
                easyVisibleCards().forEach(function (card) {
                    var tid = tidOf(card);
                    var catRows = '';
                    card.querySelectorAll('.component-row').forEach(function (row) {
                        if (!rowPassesEasy(row)) return;
                        if (row.classList.contains('row-title')) {
                            catRows += '<tr class="r-title"><td class="e-sl"></td><td colspan="4">' +
                                esc($(row).find('b').first().text()) + '</td></tr>';
                            return;
                        }
                        var cid = row.dataset.componentId;
                        var cell = mirrorCell(row);
                        if (!cell) return;
                        cnt++;
                        sl++;
                        var badge = row.querySelector('.lis');
                        var name = $(row).find('.cnn').first().text() || $(row).find('.col-name').first().text();
                        catRows += '<tr class="e-row" data-t="' + esc(tid) + '" data-c="' + esc(cid) + '">' +
                            '<td class="e-sl">' + sl + '</td>' +
                            '<td class="e-n"><div class="cnw"><span class="cnn">' + esc(name) + '</span>' +
                            (badge ? badge.outerHTML : '') + '</div></td>' + cell + '</tr>';
                    });
                    if (catRows) {
                        var done = card.classList.contains('done');
                        var n = lisPendingCount(tid);
                        var cc = card.closest('.category-container');
                        var ccColor = cc ? getComputedStyle(cc).getPropertyValue('--cc') : '';
                        rows += '<tr class="eb" style="--cc:' + esc(ccColor) + '"><td colspan="5"><div class="eb-in">' +
                            '<span class="eb-n"><i class="fas fa-circle"></i><b>' +
                            esc($(card).find('.tt b').first().text()) + '</b><small>' + esc(card.dataset.cat) + '</small></span>' +
                            '<span class="sp"></span>' +
                            (n ? '<button type="button" class="btn-x sm lis-accept" data-act="lis1" data-t="' + esc(tid) + '"><i class="fas fa-satellite-dish"></i> <span>Accept LIS (' + n + ')</span></button>' : '') +
                            '<button type="button" class="btn-x sm' + (done ? ' ok' : '') + '" data-easy-done="' + esc(tid) + '">' +
                            '<i class="fas fa-check"></i> <span>' + (done ? 'Completed' : 'Complete') + '</span></button>' +
                            '</div></td></tr>' + catRows;
                    }
                });
                if (!cnt) {
                    $('#easyHost').html('<div class="easy-card"><div class="sb-none">' +
                        '<i class="fas fa-check-circle fa-2x" style="color:var(--ok)"></i>' +
                        '<p class="mt-2 mb-0">Nothing matches this filter.</p></div></div>');
                    return;
                }
                $('#easyHost').html('<div class="easy-card"><div class="wrap"><table class="ct-easy"><thead><tr>' +
                    '<th class="e-sl">#</th><th class="e-n">Component</th><th class="e-r">Result</th>' +
                    '<th class="e-u">Unit</th><th class="e-s">Flag</th></tr></thead><tbody>' + rows +
                    '</tbody></table></div></div>');
                $('#easyQ').each(function () {
                    if (document.activeElement !== this && EASY.q) { this.focus(); }
                });
            }
            function syncFromMirror(m) {
                var $m = $(m);
                var $erow = $m.closest('tr.e-row');
                var kind = $m.attr('data-mirror');
                if (!$erow.length || !kind) return;
                var tid = String($erow.data('t'));
                var cid = String($erow.data('c'));
                var card = $card(tid);
                var row = card && card.querySelector('.component-row[data-component-id="' + cid + '"]');
                if (!row) return;
                var orig = null;
                if (kind === 'result_text')      orig = $(row).find('textarea[name$="[result_text]"]').first();
                else if (kind === 'mic')         orig = $(row).find('input.culture-mic').first();
                else if (kind === 'zone')        orig = $(row).find('input.culture-zone').first();
                else if (kind === 'interp')      orig = $(row).find('select.culture-interp').first();
                else if (kind === 'unit')        orig = $(row).find('.col-unit input').first();
                else if (kind === 'status')      orig = $(row).find('.col-status input').first();
                else orig = $(row).find('.col-result input[name$="[result]"], .col-result select[name$="[result]"]').first();
                if (!orig.length) return;
                orig.val($m.val());
                if (kind === 'interp' && window.styleInterpSelect) window.styleInterpSelect(orig[0]);
                if (kind === 'mic' || kind === 'zone') orig.removeClass('is-default');
                orig.trigger('input');
            }
            $(document).on('input',  '#easyHost input[data-mirror], #easyHost textarea[data-mirror]', function () { syncFromMirror(this); });
            $(document).on('change', '#easyHost select[data-mirror]', function () { syncFromMirror(this); });

            $(document).on('click', '#sub [data-easy-filter]', function () {
                EASY.filter = String(this.dataset.easyFilter);
                $('#sub [data-easy-filter]').removeClass('on');
                $(this).addClass('on');
                renderEasy();
            });
            $(document).on('change', '#easyCat', function () { EASY.cat = this.value; renderEasy(); });
            $(document).on('input', '#easyQ', function () {
                EASY.q = this.value;
                renderEasy();
                var el = document.getElementById('easyQ');
                if (el) { el.focus(); el.setSelectionRange(el.value.length, el.value.length); }
            });
            $(document).on('click', '#easyAcceptAll', function () { $('#kpiLisBtn').trigger('click'); });
            $(document).on('click', '#easyNextEmpty', function () {
                var el = null;
                $('#easyHost input.f[data-mirror], #easyHost textarea.f[data-mirror]').each(function () {
                    if (!el && String(this.value || '').trim() === '') el = this;
                });
                if (el) { el.focus(); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                else if (window.toastr) toastr.info('No empty result left', 'Easy');
            });
            $(document).on('click', '#easyHost [data-easy-done]', function () {
                var tid = String($(this).data('easy-done'));
                var card = $card(tid);
                if (!card) return;
                var chk = card.querySelector('.test-complete-chk');
                if (chk) { chk.checked = !chk.checked; window.toggleComplete(chk, tid); }
                renderEasy();
            });

            /* ═══════════ init ═══════════ */
            $(function () {
                if (!$cards().length) return;
                setMode('bulk');
                window.updateProgress();
                loadLis(false);
            });
        })();
    }
    </script>
@endsection
