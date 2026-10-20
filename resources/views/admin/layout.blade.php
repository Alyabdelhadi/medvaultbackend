@php
    $me = auth()->user();
    $managedLab = $me && ! $me->isAdministrator() && $me->managed_lab_id ? \App\Models\Lab::find($me->managed_lab_id)?->title : null;
    $roleLabel = ! $me ? '' : ($me->isAdministrator() ? 'Administrator' : ($me->effectiveGroup()?->name ?? 'Staff'));
    $name = $me ? ($me->display_name ?: $me->username) : '';
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $badges = ['billing_due' => fn () => \App\Models\LabPlan::needingAttention()->count()];
    $isOn = fn (string $pattern) => str_contains($pattern, '/') ? request()->is($pattern) : request()->routeIs($pattern);
    // Where this page sits, for the top bar: "People › Patients". The page
    // title itself is the big heading, so it is not repeated up there.
    $crumb = null;
    foreach (\App\Support\AdminModules::sections() as $section => $modules) {
        foreach ($modules as $module) {
            if ($isOn($module['active'])) {
                $crumb = [$section, $module];
                break 2;
            }
        }
    }
@endphp
<!doctype html>
<html lang="en">
<head>
@include('admin.partials.head')
</head>
<body>
@auth
<aside class="mv-sidebar" id="mv-sidebar">
    <div class="mv-brand-row">
        <a href="{{ route('admin.home') }}" class="mv-brand">
            <span class="mv-brand-mark"><img src="{{ \App\Support\AdminAssets::logo() }}" alt=""></span>
            <span class="mv-brand-text"><b>MedVault</b><small>Admin</small></span>
        </a>
        <button type="button" class="mv-icon-btn mv-close-btn" data-nav-close aria-label="Close menu">@include('admin.partials.icon', ['name' => 'close'])</button>
    </div>
    <nav class="mv-nav">
        @foreach (\App\Support\AdminModules::sections() as $section => $modules)
            @php $visible = array_filter($modules, fn ($m, $key) => $me->canAdmin($key), ARRAY_FILTER_USE_BOTH); @endphp
            @continue (! $visible)
            @if (isset(\App\Support\AdminModules::FOLDED[$section]))
                @php $open = collect($visible)->contains(fn ($m) => $isOn($m['active'])); @endphp
                <details class="mv-fold" @if ($open) open @endif>
                    <summary @class(['on' => $open])>@include('admin.partials.icon', ['name' => \App\Support\AdminModules::FOLDED[$section]])<span class="mv-label">{{ $section }}</span>@include('admin.partials.icon', ['name' => 'chevron'])</summary>
                    <div class="mv-fold-items">
                        @foreach ($visible as $key => $module)
                            <a href="{{ route($module['route'][0], array_slice($module['route'], 1)) }}" @class(['on' => $isOn($module['active'])])>{{ $module['label'] }}</a>
                        @endforeach
                    </div>
                </details>
            @else
                @unless ($section === 'Overview')<h4>{{ $section }}</h4>@endunless
                @foreach ($visible as $key => $module)
                    @php $badge = isset($module['badge'], $badges[$module['badge']]) ? $badges[$module['badge']]() : 0; @endphp
                    <a href="{{ route($module['route'][0], array_slice($module['route'], 1)) }}" @class(['on' => $isOn($module['active'])])>
                        @include('admin.partials.icon', ['name' => $module['icon']])<span class="mv-label">{{ $module['label'] }}</span>
                        @if ($badge)<span class="mv-badge" title="Payments due">{{ $badge }}</span>@endif
                    </a>
                @endforeach
            @endif
        @endforeach
    </nav>
    @if ($managedLab)
        <div class="mv-sidebar-foot">Managing {{ $managedLab }}</div>
    @endif
</aside>
<div class="mv-backdrop" data-nav-close></div>
@endauth
<div class="mv-main" @guest style="margin-left:0" @endguest>
    @auth
    <header class="mv-topbar">
        <button type="button" class="mv-icon-btn mv-menu-btn" data-nav-open aria-label="Menu">@include('admin.partials.icon', ['name' => 'menu'])</button>
        <nav class="mv-topbar-title" aria-label="You are here">
            @if ($crumb)
                @if ($crumb[0])<span class="muted">{{ $crumb[0] }}</span><span class="sep">›</span>@endif
                <a href="{{ route($crumb[1]['route'][0], array_slice($crumb[1]['route'], 1)) }}">{{ $crumb[1]['label'] }}</a>
            @else
                <span>MedVault Admin</span>
            @endif
        </nav>
        <div class="mv-topbar-actions">
            <div class="mv-user">
                <span class="mv-avatar">{{ $initials ?: 'A' }}</span>
                <span class="mv-user-meta">
                    <strong>{{ $name }}</strong>
                    <small>{{ $roleLabel }}{{ $managedLab ? ' · '.$managedLab : '' }}</small>
                </span>
            </div>
            <form method="post" action="{{ route('admin.logout') }}">@csrf
                <button type="submit" class="mv-icon-btn" title="Log out" aria-label="Log out">@include('admin.partials.icon', ['name' => 'logout'])</button>
            </form>
        </div>
    </header>
    @endauth
    <main>
        @if (session('status'))
            <div class="notice {{ session('status_type', 'success') }}">{{ session('status') }}</div>
        @endif
        @yield('content')
    </main>
</div>
<dialog class="mv-dialog" id="mv-confirm">
    <form method="dialog">
        <h3 data-confirm-text>Are you sure?</h3>
        <p class="muted" data-confirm-detail>This cannot be undone.</p>
        <div class="actions" style="justify-content:flex-end;margin-bottom:0">
            <button class="btn secondary" value="cancel">Cancel</button>
            <button class="btn danger" value="ok" data-confirm-ok>Delete</button>
        </div>
    </form>
</dialog>
<script>
document.addEventListener('click', function (e) {
    if (e.target.closest('[data-nav-open]')) document.body.classList.add('nav-open');
    else if (e.target.closest('[data-nav-close]')) document.body.classList.remove('nav-open');
});
// Card tables on phones: each cell is labelled with its column's heading.
document.querySelectorAll('table.cards').forEach(function (table) {
    var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
    table.querySelectorAll('tbody tr').forEach(function (tr) {
        Array.prototype.forEach.call(tr.children, function (td, i) {
            if (!td.hasAttribute('data-label') && heads[i]) td.setAttribute('data-label', heads[i]);
        });
    });
});
// <form data-confirm="Question?" data-confirm-action="Button text" data-confirm-detail="...">:
// asks in the page's own dialog before submitting.
(function () {
    var dialog = document.getElementById('mv-confirm');
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.dataset || !form.dataset.confirm || form.dataset.confirmed) return;
        e.preventDefault();
        dialog.querySelector('[data-confirm-text]').textContent = form.dataset.confirm;
        dialog.querySelector('[data-confirm-detail]').textContent = form.dataset.confirmDetail || 'This cannot be undone.';
        var ok = dialog.querySelector('[data-confirm-ok]');
        ok.textContent = form.dataset.confirmAction || 'Delete';
        ok.className = 'btn ' + (form.dataset.confirmTone === 'primary' ? '' : 'danger');
        dialog.onclose = function () {
            if (dialog.returnValue !== 'ok') return;
            form.dataset.confirmed = '1';
            form.requestSubmit ? form.requestSubmit() : form.submit();
        };
        dialog.showModal();
    }, true);
})();
</script>
</body>
</html>
