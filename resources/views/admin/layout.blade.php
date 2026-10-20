@php
    $me = auth()->user();
    $managedLab = $me && ! $me->isAdministrator() && $me->managed_lab_id ? \App\Models\Lab::find($me->managed_lab_id)?->title : null;
    $roleLabel = ! $me ? '' : ($me->isAdministrator() ? 'Administrator' : ($me->effectiveGroup()?->name ?? 'Staff'));
    $name = $me ? ($me->display_name ?: $me->username) : '';
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $badges = ['billing_due' => fn () => \App\Models\LabPlan::needingAttention()->count()];
    $isOn = fn (string $pattern) => str_contains($pattern, '/') ? request()->is($pattern) : request()->routeIs($pattern);
@endphp
<!doctype html>
<html lang="en">
<head>
@include('admin.partials.head')
</head>
<body>
@auth
<aside class="mv-sidebar" id="mv-sidebar">
    <a href="{{ route('admin.home') }}" class="mv-brand">
        <span class="mv-brand-mark"><img src="{{ \App\Support\AdminAssets::logo() }}" alt=""></span>
        <span class="mv-brand-text"><b>MedVault</b><small>Admin</small></span>
    </a>
    <nav class="mv-nav">
        @foreach (\App\Support\AdminModules::sections() as $section => $modules)
            @php $visible = array_filter($modules, fn ($m, $key) => $me->canAdmin($key), ARRAY_FILTER_USE_BOTH); @endphp
            @if ($visible)
                @unless ($section === 'Overview')<h4>{{ $section }}</h4>@endunless
                @foreach ($visible as $key => $module)
                    @php $badge = isset($module['badge'], $badges[$module['badge']]) ? $badges[$module['badge']]() : 0; @endphp
                    <a href="{{ route($module['route'][0], array_slice($module['route'], 1)) }}" @class(['on' => $isOn($module['active'])])>
                        @include('admin.partials.icon', ['name' => $module['icon']])<span>{{ $module['label'] }}</span>
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
        <p class="mv-topbar-title">@yield('title', 'Admin')</p>
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
<script>
document.addEventListener('click', function (e) {
    if (e.target.closest('[data-nav-open]')) document.body.classList.add('nav-open');
    else if (e.target.closest('[data-nav-close]')) document.body.classList.remove('nav-open');
});
</script>
</body>
</html>
