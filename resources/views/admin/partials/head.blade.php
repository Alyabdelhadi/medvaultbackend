{{--
    MedVault admin theme: the app's own palette (AppTheme in the Flutter app:
    darkNavy, greenAccent, novaLogoBlue, turquoise), Poppins and the DM Serif
    wordmark. Everything is inline, logo included: the admin is reached
    through public_html/app/.htaccess, which routes pages, not static files.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title', 'Admin') · MedVault Admin</title>
<link rel="icon" type="image/png" href="{{ \App\Support\AdminAssets::favicon() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --navy:#0d1b4b;--navy-2:#08122f;--navy-3:#1b2a5e;
    --green:#4daf79;--green-600:#3a9463;--green-50:#e9f6ef;--green-100:#cdebd9;
    --teal:#2ba9a9;--teal-50:#e3f5f5;--sky:#9dc4d8;--sky-50:#eef5f9;
    --warn:#a76a00;--warn-50:#fff6e0;--err:#c0352f;--err-50:#fdeeed;
    --bg:#f3f6f9;--card:#fff;--line:#e3e8ef;--line-strong:#cfd7e2;
    --ink:#0d1b4b;--ink-2:#46526b;--muted:#7a869a;
    --radius:16px;--radius-sm:10px;
    --shadow:0 1px 2px rgba(13,27,75,.04),0 1px 3px rgba(13,27,75,.06);
    --shadow-lg:0 14px 34px rgba(13,27,75,.14);
    --sidebar-w:260px;--topbar-h:68px;
    --font:"Poppins",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    --serif:"DM Serif Display",Georgia,serif;
}
*{box-sizing:border-box}
html,body{margin:0}
body{font:14px/1.55 var(--font);background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased}
a{color:var(--teal)}a:hover{color:var(--navy)}
::selection{background:var(--green-100)}
.icon{flex:none}

