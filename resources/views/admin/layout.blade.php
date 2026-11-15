@php
    $me = auth()->user();
    $managedLab = $me && ! $me->isAdministrator() && $me->managed_lab_id ? \App\Models\Lab::find($me->managed_lab_id)?->title : null;
    $roleLabel = ! $me ? '' : ($me->isAdministrator() ? 'Administrator' : ($me->effectiveGroup()?->name ?? 'Staff'));
    $name = $me ? ($me->display_name ?: $me->username) : '';
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $badges = [
        'billing_due' => fn () => \App\Models\LabPlan::needingAttention()->count(),
        'errors_open' => fn () => \App\Models\ErrorLog::open()->count(),
    ];
    $badgeTitles = ['billing_due' => 'Payments due', 'errors_open' => 'Open errors'];
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
                @php
                    $open = collect($visible)->contains(fn ($m) => $isOn($m['active']));
                    // A closed section still shows that something inside needs attention.
                    $foldBadges = collect($visible)->filter(fn ($m) => isset($m['badge'], $badges[$m['badge']]))->map(fn ($m) => $badges[$m['badge']]())->filter();
                @endphp
                <details class="mv-fold" @if ($open) open @endif>
                    <summary @class(['on' => $open])>@include('admin.partials.icon', ['name' => \App\Support\AdminModules::FOLDED[$section]])<span class="mv-label">{{ $section }}</span>@if ($foldBadges->isNotEmpty() && ! $open)<span class="mv-badge">{{ $foldBadges->sum() }}</span>@endif @include('admin.partials.icon', ['name' => 'chevron'])</summary>
                    <div class="mv-fold-items">
                        @foreach ($visible as $key => $module)
                            @php $badge = isset($module['badge'], $badges[$module['badge']]) ? $badges[$module['badge']]() : 0; @endphp
                            <a href="{{ route($module['route'][0], array_slice($module['route'], 1)) }}" @class(['on' => $isOn($module['active'])])>{{ $module['label'] }}@if ($badge)<span class="mv-badge" title="{{ $badgeTitles[$module['badge']] }}">{{ $badge }}</span>@endif</a>
                        @endforeach
                    </div>
                </details>
            @else
                @unless ($section === 'Overview')<h4>{{ $section }}</h4>@endunless
                @foreach ($visible as $key => $module)
                    @php $badge = isset($module['badge'], $badges[$module['badge']]) ? $badges[$module['badge']]() : 0; @endphp
                    <a href="{{ route($module['route'][0], array_slice($module['route'], 1)) }}" @class(['on' => $isOn($module['active'])])>
                        @include('admin.partials.icon', ['name' => $module['icon']])<span class="mv-label">{{ $module['label'] }}</span>
                        @if ($badge)<span class="mv-badge" title="{{ $badgeTitles[$module['badge']] }}">{{ $badge }}</span>@endif
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
<dialog class="mv-lightbox" id="mv-lightbox" aria-label="Image viewer">
    <button type="button" class="lb-btn lb-close" data-lb="close" aria-label="Close">@include('admin.partials.icon', ['name' => 'close'])</button>
    <button type="button" class="lb-btn lb-prev" data-lb="prev" aria-label="Previous image">@include('admin.partials.icon', ['name' => 'left'])</button>
    <figure>
        <img alt="">
        <figcaption><span data-lb-caption></span> <span class="lb-count" data-lb-count></span> <a data-lb-open target="_blank" rel="noopener">Open original</a></figcaption>
    </figure>
    <button type="button" class="lb-btn lb-next" data-lb="next" aria-label="Next image">@include('admin.partials.icon', ['name' => 'right'])</button>
