@extends('layouts/contentLayoutHideNavbar')
@section('title', 'Combo Edit - ' . ($receipt->sl_no ?? ''))

@section('vendor-style')
    @verbatim
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    @endverbatim
@endsection

@section('page-style')
    @verbatim
        <style>
            /* combo theme (v23) - every selector is scoped to .combo-app */
            .combo-app{--p:#5b4fe9;--pd:#4338ca;--ps:#eef0ff;--ink:#0f172a;--mut:#64748b;--bd:#e2e8f0;--bg:#f4f6fb;--ok:#16a34a;--warn:#f59e0b;--bad:#dc2626}
            .combo-app *{box-sizing:border-box}
            .combo-app{margin:0;background:var(--bg);color:#1e293b;font:14px/1.45 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
            .combo-app button{font-family:inherit;cursor:pointer}
            .combo-app{display:flex;flex-direction:column;height:100vh}
            .combo-app .top{background:#fff;border-bottom:1px solid var(--bd);padding:10px 18px;display:flex;flex-wrap:wrap;gap:10px 20px;align-items:center;justify-content:space-between}
            .combo-app .pt-strip{display:flex;align-items:center;gap:12px}
            .combo-app .av{width:40px;height:40px;border-radius:50%;background:var(--ps);color:var(--pd);font-weight:800;display:flex;align-items:center;justify-content:center}
            .combo-app .pn{font-size:16.8px;font-weight:800;color:var(--ink)}
            .combo-app .tag{font-size:12px;font-weight:700;background:#f1f5f9;color:#475569;border-radius:10px;padding:2px 9px;margin-left:6px;vertical-align:2px}
            .combo-app .pm{color:var(--mut);font-size:13.12px;display:flex;gap:14px;flex-wrap:wrap}
            .combo-app .pm i{color:var(--p);margin-right:4px}
            .combo-app .ctl{display:flex;flex-wrap:wrap;gap:10px 16px;align-items:center}
            .combo-app .cg-x{display:flex;align-items:center;gap:8px}
            .combo-app .cl{font-size:10.88px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--mut)}
            .combo-app .seg{display:inline-flex;background:#f1f5f9;border-radius:9px;padding:3px}
            .combo-app .seg button{border:0;background:transparent;padding:5px 14px;border-radius:7px;font-weight:700;font-size:13.12px;color:#475569}
            .combo-app .seg button.on{background:#fff;color:var(--pd);box-shadow:0 1px 3px rgba(15,23,42,.18)}
            .combo-app .btn-x{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:8px;padding:6px 12px;font-weight:700;font-size:13.44px}
            .combo-app .btn-x:hover{border-color:var(--p);color:var(--pd)}
            .combo-app .btn-x.pri{background:var(--p);border-color:var(--p);color:#fff}
            .combo-app .btn-x.pri:hover{background:var(--pd)}
            .combo-app .btn-x.ok{border-color:#86efac;color:#15803d;background:#f0fdf4}
            .combo-app .btn-x.sm{padding:3px 10px;font-size:12.48px}
            .combo-app a.btn-x,.combo-app a.btn-x:hover,.combo-app a.btn-x:focus{text-decoration:none;display:inline-block;cursor:pointer}
            .combo-app .cnt{background:#fff;color:var(--pd);border-radius:10px;padding:0 7px;margin-left:4px;font-size:12px}
            .combo-app #btnSide{display:none}
            .combo-app .shell{flex:1;display:flex;min-height:0;position:relative}
            .combo-app .side{width:272px;background:#fff;border-right:1px solid var(--bd);display:flex;flex-direction:column;flex:none}
            .combo-app .sb-top{padding:10px;border-bottom:1px solid var(--bd)}
            .combo-app .sb-top input[type=search]{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:6px 10px;font-size:13.76px}
            .combo-app .sb-top input:focus{outline:0;border-color:var(--p);box-shadow:0 0 0 3px rgba(91,79,233,.15)}
            .combo-app .sb-flt{display:flex;gap:4px;margin-top:8px;align-items:center}
            .combo-app .sb-flt button{border:0;background:#f1f5f9;border-radius:6px;font-size:11.84px;font-weight:700;padding:3px 9px;color:#475569}
            .combo-app .sb-flt button.on{background:var(--p);color:#fff}
            .combo-app .sb-flt .sp{flex:1}
            .combo-app .sb-flt a{font-size:11.84px;font-weight:700;color:var(--pd);cursor:pointer;margin-left:6px}
            .combo-app #sbl{flex:1;overflow:auto;padding:6px 8px 20px}
            .combo-app .sb-cat{display:flex;justify-content:space-between;align-items:center;font-size:11.2px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--mut);padding:12px 6px 4px}
            .combo-app .sb-cat label{margin:0;display:flex;gap:7px;align-items:center;cursor:pointer}
            .combo-app .sb-i{display:grid;grid-template-columns:18px 1fr auto;gap:2px 8px;align-items:center;padding:7px 8px;border-radius:8px;cursor:pointer;border:1px solid transparent}
            .combo-app .sb-i:hover{background:#f8fafc}
            .combo-app .sb-i.on{background:var(--ps);border-color:#d9d9ff}
            .combo-app .sb-n{font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .combo-app .sb-n small{color:var(--mut);font-weight:600;margin-left:4px}
            .combo-app .sb-s{font-size:11.52px;font-weight:700;color:var(--mut)}
            .combo-app .sb-p{grid-column:2/4;height:4px;background:#e2e8f0;border-radius:3px;overflow:hidden}
            .combo-app .sb-p i{display:block;height:100%;background:var(--warn);width:0}
            .combo-app .sb-i.done .sb-p i{background:var(--ok)}
            .combo-app .sb-i.dirty .sb-n:after{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--warn);margin-left:6px}
            .combo-app .sb-none{padding:20px;text-align:center;color:var(--mut)}
            .combo-app .main{flex:1;overflow:auto;min-width:0;position:relative}
            .combo-app .sub{position:sticky;top:0;z-index:20;background:rgba(244,246,251,.96);backdrop-filter:blur(4px);border-bottom:1px solid var(--bd);padding:8px 16px;display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center}
            .combo-app .sub .l{display:flex;gap:6px;align-items:center;flex-wrap:wrap;flex:1}
            .combo-app .cur{font-weight:800;color:var(--ink);padding:0 6px}
            .combo-app .cmb-chips{display:flex;gap:6px;flex-wrap:wrap}
            .combo-app .cmb-chip{border:1.5px solid #cbd5e1;background:#fff;border-radius:20px;padding:2px 12px;font-size:12.8px;font-weight:700;color:#334155;cursor:pointer}
            .combo-app .cmb-chip.on{background:var(--p);border-color:var(--p);color:#fff}
            .combo-app .cmb-chip small{opacity:.75;margin-left:4px}
            .combo-app .prog{display:flex;align-items:center;gap:8px;min-width:190px}
            .combo-app .prog .bar{flex:1;height:6px;background:#e2e8f0;border-radius:4px;overflow:hidden}
            .combo-app .prog .bar i{display:block;height:100%;background:var(--ok);width:0;transition:width .25s}
            .combo-app .prog small{font-weight:800;color:var(--mut)}
            .combo-app #body{padding:14px 20px 30px}
            .combo-app .cat{--cc:var(--p);margin-bottom:22px;background:#e9edf6;border:1px solid #cfd6e6;border-top:4px solid var(--cc);border-radius:14px;padding:0 12px 2px;box-shadow:0 6px 18px rgba(15,23,42,.14)}
            .combo-app .cat-h{display:flex;align-items:center;justify-content:space-between;cursor:pointer;background:var(--cc);color:#fff;border-radius:9px;padding:8px 14px;margin:12px 0}
            .combo-app .cat-h .t{font-weight:800;font-size:16.8px;color:#fff;letter-spacing:.3px;display:flex;align-items:center}
            .combo-app .cat-h .t i{color:#fff;margin-right:6px}
            .combo-app .cat-h .fa-chevron-down{color:#fff!important}
            .combo-app .cat-h .t small{font-weight:700;color:#fff;opacity:.85;margin-left:8px;background:rgba(255,255,255,.2);border-radius:10px;padding:0 8px}
            .combo-app .sb-cat{border-left:4px solid var(--cc);margin-top:6px;background:#f8fafc;border-radius:0 6px 6px 0}
            .combo-app .single .cat-h .fa-chevron-down{display:none}
            .combo-app .cat.cc .cat-b{display:none}
            .combo-app .tc{background:#fff;border:1px solid var(--bd);border-left:4px solid var(--warn);border-radius:10px;margin-bottom:14px;box-shadow:0 1px 3px rgba(15,23,42,.05);overflow:hidden}
            .combo-app .tc.done{border-left-color:var(--ok)}
            .combo-app .th{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fafbfe;border-bottom:1px solid var(--bd);cursor:pointer}
            .combo-app .single .th{cursor:default}
            .combo-app .tt{font-size:16.32px}
            .combo-app .tt b{color:var(--ink)}
            .combo-app .tt .mut{color:var(--mut);margin-left:6px;font-weight:600}
            .combo-app .th .sp{flex:1}
            .combo-app .sel{margin:0;display:flex;align-items:center;cursor:pointer}
            .combo-app .sel input,.combo-app .sb-i input,.combo-app .sb-cat input,.combo-app .c-h input{accent-color:var(--p);width:16px;height:16px;cursor:pointer}
            .combo-app .pill{font-size:11.52px;font-weight:800;border-radius:12px;padding:2px 10px;background:#f1f5f9;color:#475569;white-space:nowrap}
            .combo-app .pill.abn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
            .combo-app .pill.st.ok{background:#dcfce7;color:#15803d}
            .combo-app .pill.st.wait{background:#fef3c7;color:#b45309}
            .combo-app .ib{border:1px solid #cbd5e1;background:#fff;border-radius:8px;width:32px;height:30px;color:var(--pd)}
            .combo-app .ib:hover{background:var(--ps);border-color:var(--p)}
            .combo-app .tc.collapsed .tb{display:none}
            .combo-app .wrap{overflow-x:auto}
            .combo-app table.ct{width:100%;table-layout:fixed;border-collapse:collapse;margin:0}
            .combo-app .ct td{padding:4px 8px;border-bottom:1px solid #eef2f7;vertical-align:middle}
            .combo-app .ct tbody tr:hover td{background:#fafaff}
            /* v22: sl + hide are FIXED px columns (colgroup), identical in single / bulk / easy */
            .combo-app .c-sl,.combo-app .c-h,.combo-app .ct td.c-sl,.combo-app .ct td.c-h{text-align:center;padding-left:4px;padding-right:4px;white-space:nowrap}
            .combo-app .ct col.w-sl{width:36px}
            .combo-app .ct col.w-h{width:30px}
            .combo-app .ct col.w-n{width:26%}
            .combo-app .ct col.w-r{width:23%}
            .combo-app .ct col.w-u{width:10%}
            .combo-app .ct col.w-ref{width:28%}
            .combo-app .ct col.w-s{width:7%}
            .combo-app .c-sl{color:var(--mut);font-variant-numeric:tabular-nums}
            .combo-app .c-n{width:26%;font-weight:700;color:var(--ink)}
            .combo-app .c-r{width:23%}
            .combo-app .c-u{width:10%}
            .combo-app .c-ref{width:28%}
            .combo-app .c-s{width:7%}
            .combo-app select.fl{padding-left:4px;padding-right:4px;font-size:12.8px}
            .combo-app .f{width:100%;height:32px;padding:3px 9px;border:1.5px solid #d5dce6;border-radius:7px;background:#fff;color:var(--ink);font-size:14.72px;font-weight:600}
            .combo-app textarea.f{height:auto;padding:6px 9px}
            .combo-app .f:focus{outline:0;border-color:var(--p);box-shadow:0 0 0 3px rgba(91,79,233,.16);background:#fffef5}
            .combo-app .f.bad{border-color:var(--bad);background:#fef2f2}
            .combo-app .f[data-f=result]{font-variant-numeric:tabular-nums;font-weight:700}
            .combo-app tr.abn .f[data-f=result]{color:#c2410c}
            .combo-app tr.crit .f[data-f=result]{color:var(--bad)}
            .combo-app tr.crit td{background:#fff5f5}
            .combo-app .f.fl{text-align:center;font-weight:700;font-size:13.12px}
            .combo-app .fl-High,.combo-app .fl-Low,.combo-app .fl-Abnormal{background:#fff7ed;color:#c2410c;border-color:#fdba74}
            .combo-app .fl-Critical{background:#fef2f2;color:var(--bad);border-color:#fca5a5}
            .combo-app .fl-Normal{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
            .combo-app .ref{display:inline-block;padding:2px 9px;background:var(--ps);border-left:3px solid var(--p);border-radius:5px;font-size:13.44px;font-weight:600;color:var(--pd)}
            .combo-app select.refsel{font-size:13.44px;color:var(--pd);background:var(--ps)}
            .combo-app .r-title td{background:#f1f5f9;font-weight:800;color:var(--ink);font-size:13.76px}
            .combo-app tr.hid td:not(.c-h):not(.c-sl){opacity:.38}
            .combo-app tr.hid .c-n{text-decoration:line-through}
            .combo-app .cgp{display:flex;gap:6px}
            .combo-app .cgp .f{flex:1;min-width:0;text-align:center}
            .combo-app .cgp select{flex:0 0 80px}
            .combo-app .interp{font-weight:800}
            .combo-app .I_S{background:#dcfce7;color:#15803d}
            .combo-app .I_I{background:#fef3c7;color:#b45309}
            .combo-app .I_R{background:#fee2e2;color:#b91c1c}
            .combo-app .I_NT{background:#eef2f6;color:#64748b}
            .combo-app .sec{padding:12px 14px;border-top:1px solid var(--bd)}
            .combo-app .sec-t{display:flex;align-items:center;gap:6px;font-size:11.52px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:var(--pd);margin-bottom:8px}
            .combo-app .sec-t small{text-transform:none;letter-spacing:0;font-weight:600;color:var(--mut)}
            .combo-app .sec-t .r{margin-left:auto;color:var(--mut);font-weight:700;text-transform:none;letter-spacing:0}
            .combo-app .phr{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
            .combo-app .phr button{border:1px dashed #b9b4f7;background:#fafaff;color:var(--pd);border-radius:14px;padding:2px 10px;font-size:12.16px;font-weight:700}
            .combo-app .phr button:hover{background:var(--ps)}
            .combo-app .f-c{width:100%;min-height:74px;resize:vertical;border:1.5px solid #d5dce6;border-radius:8px;padding:8px 10px;font:500 14.72px/1.45 inherit;color:var(--ink)}
            .combo-app .f-c:focus{outline:0;border-color:var(--p);box-shadow:0 0 0 3px rgba(91,79,233,.16)}
            .combo-app .so-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px 14px}
            .combo-app .so-grid label{font-size:10.88px;font-weight:800;text-transform:uppercase;color:var(--mut);margin:0 0 3px;display:block}
            .combo-app .so-grid label i{color:var(--p);margin-right:4px}
            .combo-app .so-grid select{width:100%;height:34px;border:1.5px solid #d5dce6;border-radius:8px;padding:2px 10px;font-weight:600;color:var(--ink);background:#fff}
            .combo-app .so-grid select:focus{outline:0;border-color:var(--p);box-shadow:0 0 0 3px rgba(91,79,233,.16)}
            .combo-app .so-lock{display:flex;align-items:center;gap:8px;color:var(--mut);font-size:13.12px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:7px 12px}
            .combo-app .so-lock i{color:var(--p)}
            .combo-app .so-lock a{color:var(--pd);font-weight:700;cursor:pointer;margin-left:auto}
            .combo-app .done-row{display:flex;justify-content:flex-end;padding:10px 14px;border-top:1px solid var(--bd);background:#fafbfe}
            .combo-app .chk{display:inline-flex;align-items:center;gap:7px;margin:0;font-weight:700;color:var(--ink);cursor:pointer;background:#fff;border:1.5px solid #cbd5e1;border-radius:20px;padding:4px 14px}
            .combo-app .chk input{accent-color:var(--ok);width:16px;height:16px}
            .combo-app .chk:has(input:checked){border-color:#86efac;background:#f0fdf4;color:#15803d}
            .combo-app .catsign{background:linear-gradient(135deg,#f6f5ff,#eef0ff);border:1px solid #d6d4fb;border-radius:10px;padding:14px;margin-top:4px}
            .combo-app .catsign .acts{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:12px}
            .combo-app .qd.on{background:#dcfce7;border-color:#86efac;color:#15803d}
            .combo-app .qd{white-space:nowrap}
            .combo-app .sb-cat a:hover{color:var(--p)}
            .combo-app .tc:focus-within{box-shadow:0 0 0 2px rgba(91,79,233,.25)}
            .combo-app .sg-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}
            .combo-app .sg-main{flex:1;min-width:300px}
            .combo-app .sg-row .chk{margin-bottom:1px}
            .combo-app .f-c{min-height:54px}
            .combo-app .savebar{background:#fff;border-top:1px solid var(--bd);padding:9px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
            .combo-app .savebar .hint{margin-left:auto;color:var(--mut);font-size:12.16px}
            .combo-app .dirty{display:none;color:#b45309;font-weight:800;font-size:13.12px}
            .combo-app .dirty.show{display:inline}
            .combo-app .opt{margin:0;font-size:12.8px;font-weight:700;color:#475569;display:flex;gap:5px;align-items:center;cursor:pointer}
            .combo-app .opt input{accent-color:var(--p)}
            .combo-app kbd{background:#f1f5f9;border:1px solid #cbd5e1;border-bottom-width:2px;border-radius:4px;padding:0 5px;font-size:11.52px;color:#334155}
            .combo-app #toasts{position:fixed;right:18px;bottom:70px;z-index:9999;display:flex;flex-direction:column;gap:8px}
            .combo-app .toast-x{min-width:220px;max-width:360px;padding:10px 16px;border-radius:9px;color:#fff;font-weight:600;box-shadow:0 8px 22px rgba(0,0,0,.22);animation:cmb-tin .22s}
            .combo-app .toast-x.ok{background:var(--ok)}
            .combo-app .toast-x.err{background:var(--bad)}
            .combo-app .toast-x.info{background:var(--p)}
            @keyframes cmb-tin{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
            @media(max-width:900px){.combo-app #btnSide{display:inline-block}
                .combo-app .side{position:absolute;z-index:40;top:0;bottom:0;left:0;transform:translateX(-102%);transition:transform .2s;box-shadow:6px 0 24px rgba(0,0,0,.18)}
                .combo-app .side.open{transform:none}
                .combo-app .so-grid{grid-template-columns:1fr}
                .combo-app table.ct{min-width:640px}}
            .combo-app .pt-strip{gap:14px}
            .combo-app .pn,.combo-app .pm{font-size:16.8px;font-weight:800;color:var(--ink)}
            .combo-app .pm{gap:4px 18px;margin-top:2px}
            .combo-app .tag{font-size:inherit;font-weight:800;color:var(--ink);padding:1px 10px}
            .combo-app .top{box-shadow:0 1px 3px rgba(15,23,42,.06)}
            .combo-app .sb-sel{display:flex;gap:12px;align-items:center;margin-top:8px;font-size:11.2px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--mut)}
            .combo-app .sb-sel span{flex:1}
            .combo-app .sb-sel a{cursor:pointer;color:var(--pd);text-transform:none;font-size:12.8px;letter-spacing:0}
            .combo-app .sb-hid{font-size:11.2px;color:var(--mut);padding:2px 10px 6px;font-style:italic}
            .combo-app .cat{background:transparent;border:0;padding:0;box-shadow:none;margin-bottom:20px}
            .combo-app .cat-h{margin:0 0 10px;padding:9px 14px;box-shadow:0 2px 6px rgba(15,23,42,.12)}
            .combo-app .tc{border:1px solid var(--bd);border-left:4px solid var(--warn);border-radius:12px;box-shadow:0 2px 8px rgba(15,23,42,.07)}
            .combo-app .th{background:linear-gradient(#fff,#f6f8fc)}
            .combo-app .ct tbody tr:nth-child(even) td{background:#fbfcfe}
            .combo-app .f{height:34px}
            .combo-app .f[data-f=result]{font-size:16px}
            .combo-app .tfoot{display:block;border-top:1px solid var(--bd)}
            .combo-app .tfoot>.sec{border-top:0}
            .combo-app .tfoot>.sec+.sec{border-top:1px solid var(--bd);background:#fafbfe}
            .combo-app .tfoot .sg-main{min-width:0;flex:1}
            .combo-app .tfoot .sg-row{align-items:center}
            .combo-app .sg-sum{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;font-weight:700;color:var(--ink);font-size:13.76px}
            .combo-app .sg-sum span i{color:var(--p);margin-right:5px}
            .combo-app .sg-sum small{color:var(--mut);font-weight:600;margin-right:4px}
            .combo-app .sg-sum a,.combo-app .lnk{margin-left:auto;color:var(--pd);cursor:pointer;font-weight:700;font-size:12.8px}
            .combo-app .sg-edit{display:none}
            .combo-app .sgc.open .sg-edit{display:block}
            .combo-app .sgc.open .sg-sum{display:none}
            @media(max-width:900px){.combo-app .tfoot .sg-main{flex-basis:100%}}
            .combo-app .sb-i.dim{opacity:.4}
            .combo-app .sb-ok{color:var(--ok);margin-left:6px;font-size:12.48px}
            .combo-app .sb-wt{color:var(--warn);margin-left:6px;font-size:12.48px}
            .combo-app{--p:#3d4fc4;--pd:#2c3a9c;--ps:#eef1fc;--ink:#0f1b2d;--mut:#5f6f86;--bd:#dde3ec;--bg:#f2f4f8;--ok:#15803d;--warn:#d97706;--bad:#c62828}
            .combo-app{font-feature-settings:"tnum" 1,"cv11" 1}
            .combo-app .cl,.combo-app .sb-cat,.combo-app .sec-t,.combo-app .so-grid label,.combo-app .ct th,.combo-app .sb-sel{text-transform:none!important;letter-spacing:0!important}
            .combo-app .cl{font-size:11.84px;font-weight:700}
            .combo-app .av{background:var(--p);color:#fff}
            .combo-app .top{box-shadow:none;border-bottom:1px solid var(--bd)}
            .combo-app .cat-h{background:#fff!important;border:1px solid var(--bd);border-left:4px solid var(--cc);box-shadow:none!important;border-radius:10px}
            .combo-app .cat-h .t,.combo-app .cat-h .t i,.combo-app .cat-h .fa-chevron-down{color:var(--ink)!important}
            .combo-app .cat-h .t i.fa-folder-open{color:var(--cc)!important}
            .combo-app .cat-h .t small{background:var(--ps)!important;color:var(--pd)!important;opacity:1}
            .combo-app .tc{box-shadow:0 1px 2px rgba(15,27,45,.06);border-radius:10px}
            .combo-app .th{background:#fafbfd}
            .combo-app .sb-cat{background:#f7f9fc}
            .combo-app .kpi{display:flex;flex-wrap:wrap;gap:10px;padding:10px 18px;background:#fff;border-bottom:1px solid var(--bd)}
            .combo-app .kpi button{flex:1 1 130px;max-width:220px;display:flex;flex-direction:column;text-align:left;line-height:1.15;padding:7px 12px;border:1px solid var(--bd);border-radius:10px;background:#fff}
            .combo-app .kpi button:hover{border-color:var(--p)}
            .combo-app .kpi b{font-size:20px;color:var(--ink);font-variant-numeric:tabular-nums}
            .combo-app .kpi span{font-size:11.84px;font-weight:600;color:var(--mut)}
            .combo-app .kpi .okc b{color:var(--ok)}
            .combo-app .kpi .warn b{color:#c2410c}
            .combo-app .kpi .bad{border-color:#fca5a5;background:#fef2f2}
            .combo-app .kpi .bad b{color:var(--bad)}
            .combo-app .easy-card{background:#fff;border:1px solid var(--bd);border-radius:12px;overflow:hidden}
            .combo-app .easy-card .wrap{overflow:visible}
            .combo-app .eb td{background:var(--ps);border-top:1px solid var(--bd);padding:6px 10px}
            .combo-app .eb-in{display:flex;align-items:center;gap:10px}
            .combo-app .eb-in .sp{flex:1}
            .combo-app .eb-n{display:flex;align-items:center;gap:8px;color:var(--ink)}
            .combo-app .eb-n i{color:var(--cc);font-size:9.6px}
            .combo-app .eb-n small{color:var(--mut);font-weight:600}
            @media(max-width:900px){.combo-app .easy-card .wrap{overflow-x:auto}
                .combo-app .kpi button{flex-basis:44%;max-width:none}}
            .combo-app .cmb-chips{gap:4px}
            .combo-app .cmb-chip{padding:0 8px;border-width:1px;font-size:11.52px;line-height:1.55;font-weight:700}
            .combo-app .cmb-chip small{margin-left:3px;font-size:10.88px}
            .combo-app .sub{padding:5px 14px;gap:6px 10px}
            .combo-app .sub .btn-x.sm{padding:1px 8px;font-size:11.52px}
            .combo-app .sb-cat{cursor:pointer;font-size:12.48px;color:var(--ink)}
            .combo-app .sb-cat.on{background:var(--ps)}
            .combo-app .sb-cat input{margin-right:6px}
            .combo-app .sbc-n{display:flex;align-items:center;gap:6px;flex:1;min-width:0;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .combo-app .sb-all{margin-bottom:2px}
            .combo-app .sb-all.on{background:var(--ps);border-color:#d3d9f5}
            .combo-app .lis{display:inline-flex;align-items:center;gap:4px;margin-top:2px;padding:0 8px;border-radius:10px;border:1px solid;font-size:11.2px;font-weight:700;line-height:1.5;background:#fff}
            .combo-app .lis.ok{color:var(--ok);border-color:#bbf7d0;background:#f0fdf4}
            .combo-app .lis.new{color:var(--pd);border-color:#c7d0f5;background:var(--ps)}
            .combo-app .lis.diff{color:#b45309;border-color:#fcd34d;background:#fffbeb}
            .combo-app .lis:disabled{cursor:default}
            .combo-app .ecs,.combo-app .eqi{height:24px;border:1px solid #cbd5e1;border-radius:6px;font-size:11.84px;padding:0 6px;background:#fff}
            .combo-app .eqi{width:150px}
            .combo-app .sg-s{display:inline-flex;background:#f1f5f9;border-radius:7px;padding:2px}
            .combo-app .sg-s button{border:0;background:transparent;padding:0 9px;font-size:11.52px;font-weight:700;border-radius:5px;color:#475569}
            .combo-app .sg-s button.on{background:#fff;color:var(--pd);box-shadow:0 1px 2px rgba(0,0,0,.15)}
            .combo-app .eb{cursor:pointer}
            .combo-app .eb-ch{font-size:11.2px;color:var(--mut);transition:transform .15s}
            .combo-app .ebc .eb-ch{transform:rotate(-90deg)}
            .combo-app #body tr[data-col]{display:none}
            .combo-app .tg{display:inline-block;margin-right:6px;padding:0 6px;border-radius:8px;background:var(--ps);color:var(--pd);font-size:10.88px;font-weight:800}
            .combo-app .kpi{gap:4px;padding:4px 14px}
            .combo-app .kpi button,.combo-app .kpi button:hover{flex:none;max-width:none;flex-direction:row;align-items:center;gap:6px;padding:0 4px 0 10px;border:1px solid #cbd5e1;border-radius:20px;line-height:1.55;background:#fff}
            .combo-app .kpi button:hover{border-color:var(--p)}
            .combo-app .kpi span{order:1;font-size:11.52px;font-weight:700;color:#334155}
            .combo-app .kpi b{order:2;font-size:11.2px;font-weight:800;min-width:24px;text-align:center;padding:0 7px;border-radius:12px;background:#eef2f7;color:var(--ink)}
            .combo-app .kpi .okc b{background:#dcfce7;color:var(--ok)}
            .combo-app .kpi .warn{border-color:#fdba74;background:#fff7ed}
            .combo-app .kpi .warn b{background:#fed7aa;color:#c2410c}
            .combo-app .kpi .bad{border-color:#fca5a5;background:#fef2f2}
            .combo-app .kpi .bad b{background:#fecaca;color:var(--bad)}
            .combo-app .kpi .prog{margin-left:auto;flex:none;min-width:170px;max-width:280px}
            @media(max-width:900px){.combo-app .kpi button{flex-basis:auto}}
            .combo-app{font-size:14px}
            .combo-app .tt,.combo-app .tt b,.combo-app .cat-h .t,.combo-app .c-n,.combo-app .f,.combo-app .f[data-f=result],.combo-app textarea.f,.combo-app select.refsel,.combo-app .f-c,.combo-app .so-grid select,.combo-app .sb-n,.combo-app .sbc-n,.combo-app .cur,.combo-app .chk,.combo-app .btn-x,.combo-app .toast-x,.combo-app .eb-n,.combo-app .eb-n b,.combo-app .sb-none,.combo-app .sb-top input[type=search]{font-size:14px!important}
            .combo-app .ct th,.combo-app .cl,.combo-app .sec-t,.combo-app .sec-t small,.combo-app .so-grid label,.combo-app .seg button,.combo-app .cmb-chip,.combo-app .cmb-chip small,.combo-app .pill,.combo-app .kpi span,.combo-app .kpi b,.combo-app .sb-s,.combo-app .sb-sel,.combo-app .sb-sel a,.combo-app .sb-flt button,.combo-app .sb-flt a,.combo-app .sb-cat,.combo-app kbd{font-size:12px!important}
            .combo-app{--p:#1f5fbf;--pd:#174a96;--ps:#e9f1fc;--ink:#0b1f3a;--mut:#5b6b82;--bd:#d9e1ec;--bg:#f1f4f9;--ok:#168a4a;--warn:#c9730a;--bad:#c23030}
            .combo-app{font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:#1c2b42;-webkit-font-smoothing:antialiased}
            .combo-app *{scrollbar-width:thin;scrollbar-color:#c3cddc transparent}
            .combo-app :focus-visible{outline:2px solid var(--p);outline-offset:2px}
            .combo-app .top{padding:10px 20px}
            .combo-app .av{background:linear-gradient(135deg,var(--p),var(--pd));color:#fff}
            .combo-app .seg{background:#e8edf5;border-radius:10px}
            .combo-app .seg button{padding:5px 14px;border-radius:8px;font-weight:600;color:#44566f}
            .combo-app .seg button.on{background:#fff;color:var(--pd);box-shadow:0 1px 3px rgba(11,31,58,.18)}
            .combo-app .btn-x{border-color:#c9d4e3;border-radius:9px;padding:6px 14px;font-weight:600;color:#26405f;transition:.15s}
            .combo-app .btn-x:hover{border-color:var(--p);color:var(--pd);background:var(--ps)}
            .combo-app .btn-x.pri{background:var(--p);border-color:var(--p);color:#fff;box-shadow:0 1px 2px rgba(31,95,191,.35)}
            .combo-app .btn-x.pri:hover{background:var(--pd);color:#fff}
            .combo-app .btn-x.ok{background:#eefaf3;border-color:#a7dcbc;color:var(--ok)}
            .combo-app .btn-x.sm{padding:2px 11px}
            .combo-app .kpi{padding:8px 20px;gap:6px}
            .combo-app .kpi button{padding:1px 5px 1px 12px;border-color:#d3dce9}
            .combo-app .kpi b{min-width:26px;padding:0 8px}
            .combo-app .side{width:296px}
            .combo-app .sb-top{padding:12px}
            .combo-app .sb-i{padding:8px 10px;border-radius:9px}
            .combo-app .sb-i.on,.combo-app .sb-all.on{background:var(--ps);border-color:#c5d8f3}
            .combo-app .sb-p{height:5px;border-radius:4px}
            .combo-app .sb-cat{padding:9px 8px 5px;margin-top:8px;font-weight:800}
            .combo-app .sub{padding:7px 18px;background:rgba(241,244,249,.96)}
            .combo-app .cmb-chip{height:26px;display:inline-flex;align-items:center;padding:0 11px;border:1px solid #c9d4e3;font-weight:600}
            .combo-app .cmb-chip.on{background:var(--p);border-color:var(--p);color:#fff}
            .combo-app #body{padding:16px 22px 36px}
            .combo-app .cat-h{padding:10px 16px;border-radius:12px;box-shadow:0 1px 2px rgba(11,31,58,.05)!important}
            .combo-app .tc{border-radius:12px;box-shadow:0 1px 3px rgba(11,31,58,.07),0 6px 16px rgba(11,31,58,.04)}
            .combo-app .th{padding:12px 16px;background:#fbfcfe}
            .combo-app .ct td{padding:5px 10px}
            .combo-app .f{height:36px;border:1px solid #cfd9e6;border-radius:8px;padding:3px 11px}
            .combo-app .f:focus,.combo-app .f-c:focus,.combo-app .so-grid select:focus,.combo-app .sb-top input:focus,.combo-app .eqi:focus,.combo-app .ecs:focus{border-color:var(--p);box-shadow:0 0 0 3px rgba(31,95,191,.16);background:#fff}
            .combo-app .ref{background:var(--ps);border-left-color:var(--p);color:var(--pd);border-radius:6px;padding:3px 10px}
            .combo-app .savebar{padding:10px 20px;box-shadow:0 -4px 14px rgba(11,31,58,.06)}
            .combo-app .eb td{background:#e9f1fc;border-top:1px solid #cfe0f6;padding:8px 12px}
            .combo-app .easy-card{border-radius:14px;box-shadow:0 1px 3px rgba(11,31,58,.07)}
            .combo-app .pgrp{display:inline-flex;gap:6px;align-items:center}
            .combo-app .pgrp.sm{gap:4px}
            .combo-app .th .ib{margin-left:2px}
            .combo-app .selchip{display:inline-flex;align-items:center;gap:6px;padding:2px 12px;border-radius:20px;background:var(--ps);border:1px solid #c5d8f3;color:var(--pd);font-weight:700;font-size:13px;cursor:pointer}
            .combo-app .selchip::before{content:"\f14a";font-family:"Font Awesome 5 Free";font-weight:900}
            .combo-app #selN::after{content:" selected"}
            .combo-app .vsep{display:inline-block;width:1px;height:20px;background:#cbd5e1;margin:0 6px;vertical-align:middle}
            .combo-app .cat-h>span:last-child{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
            .combo-app .th [data-act=tsave],.combo-app .eb [data-act=tsave]{margin-left:4px}
            .combo-app .tc.is-dirty [data-act=tsave],.combo-app .eb.is-dirty [data-act=tsave]{background:var(--p);border-color:var(--p);color:#fff}
            .combo-app #btnOpt{display:none}
            /* compact one-row header on laptops / half-wide screens (app sits beside a left navbar) */
            @media(max-width:1400px) and (min-width:901px){
                .combo-app .top{flex-wrap:nowrap;gap:6px 12px;padding:7px 14px}
                .combo-app .pt-strip{flex:1 1 auto;min-width:0;gap:8px}
                .combo-app .pt-strip>div:last-child{min-width:0;flex:1}
                .combo-app .av{width:32px;height:32px;font-size:12px}
                .combo-app .pn{font-size:13.5px!important;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .combo-app .pn .tag{font-size:10.5px!important;padding:0 7px;margin-left:5px;white-space:nowrap}
                .combo-app .pm{flex-wrap:nowrap;gap:10px;margin:1px 0 0}
                .combo-app .pm,.combo-app .pm *{font-size:11.5px!important;font-weight:700}
                .combo-app .pm>span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .combo-app .ctl{flex-wrap:nowrap;gap:6px 8px}
                .combo-app .cg-x{gap:6px}
                .combo-app .cl{display:none}
                .combo-app .seg{padding:2px}
                .combo-app .seg button{padding:4px 9px;font-size:11.5px!important}
                .combo-app .top .btn-x{padding:5px 10px;font-size:12px!important}
                .combo-app .top .pgrp .btn-x{font-size:0!important;padding:5px 9px;border-radius:8px}
                .combo-app .top .pgrp .btn-x i{font-size:13px!important;margin:0}
                .combo-app .selchip{font-size:11.5px!important;padding:1px 9px}
                .combo-app .kpi{padding:6px 14px}
            }
            @media(max-width:900px){.combo-app{height:100vh;height:100dvh}
                .combo-app .top{flex:none;flex-wrap:wrap;gap:4px 8px;padding:6px 10px;align-items:center}
                .combo-app .pt-strip{flex:1 1 0;min-width:0;gap:8px}
                .combo-app .av{display:none}
                .combo-app .pt-strip>div:last-child{min-width:0;flex:1}
                .combo-app .pn{font-size:14px!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25}
                .combo-app .tag{font-size:11px!important;padding:0 7px;margin-left:5px}
                .combo-app .pm{flex-wrap:nowrap;gap:12px;margin:0;font-size:12px!important;white-space:nowrap;overflow:hidden}
                .combo-app .pm,.combo-app .pm *{font-size:12px!important;font-weight:700}
                .combo-app #btnSide{padding:5px 9px}
                .combo-app .ctl{display:contents}
                .combo-app .pgrp{order:2;gap:4px;flex:none}
                .combo-app .top .pgrp .btn-x{font-size:0!important;padding:6px 10px;border-radius:8px}
                .combo-app .top .pgrp .btn-x i{font-size:14px!important;margin:0}
                .combo-app #btnOpt{display:inline-flex;order:3;padding:6px 10px;font-size:0!important}
                .combo-app #btnOpt i{font-size:14px!important}
                .combo-app .selchip{display:none}
                .combo-app .cg-x{display:none;order:9;width:100%;justify-content:space-between;padding-top:4px;border-top:1px solid var(--bd)}
                .combo-app .top.opt .cg-x{display:flex}
                .combo-app .cg-x .seg{flex:1;display:flex}
                .combo-app .cg-x .seg button{flex:1;padding:5px 6px}
                .combo-app .kpi{flex:none;flex-wrap:nowrap;overflow-x:auto;padding:5px 10px;gap:5px;-webkit-overflow-scrolling:touch}
                .combo-app .kpi button{flex:none;white-space:nowrap}
                .combo-app .sub{padding:5px 10px;flex-wrap:nowrap;overflow-x:auto;gap:8px}
                .combo-app .sub .l{flex-wrap:nowrap;flex:none}
                .combo-app .cmb-chips{flex-wrap:nowrap}
                .combo-app .cmb-chip{flex:none;white-space:nowrap}
                .combo-app .prog{min-width:110px;flex:none}
                .combo-app .shell{min-height:0}
                .combo-app .main{-webkit-overflow-scrolling:touch}
                .combo-app #body{padding:8px 8px 20px}
                .combo-app .cat-h{padding:8px 10px;flex-wrap:wrap;gap:6px}
                .combo-app .th{padding:9px 10px}
                .combo-app .savebar{flex:none;padding:6px 10px;gap:6px;box-shadow:0 -2px 8px rgba(11,31,58,.08)}
                .combo-app .savebar .hint,.combo-app #optAuto{display:none}
                .combo-app #cfvNote{flex:1 1 100%;max-width:none}
                .combo-app #btnAllDone{font-size:0!important;padding:6px 11px}
                .combo-app #btnAllDone i{font-size:14px!important;margin:0}
                .combo-app #btnSave{flex:1}
                .combo-app #toasts{bottom:56px;right:10px;left:10px}
                .combo-app .toast-x{max-width:none}
                .combo-app table.ct,.combo-app table.ct.easy{min-width:0!important;display:block}
                .combo-app .ct tbody{display:block}
                .combo-app .ct tr:not(.r-title):not(.eb){display:grid;grid-template-columns:minmax(0,1fr) 84px 96px;grid-template-areas:"n n h" "r u s" "ref ref ref";gap:5px 8px;padding:9px 10px;border-bottom:1px solid var(--bd);align-items:end}
                .combo-app .ct tr.r-title{display:flex;align-items:center;gap:8px;padding:6px 10px;background:#f1f5f9}
                .combo-app .ct tr.r-title td{display:block;padding:0;font-weight:800}
                .combo-app .ct tr.r-title td:empty{display:none}
                .combo-app .ct tr.eb{display:block}
                .combo-app .ct tr.eb td{display:block}
                .combo-app .ct td{display:block;padding:0!important;border:0;min-width:0;background:transparent!important}
                .combo-app .ct td.c-sl{display:none}
                .combo-app .ct td.c-n{grid-area:n;font-weight:700;color:var(--ink);align-self:center}
                .combo-app .ct td.c-h{grid-area:h;text-align:right;align-self:center}
                .combo-app .ct td.c-r{grid-area:r}
                .combo-app .ct td.c-u{grid-area:u}
                .combo-app .ct td.c-s{grid-area:s}
                .combo-app .ct td.c-ref{grid-area:ref}
                .combo-app .ct td.c-ref:empty{display:none}
                .combo-app .ct td[colspan="2"]{grid-area:r/r/r/u}
                .combo-app .ct td[colspan]:not([colspan="2"]):not(.c-r){grid-area:auto;grid-column:1/-1}
                .combo-app .ct td.c-r::before,.combo-app .ct td.c-u::before,.combo-app .ct td.c-s::before{display:block;font-size:10px;font-weight:700;color:var(--mut);margin-bottom:1px}
                .combo-app .ct td.c-r::before{content:"Result"}
                .combo-app .ct td.c-u::before{content:"Unit"}
                .combo-app .ct td.c-s::before{content:"Flag"}
                .combo-app .easy-card .wrap,.combo-app .wrap{overflow:visible}}
            /* v24: per-test (print1) / per-category (printcat) print buttons are wired
               to the full-screen PdfPreview modal — keep them visible */
            .combo-app #body .pgrp.sm:not(:has(*)){display:none}
            /* v26: TEST CUSTOM FUNCTION — validation badge + invalid-card highlight,
               same look as the bulk-edit page (test-custom-function.js drives it) */
            .combo-app .test-validation-badge{display:none;align-items:center;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;letter-spacing:.2px;margin-left:6px;line-height:1.4;white-space:nowrap}
            .combo-app .test-validation-badge.valid{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
            .combo-app .test-validation-badge.invalid{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
            .combo-app .tc.is-invalid{border-left-color:#dc2626!important;box-shadow:inset 0 0 0 2px rgba(220,38,38,.15)}
            /* v27: the same validation result as EASY TEXT outside the card header —
               banner pill (easy view) + footer pill (bulk). Hidden until a result exists. */
            .combo-app .pill.cfv{display:none}
            .combo-app .pill.cfv.ok{display:inline-flex;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
            .combo-app .pill.cfv.bad{display:inline-flex;background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
            /* v30: FLAT easy view has no banner row (no pill mount), so the per-test
               validation state is painted on the small test tag (.tg) every flat row
               carries — green = passing, red = failing, hover shows the exact message. */
            .combo-app .ct.easy .tg.cfv-ok{background:#d1fae5;color:#065f46;box-shadow:inset 0 0 0 1px #a7f3d0}
            .combo-app .ct.easy .tg.cfv-bad{background:#fee2e2;color:#991b1b;box-shadow:inset 0 0 0 1px #fecaca}
            /* v30: brief amber flash on the row a validation note jumps to (Flat easy
               has no banner, so the row itself needs to answer "where am I?") */
            .combo-app .ct tr.cnv-jump>td{background:#fff7ed!important;transition:background .25s}
            .combo-app .tf-p.bad{color:#b91c1c;border-color:#fecaca;background:#fef2f2}
            .combo-app .tf-p.bad:before{background:#dc2626}
            /* v28: near-field number hint — sits under the result input. Shows what the
               engine really calculated ("= 4000" for a typed "4,000") or "Invalid number".
               Empty span stays invisible; the row keeps its normal height otherwise. */
            .combo-app .rowhint{display:block;font-size:10px;font-weight:700;letter-spacing:.2px;line-height:1.2;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .combo-app .rowhint:empty{display:none}
            .combo-app .rowhint.norm{color:#0f766e}
            .combo-app .rowhint.err{color:#b91c1c}
            /* v31: COMPACT FOOTER ERROR NOTE — replaces the v29/v30 notes panel that
               sat above the save bar and took too much vertical space. Now ONE short
               span inside the save bar: icon + "Save locked · <first blocker> (+N more)",
               single line, ellipsis-truncated; hover shows the full list as a tooltip,
               click jumps to the first blocker. Same refresh paths (cfvKpi + refreshDirty). */
            .combo-app #cfvNote{display:none;align-items:center;gap:6px;max-width:min(58ch,52vw);min-width:0;padding:4px 12px;border-radius:999px;background:#fee2e2;border:1px solid #fecaca;color:#991b1b;font-size:12px;font-weight:700;cursor:pointer}
            .combo-app #cfvNote.show{display:inline-flex}
            .combo-app #cfvNote i{flex-shrink:0;font-size:11px}
            .combo-app #cfvNote .cfv-t{min-width:0;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
            @media(max-width:900px){.combo-app #body .sel,.combo-app #body .cat-h .fa-chevron-down{display:none!important}
                .combo-app .cat-h{padding:8px 12px}
                .combo-app .cat-h>span:last-child{width:100%;justify-content:flex-start}
                .combo-app .tc,.combo-app .easy-card{border-radius:10px;margin:0 0 10px}
                .combo-app .th{flex-wrap:wrap;gap:6px}
                .combo-app .th .tt{flex:1 1 100%}
                .combo-app .ct tr:not(.r-title):not(.eb){grid-template-columns:minmax(0,1fr) 74px 96px;
                    grid-template-areas:"n n h" "ref ref ref" "r u s";
                    gap:6px 8px;padding:12px;align-items:stretch;background:#fff!important}
                .combo-app .ct tr:not(.r-title):not(.eb):nth-child(even){background:#fafbfd!important}
                .combo-app .ct td.c-n{font-size:15px!important;line-height:1.3}
                .combo-app .ct td.c-h{align-self:start}
                .combo-app .ct td.c-h input{width:20px;height:20px}
                .combo-app .ct td.c-ref{font-size:12px;color:var(--mut)}
                .combo-app .ct td.c-ref .ref{font-size:12px!important;padding:1px 8px}
                .combo-app .ct td.c-r::before,.combo-app .ct td.c-u::before,.combo-app .ct td.c-s::before{display:none}
                .combo-app .ct .f,.combo-app .ct select.f,.combo-app .ct textarea.f{height:44px;font-size:16px!important;border:1px solid #b9c6d8;border-radius:10px;padding:6px 12px;background:#fff;width:100%}
                .combo-app .ct textarea.f{height:auto;min-height:88px;line-height:1.4}
                .combo-app .ct .f[data-f=result]{font-weight:700;color:var(--ink);background:#fbfdff}
                .combo-app .ct .f[data-f=unit]{text-align:center;color:var(--mut);padding:6px 4px}
                .combo-app .ct select.fl{padding:6px 6px;font-size:14px!important;text-align:center;text-align-last:center}
                .combo-app .ct .f:focus{border-color:var(--p);box-shadow:0 0 0 3px rgba(31,95,191,.18)}
                .combo-app .ct td[colspan="2"]{grid-area:r/r/r/u}
                .combo-app .cgp{display:flex;gap:6px}
                .combo-app .cgp .f{min-width:0;flex:1;padding:6px 4px;text-align:center}
                .combo-app .cgp select{flex:0 0 66px}
                .combo-app .ct .lis{margin-top:4px}
                .combo-app .so-grid{grid-template-columns:1fr}
                .combo-app .tfoot select,.combo-app .tfoot .f-c,.combo-app .tfoot input[type=text]{font-size:16px!important;min-height:44px}
                .combo-app .tfoot .f-c{min-height:84px}}
            @media(max-width:900px){.combo-app .ct td,.combo-app .ct td.c-sl,.combo-app .ct td.c-h,.combo-app .ct td.c-n,.combo-app .ct td.c-r,.combo-app .ct td.c-u,.combo-app .ct td.c-ref,.combo-app .ct td.c-s{width:auto!important;max-width:none!important}
                .combo-app .ct .f,.combo-app .ct select,.combo-app .ct textarea{width:100%!important;min-width:0}
                .combo-app .top{display:grid;grid-template-columns:auto minmax(0,1fr) auto auto;gap:4px 8px;padding:6px 10px}
                .combo-app .pt-strip,.combo-app .pt-strip>div:last-child{display:contents}
                .combo-app #btnSide{grid-column:1;grid-row:1}
                .combo-app .pn{grid-column:2;grid-row:1;min-width:0}
                .combo-app .top .pgrp{grid-column:3;grid-row:1}
                .combo-app #btnOpt{grid-column:4;grid-row:1}
                .combo-app .pm{grid-column:1/-1;grid-row:2;gap:14px;overflow:hidden}
                .combo-app .pm>span{min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                .combo-app .cg-x{grid-column:1/-1;grid-row:auto}
                .combo-app .top .btn-x,.combo-app .top #btnSide{height:34px;padding:0 10px;display:inline-flex;align-items:center;justify-content:center}
                .combo-app .top .pgrp{gap:4px}
                .combo-app .kpi{display:none}
                .combo-app.opt .kpi{display:flex}
                .combo-app .eb-in{flex-wrap:wrap;gap:6px}
                .combo-app .eb td{padding:8px 10px!important}
                .combo-app .ct .lis{display:flex;width:100%;justify-content:center;white-space:nowrap;margin-top:5px;height:30px;align-items:center;font-size:12px!important}}
            @media(max-width:900px){.combo-app .top .btn-x,.combo-app .top #btnSide{height:32px;padding:0 9px}
                .combo-app .top{gap:4px 6px}
                .combo-app .pn{font-size:13.5px!important}
                .combo-app .pn .tag{font-size:10.5px!important;padding:0 6px;margin-left:4px;white-space:nowrap}}
            @media(max-width:900px){.combo-app .ct td:has(.cgp){grid-area:auto;grid-row:3;grid-column:1/-1}}
            .combo-app .tfoot{background:#fff}
            .combo-app .tfoot>.sec{padding:14px 16px}
            .combo-app .tfoot>.sec+.sec{background:#f7f9fc;border-top:1px solid var(--bd)}
            .combo-app .tfoot .sec-t{margin-bottom:10px;font-size:13px!important;font-weight:800;color:var(--ink);gap:8px;flex-wrap:nowrap}
            .combo-app .tfoot .sec-t>i{width:26px;height:26px;border-radius:50%;background:var(--ps);color:var(--p);display:inline-flex;align-items:center;justify-content:center;font-size:11px;flex:none}
            .combo-app .tfoot .sec-t small{font-size:12px!important;font-weight:500;color:var(--mut);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
            .combo-app .tfoot .sec-t .r{white-space:nowrap;font-size:12px!important;font-weight:600;color:var(--mut);background:#f1f5f9;border-radius:10px;padding:0 9px;flex:none}
            .combo-app .tfoot .phr{flex-wrap:nowrap;overflow-x:auto;gap:6px;margin:0 0 10px;padding-bottom:2px;scrollbar-width:none}
            .combo-app .tfoot .phr::-webkit-scrollbar{display:none}
            .combo-app .tfoot .phr button{flex:none;white-space:nowrap;border:1px solid #cdd8e6;border-style:solid;background:#fff;color:#35506f;border-radius:16px;padding:3px 12px;font-size:12px!important;font-weight:600}
            .combo-app .tfoot .phr button:hover{background:var(--ps);border-color:var(--p);color:var(--pd)}
            .combo-app .tfoot .f-c{display:block;width:100%;min-height:92px;padding:10px 12px;border:1px solid #cdd8e6;border-radius:10px;background:#fff;line-height:1.5;resize:vertical;transition:border-color .15s,box-shadow .15s}
            .combo-app .tfoot .f-c:focus{min-height:120px;border-color:var(--p);box-shadow:0 0 0 3px rgba(31,95,191,.15);outline:0}
            .combo-app .tfoot .sgc{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px 20px;align-items:end}
            .combo-app .tfoot .sg-main{display:block;position:relative;min-width:0}
            .combo-app .tfoot .sg-sum{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;font-size:14px!important}
            .combo-app .tfoot .sg-sum>span{display:grid;grid-template-columns:auto minmax(0,1fr);column-gap:10px;align-items:center;background:#fff;border:1px solid var(--bd);border-radius:10px;padding:9px 12px;font-weight:700;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            .combo-app .tfoot .sg-sum>span>i{grid-row:1/3;width:30px;height:30px;border-radius:50%;background:var(--ps);color:var(--p)!important;display:flex;align-items:center;justify-content:center;font-size:12px;margin:0!important}
            .combo-app .tfoot .sg-sum>span>small{font-size:11.5px!important;font-weight:600;color:var(--mut);margin:0;line-height:1.2}
            .combo-app .tfoot .sg-sum>a{position:absolute;top:-34px;right:0;margin:0;border:1px solid #c9d4e3;background:#fff;border-radius:14px;padding:1px 12px;font-size:12px!important;color:var(--pd)}
            .combo-app .tfoot .sg-sum>a:hover{border-color:var(--p);background:var(--ps)}
            .combo-app .tfoot .sg-edit .lnk{display:inline-block;border:1px solid #c9d4e3;background:#fff;border-radius:14px;padding:2px 14px;margin:0}
            .combo-app .tfoot .sgc>.chk{align-self:end;height:46px;padding:0 18px;border-radius:10px;font-size:14px;white-space:nowrap}
            .combo-app .catsign{background:#fff;border:1px solid var(--bd);border-left:4px solid var(--p);border-radius:12px;padding:16px;margin-top:6px;box-shadow:0 1px 3px rgba(11,31,58,.06)}
            .combo-app .catsign .sec-t{font-size:13px!important;font-weight:800;color:var(--ink);gap:8px;flex-wrap:wrap}
            .combo-app .catsign .sec-t>i{width:26px;height:26px;border-radius:50%;background:var(--ps);color:var(--p);display:inline-flex;align-items:center;justify-content:center;font-size:11px}
            .combo-app .catsign .sec-t small{font-size:12px!important;font-weight:500;color:var(--mut)}
            .combo-app .catsign .so-grid select{height:40px;border:1px solid #cdd8e6;border-radius:9px}
            .combo-app .catsign .acts{border-top:1px solid var(--bd);padding-top:12px;margin-top:14px}
            @media(max-width:900px){.combo-app .tfoot>.sec{padding:12px}
                .combo-app .tfoot .sec-t small{display:none}
                .combo-app .tfoot .sgc{grid-template-columns:1fr;gap:12px}
                .combo-app .tfoot .sg-sum{grid-template-columns:1fr;gap:8px}
                .combo-app .tfoot .sg-sum>span{padding:8px 12px}
                .combo-app .tfoot .sg-sum>a{top:-36px}
                .combo-app .tfoot .sgc>.chk{width:100%;justify-content:center;height:48px;font-size:15px}
                .combo-app .tfoot .f-c{min-height:96px}
                .combo-app .catsign{padding:14px 12px}
                .combo-app .catsign .sec-t small{display:block;flex-basis:100%;margin-left:34px;margin-top:-4px}
                .combo-app .catsign .so-grid{grid-template-columns:1fr;gap:10px}
                .combo-app .catsign .so-grid select{height:46px}
                .combo-app .catsign .acts{flex-direction:column;align-items:stretch}
                .combo-app .catsign .acts .btn-x{width:100%;height:44px;justify-content:center}}
            .combo-app .tfoot .sg-sum>a{top:0!important}
            @media(max-width:900px){.combo-app .tfoot .sg-sum{gap:6px}
                .combo-app .tfoot .sg-sum>span{display:flex;align-items:center;gap:10px;padding:6px 10px;font-size:14px!important}
                .combo-app .tfoot .sg-sum>span>i{width:26px;height:26px;font-size:11px;flex:none}
                .combo-app .tfoot .sg-sum>span>small{flex:0 0 74px;font-size:12px!important}
                .combo-app .tfoot .sgc>.chk{height:46px}}
            @media(min-width:901px){.combo-app .tfoot .phr{flex-wrap:wrap;overflow:visible}}
            .combo-app .tfoot>.sec:first-child .sec-t,.combo-app .tfoot .phr{display:none!important}
            .combo-app .tfoot>.sec:first-child{padding:12px 16px}
            .combo-app .tfoot .f-c{min-height:72px}
            .combo-app .tfoot .f-c:focus{min-height:96px}
            .combo-app .tfoot .sgc .sg-sum,.combo-app .tfoot .sgc .sec-t,.combo-app .tfoot .sg-edit .lnk,.combo-app .tfoot .sg-edit .text-right{display:none!important}
            .combo-app .tfoot .sgc .sg-edit{display:block!important}
            .combo-app .tfoot .sgc{align-items:end}
            .combo-app .tfoot .so-grid{gap:10px 14px}
            .combo-app .tfoot .so-grid label{font-size:12px!important;font-weight:700;color:var(--mut);text-transform:none;margin-bottom:4px}
            .combo-app .tfoot .so-grid select{height:42px;border:1px solid #cdd8e6;border-radius:9px;font-size:14px;padding:2px 12px}
            .combo-app .tfoot .sgc>.chk{height:42px;padding:0 16px;border-radius:9px;font-size:14px}
            .combo-app .catsign .sec-t small{display:none!important}
            .combo-app .catsign .so-grid label{font-size:12px!important;font-weight:700;text-transform:none}
            @media(max-width:900px){.combo-app .tfoot .sgc{grid-template-columns:1fr;gap:10px}
                .combo-app .tfoot .so-grid,.combo-app .catsign .so-grid{grid-template-columns:1fr;gap:8px}
                .combo-app .tfoot .so-grid>div,.combo-app .catsign .so-grid>div{display:grid;grid-template-columns:104px minmax(0,1fr);align-items:center;gap:8px}
                .combo-app .tfoot .so-grid label,.combo-app .catsign .so-grid label{margin:0}
                .combo-app .tfoot .so-grid select,.combo-app .catsign .so-grid select{height:44px;font-size:16px}
                .combo-app .tfoot .sgc>.chk{width:100%;height:44px}
                .combo-app .tfoot .f-c{min-height:80px}}
            .combo-app .cat-h,.combo-app .th,.combo-app .eb{cursor:default}
            .combo-app .cn,.combo-app .tt[data-act],.combo-app .cat-h .fa-chevron-down,.combo-app .eb-n,.combo-app .eb-ch{cursor:pointer}
            .combo-app .cn{display:inline-flex;align-items:center;border-radius:6px;padding:1px 6px;margin-left:-6px;user-select:none}
            .combo-app .cn:hover{background:var(--ps)}
            .combo-app .cn i{margin-right:6px}
            .combo-app .tt[data-act]{display:flex;align-items:center;gap:8px;user-select:none}
            .combo-app .tt .tgl{font-size:12px;color:var(--mut);width:18px;text-align:center;transition:transform .15s}
            .combo-app .tc.collapsed .tgl{transform:rotate(-90deg)}
            .combo-app .single .tgl{display:none}
            .combo-app .single .tt[data-act]{cursor:default}
            .combo-app .cat-h .fa-chevron-down{transition:transform .15s}
            .combo-app .cat.cc .cat-h .fa-chevron-down{transform:rotate(-90deg)}
            .combo-app .c-n{width:26%}
            .combo-app .c-r{width:23%}
            .combo-app .c-ref{width:28%}
            .combo-app .cnw{display:inline-flex;flex-wrap:wrap;align-items:center;gap:4px 8px;vertical-align:middle}
            .combo-app .cnw .lis{margin:0}
            @media(max-width:900px){.combo-app .ct td.c-n .cnw{display:flex}
                .combo-app .ct td.c-n .cnw .lis{width:auto;height:24px;margin:0;font-size:11.5px!important}}
            .combo-app #optPanel,.combo-app #optBack{display:none}
            .combo-app #btnOpt.on{background:var(--ps);border-color:var(--p);color:var(--pd)}
            @media(max-width:900px){.combo-app #optBack{position:fixed;inset:0;z-index:55;background:rgba(15,27,45,.38)}
                .combo-app #optBack.show{display:block}
                .combo-app #optPanel{position:fixed;left:8px;right:8px;top:var(--opt-top,56px);z-index:60;background:#fff;border:1px solid var(--bd);border-radius:14px;box-shadow:0 14px 36px rgba(11,31,58,.30);max-height:calc(100vh - var(--opt-top,56px) - 12px);max-height:calc(100dvh - var(--opt-top,56px) - 12px);overflow:auto;-webkit-overflow-scrolling:touch}
                .combo-app #optPanel.show{display:block}
                .combo-app .op-h{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--bd);position:sticky;top:0;background:#fff;z-index:1;border-radius:14px 14px 0 0}
                .combo-app .op-h b{font-size:14px;color:var(--ink)}
                .combo-app .op-h b i{color:var(--p);margin-right:6px}
                .combo-app .op-x{border:0;background:#eef2f7;width:30px;height:30px;border-radius:50%;color:#44566f}
                .combo-app .op-b{padding:12px 14px;display:grid;gap:12px}
                .combo-app #optPanel .cg-x{display:grid!important;grid-template-columns:76px minmax(0,1fr);align-items:center;gap:8px;width:auto;border:0;padding:0;margin:0}
                .combo-app #optPanel .cg-x .cl{font-size:13px!important;font-weight:700;color:var(--mut)}
                .combo-app #optPanel .cg-x .seg{display:flex;width:100%}
                .combo-app #optPanel .cg-x .seg button{flex:1;min-width:0;padding:8px 4px;font-size:13px!important;white-space:nowrap}
                .combo-app #optPanel .cg-x .seg i{display:none}
                .combo-app .op-f{padding:10px 14px 14px;border-top:1px solid var(--bd)}
                .combo-app .op-f .op-t{font-size:12px;font-weight:700;color:var(--mut);margin-bottom:6px}
                .combo-app #optPanel .kpi{display:flex!important;flex-wrap:wrap;padding:0;border:0;gap:6px;overflow:visible;background:transparent}
                .combo-app #optPanel .kpi button{flex:none}}
            .combo-app .tf,.combo-app .cf{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:9px 14px;border-top:1px solid var(--bd);background:#f8fafd}
            .combo-app .cf{background:#eef3fb;border:1px solid var(--bd);border-radius:10px;margin:10px 4px 2px;padding:10px 14px}
            .combo-app .tf-s,.combo-app .tf-a,.combo-app .cf-s,.combo-app .cf-a{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
            .combo-app .tf-p{display:inline-flex;align-items:center;gap:6px;padding:1px 10px;border-radius:20px;font-size:13px;font-weight:600;background:#fff;border:1px solid #d3dce9;color:#44566f}
            .combo-app .tf-p:before{content:"";width:7px;height:7px;border-radius:50%;background:#98a2b3}
            .combo-app .tf-p.w:before{background:var(--warn)}
            .combo-app .tf-p.l:before{background:var(--p)}
            .combo-app .tf-p.u{border-color:#fcd34d;background:#fffbeb;color:#b45309}
            .combo-app .tf-p.u:before{background:var(--warn)}
            .combo-app .tf-p.ok{color:var(--ok);border-color:#bbf7d0;background:#f0fdf4}
            .combo-app .tf-p.ok:before{background:var(--ok)}
            .combo-app .tf .btn-x,.combo-app .cf .btn-x{padding:4px 14px}
            .combo-app .tf-a,.combo-app .cf-a{margin-left:auto}
            .combo-app .tf-s:empty{display:none}
            .combo-app .btn-x:disabled{opacity:.5;cursor:default}
            .combo-app .tc,.combo-app .tc.done{border-left:6px solid var(--cc,var(--p))!important}
            .combo-app .tc .th{background:linear-gradient(90deg,color-mix(in srgb,var(--cc,#1f5fbf) 9%,#fff),#fff 60%)}
            .combo-app .cchip{display:inline-flex;align-items:center;gap:6px;height:22px;padding:0 10px;border-radius:11px;font-size:11.5px;font-weight:700;letter-spacing:.2px;white-space:nowrap;color:var(--cc);background:#f1f5f9;border:1px solid #d9e1ec}
            .combo-app .cchip i{font-size:6px;color:var(--cc)}
            @supports(background:color-mix(in srgb,red 10%,white)){.combo-app .cchip{background:color-mix(in srgb,var(--cc) 10%,#fff);border-color:color-mix(in srgb,var(--cc) 30%,#fff)}
                .combo-app .sb-i.on:not(.sb-all){background:color-mix(in srgb,var(--cc) 10%,#fff)}
                .combo-app .ct.easy tr.eb[style*="--cc"] td{background:color-mix(in srgb,var(--cc) 10%,#fff)}}
            .combo-app #body.bulk .cchip{display:none}
            .combo-app .sb-i{border-left:4px solid var(--cc,transparent);border-radius:4px 9px 9px 4px}
            .combo-app .sb-i.on,.combo-app .sb-i.sb-all.on{border-left-color:var(--cc,transparent)}
            .combo-app .sb-i.sb-all,.combo-app .sb-i.sb-all.on{border-left-color:transparent}
            .combo-app .ct.easy tr[style*="--cc"]>td:first-child{box-shadow:inset 6px 0 0 var(--cc)}
            @media(max-width:900px){.combo-app .ct.easy tr[style*="--cc"]>td:first-child{box-shadow:none}
                .combo-app .ct.easy tr[style*="--cc"]{box-shadow:inset 5px 0 0 var(--cc)}
                .combo-app .cchip{height:20px;font-size:11px}}

            /* ---- v22: unified KPI / filter strip (KPI + chips are ONE row now) ---- */
            .combo-app .kpi button.on,.combo-app .kpi button.on:hover{background:var(--p);border-color:var(--p);color:#fff}
            .combo-app .kpi button.on b{background:rgba(255,255,255,.28);color:#fff}
            .combo-app .kpi .prog{min-width:200px}

            /* ---- v22: patient profile quick-edit (same endpoint as the edit-receipt patient form) ---- */
            .combo-app .pm .ib{width:26px;height:26px;font-size:11px;border-radius:7px}
            .combo-app #pedBack{position:fixed;inset:0;z-index:1300;background:rgba(11,27,45,.44);display:none;align-items:center;justify-content:center;padding:16px}
            .combo-app #pedBack.show{display:flex}
            .combo-app .ped{background:#fff;border-radius:14px;box-shadow:0 18px 48px rgba(11,27,45,.3);width:min(440px,94vw);overflow:hidden}
            .combo-app .ped-h{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--bd)}
            .combo-app .ped-h b{font-size:15px;color:var(--ink)}
            .combo-app .ped-h b i{color:var(--p);margin-right:7px}
            .combo-app .ped-x{border:0;background:#eef2f7;width:30px;height:30px;border-radius:50%;color:#44566f}
            .combo-app .ped-b{padding:14px 16px;display:grid;gap:11px}
            .combo-app .ped-b label{font-size:11.5px;font-weight:700;color:var(--mut);display:block;margin:0 0 4px}
            .combo-app .ped-2{display:grid;grid-template-columns:1fr 1fr;gap:11px}
            .combo-app .ped-dob{display:block;font-size:11.5px;font-weight:600;color:var(--ok,#0f766e);margin:3px 0 0}
            .combo-app .ped-f{display:flex;gap:8px;justify-content:flex-end;padding:12px 16px;border-top:1px solid var(--bd);background:#f8fafd}
            @media(max-width:900px){.combo-app .ped-2{grid-template-columns:1fr}
                .combo-app .pm .ib{width:24px;height:24px;font-size:10px}}

            /* ---- integration with the project layout (the theme itself is untouched) ---- */
            #combo.combo-app{font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;height:calc(100vh - 20px);height:calc(100dvh - 20px);min-height:620px;border:1px solid var(--bd);border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(15,23,42,.06);text-align:left}
            #combo.combo-app button,#combo.combo-app input,#combo.combo-app select,#combo.combo-app textarea{font-family:inherit}
            #combo.combo-app a.btn-x,#combo.combo-app a.btn-x:hover{color:inherit}
            #combo.combo-app a.btn-x.pri,#combo.combo-app a.btn-x.pri:hover{color:#fff}
            #combo.combo-app label{margin-bottom:0}
            #combo.combo-app kbd{background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;box-shadow:none;padding:0 5px;font-size:11px}
            #combo.combo-app h1,#combo.combo-app h2,#combo.combo-app h3,#combo.combo-app h4,#combo.combo-app h5,#combo.combo-app p{margin:0}

            /* sidebar search row:  <  [test / barcode]  >  single  bulk */
            #combo .sb-go{display:flex;align-items:center;gap:4px}
            #combo .sb-go .btn-x{flex:none;width:30px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
            #combo .sb-go input[type=search]{flex:1 1 auto;width:auto;min-width:0;height:32px}
            #combo .sb-top{position:relative}
            .combo-find-box{display:none;position:absolute;left:10px;right:10px;top:100%;z-index:1050;background:#fff;border:1px solid #cbd5e1;border-radius:9px;box-shadow:0 10px 28px rgba(15,23,42,.18);max-height:260px;overflow:auto;margin-top:3px;min-width:190px}
            .combo-find-box a{display:block;padding:7px 12px;color:#1e293b;font-weight:600;font-size:13px;text-decoration:none;border-bottom:1px solid #f1f5f9}
            .combo-find-box a:hover{background:#eef0ff;color:#4338ca}
            @media(max-width:900px){
                #combo.combo-app{height:calc(100dvh - 70px);min-height:520px;border-radius:0;border-left:0;border-right:0}
            }

        </style>
    @endverbatim
@endsection

@section('content')
    @if (count($combo['tests']))
        <div id="combo" class="combo-app">

            @verbatim
                <!-- ===== TOP BAR ===== -->
                <header class="top">
                    <div class="pt-strip">
                        <button class="btn-x" id="btnSide" title="Tests"><i class="fas fa-list"></i></button>
                        <div class="av" id="avatar">·</div>
                        <div>
                            <div class="pn" id="pName">— <span class="tag"></span></div>
                            <div class="pm"><span title="Receipt barcode"><i class="fas fa-barcode"></i><span id="pId">—</span></span><span><i class="fas fa-user-md"></i><span id="pRef">—</span></span></div>
                        </div>
                    </div>
                    <div class="ctl">
                        <div class="cg-x"><span class="cl">View</span>
                            <div class="seg" id="segMode"><button data-m="single"><i class="fas fa-file-medical"></i> Single</button><button data-m="bulk"><i class="fas fa-th-list"></i> Bulk</button><button data-m="easy" title="One flat worklist for all tests"><i class="fas fa-table"></i> Easy</button></div></div>
                        <div class="cg-x"><span class="cl">Sign-off</span>
                            <div class="seg" id="segSign"><button data-s="test"><i class="fas fa-flask"></i> Per test</button><button data-s="cat"><i class="fas fa-layer-group"></i> By category</button><button data-s="hide" title="Hide sign-off areas"><i class="fas fa-eye-slash"></i> Hide</button></div></div>
                        <span class="selchip" id="selN" title="Selected tests for Preview / Blank / Print. Click to clear">0</span><div class="pgrp"><a href="#" class="btn-x" id="btnPreview" title="Preview selected tests"><i class="fas fa-eye"></i> Preview</a><a href="#" class="btn-x" id="btnBlank" title="Print blank form of selected tests"><i class="far fa-file"></i> Blank print</a><a href="#" class="btn-x pri" id="btnPrintNow" title="Print selected tests"><i class="fas fa-print"></i> Print</a></div>
                    </div>
                </header>
                <div class="kpi" id="kpi"></div>

                <div class="shell">
                    <!-- ===== SIDEBAR ===== -->
                    <aside class="side" id="side">
                        <div class="sb-top">
                            @endverbatim
                            <div class="sb-go">
                                <a class="btn-x" title="Previous receipt"
                                   href="{{ route('lab.report.bulk.shift', ['id' => $receipt->id, 'd' => 'previous', 'r' => 'combo']) }}"><i class="fas fa-chevron-left"></i></a>
                                <input type="search" id="combo-find" autocomplete="off" placeholder="Barcode" title="Barcode and press Enter to open that receipt">
                                <a class="btn-x" title="Next receipt"
                                   href="{{ route('lab.report.bulk.shift', ['id' => $receipt->id, 'd' => 'next', 'r' => 'combo']) }}"><i class="fas fa-chevron-right"></i></a>
                            </div>
                            @verbatim
                                <div class="sb-flt" id="sbf">
                                    <button data-f="all" class="on"><i class="fas fa-list"></i> All</button><button data-f="pending"><i class="far fa-clock"></i> Pending</button><button data-f="done"><i class="fas fa-check"></i> Done</button>
                                </div>
                                <div class="sb-sel"><span><i class="fas fa-print"></i> Select for print</span><a data-act="selall">All</a><a data-act="selnone">None</a></div>
                        </div>
                        <div id="sbl"></div>
                    </aside>

                    <!-- ===== MAIN ===== -->
                    <main class="main" id="main">
                        <div class="sub" id="sub"></div>
                        <div id="body" class="single"></div>
                    </main>
                </div>

                <!-- ===== SAVE BAR ===== -->
                <footer class="savebar">
                    <button class="btn-x pri" id="btnSave"><i class="fas fa-save"></i> Save <span id="saveScope">test</span></button>
                    <button class="btn-x ok" id="btnAllDone"><i class="fas fa-check-double"></i> Mark all complete</button>
                    <label class="opt" id="optAuto" title="After marking a test complete, open the next pending test"><input type="checkbox" id="autoNext" checked> Auto-next</label>
                    <span class="dirty" id="dirty"><i class="fas fa-circle"></i> <span id="dirtyN">0</span> unsaved</span>
                    <span id="cfvNote" data-v="v31" role="status" aria-live="polite" title=""><i class="fas fa-exclamation-triangle"></i><span class="cfv-t"></span></span>
                    <span class="hint"><kbd>Enter</kbd>/<kbd>↓</kbd> next · <kbd>F2</kbd> next empty · <kbd>Alt</kbd>+<kbd>←</kbd><kbd>→</kbd> test · <kbd>Ctrl</kbd>+<kbd>S</kbd> save · <kbd>Alt</kbd>+<kbd>L</kbd> accept LIS</span>
                </footer>
                <div id="toasts"></div>
        </div>
        @endverbatim
    @else
        <div class="card"><div class="card-body text-center text-muted py-5">
                <i class="fas fa-vial fa-2x mb-1"></i>
                <p class="mb-0">No editable tests found in this receipt.</p>
            </div></div>
    @endif

    {{-- v24: full-screen mPDF preview modal (pdf.js) — GLOBAL partial. The file
         lives at resources/views/modal/modal-pdf.blade.php and works from ANY
         view: just @include('modal.modal-pdf') and call PdfPreview.preview(url).
         The wrap inside the partial keeps IDs/scripts unique even if another
         view also includes it on the same page. --}}
    @include('modal.modal-pdf')
@endsection

@section('page-script')
    <script>window.COMBO = @json($combo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
    <script>window.COMBO_SL = @json($receipt->sl_no ?? ($receipt->id ?? null)); /* barcode / serial no shown in the top strip */window.COMBO_PT = @json($receipt->patient ?? null); /* full patient row for the quick-edit modal — phone/mobile live here when $combo.patient omits them */window.COMBO_RID = @json($receipt->id ?? null); /* v24: receipt id for the mPDF print endpoints */</script>
    @verbatim
        <script>
            (function(){
                var inp=document.getElementById('combo-find');if(!inp)return;
                var base=(typeof assetPath!=='undefined'&&assetPath)?assetPath:'/',redirect=inp.getAttribute('data-redirect')||'lab/report/combo-edit/';
                var tok=(document.querySelector('meta[name="csrf-token"]')||{}).content||'',box=document.createElement('div'),t=null,items=[];
                box.className='combo-find-box';inp.parentNode.parentNode.appendChild(box);
                function go(id){window.location.href=base+redirect+id}
                function show(list){items=list;box.innerHTML='';
                    if(!list.length){box.style.display='none';return}
                    list.slice(0,12).forEach(function(it){var a=document.createElement('a');a.href='#';a.textContent=it.name;a.onclick=function(e){e.preventDefault();go(it.id)};box.appendChild(a)});
                    box.style.display='block'}
                function q(term){
                    fetch(base+'lab/receipt/search-receipt',{method:'POST',credentials:'same-origin',
                        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-CSRF-TOKEN':tok,'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},
                        body:'term='+encodeURIComponent(term)+'&redirect='+encodeURIComponent(redirect)})
                        .then(function(r){return r.json()}).then(function(res){show((res||[]).filter(function(x){return x.id}).map(function(x){return {id:x.id,name:x.name}}))}).catch(function(){show([])})}
                inp.addEventListener('focus',function(){q(inp.value)});
                inp.addEventListener('input',function(){clearTimeout(t);t=setTimeout(function(){q(inp.value)},180)});
                inp.addEventListener('keydown',function(e){if(e.key==='Enter'&&items[0]){e.preventDefault();go(items[0].id)}if(e.key==='Escape')box.style.display='none'});
                document.addEventListener('click',function(e){if(e.target!==inp&&!box.contains(e.target))box.style.display='none'});
            })();
            (function(){
                var ROOT=document.getElementById('combo');if(!ROOT||!window.COMBO)return;
                function byId(i){return ROOT.querySelector('#'+i)}

                /* =====================================================================
                   DATA — from LabReportController@comboEdit (window.COMBO). Test / component
                   ids are mapped to simple numbers locally (uid keeps the real DB id, so
                   integer and UUID keys both work). Edits live in these objects so switching
                   Single / Bulk / Easy never loses what was typed.
                   ===================================================================== */
                var CB=window.COMBO;
                var PT=CB.patient;
                /* merge the raw receipt patient row (window.COMBO_PT) into PT for any key COMBO lacks
                   or ships empty — that is where phone / mobile usually hide */
                if(window.COMBO_PT&&typeof window.COMBO_PT==='object')for(var _pk in window.COMBO_PT)if(window.COMBO_PT.hasOwnProperty(_pk)){
                    /* _pk==='id' here is the TRUE patient PK (uuid) — kept as PT.pid because
                       $combo.patient.id can carry the reg_no ('0000012') instead of the PK */
                    if(_pk==='id'){if(!PT.pid)PT.pid=window.COMBO_PT.id}
                    else if(PT[_pk]==null||PT[_pk]==='')PT[_pk]=window.COMBO_PT[_pk]}
                var SLNO=(CB.receipt&&(CB.receipt.sl_no||CB.receipt.barcode))||window.COMBO_SL||PT.id||'—'; /* #pId shows the BARCODE, not the patient id */
                var USERS=CB.users.map(function(u){return u.label});
                var USER_ID={};CB.users.forEach(function(u){USER_ID[u.label]=u.id});
                var DEF_SIGN=CB.defaultSign;
                var PHRASES=['Within normal limits','Correlate clinically','Repeat sample advised','Sample hemolyzed','Critical value — physician informed','Confirmed on repeat'];
                var STATUS=['Normal','High','Low','Abnormal','Critical'];
                var TESTS=CB.tests,_n=0;
                TESTS.forEach(function(t,i){t.uid=t.id;t.id=i+1;t.comps.forEach(function(c){c.uid=c.id;c.id=++_n})});
                function byUid(u){return TESTS.filter(function(t){return String(t.uid)===String(u)})[0]}

                /* ============================ helpers ============================ */
                var $=function(s,r){return (r||ROOT).querySelector(s)};
                var $$=function(s,r){return Array.prototype.slice.call((r||ROOT).querySelectorAll(s))};
                function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]})}
                function opt(list,sel){return list.map(function(x){return '<option'+(x===sel?' selected':'')+'>'+esc(x)+'</option>'}).join('')}
                function T(id){return TESTS.filter(function(t){return t.id===id})[0]}
                function C(t,id){return t.comps.filter(function(c){return c.id===id})[0]}
                function cats(){var o=[];TESTS.forEach(function(t){if(o.indexOf(t.cat)<0)o.push(t.cat)});return o}
                function inCat(c){return TESTS.filter(function(t){return t.cat===c})}
                function isMobile(){return window.innerWidth<=900}
                /* v22: fixed column plan for every .ct table — colgroup keeps sl/hide (and all other columns)
                   identical in single, bulk and easy, even when the first row is a colspan banner */
                var COLS='<colgroup><col class="w-sl"><col class="w-h"><col class="w-n"><col class="w-r"><col class="w-u"><col class="w-ref"><col class="w-s"></colgroup>';

                /* ============================== state ============================ */
                var CATC=['#1f5fbf','#0f8b8d','#b4572a','#7a4fb5','#2f855a','#b83250'];
                function cc(c){return CATC[cats().indexOf(c)%CATC.length]}
                var S={mode:'single',cur:(TESTS[0]?TESTS[0].id:0),cat:'__all__',sign:'test',filter:'all',q:'',collapsed:{},cc:{}};
                var SIGN={};      /* category → {p,r,a}  (used when S.sign==='cat') */
                var PV={one:null};
                try{ /* restore last View + Sign-off choices */
                    var _v=localStorage.getItem('lr_view');if(_v==='single'||_v==='bulk'||_v==='easy')S.mode=_v;
                    var _g=localStorage.getItem('lr_sign');if(_g==='test'||_g==='cat'||_g==='hide')S.sign=_g;
                }catch(_){}

                /* ---- auto flag from reference range ---- */
                /* v28: ONE number dialect for the whole page. Analyzers and staff type grouping
                   characters freely — "4,000" / "2,06,000" / "12 100" for a WBC. Commas, NBSP,
                   thin spaces and plain spaces are grouping (dropped), the dot is the decimal
                   point — exactly what the engine's toNum() already did for calculations.
                   parseRef used to match "000-11" inside "4,000-11,000" and autoFlag read
                   parseFloat("4,000") as 4, so High/Low flags were wrong for comma values and
                   badNum() blocked the save even though the ✓ Differential: 100% badge was lit. */
                function numNorm(s){return String(s==null?'':s).replace(/[,\s\u00A0\u202F]/g,'')}
                function parseRef(r){
                    if(!r)return null; var s=numNorm(r).replace(/\u2264/g,'<').replace(/\u2265/g,'>');
                    var m=s.match(/(-?\d+\.?\d*)\s*[\u2013\u2014-]\s*(-?\d+\.?\d*)/);
                    if(m)return {lo:+m[1],hi:+m[2]};
                    m=s.match(/<\s*(=)?\s*(-?\d+\.?\d*)/); if(m)return {hi:+m[2],strict:!m[1]};
                    m=s.match(/>\s*(=)?\s*(-?\d+\.?\d*)/); if(m)return {lo:+m[2],strictLo:!m[1]};
                    return null;
                }
                function autoFlag(c){
                    if(c.type!=='number'||c.result==null||String(c.result).trim()==='')return '';
                    var v=parseFloat(numNorm(c.result).replace(/[<>]/g,'')); if(isNaN(v))return '';
                    var r=parseRef(c.ref); if(!r)return '';
                    if(r.lo!=null&&(r.strictLo?v<=r.lo:v<r.lo))return 'Low';
                    if(r.hi!=null&&(r.strict?v>=r.hi:v>r.hi))return 'High';
                    return 'Normal';
                }
                /* v28: badNum accepts everything numNorm can read — "4,000" is a valid WBC,
                   not an error. Only text that stays unparseable after normalization is bad.
                   (Same rule the engine applies before running any calculation.) */
                function badNum(c){
                    if(c.type!=='number'||c.result==null||String(c.result).trim()==='')return false;
                    return !/^[<>]?-?\d+(\.\d*)?$/.test(numNorm(c.result));
                }
                /* v28: near-field hint — shows the normalized value the page actually uses,
                   or a persistent "Invalid number" message right under the input. */
                function numHint(c){
                    if(!c||c.type!=='number')return {cls:'',text:''};
                    var raw=String(c.result==null?'':c.result).trim();
                    if(raw==='')return {cls:'',text:''};
                    if(badNum(c))return {cls:'err',text:'Invalid number'};
                    var norm=numNorm(raw);
                    if(norm!==raw)return {cls:'norm',text:'= '+norm};
                    return {cls:'',text:''};
                }
                function isAbn(c){return /^(High|Low|Abnormal|Critical)$/.test(c.status||'')}
                function hasVal(c){return c.type==='culture'?!!(c.mic||c.zone||c.interp):(c.result!=null&&String(c.result).trim()!=='')}
                function stats(t){var tot=0,f=0,a=0;t.comps.forEach(function(c){if(c.type==='title'||c.hide)return;tot++;if(hasVal(c))f++;if(isAbn(c))a++});return {tot:tot,f:f,a:a}}
                function sg(t){if(S.sign==='cat'){var s=SIGN[t.cat]||{};return {p:s.p||'',r:s.r||'',a:s.a||''}}return {p:t.sp,r:t.sr,a:t.sa}}

                /* init model */
                TESTS.forEach(function(t){
                    t.sel=false;t.dirty=false;t.comment=t.comment||'';t.sp=t.sp||DEF_SIGN.p;t.sr=t.sr||DEF_SIGN.r;t.sa=t.sa||DEF_SIGN.a;
                    t.comps.forEach(function(c){if(c.type==='number'&&!c.status)c.status=autoFlag(c)});
                });
                cats().forEach(function(c){SIGN[c]={p:DEF_SIGN.p,r:DEF_SIGN.r,a:DEF_SIGN.a}});

                /* ============================ rendering ========================== */
                function rowCls(c){return (c.hide?'hid ':'')+(isAbn(c)&&c.status!=='Critical'?'abn ':'')+(c.status==='Critical'?'abn crit':'')}
                /* v26: rows carry the classes test-custom-function.js already speaks on the bulk-edit
                   page (component-row / col-name / component-value) — the engine reads and writes
                   results through them; title rows stay out of the engine on purpose. */
                function row(t,c,i){
                    var id='r'+t.id+'_'+c.id, a='data-t="'+t.id+'" data-c="'+c.id+'"';
                    if(c.type==='title')return '<tr class="r-title" id="'+id+'" style="--cc:'+cc(t.cat)+'"><td class="c-sl"></td><td class="c-h"><input type="checkbox" data-act="hidetitle" '+a+(c.hide?' checked':'')+' title="Hide whole section"></td><td colspan="5">'+esc(c.name)+'</td></tr>';
                    var head='<td class="c-sl">'+i+'</td><td class="c-h"><input type="checkbox" data-act="hide" '+a+(c.hide?' checked':'')+' title="Hide from report"></td><td class="c-n col-name">'+esc(c.name)+'</td>';
                    if(c.type==='culture')
                        return '<tr id="'+id+'" style="--cc:'+cc(t.cat)+'" class="'+rowCls(c)+' component-row">'+head+'<td colspan="2"><div class="cgp"><input class="f cmb-nav component-value" '+a+' data-f="mic" placeholder="MIC" value="'+esc(c.mic)+'"><input class="f cmb-nav" '+a+' data-f="zone" placeholder="Zone mm" value="'+esc(c.zone)+'"><select class="f interp I_'+(c.interp||'NT')+'" '+a+' data-f="interp"><option value="">—</option>'+opt(['S','I','R','NT'],c.interp)+'</select></div></td><td class="c-ref"></td><td class="c-s"></td></tr>';
                    if(c.type==='textarea')
                        return '<tr id="'+id+'" style="--cc:'+cc(t.cat)+'" class="'+rowCls(c)+' component-row">'+head+'<td colspan="4"><textarea class="f cmb-nav component-value" rows="2" '+a+' data-f="result">'+esc(c.result)+'</textarea></td></tr>';
                    var isNum=c.type==='number';
                    var ref=c.refs?'<select class="f refsel" '+a+' data-f="ref">'+opt(c.refs,c.ref)+'</select>':(c.ref?'<span class="ref">'+esc(c.ref)+'</span>':'');
                    var nh=numHint(c); /* v28: "= 4000" / "Invalid number" under the input */
                    return '<tr id="'+id+'" style="--cc:'+cc(t.cat)+'" class="'+rowCls(c)+' component-row">'+head+
                        '<td class="c-r"'+(isNum?'':' colspan="2"')+'><input class="f cmb-nav component-value'+(badNum(c)?' bad':'')+'" '+a+' data-f="result" value="'+esc(c.result)+'" autocomplete="off" inputmode="'+(isNum?'decimal':'text')+'"><span class="rowhint '+(nh.cls||'')+'">'+esc(nh.text)+'</span></td>'+
                        (isNum?'<td class="c-u"><input class="f" '+a+' data-f="unit" value="'+esc(c.unit)+'" placeholder="Unit"></td>':'')+
                        '<td class="c-ref">'+ref+'</td>'+
                        '<td class="c-s"><select class="f fl fl-'+(c.status||'none')+'" '+a+' data-f="status"><option value="">—</option>'+opt(STATUS,c.status)+'</select></td></tr>';
                }
                function signGrid(o){ /* o: {cls attr builder} */
                    function s(key,label,ic,val){return '<div><label><i class="fas '+ic+'"></i>'+label+'</label><select '+o.attr(key)+'><option value="">Select…</option>'+opt(USERS,val)+'</select></div>'}
                    return '<div class="so-grid">'+s('p','Prepared by','fa-user-edit',o.v.p)+s('r','Reviewed by','fa-search',o.v.r)+s('a','Authorized by','fa-stamp',o.v.a)+'</div>';
                }
                function sgSum(v){return [['fa-user-edit','Prepared',v.p],['fa-search','Reviewed',v.r],['fa-stamp','Authorized',v.a]].map(function(x){return '<span><i class="fas '+x[0]+'"></i><small>'+x[1]+'</small>'+(x[2]?esc(x[2]):'—')+'</span>'}).join('')}
                function testCard(t){
                    var p=null,rows='',k=0,s=stats(t);
                    t.comps.forEach(function(c){if(c.type==='title')p=c.id;if(c.type!=='title')k++;rows+=row(t,c,k)});
                    var dn='<label class="chk"><input type="checkbox" data-act="done" data-t="'+t.id+'"'+(t.done?' checked':'')+'> Completed</label>';
                    var map={p:'sp',r:'sr',a:'sa'};
                    var sign=S.sign==='test'
                        ? '<div class="sec sg-row sgc"><div class="sg-main"><div class="sec-t"><i class="fas fa-signature"></i>Sign-off<small>auto-applied · change only if needed</small></div><div class="sg-sum" id="sgs'+t.id+'">'+sgSum({p:t.sp,r:t.sr,a:t.sa})+'<a data-act="sgedit">Change</a></div><div class="sg-edit">'+signGrid({v:{p:t.sp,r:t.sr,a:t.sa},attr:function(k){return 'data-t="'+t.id+'" data-tf="'+map[k]+'"'}})+'<div class="text-right mt-2"><a data-act="sgedit" class="lnk">Done</a></div></div></div>'+dn+'</div>'
                        : S.sign==='cat'
                          ? '<div class="sec sg-row"><div class="so-lock" style="flex:1"><i class="fas fa-layer-group"></i>Signed category-wise under <b>'+esc(t.cat)+'</b><a data-act="gocat" data-cat="'+esc(t.cat)+'">Edit category sign-off ↓</a></div>'+dn+'</div>'
                          : ''; /* hide: no sign-off UI, saved values stay as-is */
                    /* v26: a test whose config carries custom_js (formula or registry key) gets the
                       data-custom-js attribute + the classes test-custom-function.js resolves
                       (.test-card / .test-header / .test-validation-badge). Tests without custom_js
                       render exactly as before. */
                    return '<article class="tc'+(t.done?' done':'')+(S.mode==='bulk'&&S.collapsed[t.id]?' collapsed':'')+(t.js?' test-card':'')+'" id="tc'+t.id+'" data-t="'+t.id+'"'+(t.js?' data-custom-js="'+esc(t.js)+'" data-test-id="'+t.id+'"':'')+' style="--cc:'+cc(t.cat)+'">'+
                        '<div class="th'+(t.js?' test-header':'')+'">'+
                        '<label class="sel" title="Select for print" onclick="event.stopPropagation()"><input type="checkbox" data-act="sel" data-t="'+t.id+'"></label>'+
                        '<div class="tt" data-act="toggle" data-t="'+t.id+'"><i class="fas fa-chevron-down tgl"></i><b>'+esc(t.name)+'</b><span class="mut">'+esc(t.short)+'</span></div>'+(t.js?'<span class="test-validation-badge" style="display:none;"></span>':'')+'<span class="cchip" title="Category"><i class="fas fa-circle"></i>'+esc(t.cat)+'</span><span class="sp"></span>'+
                        '<span class="pill" data-r="fill">'+s.f+'/'+s.tot+' filled</span>'+
                        '<span class="pill abn" data-r="abn" style="'+(s.a?'':'display:none')+'">'+s.a+' abnormal</span>'+
                        '<span class="pill st '+(t.done?'ok':'wait')+'" data-r="st">'+(t.done?'<i class="fas fa-check"></i> Completed':'<i class="far fa-clock"></i> Pending')+'</span>'+
                        '<button class="btn-x sm qd'+(t.done?' on':'')+'" data-act="qdone" data-t="'+t.id+'" title="Mark complete in one click"><i class="fas fa-check"></i> <span>'+(t.done?'Completed':'Complete')+'</span></button>'+
                        '<button type="button" class="ib" data-act="hideempty" data-t="'+t.id+'" title="Hide empty rows from report"><i class="fas fa-eye-slash"></i></button>'+
                        '<button type="button" class="ib" data-act="showall" data-t="'+t.id+'" title="Show all rows"><i class="fas fa-eye"></i></button>'+
                        '<a href="#" class="ib" data-act="print1" data-t="'+t.id+'" title="Preview / print this test"><i class="fas fa-print"></i></a>'+
                        '</div>'+
                        '<div class="tb">'+
                        '<div class="wrap"><table class="ct">'+COLS+'<tbody>'+rows+'</tbody></table></div>'+
                        '<div class="tfoot"><div class="sec"><div class="sec-t"><i class="fas fa-comment-medical"></i>Comment / Interpretation<small>printed before the signatures</small><span class="r"><span id="cc'+t.id+'">'+t.comment.length+'</span> chars</span></div>'+
                        '<div class="phr">'+PHRASES.map(function(x,i){return '<button type="button" data-act="phr" data-t="'+t.id+'" data-i="'+i+'">+ '+esc(x)+'</button>'}).join('')+'</div>'+
                        '<textarea class="f-c" data-t="'+t.id+'" data-tf="comment" placeholder="Add interpretation, clinical note or remarks…">'+esc(t.comment)+'</textarea></div>'+
                        sign+'</div>'+
                        '</div></article>';
                }
                function catSignPanel(c){
                    var n=inCat(c).length, map=['p','r','a'];
                    return '<div class="catsign" id="cs-'+esc(c).replace(/\s/g,'_')+'"><div class="sec-t"><i class="fas fa-users-cog"></i>Category sign-off — '+esc(c)+'<small>applies to all '+n+' test'+(n===1?'':'s')+' in this category</small></div>'+
                        signGrid({v:SIGN[c],attr:function(k){return 'data-cat="'+esc(c)+'" data-cf="'+k+'"'}})+
                        '<div class="acts"><button class="btn-x sm" data-act="copycats" data-cat="'+esc(c)+'"><i class="fas fa-copy"></i> Use for all categories</button>'+
                        '<button class="btn-x sm ok" data-act="catdone" data-cat="'+esc(c)+'"><i class="fas fa-check-double"></i> Mark category complete</button></div></div>';
                }
                function visibleTests(){return S.mode==='single'?TESTS.filter(function(t){return t.id===S.cur}):TESTS.filter(function(t){return S.cat==='__all__'||t.cat===S.cat})}
                function renderMain(){
                    var list=visibleTests(),g={},h='';
                    list.forEach(function(t){(g[t.cat]=g[t.cat]||[]).push(t)});
                    Object.keys(g).forEach(function(c){
                        h+='<section class="cat'+(S.cc[c]?' cc':'')+'" style="--cc:'+cc(c)+'" data-cat="'+esc(c)+'"><div class="cat-h"><span class="t"><label class="sel d-inline-flex mr-2" onclick="event.stopPropagation()"><input type="checkbox" data-act="selcat" data-cat="'+esc(c)+'" title="Select whole category"></label><span class="cn" data-act="togcat" data-cat="'+esc(c)+'"><i class="fas fa-folder-open"></i>'+esc(c)+'</span><small>'+g[c].length+' test'+(g[c].length===1?'':'s')+'</small></span><span><span class="pgrp sm"><a href="#" class="btn-x sm" data-act="printcat" data-cat="'+esc(c)+'"><i class="fas fa-print"></i> Print category</a></span> <i class="fas fa-chevron-down text-muted ml-2" data-act="togcat" data-cat="'+esc(c)+'"></i></span></div>'+
                            '<div class="cat-b">'+g[c].map(testCard).join('')+(S.sign==='cat'?catSignPanel(c):'')+'</div></section>';
                    });
                    $('#body').className=S.mode;
                    $('#body').innerHTML=h||'<div class="sb-none">No tests</div>';
                    syncSel();
                }
                function sbItem(t){
                    var s=stats(t),pc=s.tot?Math.round(s.f/s.tot*100):0;
                    return '<div class="sb-i'+(fOk(t)?'':' dim')+(S.mode==='single'&&S.cur===t.id?' on':'')+(t.done?' done':'')+(t.dirty?' dirty':'')+'" id="sb'+t.id+'" data-act="goto" data-t="'+t.id+'" style="--cc:'+cc(t.cat)+'"><input type="checkbox" data-act="sel" data-t="'+t.id+'" title="Select for print"><div class="sb-n">'+esc(t.name)+'<small>'+esc(t.short)+'</small>'+(t.done?'<i class="fas fa-check-circle sb-ok"></i>':'<i class="far fa-clock sb-wt"></i>')+'</div><span class="sb-s">'+s.f+'/'+s.tot+'</span><div class="sb-p"><i style="width:'+pc+'%"></i></div></div>';
                }
                function fOk(t){return !((S.filter==='pending'&&t.done)||(S.filter==='done'&&!t.done))}
                function matchF(t){
                    if(S.q){var q=S.q.toLowerCase();if((t.name+' '+t.short+' '+t.cat).toLowerCase().indexOf(q)<0)return false}
                    return true;
                }
                function renderSide(){
                    var h='';
                    cats().forEach(function(c){
                        var all=inCat(c),ts=all.filter(matchF),d=all.filter(function(t){return t.done}).length; if(!ts.length){if(S.q)return;ts=all.slice()} /* fully filtered category still lists its tests */
                        h+='<div class="sb-cat" style="--cc:'+cc(c)+'"><label><input type="checkbox" data-act="selcat" data-cat="'+esc(c)+'"><i class="fas fa-folder-open" style="color:var(--cc)"></i>'+esc(c)+'</label><span><a href="#" data-act="printcat" data-cat="'+esc(c)+'" title="Preview / print category" style="cursor:pointer;color:var(--pd);margin-right:8px"><i class="fas fa-print"></i></a>'+d+'/'+all.length+'</span></div>'+(all.length>ts.length&&!S.q?'<div class="sb-hid"><i class="fas fa-eye-slash"></i> '+(all.length-ts.length)+' hidden by filter · still counted for print</div>':'')+ts.map(sbItem).join('');
                    });
                    $('#sbl').innerHTML=h||'<div class="sb-none">No tests match</div>'; syncSel();
                }
                function renderSub(){
                    var h='<div class="l">';
                    if(S.mode==='single'){
                        var i=TESTS.indexOf(T(S.cur));
                        h+='<button class="btn-x sm" data-act="prev" title="Alt+←"><i class="fas fa-chevron-left"></i></button><span class="cur">'+(i+1)+' / '+TESTS.length+' · '+esc(T(S.cur).name)+'</span><button class="btn-x sm" data-act="next" title="Alt+→"><i class="fas fa-chevron-right"></i></button>'+
                            '<button class="btn-x sm" data-act="nextpend"><i class="fas fa-forward"></i> Next pending</button>';
                    }else{
                        h+='<div class="cmb-chips"><span class="cmb-chip'+(S.cat==='__all__'?' on':'')+'" data-act="cat" data-cat="__all__">All<small>'+TESTS.length+'</small></span>';
                        cats().forEach(function(c){var ts=inCat(c),d=ts.filter(function(t){return t.done}).length;h+='<span class="cmb-chip'+(S.cat===c?' on':'')+'" data-act="cat" data-cat="'+esc(c)+'">'+esc(c)+'<small>'+d+'/'+ts.length+'</small></span>'});
                        h+='</div><button class="btn-x sm" data-act="colall">Collapse all</button><button class="btn-x sm" data-act="expall">Expand all</button>';
                    }
                    h+='<button class="btn-x sm" data-act="nextempty" title="F2"><i class="fas fa-keyboard"></i> Next empty</button></div>';
                    $('#sub').innerHTML=h; progress();
                }
                function progress(){var d=TESTS.filter(function(t){return t.done}).length,p=Math.round(d/TESTS.length*100);if($('#pbar')){$('#pbar').style.width=p+'%';$('#ptxt').textContent=d+'/'+TESTS.length+' done'}}
                function renderChrome(){
                    $$('#segMode button').forEach(function(b){b.classList.toggle('on',b.dataset.m===S.mode)});
                    $$('#segSign button').forEach(function(b){b.classList.toggle('on',b.dataset.s===S.sign)});
                    $('#saveScope').textContent=S.mode==='single'?'this test':'all tests';
                    $('#btnAllDone').style.display=S.mode==='bulk'?'':'none';
                    $('#optAuto').style.display=S.mode==='single'?'':'none';
                    $('#pName').innerHTML=esc(PT.name)+' <span class="tag">'+esc(PT.age)+' · '+esc(PT.sex)+'</span>';
                    $('#pId').textContent=SLNO;$('#pRef').textContent=PT.ref;
                    $('#avatar').textContent=PT.name.split(' ').map(function(w){return w[0]}).slice(0,2).join('');
                }
                function renderAll(){renderChrome();renderSide();renderSub();renderMain();refreshDirty()}

                /* ====================== incremental updates ====================== */
                function updateRow(t,c){
                    var tr=byId('r'+t.id+'_'+c.id);if(!tr)return;
                    /* v26: re-assert component-row — test-custom-function.js resolves rows through it,
                       and this rebuild used to wipe the class on every edit */
                    tr.className=rowCls(c)+(c.type==='title'?' r-title':'')+(c.type!=='title'?' component-row':'');
                    var s=$('[data-f=status]',tr);if(s){s.value=c.status||'';s.className='f fl fl-'+(c.status||'none')}
                    var r=$('[data-f=result]',tr);if(r&&c.type!=='textarea')r.classList.toggle('bad',badNum(c));
                    /* v28: refresh the near-field hint after every edit ("= 4000" / "Invalid number") */
                    var rh=$('.rowhint',tr);if(rh){var nh=numHint(c);rh.textContent=nh.text||'';rh.className='rowhint '+(nh.cls||'')}
                    var h=$('[data-act=hide]',tr);if(h)h.checked=!!c.hide;
                }
                function updateStats(t){
                    var s=stats(t),card=$('#tc'+t.id);
                    if(card){
                        card.classList.toggle('done',t.done);
                        $('[data-r=fill]',card).textContent=s.f+'/'+s.tot+' filled';
                        var a=$('[data-r=abn]',card);a.style.display=s.a?'':'none';a.textContent=s.a+' abnormal';
                        var st=$('[data-r=st]',card);st.className='pill st '+(t.done?'ok':'wait');st.innerHTML=t.done?'<i class="fas fa-check"></i> Completed':'<i class="far fa-clock"></i> Pending';
                        var cb=$('[data-act=done]',card);if(cb)cb.checked=t.done;var qb=$('.qd',card);if(qb){qb.classList.toggle('on',t.done);$('span',qb).textContent=t.done?'Completed':'Complete'}
                    }
                    var sb=$('#sb'+t.id);
                    if(sb){sb.classList.toggle('done',t.done);sb.classList.toggle('dirty',t.dirty);$('.sb-s',sb).textContent=s.f+'/'+s.tot;$('.sb-p i',sb).style.width=(s.tot?Math.round(s.f/s.tot*100):0)+'%'}
                    progress();
                }
                function markDirty(t){t.dirty=true;var sb=$('#sb'+t.id);if(sb)sb.classList.add('dirty');refreshDirty()}
                function refreshDirty(){var n=TESTS.filter(function(t){return t.dirty}).length;$('#dirty').classList.toggle('show',n>0);$('#dirtyN').textContent=n;$('#btnSave').classList.toggle('pri',true)}
                function setDone(t,v){
                    if(t.done===v)return; t.done=v; markDirty(t); updateStats(t);
                }
                function syncSel(){
                    $$('input[data-act=sel]').forEach(function(i){i.checked=!!T(+i.dataset.t).sel});
                    $$('input[data-act=selcat]').forEach(function(i){var ts=inCat(i.dataset.cat),n=ts.filter(function(t){return t.sel}).length;i.checked=n===ts.length;i.indeterminate=n>0&&n<ts.length});
                    var n=TESTS.filter(function(t){return t.sel}).length;$('#selN').textContent=n;$('#selN').style.display=n?'':'none';
                }
                function setHide(t,c,v){c.hide=v;updateRow(t,c);updateStats(t);markDirty(t)}
                function toast(m,k){var e=document.createElement('div');e.className='toast-x '+(k||'info');e.textContent=m;$('#toasts').appendChild(e);setTimeout(function(){e.style.opacity=0;e.style.transition='opacity .3s';setTimeout(function(){e.remove()},300)},2400)}
                /* v26: expose the page toast NOW (the later preview/print block re-exports it) —
                   the test-custom-function engine validates as soon as cards render and its
                   alerts must use THIS toast, not a blocking alert() dialog */
                window.COMBO_TOAST = toast;

                /* ========================= navigation ============================ */
                function focusFirstEmpty(){if(isMobile())return;var e=$$('#body .cmb-nav').filter(function(x){return x.offsetParent&&!x.value})[0]||$('#body .cmb-nav');if(e)e.focus()}
                function openTest(id){S.cur=id;renderSide();renderSub();renderMain();$('#main').scrollTop=0;setTimeout(focusFirstEmpty,30)}
                function goTo(id){
                    if(S.mode==='single'){openTest(id);return}
                    if(S.cat!=='__all__'&&T(id).cat!==S.cat){S.cat='__all__';renderSub();renderMain()}
                    S.collapsed[id]=false;var c=$('#tc'+id);if(c){c.classList.remove('collapsed');var cs=c.closest('.cat');cs.classList.remove('cc');S.cc[cs.dataset.cat]=false;c.scrollIntoView({behavior:'smooth',block:'start'})}
                }
                function step(d){var i=TESTS.indexOf(T(S.cur))+d;if(i<0||i>=TESTS.length)return;openTest(TESTS[i].id)}
                function nextPending(){
                    var i=TESTS.indexOf(T(S.cur)),n=TESTS.length;
                    for(var k=1;k<=n;k++){var t=TESTS[(i+k)%n];if(!t.done&&t.id!==S.cur){if(S.mode==='single')openTest(t.id);else goTo(t.id);return}}
                    toast('All tests are completed','ok');
                }
                function nextEmpty(){
                    var all=$$('#body .cmb-nav').filter(function(x){return x.offsetParent}),cur=all.indexOf(document.activeElement),e=all.filter(function(x,i){return !x.value&&i>cur})[0]||all.filter(function(x){return !x.value})[0];
                    if(e){e.focus();e.scrollIntoView({block:'center',behavior:'smooth'})}else toast('No empty result left','ok');
                }
                function setMode(m){S.mode=m;try{localStorage.setItem('lr_view',m)}catch(_){}renderAll();if(m==='single')setTimeout(focusFirstEmpty,30)}
                function setSign(m){
                    if(m===S.sign)return;
                    if(m==='cat'){ /* seed category sign from first signed test */
                        cats().forEach(function(c){var s=SIGN[c];if(s.p||s.r||s.a)return;inCat(c).forEach(function(t){if(!s.p)s.p=t.sp;if(!s.r)s.r=t.sr;if(!s.a)s.a=t.sa})});
                    }else if(m==='test'){ /* back to per test: fill empty individual fields from category */
                        TESTS.forEach(function(t){var s=SIGN[t.cat]||{};if(!t.sp&&s.p)t.sp=s.p;if(!t.sr&&s.r)t.sr=s.r;if(!t.sa&&s.a)t.sa=s.a});
                    }
                    S.sign=m;try{localStorage.setItem('lr_sign',m)}catch(_){}
                    renderChrome();renderMain();
                    toast(m==='cat'?'Category-wise sign-off: individual sign-off is hidden':(m==='hide'?'Sign-off areas hidden':'Per-test sign-off enabled'),'info');
                }

                /* ============================ events ============================= */
                function onEdit(e){
                    var el=e.target,d=el.dataset;if(!d)return;
                    if(d.f!==undefined){
                        var t=T(+d.t),c=C(t,+d.c);if(!c)return;
                        c[d.f]=el.value;
                        if(d.f==='status')c.manual=el.value!=='';
                        if((d.f==='result'||d.f==='ref')&&!c.manual)c.status=autoFlag(c);
                        if(d.f==='interp')el.className='f interp I_'+(el.value||'NT');
                        updateRow(t,c);updateStats(t);markDirty(t);return;
                    }
                    if(d.tf!==undefined){
                        var t2=T(+d.t);t2[d.tf]=el.value;if(/^s[pra]$/.test(d.tf)&&el.value)TESTS.forEach(function(x){if(!x[d.tf]){x[d.tf]=el.value;markDirty(x);var s2=$('select[data-t="'+x.id+'"][data-tf="'+d.tf+'"]');if(s2)s2.value=el.value}});if(d.tf==='comment'){var cc=$('#cc'+t2.id);if(cc)cc.textContent=el.value.length}
                        if(/^s[pra]$/.test(d.tf))TESTS.forEach(function(x){var e=$('#sgs'+x.id);if(e)e.innerHTML=sgSum({p:x.sp,r:x.sr,a:x.sa})+'<a data-act="sgedit">Change</a>'});
                        markDirty(t2);return;
                    }
                    if(d.cf!==undefined){SIGN[d.cat][d.cf]=el.value;if(el.value)cats().forEach(function(c){if(!SIGN[c][d.cf]){SIGN[c][d.cf]=el.value;inCat(c).forEach(markDirty);var s3=$('select[data-cat="'+c+'"][data-cf="'+d.cf+'"]');if(s3)s3.value=el.value}});inCat(d.cat).forEach(markDirty);return}
                    switch(d.act){
                        case 'sel':T(+d.t).sel=el.checked;syncSel();break;
                        case 'selcat':inCat(d.cat).forEach(function(t){t.sel=el.checked});syncSel();break;
                        case 'hide':var t3=T(+d.t);setHide(t3,C(t3,+d.c),el.checked);break;
                        case 'hidetitle':
                            var t4=T(+d.t),on=el.checked,inSec=false;
                            t4.comps.forEach(function(c){if(c.type==='title'){inSec=(c.id===+d.c);if(inSec)c.hide=on;updateRow(t4,c);return}if(inSec){c.hide=on;updateRow(t4,c)}});
                            updateStats(t4);markDirty(t4);break;
                        case 'done':
                            var t5=T(+d.t);if(t5.done===el.checked)break;setDone(t5,el.checked);renderSide();renderSub();
                            if(el.checked&&S.mode==='single'&&$('#autoNext').checked){setTimeout(function(){var i=TESTS.indexOf(t5),nx=TESTS.filter(function(x,k){return k>i&&!x.done})[0]||TESTS.filter(function(x){return !x.done})[0];if(nx)openTest(nx.id);else toast('All tests are completed','ok')},320)}
                            break;
                    }
                }
                ROOT.addEventListener('input',onEdit);
                ROOT.addEventListener('change',onEdit);

                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest('[data-act]');if(!b||/^(INPUT|SELECT|TEXTAREA)$/.test(b.tagName))return;
                    var a=b.dataset.act,d=b.dataset;
                    switch(a){
                        case 'toggle':
                            if(S.mode!=='bulk')return;var id=+d.t;S.collapsed[id]=!S.collapsed[id];$('#tc'+id).classList.toggle('collapsed',S.collapsed[id]);break;
                        case 'togcat':var s=b.closest('.cat');s.classList.toggle('cc');S.cc[d.cat]=s.classList.contains('cc');break;
                        case 'goto':goTo(+d.t);if(isMobile())$('#side').classList.remove('open');break;
                        case 'prev':step(-1);break;case 'next':step(1);break;
                        case 'nextpend':nextPending();break;case 'nextempty':nextEmpty();break;
                        case 'cat':S.cat=d.cat;renderSub();renderMain();break;
                        case 'colall':TESTS.forEach(function(t){S.collapsed[t.id]=true});$$('.tc').forEach(function(x){x.classList.add('collapsed')});if(S.mode==='easy'&&S.eg!=='flat'){TESTS.forEach(function(t){S.ecol[t.id]=true});$$('.eb').forEach(function(x){x.classList.add('ebc')});$$('#body tr[id^="r"]').forEach(function(x){x.setAttribute('data-col','1')})}break;
                        case 'expall':S.collapsed={};S.cc={};S.ecol={};$$('.tc').forEach(function(x){x.classList.remove('collapsed')});$$('.cat').forEach(function(x){x.classList.remove('cc')});$$('.eb').forEach(function(x){x.classList.remove('ebc')});$$('#body tr[data-col]').forEach(function(x){x.removeAttribute('data-col')});break;
                        case 'qdone':var cb0=$('#tc'+d.t+' [data-act=done]');cb0.checked=!cb0.checked;onEdit({target:cb0});break;
                        case 'selall':TESTS.forEach(function(t){t.sel=true});syncSel();break;
                        case 'selnone':TESTS.forEach(function(t){t.sel=false});syncSel();break;
                        case 'hideempty':var t=T(+d.t);t.comps.forEach(function(c){if(c.type!=='title'&&!hasVal(c)){c.hide=true;updateRow(t,c)}});updateStats(t);markDirty(t);toast('Empty rows hidden from report','info');break;
                        case 'showall':var t2=T(+d.t);t2.comps.forEach(function(c){c.hide=false;updateRow(t2,c)});updateStats(t2);markDirty(t2);break;
                        case 'phr':
                            var t3=T(+d.t),ta=$('#tc'+t3.id+' textarea[data-tf=comment]'),p=PHRASES[+d.i];
                            ta.value=(ta.value.trim()?ta.value.replace(/\s+$/,'')+'. ':'')+p+'.';t3.comment=ta.value;$('#cc'+t3.id).textContent=ta.value.length;markDirty(t3);ta.focus();break;
                        case 'sgedit':b.closest('.sgc').classList.toggle('open');break;
                        case 'gocat':var cs=$('.catsign');if(cs)cs.scrollIntoView({behavior:'smooth',block:'center'});break;
                        case 'copycats':var src=SIGN[d.cat];cats().forEach(function(c){SIGN[c]={p:src.p,r:src.r,a:src.a};inCat(c).forEach(markDirty)});renderMain();toast('Sign-off copied to all categories','ok');break;
                        case 'catdone':inCat(d.cat).forEach(function(t){setDone(t,true)});renderSide();renderSub();toast(d.cat+' marked complete','ok');break;
                    }
                });
                $('#segMode').addEventListener('click',function(e){var b=e.target.closest('button');if(b)setMode(b.dataset.m)});
                $('#segSign').addEventListener('click',function(e){var b=e.target.closest('button');if(b)setSign(b.dataset.s)});
                $('#sbf').addEventListener('click',function(e){var b=e.target.closest('button');if(!b)return;S.filter=b.dataset.f;$$('#sbf button').forEach(function(x){x.classList.toggle('on',x===b)});renderSide()});
                $('#combo-find').addEventListener('input',function(){S.q=this.value.trim();renderSide()});
                $('#btnSide').addEventListener('click',function(){$('#side').classList.toggle('open')});
                $('#btnAllDone').addEventListener('click',function(){visibleTests().forEach(function(t){setDone(t,true)});renderSide();renderSub();toast('All visible tests marked complete','ok')});
                $('#btnSave').addEventListener('click',save);
                window.addEventListener('beforeunload',function(e){if(TESTS.some(function(t){return t.dirty})){e.preventDefault();e.returnValue=''}});

                /* keyboard */
                document.addEventListener('keydown',function(e){
                    var k=e.key,mod=e.ctrlKey||e.metaKey;
                    if(mod&&k.toLowerCase()==='s'){e.preventDefault();save();return}
                    if(k==='F2'){e.preventDefault();nextEmpty();return}
                    if(e.altKey&&S.mode==='single'&&(k==='ArrowRight'||k==='ArrowLeft')){e.preventDefault();step(k==='ArrowRight'?1:-1);return}
                    var el=e.target;
                    if(el.classList&&el.classList.contains('cmb-nav')&&!e.shiftKey&&!mod&&!e.altKey){
                        var dir=k==='ArrowUp'?-1:(k==='ArrowDown'||(k==='Enter'&&el.tagName!=='TEXTAREA'))?1:0;if(!dir)return;
                        var all=$$('#body .cmb-nav').filter(function(x){return x.offsetParent}),i=all.indexOf(el)+dir;
                        if(i<0||i>=all.length)return;e.preventDefault();all[i].focus();if(all[i].select)all[i].select();
                    }
                });

                /* ============================== save ============================= */
                var CSRF=(document.querySelector('meta[name="csrf-token"]')||{}).content||'';
                var SAVING=false;
                function uid(label){return label?(USER_ID[label]||null):null}
                function compPayload(c){
                    var o={component_id:c.uid,type:c.type,order:c.order||0,hide:c.hide?1:0};
                    if(c.type==='title')return o;
                    if(c.type==='culture'){o.mic_value=c.mic||'';o.zone_value=c.zone||'';o.result=c.interp||'';o.status=c.status||'';return o}
                    if(c.type==='textarea')o.result_text=c.result||'';else o.result=c.result==null?'':String(c.result);
                    o.unit=c.unit||'';o.reference_text=c.ref||'';o.status=c.status||'';o.color=c.color||'';
                    return o;
                }
                function save(only){
                    if(SAVING)return;
                    var list=Array.isArray(only)?only:(S.mode==='single'?[T(S.cur)]:TESTS),bad=[];
                    list=list.filter(Boolean);
                    list.forEach(function(t){t.comps.forEach(function(c){if(badNum(c))bad.push(t.name+' › '+c.name)})});
                    if(bad.length){toast('Invalid number: '+bad.slice(0,3).join(', ')+(bad.length>3?' …':''),'err');var b=$('#body .f.bad');if(b){b.focus();b.scrollIntoView({block:'center'})}return}
                    /* v27: SAVE LOCK — a custom-function validation that is not passing (a CBC
                       differential that does not total 100%, a failed formula, a registry script
                       error) blocks the save exactly like a bad number does. The state is
                       refreshed synchronously for every test in the save list first: bulk/single
                       reads the engine's badge, easy view re-runs the same function over the live
                       rows. Tests not currently rendered keep their last known result. */
                    list.forEach(cfvRunNow);
                    var locked=list.filter(cfvBad);
                    if(locked.length){
                        var lmsg=locked.slice(0,3).map(function(t){return t.name+' — '+String((CFV[t.id]&&CFV[t.id].text)||'Validation failed').replace(/^\u26a0\s*/,'')});
                        toast('Save locked · '+lmsg.join(' | ')+(locked.length>3?' …':''),'err');
                        var le=byId('eb'+locked[0].id)||byId('tc'+locked[0].id);
                        if(le)le.scrollIntoView({block:'center',behavior:'smooth'});
                        return;
                    }
                    if(!list.length)return;
                    var payload=list.map(function(t){var s=sg(t);return {
                        id:t.uid,complete:t.done?1:0,
                        prepared_by:uid(s.p),reviewed_by:uid(s.r),authorized_by:uid(s.a),comment:t.comment||'',
                        components:t.comps.map(compPayload)}});
                    SAVING=true;$('#btnSave').disabled=true;
                    fetch(CB.updateUrl,{method:'POST',credentials:'same-origin',
                        headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'},
                        body:JSON.stringify({receipt_id:CB.receipt.id,tests:payload})})
                        .then(function(r){return r.json().catch(function(){return {type:'fail',note:'Server error ('+r.status+')'}})})
                        .then(function(res){
                            if(res.type==='invalid'){var n=res.note,first=n&&typeof n==='object'?(Object.keys(n).map(function(k){return n[k][0]})[0]):String(n);toast('Not saved: '+first,'err');return}
                            (res.saved||[]).forEach(function(u){
                                var t=byUid(u);if(!t)return;t.dirty=false;
                                var ids=(res.report_ids||{})[u]||{};t.comps.forEach(function(c){if(ids[c.uid])c.rid=ids[c.uid]});
                                var sb=$('#sb'+t.id);if(sb)sb.classList.remove('dirty');
                            });
                            refreshDirty();
                            /* v24: results changed on the server — drop the cached PDF buffer
                               so the preview modal regenerates it from fresh data next time */
                            if(window.PdfPreview&&PdfPreview.invalidate)PdfPreview.invalidate();
                            var f=res.failed||{},fk=Object.keys(f);
                            if(res.type==='success')toast('Saved '+(res.saved||[]).length+' test'+((res.saved||[]).length===1?'':'s'),'ok');
                            else if(res.type==='partial')toast(res.note+' — '+f[fk[0]],'err');
                            else toast(res.note||'Save failed','err');
                        })
                        .catch(function(){toast('Network error — nothing was saved','err')})
                        .then(function(){SAVING=false;$('#btnSave').disabled=false});
                }

                /* ===================== v8 additions ===================== */
                S.ef='all';
                var renderSub0=renderSub,renderMain0=renderMain,visibleTests0=visibleTests,goTo0=goTo,renderChrome0=renderChrome,updateStats0=updateStats,refreshDirty0=refreshDirty,progress0=progress;
                progress=function(){progress0();kpi()};
                refreshDirty=function(){refreshDirty0();kpi();cfvNotesRender()}; /* v29: invalid-number notes refresh on every edit, js or not */
                visibleTests=function(){return S.mode==='easy'?TESTS.slice():visibleTests0()};
                renderChrome=function(){renderChrome0();$('#btnAllDone').style.display=(S.mode==='bulk'||S.mode==='easy')?'':'none'};
                goTo=function(id){if(S.mode==='easy'){var b=$('#eb'+id);if(b){b.scrollIntoView({behavior:'smooth',block:'start'});return}var r=$('#body tr[id^="r'+id+'_"]'); /* v30: Flat grouping has no banner — jump to the test's first row instead */
                    if(r){r.scrollIntoView({behavior:'smooth',block:'center'});r.classList.add('cnv-jump');setTimeout(function(){r.classList.remove('cnv-jump')},1600);return}toast('Test is hidden by the current filter','info');return}goTo0(id)};
                renderMain=function(){if(S.mode!=='easy')return renderMain0();$('#body').className='easy';$('#body').innerHTML=easyHTML();syncSel()};
                updateStats=function(t){updateStats0(t);var eb=$('#eb'+t.id);if(!eb)return;var s=stats(t);$('[data-r=fill]',eb).textContent=s.f+'/'+s.tot+' filled';var a=$('[data-r=abn]',eb);a.style.display=s.a?'':'none';a.textContent=s.a+' abnormal';var q=$('.qd',eb);q.classList.toggle('on',t.done);$('span',q).textContent=t.done?'Completed':'Complete'};
                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest('[data-act]');if(!b||/^(INPUT|SELECT|TEXTAREA)$/.test(b.tagName))return;var d=b.dataset;
                    if(d.act==='ef'){S.ef=d.f;renderSub();renderMain()}
                    else if(d.act==='kpi'){
                        if(d.k==='unsaved'){var x=TESTS.filter(function(t){return t.dirty})[0];if(x)goTo(x.id);else toast('Nothing unsaved','ok');return}
                        S.ef=(S.ef===d.k)?'all':d.k; /* v22: the chips ARE the filters; clicking the active chip returns to All */
                        if(S.mode==='easy'){renderSub();renderMain()}else setMode('easy');
                    }
                    else if(d.act==='edone'){var t=T(+d.t);setDone(t,!t.done);var eb=$('#eb'+t.id);if(eb)eb.outerHTML=easyBanner(t);renderSide();renderSub()}
                });

                /* ===================== v8.1 additions ===================== */
                S.ecat='';S.eq='';S.eg='test';S.ecol={};try{var _eg=localStorage.getItem('lr_eg');if(_eg==='test'||_eg==='flat')S.eg=_eg}catch(_){} /* By test / Flat sticks like the view + sign-off picks */
                /* LIS demo: Laravel should fill c.lis={v,inst,t} from the analyzer queue */
                /* LIS: the server may send c.lis={v,inst,t} per component; nothing is mocked here */
                function rv(c){return String(c.result==null?'':c.result)}
                function lisPend(c){return c.type==='number'&&!!c.lis&&rv(c)!==c.lis.v}
                function lisCount(t){var n=0;(t?[t]:TESTS).forEach(function(x){x.comps.forEach(function(c){if(lisPend(c))n++})});return n}
                /* update the badge IN PLACE: replacing the node between mousedown and mouseup (blur->change->updateRow) swallows the click */
                function lisSync(b,t,c){var m=rv(c)===c.lis.v;b.className='lis '+(m?'ok':(rv(c).trim()===''?'new':'diff'));b.disabled=m;b.innerHTML='<i class="fas fa-satellite-dish"></i> LIS '+esc(c.lis.v)+(m?' ✓':' · Use')}
                function lisHTML(t,c){var m=rv(c)===c.lis.v;return '<button type="button" class="lis '+(m?'ok':(rv(c).trim()===''?'new':'diff'))+'" data-act="lis" data-t="'+t.id+'" data-c="'+c.id+'" title="'+esc(c.lis.inst+' · '+c.lis.t)+'"'+(m?' disabled':'')+'><i class="fas fa-satellite-dish"></i> LIS '+esc(c.lis.v)+(m?' ✓':' · Use')+'</button>'}
                var row0=row;row=function(t,c,i){var h=row0(t,c,i);if(c.type==='number'&&c.lis)h=h.replace('<td class="c-n col-name">'+esc(c.name)+'</td>','<td class="c-n col-name"><div class="cnw"><span class="cnn">'+esc(c.name)+'</span>'+lisHTML(t,c)+'</div></td>');return h};
                var updateRow0=updateRow;updateRow=function(t,c){updateRow0(t,c);if(c.type==='number'&&c.lis){var tr=$('#r'+t.id+'_'+c.id),b=tr&&$('.lis',tr);if(b)lisSync(b,t,c)}var n=$('#lisN');if(n)n.textContent=lisCount()};
                /* LIS "Use": copy the LIS value into THIS component's result (replace). Nothing else is called. */
                function acceptLis(tid,cid){
                    var t=T(tid),c=C(t,cid);if(!c||!c.lis)return;
                    var i=ROOT.querySelector('input[data-f="result"][data-t="'+tid+'"][data-c="'+cid+'"]');
                    c.result=c.lis.v;if(i)i.value=c.lis.v;
                    markDirty(t);
                    /* v26: announce the fill like a real keystroke — combo state (onEdit),
                       auto-flag and test-custom-function.js all re-validate the card */
                    if(i){try{i.dispatchEvent(new Event('input',{bubbles:true}))}catch(_){}}
                    var b=ROOT.querySelector('.lis[data-t="'+tid+'"][data-c="'+cid+'"]');if(b)lisSync(b,t,c);
                }
                function acceptTest(t){t.comps.forEach(function(c){acceptLis(t.id,c.id,true)});var b=$('#eb'+t.id+' .lisall');if(b)b.remove()}
                /* v27: the Invalid chip filters down to tests whose custom-function validation is not passing */
                function cfvBad(t){return !!(t.js&&CFV[t.id]&&CFV[t.id].level==='bad')}
                function easyTests(){return TESTS.filter(function(t){return fOk(t)&&matchF(t)&&!(S.ef==='pending'&&t.done)&&(S.ef!=='cf'||cfvBad(t))&&(!S.ecat||t.cat===S.ecat)})}

                /* v22: ONE strip = old KPI row + old filter chips. Every chip IS a filter (data-k -> S.ef),
                   "Unsaved" jumps to the first dirty test, the right side keeps tests + results progress. */
                var KTIP={all:'Show every row',pending:'Tests not completed yet',empty:'Rows without a result yet',abn:'Rows flagged abnormal',crit:'Critical values',lis:'Rows with a fresh LIS value to accept',unsaved:'Jump to the first unsaved test',cf:'Custom-function validation is not passing — save is locked for those tests'};
                function kpi(){
                    var d=0,tot=0,f=0,a=0,cr=0,u=0,L=lisCount(),cf=0;
                    TESTS.forEach(function(t){if(cfvBad(t))cf++;if(t.done)d++;if(t.dirty)u++;t.comps.forEach(function(c){if(c.type==='title'||c.hide)return;tot++;if(hasVal(c))f++;if(isAbn(c))a++;if(c.status==='Critical')cr++})});
                    function k(lab,act,cl,n){return '<button class="'+cl+(S.ef===act?' on':'')+'" data-act="kpi" data-k="'+act+'" title="'+KTIP[act]+'"><span>'+lab+'</span><b>'+n+'</b></button>'}
                    var pc=TESTS.length?Math.round(d/TESTS.length*100):0;
                    $('#kpi').innerHTML=k('All','all','',tot)+k('Pending','pending','',TESTS.length-d)+k('Empty','empty','',tot-f)+k('Abn','abn',a?'warn':'',a)+k('Crit','crit',cr?'bad':'',cr)+k('LIS','lis',L?'warn':'',L)+k('Unsaved','unsaved','',u)+k('Invalid','cf',cf?'bad':'',cf)
                        +'<div class="prog"><div class="bar"><i id="pbar" style="width:'+pc+'%"></i></div><small id="ptxt">'+d+'/'+TESTS.length+' tests · '+f+'/'+tot+' results</small></div>';
                }
                function efOk(c){return S.ef==='all'||S.ef==='pending'||S.ef==='cf'||(S.ef==='empty'&&!hasVal(c))||(S.ef==='abn'&&isAbn(c))||(S.ef==='crit'&&c.status==='Critical')||(S.ef==='lis'&&lisPend(c))}
                function easyBanner(t){var s=stats(t),L=lisCount(t),cfv=t.js?(CFV[t.id]&&CFV[t.id].level?'<span class="pill cfv '+CFV[t.id].level+'" id="cfv'+t.id+'" title="Custom function validation">'+esc(CFV[t.id].text||'')+'</span>':'<span class="pill cfv" id="cfv'+t.id+'" title="Custom function validation"></span>'):'';return '<tr class="eb'+(S.ecol[t.id]?' ebc':'')+'" id="eb'+t.id+'" style="--cc:'+cc(t.cat)+'"><td colspan="7"><div class="eb-in"><i class="fas fa-chevron-down eb-ch" data-act="ebt" data-t="'+t.id+'"></i><span class="eb-n" data-act="ebt" data-t="'+t.id+'" style="--cc:'+cc(t.cat)+'"><i class="fas fa-circle"></i><b>'+esc(t.name)+'</b><small>'+esc(t.cat)+'</small></span><span class="sp"></span><span class="pill" data-r="fill">'+s.f+'/'+s.tot+' filled</span><span class="pill abn" data-r="abn" style="'+(s.a?'':'display:none')+'">'+s.a+' abnormal</span>'+cfv+(L?'<button class="btn-x sm lisall" data-act="lis1" data-t="'+t.id+'"><i class="fas fa-satellite-dish"></i> Accept LIS ('+L+')</button>':'')+'<button class="btn-x sm qd'+(t.done?' on':'')+'" data-act="edone" data-t="'+t.id+'"><i class="fas fa-check"></i> <span>'+(t.done?'Completed':'Complete')+'</span></button></div></td></tr>'}
                function easyHTML(){
                    var rows='',n=0,flat=S.eg==='flat',q=(S.eq||'').toLowerCase();
                    function tag(h,col){return col?h.replace('<tr ','<tr data-col="1" '):h}
                    easyTests().forEach(function(t){
                        var r='',k=0,cnt=0,col=!flat&&S.ecol[t.id];
                        t.comps.forEach(function(c){
                            if(c.type==='title'){if((S.ef==='all'||S.ef==='pending')&&!q&&!flat)r+=tag(row(t,c,0),col);return}
                            k++;if(!efOk(c)||(q&&c.name.toLowerCase().indexOf(q)<0))return;cnt++;
                            var h=row(t,c,k);if(flat)h=h.replace('<td class="c-n col-name">','<td class="c-n col-name"><span class="tg">'+esc(t.short||t.name)+'</span>');
                            r+=tag(h,col);
                        });
                        if(cnt){n+=cnt;rows+=(flat?'':easyBanner(t))+r}
                    });
                    if(!n)return '<div class="easy-card"><div class="sb-none"><i class="fas fa-check-circle fa-2x" style="color:var(--ok)"></i><p class="mt-2 mb-0">Nothing matches this filter.</p></div></div>';
                    return '<div class="easy-card"><div class="wrap"><table class="ct easy">'+COLS+'<tbody>'+rows+'</tbody></table></div></div>';
                }
                /* v22: easy sub toolbar — the row filters moved up into #kpi, only tools stay here */
                renderSub=function(){
                    if(S.mode!=='easy')return renderSub0();
                    $('#sub').innerHTML='<div class="l">'+
                        '<select class="ecs" id="ecs"><option value="">All categories</option>'+cats().map(function(x){return '<option'+(S.ecat===x?' selected':'')+'>'+esc(x)+'</option>'}).join('')+'</select>'+
                        '<input type="search" class="eqi" id="eq" placeholder="Find component…" value="'+esc(S.eq)+'">'+
                        '<div class="sg-s"><button data-act="eg" data-g="test" class="'+(S.eg==='test'?'on':'')+'">By test</button><button data-act="eg" data-g="flat" class="'+(S.eg==='flat'?'on':'')+'">Flat</button></div>'+
                        '<button class="btn-x sm" data-act="colall">Collapse all</button><button class="btn-x sm" data-act="expall">Expand all</button>'+
                        '<button class="btn-x sm" data-act="lisall"><i class="fas fa-satellite-dish"></i> Accept all LIS <b id="lisN">'+lisCount()+'</b></button><button class="btn-x sm" data-act="ehide"><i class="fas fa-eye-slash"></i> Hide empty</button><button class="btn-x sm" data-act="nextempty" title="F2"><i class="fas fa-keyboard"></i> Next empty</button></div>';
                    progress();
                };

                /* ---- sidebar: category click = category view, test click = single test ---- */
                var sbItem0=sbItem;sbItem=function(t){return sbItem0(t).replace('data-act="goto"','data-act="sbtest"')};
                renderSide=function(){
                    var h='<div class="sb-i sb-all'+((S.mode==='bulk'&&S.cat==='__all__')||(S.mode==='easy'&&!S.ecat)?' on':'')+'" data-act="sball"><input type="checkbox" data-act="selall" title="Select all tests (Preview / Blank / Print)"><div class="sb-n">All tests</div><span class="sb-s">'+TESTS.filter(function(t){return t.done}).length+'/'+TESTS.length+'</span></div>';
                    cats().forEach(function(c){
                        var all=inCat(c),ts=all.filter(matchF),d=all.filter(function(t){return t.done}).length;if(!ts.length){if(S.q)return;ts=all.slice()}
                        var on=(S.mode==='bulk'&&S.cat===c)||(S.mode==='easy'&&S.ecat===c);
                        h+='<div class="sb-cat'+(on?' on':'')+'" style="--cc:'+cc(c)+'" data-act="sbcat" data-cat="'+esc(c)+'"><input type="checkbox" data-act="selcat" data-cat="'+esc(c)+'" title="Select for print"><span class="sbc-n"><i class="fas fa-folder-open" style="color:var(--cc)"></i>'+esc(c)+'</span><span><a href="#" data-act="printcat" data-cat="'+esc(c)+'" title="Preview / print category" style="cursor:pointer;color:var(--pd);margin-right:8px"><i class="fas fa-print"></i></a>'+d+'/'+all.length+'</span></div>'+ts.map(sbItem).join('');
                    });
                    $('#sbl').innerHTML=h;syncSel();
                };
                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest('[data-act]');if(!b||/^(INPUT|SELECT|TEXTAREA)$/.test(b.tagName))return;var d=b.dataset,a=d.act;
                    function closeSide(){if(isMobile())$('#side').classList.remove('open')}
                    function easyRe(){renderSide();renderSub();renderMain()}
                    if(a==='sbtest'){var id=+d.t;closeSide();
                        if(S.mode==='easy'){var eb=$('#eb'+id);if(eb)eb.scrollIntoView({behavior:'smooth',block:'start'});else toast('Test is hidden by the current filter','info');return}
                        S.cur=id;if(S.mode==='single')openTest(id);else setMode('single')}
                    else if(a==='sbcat'){closeSide();
                        if(S.mode==='easy'){S.ecat=S.ecat===d.cat?'':d.cat;easyRe();return}
                        S.cat=d.cat;S.cc[d.cat]=false;inCat(d.cat).forEach(function(t){S.collapsed[t.id]=false});setMode('bulk')}
                    else if(a==='sball'){closeSide();if(S.mode==='easy'){S.ecat='';easyRe();return}S.cat='__all__';setMode('bulk')}
                    else if(a==='lis'){acceptLis(+d.t,+d.c)}
                    else if(a==='lis1'){acceptTest(T(+d.t))}
                    else if(a==='lisall'){var n=0;easyTests().forEach(function(t){n+=lisCount(t);acceptTest(t)});renderSub();toast(n?n+' LIS value'+(n===1?'':'s')+' accepted':'No LIS value to accept','ok')}
                    else if(a==='ehide'){easyTests().forEach(function(t){var ch=false;t.comps.forEach(function(c){if(c.type!=='title'&&!c.hide&&!hasVal(c)){c.hide=true;updateRow(t,c);ch=true}});if(ch){updateStats(t);markDirty(t)}});toast('Empty rows hidden from report','info')}
                    else if(a==='eg'){S.eg=d.g;try{localStorage.setItem('lr_eg',S.eg)}catch(_){}renderSub();renderMain()}
                    else if(a==='ebt'){var tid=+d.t,off=!S.ecol[tid];S.ecol[tid]=off;b.closest('tr').classList.toggle('ebc',off);$$('#body tr[id^="r'+tid+'_"]').forEach(function(tr){if(off)tr.setAttribute('data-col','1');else tr.removeAttribute('data-col')})}
                });
                ROOT.addEventListener('change',function(e){if(e.target.id==='ecs'){S.ecat=e.target.value;renderSide();renderMain()}});
                ROOT.addEventListener('input',function(e){if(e.target.id==='eq'){S.eq=e.target.value;renderMain()}});
                document.addEventListener('keydown',function(e){var el=e.target;if(e.altKey&&e.key.toLowerCase()==='l'&&el.dataset&&el.dataset.c){e.preventDefault();acceptLis(+el.dataset.t,+el.dataset.c)}});

                /* ===================== selection: count cmb-chip + "All" checkbox ===================== */
                var syncSel0=syncSel;syncSel=function(){
                    syncSel0();
                    var a=$('#sbl input[data-act=selall]');
                    if(a){var n=TESTS.filter(function(t){return t.sel}).length;a.checked=n===TESTS.length;a.indeterminate=n>0&&n<TESTS.length}
                };
                ROOT.addEventListener('change',function(e){var el=e.target;if(el.tagName==='INPUT'&&el.dataset.act==='selall'){TESTS.forEach(function(t){t.sel=el.checked});syncSel()}});
                $('#selN').addEventListener('click',function(){TESTS.forEach(function(t){t.sel=false});syncSel()});

                /* ===================== category-wise + per-test Save / Mark complete ===================== */
                function syncDirtyUI(){TESTS.forEach(function(t){var c=$('#tc'+t.id),b=$('#eb'+t.id);if(c)c.classList.toggle('is-dirty',!!t.dirty);if(b)b.classList.toggle('is-dirty',!!t.dirty)})}
                var rd2=refreshDirty;refreshDirty=function(){rd2();syncDirtyUI()};
                var rm2=renderMain;renderMain=function(){
                    rm2();
                    $$('#body .cat-h .pgrp').forEach(function(g){var c=esc(g.closest('.cat').dataset.cat);
                        g.insertAdjacentHTML('beforebegin','<span class="pgrp sm"><button class="btn-x sm" data-act="csave" data-cat="'+c+'" title="Save all tests in this category"><i class="fas fa-save"></i> Save</button><button class="btn-x sm ok" data-act="cdone" data-cat="'+c+'" title="Mark all tests in this category complete"><i class="fas fa-check-double"></i> Mark complete</button></span><span class="vsep"></span>')});
                    $$('#body .th .qd,#body .eb .qd').forEach(function(q){q.insertAdjacentHTML('beforebegin','<button class="btn-x sm" data-act="tsave" data-t="'+q.dataset.t+'" title="Save this test only"><i class="fas fa-save"></i> Save</button>')});
                    syncDirtyUI();
                };
                var rs2=renderSub;renderSub=function(){rs2();
                    if(S.mode==='easy'&&S.ecat){var l=$('#sub .l'),c=esc(S.ecat);if(l)l.insertAdjacentHTML('beforeend','<span class="vsep"></span><button class="btn-x sm" data-act="csave" data-cat="'+c+'"><i class="fas fa-save"></i> Save '+c+'</button><button class="btn-x sm ok" data-act="cdone" data-cat="'+c+'"><i class="fas fa-check-double"></i> Complete '+c+'</button>')}
                };
                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest('[data-act]');if(!b||/^(INPUT|SELECT|TEXTAREA)$/.test(b.tagName))return;var d=b.dataset,a=d.act;
                    if(a==='tsave'){save([T(+d.t)])}
                    else if(a==='csave'){save(inCat(d.cat))}
                    else if(a==='cdone'){
                        var ts=inCat(d.cat),e0=0;
                        ts.filter(function(t){return !t.done}).forEach(function(t){t.comps.forEach(function(c){if(c.type!=='title'&&!c.hide&&!hasVal(c))e0++})});
                        if(e0&&!confirm(e0+' result'+(e0===1?' is':'s are')+' still empty in '+d.cat+'.\nMark complete anyway?'))return;
                        ts.forEach(function(t){setDone(t,true)});renderSide();renderSub();toast(d.cat+' marked complete. Remember to save.','ok');
                    }
                });

                /* v16 auto-collapse removed: completed tests stay expanded — Expand all never turns off by itself */

                /* ===== v20: LIS button = fill only (capture phase, blocks every other click handler) ===== */
                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest&&e.target.closest('.lis');if(!b)return;
                    e.preventDefault();e.stopImmediatePropagation();
                    if(!b.disabled)acceptLis(+b.dataset.t,+b.dataset.c);
                },true);

                /* ===================== v21: footer Save / Complete for every test and category ===================== */
                function emptyIn(ts){var n=0;ts.filter(function(t){return !t.done}).forEach(function(t){t.comps.forEach(function(c){if(c.type!=='title'&&!c.hide&&!hasVal(c))n++})});return n}
                function okEmpty(ts,what){var n=emptyIn(ts);return !n||confirm(n+' result'+(n===1?' is':'s are')+' still empty in '+what+'.\nMark complete anyway?')}
                function nextPending(t){var i=TESTS.indexOf(t),l=TESTS.slice(i+1).concat(TESTS.slice(0,i));return l.filter(function(x){return !x.done})[0]}
                function tfHTML(t){return '<div class="tf" id="tf'+t.id+'"><div class="tf-s"></div><div class="tf-a">'+
                    '<button class="btn-x sm" data-act="lis1" data-t="'+t.id+'" title="Copy every LIS value of this test"><i class="fas fa-satellite-dish"></i> <span>Accept LIS</span></button>'+
                    '<button class="btn-x sm" data-act="tsave" data-t="'+t.id+'"><i class="fas fa-save"></i> Save</button>'+
                    '<button class="btn-x sm pri" data-act="tcomp" data-t="'+t.id+'"></button></div></div>'}
                function cfHTML(c){c=esc(c);return '<div class="cf" data-cat="'+c+'"><div class="cf-s"></div><div class="cf-a">'+
                    '<button class="btn-x sm" data-act="csave" data-cat="'+c+'"><i class="fas fa-save"></i> Save category</button>'+
                    '<button class="btn-x sm pri" data-act="ccomp" data-cat="'+c+'" title="Save and mark every test complete"><i class="fas fa-check-double"></i> Complete category</button></div></div>'}
                function pill(t,c){return '<span class="tf-p '+(c||'')+'">'+t+'</span>'}
                function tfSync(t){
                    var f=$('#tf'+t.id);if(!f)return;var L=lisCount(t);
                    /* v27: footer pill mirrors the validation state — visible even when the
                       card header (and its badge) is scrolled away on a long test */
                    var cv=(t.js&&CFV[t.id]&&CFV[t.id].level==='bad')?pill('\u26a0 '+String(CFV[t.id].text||'Validation failed').replace(/^\u26a0\s*/,''),'bad'):'';
                    $('.tf-s',f).innerHTML=cv+(t.dirty?pill('Unsaved changes','u'):'');
                    var cb=$('[data-act=tcomp]',f);cb.innerHTML=t.done?'<i class="fas fa-undo"></i> Reopen':'<i class="fas fa-check-double"></i> Complete';
                    cb.classList.toggle('pri',!t.done);cb.title=t.done?'Mark as pending again':'Save and mark complete';
                    var lb=$('[data-act=lis1]',f);lb.style.display=L?'':'none';$('span',lb).textContent='Accept LIS ('+L+')';
                }
                function cfSync(){
                    $$('#body .cf').forEach(function(f){var ts=inCat(f.dataset.cat),d=ts.filter(function(t){return t.done}).length,u=ts.filter(function(t){return t.dirty}).length;
                        $('.cf-s',f).innerHTML=pill(d+'/'+ts.length+' tests complete',d===ts.length?'ok':'')+(u?pill(u+' unsaved','u'):'');
                        $('[data-act=ccomp]',f).disabled=d===ts.length});
                }
                function footSync(){TESTS.forEach(tfSync);cfSync()}
                var rm3=renderMain;renderMain=function(){
                    rm3();
                    if(S.mode==='easy')return;
                    $$('#body .cat-h [data-act=csave]').forEach(function(b){var g=b.closest('.pgrp'),sp=g.nextElementSibling;g.remove();if(sp&&sp.classList.contains('vsep'))sp.remove()});
                    $$('#body .th [data-act=tsave],#body .th .qd').forEach(function(b){b.remove()});
                    $$('#body .tc').forEach(function(tc){var t=T(+tc.id.slice(2)),tb=$('.tb',tc);if(t&&tb)tb.insertAdjacentHTML('beforeend',tfHTML(t));
                        var sg=$('.sg-row',tc);if(sg&&!$('.sgc',tc))sg.style.display='none';            /* category-wise sign-off: nothing to show per test */
                        $$('label.chk',tc).forEach(function(l){if($('[data-act=done]',l))l.style.display='none'})  /* Complete lives in the footer */
                    });
                    $$('#body .cat').forEach(function(sec){var cb=$('.cat-b',sec);if(cb&&inCat(sec.dataset.cat).length>1)cb.insertAdjacentHTML('beforeend',cfHTML(sec.dataset.cat))});
                    footSync();
                };
                var us3=updateStats;updateStats=function(t){us3(t);tfSync(t);cfSync()};
                var sd3=syncDirtyUI;syncDirtyUI=function(){sd3();footSync()};
                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest('[data-act]');if(!b||/^(INPUT|SELECT|TEXTAREA)$/.test(b.tagName))return;var d=b.dataset,a=d.act,t;
                    if(a==='tdone'){t=T(+d.t);if(!t.done&&!okEmpty([t],t.name))return;setDone(t,!t.done);renderSide();renderSub();footSync()}
                    else if(a==='tsdone'){t=T(+d.t);if(!okEmpty([t],t.name))return;var nx=nextPending(t);setDone(t,true);save([t]);renderSide();renderSub();footSync();if(S.mode==='single'&&nx&&nx!==t)openTest(nx.id)}
                    else if(a==='csdone'){var ts=inCat(d.cat);if(!okEmpty(ts,d.cat))return;ts.forEach(function(x){setDone(x,true)});save(ts);renderSide();renderSub();footSync()}
                });

                ROOT.addEventListener('click',function(e){
                    var b=e.target.closest('[data-act]');if(!b||b.disabled)return;var d=b.dataset,t;
                    if(d.act==='tcomp'){t=T(+d.t);
                        if(t.done){setDone(t,false);renderSide();renderSub();footSync();return}
                        if(!okEmpty([t],t.name))return;var nx=nextPending(t);setDone(t,true);save([t]);renderSide();renderSub();footSync();
                        if(S.mode==='single'&&nx&&nx!==t)openTest(nx.id)}
                    else if(d.act==='ccomp'){var ts=inCat(d.cat);if(!okEmpty(ts,d.cat))return;ts.forEach(function(x){setDone(x,true)});save(ts);renderSide();renderSub();footSync()}
                });

                /* ===== v26: TEST CUSTOM FUNCTION — re-run the engine after every (re)render.
                   renderMain rebuilds the cards (mode switches, single-test navigation, category
                   filters …) which wipes the validation badges; runCustomJs re-validates every card
                   that carries data-custom-js. The engine also listens to input/change on its own,
                   so typing is validated live without any render. ===== */
                var rmCf=renderMain;renderMain=function(){rmCf();if(window.runCustomJs){try{window.runCustomJs()}catch(_){}}cfvAll()};

                /* ===== v27: CUSTOM FUNCTION STATUS AS TEXT + SAVE LOCK.
                   The engine (test-custom-function.js) paints the ✓/⚠ badge ONLY on bulk/single
                   cards — the easy view has no card, so the same result is mirrored there as a
                   plain-text pill next to the other status pills. CFV[] holds the latest result
                   per test and is what save() locks on. Two paths, one state:
                   • bulk/single  — read the engine's badge AFTER it ran (one source of truth,
                     zero divergence; the engine re-paints on every input/change itself).
                   • easy view    — no card exists, so the named function runs again from the
                     SAME window.TEST_JS registry over the live rows (identical helpers
                     contract: get/raw/set/badge/clearBadge), and inline formulas are
                     re-evaluated with the engine's grammar and rounding.
                   Debounced input/change taps keep CFV fresh between renders. ===== */
                var CFV={};                                   /* tid -> {level:'ok'|'bad'|null, text:''} */
                var CFV_SCHED={};                             /* tid -> pending revalidation timeout */
                var CFV_LASTA={};                             /* alert dedup, same 2.5s window as the engine */
                var CFV_BADN=-1;                              /* last Invalid count painted into #kpi */
                function cfvNorm(s){return String(s==null?'':s).trim().toLowerCase().replace(/\s+/g,'_')}
                function cfvToNum(v){var n=parseFloat(String(v==null?'':v).replace(/[,\s\u00A0]/g,''));return isNaN(n)?0:n}
                function cfvRound(n){return Math.round((n+Number.EPSILON)*100)/100}
                function cfvRows(tid){return $$('#body tr[id^="r'+tid+'_"].component-row')}
                /* v30: flat easy rows prefix the name cell with the test tag
                   <span class="tg">CBC</span> — cell.textContent was "CBCNeutrophils", so NO
                   component name ever matched, the mirror silently produced no state and
                   Flat grouping lost the pill, the error notes AND the save lock
                   ("easy mode not check" — this was the only view that still read the
                   concatenated text). Strip the tag before reading; .cnn (LIS wrap) wins. */
                function cfvRowName(tr){var cell=tr.querySelector('.col-name');if(!cell)return '';var p=cell.querySelector('.cnn');if(p)return p.textContent.replace(/\u00A0/g,' ').trim();var cl=cell.cloneNode(true),tg=cl.querySelectorAll('.tg'),i;for(i=0;i<tg.length;i++)if(tg[i].parentNode)tg[i].parentNode.removeChild(tg[i]);return cl.textContent.replace(/\u00A0/g,' ').trim()}
                function cfvFindRow(rows,name){var tg=cfvNorm(name),f=null;rows.forEach(function(r){if(f)return;if(cfvNorm(cfvRowName(r))===tg)f=r});return f}
                function cfvInputOf(tr){return tr?tr.querySelector('.table-input[type="text"], .table-input[type="search"], .component-value'):null}
                function cfvAlert(m){var k=String(m),now=Date.now();if(CFV_LASTA[k]&&now-CFV_LASTA[k]<2500)return;CFV_LASTA[k]=now;try{if(typeof window.COMBO_TOAST==='function')window.COMBO_TOAST(k,'err')}catch(_){}}
                function cfvHelpers(rows,note){
                    function get(n){var i=cfvInputOf(cfvFindRow(rows,n));return i?cfvToNum(i.value):0}
                    function raw(n){var i=cfvInputOf(cfvFindRow(rows,n));return i?i.value:''}
                    function set(n,v){var i=cfvInputOf(cfvFindRow(rows,n));if(!i)return;i.value=v;try{i.dispatchEvent(new Event('input',{bubbles:true}))}catch(_){}}
                    return {get:get,raw:raw,set:set,log:function(){},
                        badge:function(txt,level){note.level=level==='error'?'bad':'ok';note.text=String(txt)},
                        clearBadge:function(){note.level=null;note.text=''}};
                }
                /* inline formula mirror — the engine's statement grammar: expr | expr = expr [~ tolerance] ; … */
                function cfvFormula(src,rows,note){
                    var H=cfvHelpers(rows,note);
                    function evalExpr(s){
                        var i=0;
                        function ws(){while(i<s.length&&/\s/.test(s[i]))i++}
                        function factor(){ws();var c=s[i];
                            if(c==='('){i++;var v=expr();ws();if(s[i]!==')')throw new Error('Expected )');i++;return v}
                            if(c==='-'){i++;return -factor()}
                            if(/[0-9.]/.test(c)){var n='';while(i<s.length&&/[0-9.]/.test(s[i]))n+=s[i++];return isNaN(parseFloat(n))?0:parseFloat(n)}
                            if(/[A-Za-z_]/.test(c)){var id='';while(i<s.length&&/[A-Za-z0-9_]/.test(s[i]))id+=s[i++];return H.get(id)}
                            throw new Error('Unexpected character: '+(c===undefined?'end of input':c))}
                        function term(){var v=factor();for(;;){ws();var c=s[i];if(c==='*'){i++;v*=factor()}else if(c==='/'){i++;var d=factor();v/=(d||1)}else return v}}
                        function expr(){var v=term();for(;;){ws();var c=s[i];if(c==='+'){i++;v+=term()}else if(c==='-'){i++;v-=term()}else return v}}
                        return expr()}
                    String(src).split(';').forEach(function(stmt){
                        var s0=String(stmt).trim();if(!s0)return;
                        var eq=s0.indexOf('=');
                        if(eq<0){try{evalExpr(s0)}catch(e){if(note.level!=='bad'){note.level='bad';note.text='\u26a0 Formula: '+e.message}}return}
                        var lhs=s0.slice(0,eq),rhs=s0.slice(eq+1),tol=0,til=rhs.indexOf('~');
                        if(til>=0){tol=cfvToNum(rhs.slice(til+1));rhs=rhs.slice(0,til)}
                        var target=/^\s*[A-Za-z_][A-Za-z0-9_]*\s*$/.test(rhs)?rhs.trim():null;
                        try{
                            var lv=evalExpr(lhs);
                            if(target){
                                var rv0=H.raw(target);
                                if(rv0===''){H.set(target,cfvRound(lv));if(note.level!=='bad'){note.level='ok';note.text='\u2713 '+target+' = '+cfvRound(lv)}}
                                else{
                                    var tv=cfvToNum(rv0);
                                    if(Math.abs(lv-tv)>tol&&note.level!=='bad'){note.level='bad';note.text='\u26a0 '+target+' should be '+cfvRound(lv)+' but is '+cfvRound(tv);cfvAlert(note.text.replace(/^\u26a0\s*/,''))}
                                }
                            }else{
                                var rvv=evalExpr(rhs);
                                if(Math.abs(lv-rvv)>tol&&note.level!=='bad'){note.level='bad';note.text='\u26a0 Expected '+cfvRound(rvv)+' but computed '+cfvRound(lv);cfvAlert(note.text.replace(/^\u26a0\s*/,''))}
                            }
                        }catch(e){if(note.level!=='bad'){note.level='bad';note.text='\u26a0 Formula: '+e.message;cfvAlert(note.text.replace(/^\u26a0\s*/,''))}}
                    });
                }
                /* ===== v29: FALLBACK REGISTRY — the named custom functions, logic-identical to
                   test-custom-function.js. If that engine file is missing or not yet loaded
                   (mix() 404, jQuery timing, stale cache), registry tests like
                   cbc_differentiation went COMPLETELY SILENT before: no badge, no easy pill,
                   no save lock, nothing on screen saying why. The engine always wins when
                   present; these fill the gap so easy view + the save lock never lose them. ===== */
                var CFV_REG={
                    cbc_differentiation:function(ctx,h){
                        try{
                            var w=h.get('Total WBC Count'),hasE=h.raw('Eosinophils')!=='';
                            var out=(w>0&&hasE)?Math.round(w*h.get('Eosinophils')/100):'';
                            if(String(h.raw('Total Cir. Eosinophil'))!==String(out))h.set('Total Cir. Eosinophil',out);
                        }catch(err){}
                        var names=['Neutrophils','Lymphocytes','Monocytes','Eosinophils','Basophils','Blasts'],sum=0,hasAny=false;
                        names.forEach(function(n){var v=h.get(n);if(v>0)hasAny=true;sum+=v});
                        if(!hasAny){h.clearBadge();return}
                        if(Math.abs(sum-100)<0.01)h.badge('\u2713 Differential: 100%','ok');
                        else{var msg='Differential count must total 100% (currently '+cfvRound(sum)+'%)';h.badge('\u26a0 '+msg,'error');cfvAlert(msg)}
                    },
                    lipid_profile:function(ctx,h){
                        var tc=h.get('Total Cholesterol'),hdl=h.get('HDL Cholesterol'),tg=h.get('Triglyceride');
                        if(tc>0&&hdl>0&&tg>0&&tg<400){var ldl=tc-hdl-(tg/5);if(ldl>0)h.set('LDL Cholesterol',cfvRound(ldl))}
                        if(tc>0&&hdl>0){var ratio=tc/hdl;
                            if(ratio>5){h.badge('\u26a0 TC/HDL ratio: '+cfvRound(ratio),'error');cfvAlert('TC/HDL ratio is '+cfvRound(ratio)+' (high risk)')}
                            else h.badge('\u2713 TC/HDL ratio: '+cfvRound(ratio),'ok')}
                    },
                    liver_function:function(ctx,h){
                        var alt=h.get('ALT'),ast=h.get('AST');
                        if(alt>40||ast>40){h.badge('\u26a0 Elevated liver enzymes','error');cfvAlert('ALT ('+alt+') or AST ('+ast+') is elevated')}
                        else if(alt>0||ast>0)h.badge('\u2713 Liver enzymes normal','ok');
                    },
                    renal_function:function(ctx,h){
                        var egfr=h.get('eGFR');
                        if(egfr>0&&egfr<60){h.badge('\u26a0 Reduced kidney function (eGFR '+egfr+')','error');cfvAlert('eGFR is low ('+egfr+')')}
                        else if(egfr>0)h.badge('\u2713 Kidney function normal','ok');
                    },
                    bsa_calculator:function(ctx,h){
                        var ht=h.get('Height'),wt=h.get('Weight');
                        if(ht>0&&wt>0)h.set('Body Surface Area',cfvRound(Math.sqrt((ht*wt)/3600)));
                    },
                    thyroid_panel:function(ctx,h){
                        var tsh=h.get('TSH');if(!tsh)return;
                        if(tsh>4.5){h.badge('\u26a0 Possible hypothyroid (TSH '+tsh+')','error');cfvAlert('TSH is high ('+tsh+')')}
                        else if(tsh<0.4){h.badge('\u26a0 Possible hyperthyroid (TSH '+tsh+')','error');cfvAlert('TSH is low ('+tsh+')')}
                        else h.badge('\u2713 TSH normal','ok');
                    },
                    urine_re_me:function(ctx,h){
                        var required=['Colour','Appearance','Albumin','Sugar','Pus Cells'],missing=[];
                        required.forEach(function(n){if(!h.raw(n))missing.push(n)});
                        if(missing.length)h.badge('\u26a0 Missing: '+missing.join(', '),'error');
                        else h.badge('\u2713 All fields filled','ok');
                    },
                    diabetes_profile:function(ctx,h){
                        var fpg=h.get('Fasting Plasma Glucose'),rpg=h.get('Random Plasma Glucose'),hba1c=h.get('HbA1c');
                        if(fpg>126)cfvAlert('FPG is high ('+fpg+' mg/dL) — diabetic range');
                        if(hba1c>=6.5)cfvAlert('HbA1c is '+hba1c+'% — diabetic range');
                        if(fpg>126||hba1c>=6.5)h.badge('\u26a0 Diabetic indicators','error');
                        else if(fpg>0||rpg>0||hba1c>0)h.badge('\u2713 Within normal range','ok');
                    }
                };
                /* v29: the mirror — runs the SAME custom_js over the LIVE rows (engine grammar
                   + window.TEST_JS registry + CFV_REG fallback, identical helpers contract).
                   Returns true when a state was produced, false when the rows are not
                   rendered (keep last state). v29 adds a partial-render guard: row filters
                   (Empty / Abn / Crit / LIS / find box) hide rows — the helpers would read
                   the missing components as 0 and FALSE-LOCK the save; keeping the last
                   known state is correct because hiding rows never changes the data. */
                function cfvMirror(t){
                    var rows=cfvRows(t.id);
                    if(!rows.length)return false;
                    var need=0;t.comps.forEach(function(c){if(c.type!=='title')need++});
                    if(rows.length<need)return false;
                    var rjs=String(t.js).trim(),note={level:null,text:''},REG=window.TEST_JS||{};
                    try{
                        if(/[+\-*/=()~;]/.test(rjs))cfvFormula(rjs,rows,note);          /* engine order: formula first */
                        else{
                            var fn=(typeof REG[rjs]==='function')?REG[rjs]:CFV_REG[rjs]; /* engine wins; fallback fills a missing engine */
                            if(typeof fn==='function')fn({testName:t.name,components:{}},cfvHelpers(rows,note));
                            else console.warn('[combo] unknown custom function:',rjs);
                        }
                    }catch(e){note={level:'bad',text:'\u26a0 Script error'}}
                    CFV[t.id]={level:note.level,text:note.text};
                    return true;
                }
                /* one authoritative refresh for a test — v28 order:
                   1. bulk/single card with a PAINTED badge  -> badge text is the truth (engine owns it);
                   2. badge never painted (engine still loading, missing, or unknown registry key)
                      -> mirror the same logic over the live rows, so a CBC that totals 100%
                      shows "✓ Differential: 100%" in EVERY view and the save lock sees a real
                      state instead of nothing;
                   3. easy view (no card) -> mirror;
                   4. rows not rendered (filtered out) -> keep the last known state. */
                function cfvRunNow(t){
                    if(!t||!t.js)return;
                    var card=byId('tc'+t.id);
                    if(card&&card.classList.contains('test-card')){
                        var b=card.querySelector('.test-validation-badge');
                        if(b&&b.style.display!=='none'){
                            var st={level:null,text:''};
                            if(b.classList.contains('invalid'))st={level:'bad',text:b.textContent||''};
                            else if(b.classList.contains('valid'))st={level:'ok',text:b.textContent||''};
                            CFV[t.id]=st;cfvSync(t.id);return;
                        }
                        if(cfvMirror(t)){cfvSync(t.id);return}
                        CFV[t.id]={level:null,text:''};          /* badge hidden + no rows: engine said nothing to check */
                        cfvSync(t.id);return;
                    }
                    cfvMirror(t);cfvSync(t.id);                  /* easy view / no card */
                }
                function cfvAll(){TESTS.forEach(cfvRunNow)}
                /* v30: FLAT easy paint — the per-test validation state on the .tg test tag.
                   By-test easy shows the banner pill; Flat has no banner, so every flat row
                   of a failing test gets a red tag (hover = exact reason) and a passing one
                   gets green. Kept in sync by cfvSync, which already runs on every
                   validation refresh in every view. */
                function cfvFlatSync(tid){
                    var t=T(tid);if(!t)return;
                    var st=CFV[tid]||{},txt=String(st.text||'').replace(/^\u26a0\s*/,'').replace(/^\u2713\s*/,'');
                    $$('#body tr[id^="r'+tid+'_"] .tg').forEach(function(el){
                        el.classList.remove('cfv-ok','cfv-bad');
                        if(st.level==='bad'){el.classList.add('cfv-bad');el.title=txt||'Validation failed'}
                        else if(st.level==='ok'){el.classList.add('cfv-ok');el.title=txt}
                        else el.removeAttribute('title');
                    });
                }
                function cfvSync(tid){
                    var st=CFV[tid],el=byId('cfv'+tid);
                    if(el){
                        if(st&&st.level){el.className='pill cfv '+st.level;el.textContent=st.text||''}
                        else el.className='pill cfv';
                    }
                    cfvFlatSync(tid);                              /* v30: flat easy — tag paint */
                    var tt=T(tid);if(typeof tfSync==='function'&&tt)tfSync(tt);   /* bulk footer pill; easy view has no footer */
                    cfvKpi();
                }
                function cfvKpi(){
                    cfvNotesRender();                            /* v31: keep the footer error note live on every validation refresh */
                    var n=0;TESTS.forEach(function(t){if(cfvBad(t))n++});
                    if(n!==CFV_BADN){CFV_BADN=n;if(typeof kpi==='function')kpi()}
                }
                /* debounced tap — fires AFTER the engine's own listeners ran (setTimeout),
                   so the bulk badge is already fresh when cfvRunNow reads it; coalesced per test */
                function cfvQueue(e){
                    var el=e.target;if(!el||!el.closest)return;
                    var tr=el.closest('tr[id^="r"]');if(!tr)return;
                    var m=/^r(\d+)_/.exec(tr.id);if(!m)return;
                    var t=T(+m[1]);if(!t||!t.js)return;
                    if(CFV_SCHED[t.id])clearTimeout(CFV_SCHED[t.id]);
                    CFV_SCHED[t.id]=setTimeout(function(){CFV_SCHED[t.id]=null;cfvRunNow(t)},0);
                }
                document.addEventListener('input',cfvQueue);
                document.addEventListener('change',cfvQueue);
                /* ===================== v27 end ===================== */

                /* ===================== v31: COMPACT FOOTER ERROR NOTE =====================
                   v29/v30 put a full notes panel above the save bar — it worked, but
                   took too much vertical space. Replaced by ONE short span (#cfvNote)
                   INSIDE the save bar: icon + "Save locked · <first blocker> (+N more)",
                   a single line, ellipsis-truncated. Hover = full blocker list in the
                   tooltip; click = jump to the first blocker and focus its input.
                   Same blockers as before: custom-function failures (save-lock) +
                   invalid numbers. Same refresh paths: cfvKpi() + refreshDirty(). */
                var CFV_NOTES=[];
                function cfvNotesRender(){
                    var box=byId('cfvNote');if(!box)return;
                    var items=[];
                    TESTS.forEach(function(t){
                        if(cfvBad(t))items.push({t:t,txt:String((CFV[t.id]&&CFV[t.id].text)||'Validation failed').replace(/^\u26a0\s*/,'')});
                        t.comps.forEach(function(c){if(badNum(c))items.push({t:t,c:c,txt:c.name+' — invalid number "'+String(c.result==null?'':c.result).trim()+'"'})});
                    });
                    CFV_NOTES=items;
                    var txt=box.querySelector('.cfv-t');
                    if(!items.length){box.className='';box.title='';if(txt)txt.textContent='';return}
                    var msg=(items[0].t.name?items[0].t.name+' — ':'')+items[0].txt;
                    if(items.length>1)msg+=' (+'+(items.length-1)+' more)';
                    box.className='show';
                    if(txt)txt.textContent='Save locked · '+msg;
                    box.title='Save locked until fixed:\n'+items.map(function(it){return it.t.name+': '+it.txt}).join('\n');
                }
                ROOT.addEventListener('click',function(e){
                    if(!(e.target.closest&&e.target.closest('#cfvNote')))return;
                    var it=CFV_NOTES[0];if(!it)return;
                    goTo(it.t.id);
                    if(it.c){var inp=ROOT.querySelector('#r'+it.t.id+'_'+it.c.id+' [data-f=result]');if(inp){try{inp.focus({preventScroll:true})}catch(_){inp.focus()}}}
                });
                try{console.info('[combo-edit] v31 active — compact footer error note (save-bar span) + flat-easy validation + custom-function fallback registry')}catch(_){}
                /* ===================== v31 end ===================== */

                renderAll();
                setTimeout(focusFirstEmpty,60);

                /* ===== v28: the engine script (test-custom-function.js) loads at the END of the
                   page — at first render window.TEST_JS is still empty, so a freshly opened
                   easy view showed NO validation pill ("CBC 100% but nothing displays") until
                   the first keystroke, and bulk badges painted by the engine's own ready()
                   were not mirrored into CFV either. Re-validate everything as soon as the
                   engine registers itself (poll briefly), and once more on window load.
                   Pure formulas never needed the engine, so the mirror already worked for them. ===== */
                (function(){
                    var n=0;
                    (function tick(){
                        if(window.TEST_JS&&Object.keys(window.TEST_JS).length){cfvAll();return}
                        if(++n>40)return;                        /* ~8s cap: page still works without the engine */
                        setTimeout(tick,200);
                    })();
                    window.addEventListener('load',cfvAll);
                })();


                /* ===== v11: mobile shell helpers ===== */
                (function(){
                    var top=ROOT.querySelector('.top'),ctl=ROOT.querySelector('.ctl');
                    var b=document.createElement('button');b.type='button';b.id='btnOpt';b.className='btn-x';b.title='View options';b.innerHTML='<i class="fas fa-sliders-h"></i>';
                    b.onclick=function(){top.classList.toggle('opt');ROOT.classList.toggle('opt')};ctl.appendChild(b);
                    var mq=window.matchMedia('(max-width:900px)');
                    /* Easy mode = simplest flow on phones (one flat worklist) */
                    if(mq.matches){var e=ROOT.querySelector('#segMode [data-m=easy]');if(e&&S.mode!=='easy')e.click();ROOT.querySelector('.top').classList.remove('opt')}
                })();


                /* ===== v17: mobile options panel ===== */
                (function(){
                    var mq=window.matchMedia('(max-width:900px)'),top=ROOT.querySelector('.top'),ctl=ROOT.querySelector('.ctl'),
                        shell=ROOT.querySelector('.shell'),kpi=byId('kpi'),
                        anchor=byId('selN'),btn=byId('btnOpt'),
                        groups=[].slice.call(ctl.querySelectorAll('.cg-x'));
                    var back=document.createElement('div');back.id='optBack';
                    var p=document.createElement('div');p.id='optPanel';
                    p.innerHTML='<div class="op-h"><b><i class="fas fa-sliders-h"></i>View options</b><button type="button" class="op-x" aria-label="Close"><i class="fas fa-times"></i></button></div><div class="op-b"></div><div class="op-f"><div class="op-t">Quick filters</div></div>';
                    ROOT.appendChild(back);ROOT.appendChild(p);
                    var body=p.querySelector('.op-b'),foot=p.querySelector('.op-f');
                    function isOpen(){return p.classList.contains('show')}
                    function open(){
                        top.classList.remove('opt');ROOT.classList.remove('opt');
                        var r=top.getBoundingClientRect();p.style.setProperty('--opt-top',Math.round(r.bottom+6)+'px');
                        p.classList.add('show');back.classList.add('show');btn.classList.add('on');btn.setAttribute('aria-expanded','true');
                    }
                    function close(){p.classList.remove('show');back.classList.remove('show');btn.classList.remove('on');btn.setAttribute('aria-expanded','false')}
                    function place(){
                        if(mq.matches){
                            groups.forEach(function(g){body.appendChild(g)});foot.appendChild(kpi);
                        }else{
                            groups.forEach(function(g){ctl.insertBefore(g,anchor)});shell.parentNode.insertBefore(kpi,shell);close();
                        }
                    }
                    btn.onclick=function(e){e.stopPropagation();isOpen()?close():open()};
                    back.addEventListener('click',close);
                    p.querySelector('.op-x').addEventListener('click',close);
                    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&isOpen())close()});
                    /* choosing a view or a quick filter closes the panel; sign-off / density stay open for tweaking */
                    byId('segMode').addEventListener('click',function(){setTimeout(close,120)});
                    kpi.addEventListener('click',function(){setTimeout(close,120)});
                    window.addEventListener('resize',function(){if(isOpen()){var r=top.getBoundingClientRect();p.style.setProperty('--opt-top',Math.round(r.bottom+6)+'px')}});
                    (mq.addEventListener?mq.addEventListener('change',place):mq.addListener(place));
                    place();
                })();

                /* ===== v22: patient profile quick-edit — posts to the SAME admin/patient/update/{id}
                   endpoint the edit-receipt patient form already uses (assetPath + 'admin/patient/update/'+id),
                   so no new route and no new controller code is needed. Fields: name / phone / age / gender. ===== */
                (function(){
                    var pm=ROOT.querySelector('.pm');if(!pm)return;
                    /* TRUE patient PK for admin/patient/update — prefer a uuid from the raw patient
                       row (window.COMBO_PT) / top-level payload, fall back to whatever PT.id holds.
                       Posting the reg_no here makes Patient::findOrFail throw and the save die. */
                    function pedPid(){var u=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
                        if(PT.pid&&u.test(PT.pid))return PT.pid;
                        if(PT.patient_id&&u.test(PT.patient_id))return PT.patient_id;
                        if(window.COMBO_PT&&window.COMBO_PT.id&&u.test(window.COMBO_PT.id))return window.COMBO_PT.id;
                        if(PT.id&&u.test(PT.id))return PT.id;
                        if(window.COMBO&&window.COMBO.id&&u.test(window.COMBO.id))return window.COMBO.id;
                        if(PT.pid)return PT.pid;
                        if(PT.patient_id)return PT.patient_id;
                        if(window.COMBO_PT&&window.COMBO_PT.id)return window.COMBO_PT.id;
                        return PT.id}
                    var btn=document.createElement('button');btn.type='button';btn.id='btnPedit';btn.className='ib';
                    btn.title='Edit patient profile';btn.innerHTML='<i class="fas fa-user-edit"></i>';
                    if(!pedPid())btn.style.display='none';
                    pm.appendChild(btn);
                    var back=document.createElement('div');back.id='pedBack';
                    back.innerHTML='<div class="ped">'+
                        '<div class="ped-h"><b><i class="fas fa-user-edit"></i>Edit patient profile</b><button type="button" class="ped-x" data-ped="x" aria-label="Close"><i class="fas fa-times"></i></button></div>'+
                        '<div class="ped-b">'+
                        '<div><label>Name</label><input class="f" id="pedName" autocomplete="off"></div>'+
                        '<div class="ped-2"><div><label>Phone</label><input class="f" id="pedPhone" inputmode="numeric" maxlength="11" placeholder="01700000000" autocomplete="off"></div>'+
                        '<div><label>Age</label><input class="f" id="pedAge" placeholder="34y · 6m · 21d" title="Plain number = years. 34y / 6m / 21d all accepted — same convention as the receipt patient form." autocomplete="off"><small class="ped-dob" id="pedDobHint"></small></div></div>'+
                        '<div><label>Gender</label><select class="f" id="pedSex"></select></div>'+
                        '</div>'+
                        '<div class="ped-f"><button type="button" class="btn-x" data-ped="x">Cancel</button><button type="button" class="btn-x pri" id="pedSave"><i class="fas fa-save"></i> Save</button></div></div>';
                    ROOT.appendChild(back);
                    var GEN=['Male','Female','Other'];if(PT.sex&&GEN.indexOf(PT.sex)<0)GEN.unshift(PT.sex);
                    /* phone may live on the patient row OR denormalized on the receipt — try every column name in use */
                    function pPhone(){var r=CB.receipt||{};return PT.phone||PT.mobile||PT.patient_phone||PT.telephone||r.patient_phone||r.phone||r.mobile||''}
                    /* age untouched since the modal opened -> stored dob is KEPT (server keeps it too);
                       age actually edited -> the matching dob is derived and sent with it */
                    var age0='';
                    function normAge(a){return /^\d+$/.test(a)?a+'y':a}
                    function ageChanged(){return normAge($('#pedAge').value.trim())!==normAge(age0)}
                    function dobHint(){var v=normAge($('#pedAge').value.trim());
                        if(v===normAge(age0)){$('#pedDobHint').textContent=PT.dob?'Date of birth: '+PT.dob+' (kept)':'';return}
                        var d=pedDob(v);$('#pedDobHint').textContent=d?'Date of birth (auto): '+d:''}
                    function openM(){
                        $('#pedName').value=PT.name||'';$('#pedPhone').value=pPhone();$('#pedAge').value=PT.age||'';age0=$('#pedAge').value.trim();
                        $('#pedSex').innerHTML='<option value="">—</option>'+GEN.map(function(x){return '<option'+(x===PT.sex?' selected':'')+'>'+esc(x)+'</option>'}).join('');
                        dobHint();
                        back.classList.add('show');setTimeout(function(){$('#pedName').focus()},60);
                    }
                    btn.addEventListener('click',openM);
                    $('#pedAge').addEventListener('input',dobHint);
                    back.addEventListener('click',function(e){if(e.target===back||e.target.closest('[data-ped]'))back.classList.remove('show')});
                    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&back.classList.contains('show'))back.classList.remove('show')});
                    /* age handled EXACTLY like the receipt patient form: plain digits get 'y' appended,
                       34y / 6m / 21d are all accepted, and the matching date of birth is derived and sent
                       WITH the age on EVERY save — the controller keeps dob as the displayed source of
                       truth, so a bare age update would leave the OLD date showing. Compound legacy
                       strings ('33yrs 1mo', '2m 12d') are parsed token-by-token so the derivation never
                       silently skips. */
                    function pedDob(a){var y=0,m=0,d=0,tk=a.split(/\s+/),mm,i,any=false;
                        for(i=0;i<tk.length;i++){if(!(mm=/^(\d+)(y|m|d)/i.exec(tk[i])))continue;
                            var n=+mm[1],u=mm[2].toLowerCase();
                            if(u==='y'){y+=n;any=true}else if(u==='m'){m+=n;any=true}else{d+=n;any=true}}
                        if(!any)return '';
                        var dt=new Date(),mo,dy;
                        if(y){mo=dt.getMonth();dy=dt.getDate();dt.setFullYear(dt.getFullYear()-y);if(dt.getMonth()!==mo||dt.getDate()!==dy)dt.setDate(0)}
                        if(m){dy=dt.getDate();dt.setMonth(dt.getMonth()-m);if(dt.getDate()!==dy)dt.setDate(0)}
                        if(d)dt.setDate(dt.getDate()-d);
                        return dt.getFullYear()+'-'+('0'+(dt.getMonth()+1)).slice(-2)+'-'+('0'+dt.getDate()).slice(-2)}
                    $('#pedSave').addEventListener('click',function(){
                        var b=this,name=$('#pedName').value.trim(),phone=$('#pedPhone').value.trim(),age=$('#pedAge').value.trim(),sex=$('#pedSex').value;
                        if(!name){$('#pedName').classList.add('bad');$('#pedName').focus();toast('Patient name is required','err');return}
                        $('#pedName').classList.remove('bad');
                        phone=phone.replace(/\s+/g,''); /* server rule: nullable|numeric|digits:11 */
                        if(phone&&!/^\d{11}$/.test(phone)){$('#pedPhone').classList.add('bad');$('#pedPhone').focus();toast('Phone must be 11 digits (01XXXXXXXXX)','err');return}
                        $('#pedPhone').classList.remove('bad');
                        if(!age){$('#pedAge').classList.add('bad');$('#pedAge').focus();toast('Age or date of birth is required','err');return} /* same rule the receipt patient form enforces */
                        $('#pedAge').classList.remove('bad');
                        var changed=ageChanged(); /* untouched age -> no dob sent, stored dob survives */
                        if(/^\d+$/.test(age))age=age+'y';
                        var dob=changed?pedDob(age):'';
                        b.disabled=true;var old=b.innerHTML;b.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
                        var base=(typeof assetPath!=='undefined'&&assetPath)?assetPath:'/';
                        /* id MUST ride in the body: the controller validates 'id' => required|integer_or_uuid
                           and runs Patient::findOrFail($data['id']) — the route param alone is not enough */
                        var pid=pedPid();
                        var fd=new FormData();fd.append('_token',CSRF||'');fd.append('id',pid);fd.append('name',name);fd.append('phone',phone);fd.append('age',age);if(dob)fd.append('dob',dob);fd.append('gender',sex);
                        fetch(base+'admin/patient/update/'+pid,{method:'POST',credentials:'same-origin',
                            headers:{'X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:fd})
                            .then(function(r){return r.json().catch(function(){return {type:'fail',note:'Server error ('+r.status+')'}})})
                            .then(function(res){
                                if(res.type==='success'){
                                    PT.name=name;PT.age=age;PT.sex=sex||PT.sex;PT.phone=phone;if(dob)PT.dob=dob;
                                    renderChrome();back.classList.remove('show');toast('Patient profile saved','ok');
                                }else if(res.type==='invalid'){
                                    var n=res.note,first=n&&typeof n==='object'?(Object.keys(n).map(function(k2){return n[k2][0]})[0]):String(n);
                                    toast('Not saved: '+first,'err');
                                }else{var nn=res&&res.note;toast((nn&&typeof nn==='object')?'Update failed (server error)':(nn||'Save failed'),'err')}
                            })
                            .catch(function(){toast('Network error — patient not saved','err')})
                            .then(function(){b.disabled=false;b.innerHTML=old});
                    });
                })();

                (function(){
                    var PV   = window.PdfPreview,
                        base = (typeof assetPath !== 'undefined' && assetPath) ? assetPath : '/',
                        RID  = window.COMBO_RID || (CB.receipt && CB.receipt.id) || '';

                    window.COMBO_TOAST = toast;

                    function pdfUrl(u)   { return base + 'lab/report/print/'       + RID + '/' + u.join(','); }
                    function blankUrl(u) { return base + 'lab/report/blank-print/' + RID + '/' + u.join(','); }
                    function selUids()   { return TESTS.filter(function(t){return t.sel}).map(function(t){return t.uid}); }
                    function uids(ts)    { return ts.map(function(t){return t.uid}); }

                    function isMobile(){
                        return /android|iphone|ipad|ipod|mobile/i.test(navigator.userAgent || '');
                    }

                    /* PREVIEW — always the modal */
                    function showPdf(url, label){
                        if (window.advpreview) { window.advpreview(url, {title:label}); return; }
                        if (PV && PV.preview)  { PV.preview(url, {title:label});        return; }
                        window.open(url, '_blank');
                    }

                    /* PRINT — defer entirely to AdvPrintHelper (same as lab-receipt.js).
                       AdvPrintHelper handles: extension present+healthy → silent;
                       extension absent → desktop window.open / mobile location.href;
                       mobile → reserved tab; errors → toast.

                       If AdvPrintHelper is somehow missing, we fall back to
                       AdvPrintHelper-like behavior inline (so this still works). */
                    function printNow(url, label){
                        label = label || 'Report';

                        if (window.AdvPrintHelper && typeof window.AdvPrintHelper.url === 'function') {
                            /* ── the correct path — the helper does everything ── */
                            try {
                                window.AdvPrintHelper.url(url, { title: label });
                                return;
                            } catch (e) {
                                console.warn('[combo-edit] AdvPrintHelper.url threw:', e);
                                /* fall through to inline fallback below */
                            }
                        }

                        /* ── inline fallback (only if AdvPrintHelper is missing) ──
                           Mirrors AdvPrintHelper's behavior without duplicating its
                           whole surface: AdvPrint extension silent print when healthy;
                           window.open on desktop; location.href on mobile. */
                        console.warn('[combo-edit] AdvPrintHelper missing — using inline fallback');

                        var AP = window.advprint;
                        var hasExt = !!(AP && typeof AP.printPdf === 'function');

                        if (!hasExt) {
                            if (isMobile()) { window.location.href = url; return; }
                            window.open(url, '_blank');
                            return;
                        }

                        Promise.resolve()
                            .then(function () { return AP.status(); })
                            .then(function (st) {
                                if (!st || st.ok === false) {
                                    if (window.COMBO_TOAST) window.COMBO_TOAST(
                                        'Printer hub not responding. Open manually: ' + url, 'info');
                                    return null;
                                }
                                return AP.printPdf(url, { title: label })
                                    .then(function (res) {
                                        if (!res || res.ok !== true) {
                                            console.warn('[combo-edit] printPdf returned', res);
                                            if (window.COMBO_TOAST) window.COMBO_TOAST(
                                                'Print failed: ' + ((res && res.error) || 'unknown'), 'info');
                                        }
                                    });
                            })
                            .catch(function (err) {
                                console.warn('[combo-edit] advprint error:', err);
                                if (isMobile()) { window.location.href = url; return; }
                                window.open(url, '_blank');
                            });
                    }

                    function needSel(){
                        var u = selUids();
                        if (!u.length) toast('Select at least one test for print', 'info');
                        return u;
                    }

                    byId('btnPreview').addEventListener('click', function(e){
                        e.preventDefault(); e.stopImmediatePropagation();
                        var u = needSel();
                        if (u.length) showPdf(pdfUrl(u), SLNO + ' — Report preview');
                    });

                    byId('btnBlank').addEventListener('click', function(e){
                        e.preventDefault(); e.stopImmediatePropagation();
                        var u = needSel();
                        if (!u.length) return;
                        printNow(blankUrl(u), SLNO + ' — Blank form');
                    });

                    byId('btnPrintNow').addEventListener('click', function(e){
                        e.preventDefault(); e.stopImmediatePropagation();
                        var u = needSel();
                        if (!u.length) return;
                        printNow(pdfUrl(u), SLNO + ' — Report');
                    });

                    ROOT.addEventListener('click', function(e){
                        var b = e.target.closest && e.target.closest('[data-act=print1],[data-act=printcat]');
                        if (!b) return;
                        e.preventDefault(); e.stopImmediatePropagation();

                        var d = b.dataset, a = d.act, t, ts;
                        if (a === 'print1') {
                            t = T(+d.t);
                            if (t) showPdf(pdfUrl([t.uid]), t.name + ' — ' + SLNO);
                        } else if (a === 'printcat') {
                            ts = inCat(d.cat);
                            if (ts.length) showPdf(pdfUrl(uids(ts)), d.cat + ' — ' + SLNO);
                        }
                    }, true);
                })();

            })();
        </script>
    @endverbatim

    {{-- v26: TEST CUSTOM FUNCTION — the SAME engine file the bulk-edit page loads
         (js/scripts/pages/test-custom-function.js). Tests whose config carries custom_js
         were rendered with data-custom-js + the engine's classes above; the engine runs
         named functions (window.TEST_JS registry) or inline formulas and shows the
         ✓/⚠ badge in the test header. Requires the comboPayload 'js' key (controller). --}}
    <script src="{{ asset(mix('js/scripts/pages/test-custom-function.js')) }}"></script>
@endsection