/* Sidebar */
.mv-sidebar{position:fixed;inset:0 auto 0 0;width:var(--sidebar-w);background:linear-gradient(180deg,var(--navy) 0%,var(--navy-2) 100%);color:#c3cbe0;display:flex;flex-direction:column;z-index:40;transition:transform .25s ease}
.mv-brand{display:flex;align-items:center;gap:12px;height:var(--topbar-h);padding:0 20px;border-bottom:1px solid rgba(255,255,255,.07);text-decoration:none}
.mv-brand-mark{width:38px;height:38px;border-radius:11px;background:#fff;display:grid;place-items:center;box-shadow:0 0 0 1px rgba(255,255,255,.1)}
.mv-brand-mark img{width:26px;height:auto}
.mv-brand-text{display:flex;flex-direction:column;line-height:1.05}
.mv-brand-text b{font:22px/1 var(--serif);color:#fff;font-weight:400;letter-spacing:.01em}
.mv-brand-text small{color:var(--green);font-size:10.5px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;margin-top:4px}
.mv-nav{flex:1;overflow-y:auto;padding:10px 12px 24px;scrollbar-width:thin;scrollbar-color:var(--navy-3) transparent}
.mv-nav h4{margin:18px 12px 6px;font-size:10.5px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#7482a6}
.mv-nav a{display:flex;align-items:center;gap:12px;padding:9px 12px;margin:2px 0;border-radius:var(--radius-sm);color:#c3cbe0;text-decoration:none;font-weight:500;transition:background .15s,color .15s}
.mv-nav a .icon{color:#8592b3;transition:color .15s}
.mv-nav a:hover{background:rgba(255,255,255,.06);color:#fff}.mv-nav a:hover .icon{color:#fff}
.mv-nav a.on{background:rgba(77,175,121,.16);color:#fff;box-shadow:inset 3px 0 0 var(--green)}.mv-nav a.on .icon{color:var(--green)}
.mv-sidebar-foot{padding:14px 20px;border-top:1px solid rgba(255,255,255,.07);font-size:12px;color:#7482a6}

/* Main + topbar */
.mv-main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.mv-topbar{position:sticky;top:0;z-index:30;height:var(--topbar-h);display:flex;align-items:center;gap:14px;padding:0 28px;background:rgba(255,255,255,.88);backdrop-filter:saturate(1.4) blur(10px);border-bottom:1px solid var(--line)}
.mv-topbar-title{font-size:15px;font-weight:600;margin:0;color:var(--ink-2)}
.mv-topbar-actions{margin-left:auto;display:flex;align-items:center;gap:12px}
.mv-user{display:flex;align-items:center;gap:10px}
.mv-avatar{width:36px;height:36px;border-radius:50%;display:grid;place-items:center;background:var(--sky-50);color:var(--navy);font-weight:600;font-size:13px;box-shadow:inset 0 0 0 1px var(--sky)}
.mv-user-meta{display:flex;flex-direction:column;line-height:1.2}.mv-user-meta strong{font-size:13px}.mv-user-meta small{font-size:11.5px;color:var(--muted)}
.mv-icon-btn{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:var(--radius-sm);border:1px solid var(--line);background:#fff;color:var(--ink-2);cursor:pointer}
.mv-icon-btn:hover{border-color:var(--line-strong);color:var(--navy)}
.mv-menu-btn{display:none}
main{flex:1;padding:28px;min-width:0;max-width:1400px;width:100%}

/* Content */
h1{font-size:24px;font-weight:600;letter-spacing:-.01em;margin:0 0 18px}
h2{font-size:17px;font-weight:600;margin:28px 0 12px}
h3{font-size:15px;font-weight:600}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:20px 22px;margin-bottom:18px;box-shadow:var(--shadow)}
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}
.stat{position:relative;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px 18px;box-shadow:var(--shadow);overflow:hidden}
.stat::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--green)}
.stat:nth-child(3n+2)::before{background:var(--teal)}.stat:nth-child(3n)::before{background:var(--sky)}
.stat .muted{font-size:12.5px;font-weight:500}
.stat b{display:block;font-size:28px;font-weight:600;letter-spacing:-.02em;margin-top:4px;color:var(--navy)}
.table-wrap{overflow-x:auto;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}
table{border-collapse:collapse;width:100%;background:var(--card)}
th,td{border-bottom:1px solid var(--line);padding:11px 14px;text-align:left;vertical-align:top}
tr:last-child td{border-bottom:0}
th{background:#f7f9fc;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
tbody tr:hover td{background:#fafcfe}
label{font-weight:500;display:block;margin:14px 0 6px;color:var(--ink)}
.hint{color:var(--muted);font-size:12.5px;margin:4px 0 0;font-weight:400}
input[type=text],input[type=url],input[type=email],input[type=number],input[type=password],input[type=date],input[type=search],select,textarea{width:100%;max-width:640px;padding:10px 12px;border:1px solid var(--line-strong);border-radius:var(--radius-sm);font:inherit;color:var(--ink);background:#fff;transition:border-color .15s,box-shadow .15s}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(43,169,169,.16)}
textarea{min-height:100px}input[type=number]{max-width:140px}
input[type=checkbox],input[type=radio]{accent-color:var(--green);width:16px;height:16px}
.check{font-weight:400;display:flex;gap:9px;align-items:center}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:4px 28px}
.btn{display:inline-flex;align-items:center;gap:8px;background:var(--navy);color:#fff;border:1px solid var(--navy);border-radius:var(--radius-sm);padding:9px 18px;font:500 14px/1.3 var(--font);cursor:pointer;text-decoration:none;transition:background .15s,border-color .15s,box-shadow .15s}
.btn:hover{background:var(--navy-3);border-color:var(--navy-3);color:#fff}
.btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(77,175,121,.35)}
.btn.secondary{background:#fff;color:var(--navy);border-color:var(--line-strong)}.btn.secondary:hover{border-color:var(--navy);background:#fff;color:var(--navy)}
.btn.danger{background:var(--err);border-color:var(--err)}.btn.danger:hover{background:#a52b26;border-color:#a52b26}
.btn.small{padding:5px 11px;font-size:12.5px}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:16px 0}
.notice{display:flex;gap:10px;align-items:flex-start;padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:16px;border:1px solid}
.notice.success{background:var(--green-50);border-color:var(--green-100);color:#24704a}
.notice.warning{background:var(--warn-50);border-color:#f1dca5;color:var(--warn)}
.notice.error{background:var(--err-50);border-color:#f3c9c6;color:var(--err)}
.error-text{color:var(--err);font-size:12.5px;margin:4px 0 0}
.muted{color:var(--muted)}
.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:500;background:var(--teal-50);color:#1d7f7f}
.thumb{width:96px;height:60px;object-fit:cover;border:1px solid var(--line);border-radius:8px}
code{background:var(--sky-50);color:var(--navy);padding:2px 6px;border-radius:5px;font-size:12.5px}
.pager{display:flex;gap:6px;margin:14px 0;flex-wrap:wrap}
.pager a,.pager span{min-width:34px;text-align:center;padding:5px 10px;border:1px solid var(--line);border-radius:8px;text-decoration:none;background:#fff;color:var(--ink)}
.pager span{background:var(--navy);border-color:var(--navy);color:#fff}

/* Page furniture */
[hidden]{display:none!important}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.page-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px}.page-head h1{margin:0}
.page-head select{width:auto;min-width:110px}
.crumbs{margin:0 0 6px;font-size:13px;color:var(--muted)}.crumbs a{color:var(--muted);text-decoration:none}.crumbs a:hover{color:var(--teal)}
.segmented{display:flex;gap:10px 22px;flex-wrap:wrap;margin-top:4px}.segmented .check{margin:0;padding:9px 14px;border:1px solid var(--line-strong);border-radius:var(--radius-sm);cursor:pointer}
.segmented .check:has(input:checked){border-color:var(--green);background:var(--green-50)}
select[size]{padding:4px}select[size] option{padding:6px 8px;border-radius:6px}
tfoot th{background:#f7f9fc;color:var(--ink);font-size:13px;text-transform:none;letter-spacing:0}
.pill-navy{background:var(--sky-50);color:var(--navy)}
.mv-badge{margin-left:auto;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e8a317;color:#1c1300;font-size:11px;font-weight:600;display:inline-grid;place-items:center}
.mv-nav a span{flex:1}

/* Charts */
.charts{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-top:18px}
.charts-wide{grid-template-columns:1fr}.chart-wide{grid-column:1/-1}
.chart{margin:0;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px 12px;box-shadow:var(--shadow)}
.chart figcaption{display:flex;justify-content:space-between;align-items:baseline;gap:10px;margin-bottom:12px}
.chart figcaption span{font-weight:600}.chart figcaption b{font-size:20px;font-weight:600;color:var(--navy)}
.chart-bars{display:flex;align-items:stretch;gap:6px;height:170px}
.chart-col{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;min-width:0}
.chart-bar{display:block;width:100%;max-width:34px;border-radius:6px 6px 2px 2px;background:var(--green);transition:opacity .15s}
.chart-teal .chart-bar{background:var(--teal)}.chart-navy .chart-bar{background:var(--navy)}
.chart-col:hover .chart-bar{opacity:.8}
.chart-value{font-size:10.5px;color:var(--ink-2);margin-bottom:3px;white-space:nowrap}
.chart-label{font-size:11px;color:var(--muted);margin-top:6px;white-space:nowrap}

/* Accounting */
.reminders{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.reminder{display:flex;flex-direction:column;gap:2px;padding:14px 16px;border-radius:var(--radius);border:1px solid;text-decoration:none;color:var(--ink)}
.reminder em{font-style:normal;font-size:12.5px;font-weight:500}
.reminder-overdue{background:var(--err-50);border-color:#f3c9c6}.reminder-overdue em{color:var(--err)}
.reminder-due{background:var(--warn-50);border-color:#f1dca5}.reminder-due em{color:var(--warn)}
.status{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:500;margin-right:6px}
.status-overdue{background:var(--err-50);color:var(--err)}.status-due{background:var(--warn-50);color:var(--warn)}
.status-ok{background:var(--green-50);color:#24704a}.status-paused{background:#eef1f5;color:var(--muted)}
.stat .status{margin-top:6px}

/* Groups */
.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--line)}
.perm-table th:not(:first-child),.perm-table td:not(:first-child){text-align:center;width:96px}
.perm-table td{vertical-align:middle}
.perm-section td{background:#f7f9fc;font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
.perm-page{display:inline-flex;align-items:center;gap:8px;font-weight:500}.perm-page .icon{color:var(--muted)}
.perm-note{font-size:11.5px;margin-left:8px}
.perm-table tr.is-off td{opacity:.4}

/* Login */
.mv-auth{display:grid;grid-template-columns:1.1fr 1fr;min-height:100vh}
.mv-auth-hero{position:relative;overflow:hidden;color:#fff;padding:40px 48px;display:flex;flex-direction:column;justify-content:space-between;background:radial-gradient(120% 80% at 0% 100%,rgba(77,175,121,.32) 0%,rgba(77,175,121,0) 55%),radial-gradient(90% 70% at 100% 0%,rgba(43,169,169,.28) 0%,rgba(43,169,169,0) 60%),linear-gradient(160deg,var(--navy) 0%,var(--navy-2) 100%)}
.mv-auth-hero::after{content:"";position:absolute;right:-120px;bottom:-140px;width:460px;height:520px;background:var(--mv-logo) center/contain no-repeat;opacity:.06;filter:brightness(0) invert(1)}
.mv-auth-hero h2{font:400 46px/1.08 var(--serif);margin:0 0 14px;max-width:520px}
.mv-auth-hero h2 em{font-style:normal;color:var(--green)}
.mv-auth-hero p{color:#c3cbe0;max-width:440px;margin:0;font-size:15px}
.mv-auth-facts{display:flex;gap:40px}.mv-auth-facts b{display:block;font-size:20px;font-weight:600}.mv-auth-facts span{color:#93a0c2;font-size:12.5px}
.mv-auth-form{display:grid;place-items:center;padding:40px 24px;background:#fff}
.mv-auth-form form{width:100%;max-width:380px}
.mv-auth-form h1{font-size:28px;margin:0 0 4px}
.mv-auth-form .lead{color:var(--muted);margin:0 0 26px}
.mv-auth-form input[type=text],.mv-auth-form input[type=password]{max-width:none;padding:12px 14px;background:#f7f9fc}
.mv-auth-form .btn{width:100%;justify-content:center;padding:12px 18px;margin-top:22px}

/* Small screens: the sidebar slides over the page. */
.mv-backdrop{display:none}
@media (max-width:900px){
    .mv-sidebar{transform:translateX(-100%)}
    body.nav-open .mv-sidebar{transform:none;box-shadow:var(--shadow-lg)}
    body.nav-open .mv-backdrop{display:block;position:fixed;inset:0;background:rgba(8,18,47,.45);z-index:35}
    .mv-main{margin-left:0}.mv-menu-btn{display:inline-grid}
    .mv-topbar{padding:0 16px}main{padding:20px 16px}
    .mv-user-meta{display:none}
    .mv-auth{grid-template-columns:1fr}.mv-auth-hero{padding:28px 24px;min-height:auto;gap:28px}.mv-auth-hero h2{font-size:32px}.mv-auth-facts,.mv-auth-hero::after{display:none}
}
</style>
<style>:root{--mv-logo:url("{{ \App\Support\AdminAssets::logo() }}")}</style>