</dialog>
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
// Lightbox: <a href="big.jpg" data-lightbox="group" data-caption="...">. Images
// in the same group page with the arrows (keys too); Esc or a click outside closes.
(function () {
    var box = document.getElementById('mv-lightbox'), img = box.querySelector('img');
    var items = [], index = 0;
    function show(i) {
        index = (i + items.length) % items.length;
        var a = items[index];
        img.src = a.getAttribute('href');
        img.alt = a.dataset.caption || '';
        box.querySelector('[data-lb-caption]').textContent = a.dataset.caption || '';
        box.querySelector('[data-lb-count]').textContent = items.length > 1 ? (index + 1) + ' / ' + items.length : '';
        box.querySelector('[data-lb-open]').href = a.getAttribute('href');
        box.classList.toggle('single', items.length < 2);
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-lightbox]');
        if (!a || e.metaKey || e.ctrlKey) return;
        e.preventDefault();
        items = Array.prototype.slice.call(document.querySelectorAll('a[data-lightbox="' + a.dataset.lightbox + '"]'));
        show(items.indexOf(a));
        box.showModal();
    });
    box.addEventListener('click', function (e) {
        var action = e.target.closest('[data-lb]');
        if (action) { action.dataset.lb === 'close' ? box.close() : show(index + (action.dataset.lb === 'next' ? 1 : -1)); return; }
        if (e.target === box) box.close();
    });
    box.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') show(index + 1);
        if (e.key === 'ArrowLeft') show(index - 1);
    });
    box.addEventListener('close', function () { img.removeAttribute('src'); });
})();
// <select data-searchable>: a button that opens a filterable list. The native
// select stays in the form (and gets the change event), so posting is unchanged.
// Matches the option text, its title and any data-search, e.g. "ivoire" or "225".
document.querySelectorAll('select[data-searchable]').forEach(function (select) {
    var wrap = document.createElement('div'); wrap.className = 'mv-combo';
    select.parentNode.insertBefore(wrap, select); wrap.appendChild(select);
    select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'mv-combo-btn';
    btn.setAttribute('aria-haspopup', 'listbox'); btn.setAttribute('aria-label', select.getAttribute('aria-label') || 'Choose');
    var label = document.createElement('span'); btn.appendChild(label);
    var pop = document.createElement('div'); pop.className = 'mv-combo-pop'; pop.hidden = true;
    var search = document.createElement('input'); search.type = 'search'; search.placeholder = 'Search country or code'; search.autocomplete = 'off';
    var list = document.createElement('ul'); list.className = 'mv-combo-list'; list.setAttribute('role', 'listbox');
    pop.appendChild(search); pop.appendChild(list); wrap.appendChild(btn); wrap.appendChild(pop);
    var opts = Array.prototype.map.call(select.options, function (o) {
        var li = document.createElement('li'); li.setAttribute('role', 'option');
        li.textContent = o.title ? o.text + '  ' + o.title : o.text;
        li.dataset.value = o.value; list.appendChild(li);
        return { li: li, opt: o, hay: ((o.dataset.search || '') + ' ' + o.text + ' ' + o.title).toLowerCase() };
    });
    var empty = document.createElement('li'); empty.className = 'mv-combo-empty'; empty.textContent = 'No match'; list.appendChild(empty);
    var active = -1;
    function visible() { return opts.filter(function (x) { return !x.li.hidden; }); }
    function sync() { var o = select.options[select.selectedIndex]; label.textContent = o ? o.text : ''; btn.title = o && o.title || ''; }
    function mark(i) {
        var v = visible(); opts.forEach(function (x) { x.li.classList.remove('active'); });
        active = Math.max(0, Math.min(i, v.length - 1));
        if (v[active]) { v[active].li.classList.add('active'); v[active].li.scrollIntoView({ block: 'nearest' }); }
    }
    function filter() {
        var q = search.value.trim().toLowerCase().replace(/^00/, '+'), n = 0;
        opts.forEach(function (x) { var hit = !q || x.hay.indexOf(q) !== -1 || x.hay.indexOf('+' + q) !== -1; x.li.hidden = !hit; if (hit) n++; });
        empty.hidden = n > 0; mark(0);
    }
    function open() {
        pop.hidden = false; search.value = ''; filter();
        opts.forEach(function (x) { x.li.setAttribute('aria-selected', x.opt.selected ? 'true' : 'false'); });
        mark(visible().findIndex(function (x) { return x.opt.selected; }));
        search.focus();
    }
    function close(refocus) { pop.hidden = true; if (refocus) btn.focus(); }
    function pick(x) {
        if (!x) return;
        if (select.value !== x.opt.value) { select.value = x.opt.value; select.dispatchEvent(new Event('change', { bubbles: true })); }
        sync(); close(true);
    }
    btn.addEventListener('click', function () { pop.hidden ? open() : close(); });
    btn.addEventListener('keydown', function (e) { if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); } });
    search.addEventListener('input', filter);
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
        else if (e.key === 'Enter') { e.preventDefault(); pick(visible()[active]); }
        else if (e.key === 'Escape') { e.preventDefault(); close(true); }
        else if (e.key === 'Tab') close();
    });
    list.addEventListener('click', function (e) {
        var li = e.target.closest('li[data-value]');
        if (li) pick(opts.find(function (x) { return x.li === li; }));
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    select.addEventListener('change', sync);
    sync();
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
