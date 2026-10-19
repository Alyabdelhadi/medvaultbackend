<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>@yield('title', 'Admin') · MedVault</title>
<style>
:root{--bg:#f4f6f9;--card:#fff;--ink:#16202e;--muted:#5d6b7e;--line:#dfe4ec;--brand:#0f3b66;--brand-ink:#fff;--ok:#1f7a4d;--ok-bg:#e6f4ec;--warn:#8a5a00;--warn-bg:#fdf3dc;--err:#a1262b;--err-bg:#fbe9ea}
*{box-sizing:border-box}body{margin:0;font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink)}
a{color:var(--brand)}header{background:var(--brand);color:var(--brand-ink);padding:10px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
header strong{font-size:16px}header .who{margin-left:auto;font-size:13px;opacity:.9}header form{display:inline}header button{background:none;border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:4px;padding:3px 10px;cursor:pointer}
.wrap{display:flex;min-height:calc(100vh - 46px)}nav{width:220px;flex:none;background:var(--card);border-right:1px solid var(--line);padding:12px 0}
nav a{display:block;padding:7px 18px;color:var(--ink);text-decoration:none}nav a.on{background:#e8eef6;color:var(--brand);font-weight:600;border-left:3px solid var(--brand);padding-left:15px}nav h4{margin:14px 18px 4px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}
main{flex:1;padding:20px 28px;min-width:0}h1{font-size:22px;margin:0 0 14px}h2{font-size:17px;margin:24px 0 10px}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:16px 18px;margin-bottom:16px}
table{border-collapse:collapse;width:100%;background:var(--card)}th,td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}th{background:#f8fafc;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:8px}
label{font-weight:600;display:block;margin:10px 0 4px}.hint{color:var(--muted);font-size:12.5px;margin:3px 0 0;font-weight:400}
input[type=text],input[type=url],input[type=email],input[type=number],input[type=password],input[type=date],input[type=search],select,textarea{width:100%;max-width:640px;padding:7px 9px;border:1px solid #c5ccd8;border-radius:5px;font:inherit;background:#fff}
textarea{min-height:90px}input[type=number]{max-width:120px}.check{font-weight:400;display:flex;gap:8px;align-items:center}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:4px 24px}
.btn{display:inline-block;background:var(--brand);color:#fff;border:0;border-radius:5px;padding:7px 14px;font:inherit;cursor:pointer;text-decoration:none}.btn.secondary{background:#fff;color:var(--brand);border:1px solid var(--brand)}.btn.danger{background:var(--err)}.btn.small{padding:3px 9px;font-size:12.5px}
.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:14px 0}
.notice{padding:10px 14px;border-radius:6px;margin-bottom:14px;border:1px solid}.notice.success{background:var(--ok-bg);border-color:#b7dfc8;color:var(--ok)}.notice.warning{background:var(--warn-bg);border-color:#efd9a2;color:var(--warn)}.notice.error{background:var(--err-bg);border-color:#efc2c4;color:var(--err)}
.error-text{color:var(--err);font-size:12.5px;margin:3px 0 0}.muted{color:var(--muted)}.pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;background:#e8eef6;color:var(--brand)}
.thumb{width:90px;height:56px;object-fit:cover;border:1px solid var(--line);border-radius:4px}.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}.stat{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:12px 14px}.stat b{display:block;font-size:22px}
code{background:#eef1f5;padding:1px 5px;border-radius:3px;font-size:12.5px}.pager{display:flex;gap:6px;margin:12px 0}.pager a,.pager span{padding:3px 9px;border:1px solid var(--line);border-radius:4px;text-decoration:none;background:#fff}.pager span{background:var(--brand);color:#fff}
@media (max-width:760px){.wrap{display:block}nav{width:auto;border-right:0;border-bottom:1px solid var(--line);display:flex;flex-wrap:wrap;padding:6px}nav h4{display:none}nav a{padding:5px 10px}main{padding:16px}}
</style>
</head>
<body>
<header>
    <strong>MedVault Admin</strong>
    @auth
        <span class="who">{{ auth()->user()->display_name ?: auth()->user()->username }}
            @if (auth()->user()->role === \App\Models\User::ROLE_LAB_MANAGER)
                · {{ \App\Models\Lab::find(auth()->user()->managed_lab_id)?->title }}
            @endif
        </span>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button type="submit">Log out</button></form>
    @endauth
</header>
<div class="wrap">
    @auth
    <nav>
        @can('admin')
            <a href="{{ route('admin.dashboard') }}" @class(['on' => request()->routeIs('admin.dashboard')])>Dashboard</a>
            <h4>Directory</h4>
            <a href="{{ route('admin.labs.index') }}" @class(['on' => request()->routeIs('admin.labs.*')])>Labs</a>
            <a href="{{ route('admin.images.index', 'sliders') }}" @class(['on' => request()->is('admin/sliders*')])>Slider Images</a>
            <a href="{{ route('admin.images.index', 'banners') }}" @class(['on' => request()->is('admin/banners*')])>Banner Images</a>
            <a href="{{ route('admin.categories.edit') }}" @class(['on' => request()->routeIs('admin.categories.*')])>Result Categories</a>
            <a href="{{ route('admin.pages.edit', 'about') }}" @class(['on' => request()->is('admin/pages/about*')])>About page</a>
            <a href="{{ route('admin.pages.edit', 'faq') }}" @class(['on' => request()->is('admin/pages/faq*')])>FAQ page</a>
            <h4>Notifications</h4>
            <a href="{{ route('admin.push.index') }}" @class(['on' => request()->routeIs('admin.push.index')])>Send Notification</a>
            <a href="{{ route('admin.alerts.show') }}" @class(['on' => request()->routeIs('admin.alerts.*')])>Result Alerts</a>
            <a href="{{ route('admin.push.firebase') }}" @class(['on' => request()->routeIs('admin.push.firebase*')])>Firebase Settings</a>
            <a href="{{ route('admin.ai.show') }}" @class(['on' => request()->routeIs('admin.ai.*')])>AI Summary</a>
        @endcan
        @can('manage-users')
            <h4>People</h4>
            @foreach (\App\Services\Admin\UserDirectory::PLURALS as $code => $plural)
                <a href="{{ route('admin.users.index', strtolower($plural)) }}" @class(['on' => request()->is('admin/'.strtolower($plural).'*')])>{{ $plural }}</a>
            @endforeach
        @endcan
    </nav>
    @endauth
    <main>
        @if (session('status'))
            <div class="notice {{ session('status_type', 'success') }}">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</div>
</body>
</html>
