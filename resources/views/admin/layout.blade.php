@php
    $me = auth()->user();
    $managedLab = $me?->role === \App\Models\User::ROLE_LAB_MANAGER ? \App\Models\Lab::find($me->managed_lab_id)?->title : null;
    $name = $me ? ($me->display_name ?: $me->username) : '';
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $peopleIcons = ['P' => 'patients', 'D' => 'doctors', 'G' => 'guarantors'];
@endphp
<!doctype html>
<html lang="en">
<head>
@include('admin.partials.head')
</head>
<body>
@auth
<aside class="mv-sidebar" id="mv-sidebar">
    <a href="{{ $me->can('admin') ? route('admin.dashboard') : route('admin.users.index', 'patients') }}" class="mv-brand">
        <span class="mv-brand-mark"><img src="{{ \App\Support\AdminAssets::logo() }}" alt=""></span>
        <span class="mv-brand-text"><b>MedVault</b><small>Admin</small></span>
    </a>
    <nav class="mv-nav">
        @can('admin')
            <a href="{{ route('admin.dashboard') }}" @class(['on' => request()->routeIs('admin.dashboard')])>@include('admin.partials.icon', ['name' => 'dashboard'])Dashboard</a>
            <h4>Directory</h4>
            <a href="{{ route('admin.labs.index') }}" @class(['on' => request()->routeIs('admin.labs.*')])>@include('admin.partials.icon', ['name' => 'lab'])Labs</a>
            <a href="{{ route('admin.images.index', 'sliders') }}" @class(['on' => request()->is('admin/sliders*')])>@include('admin.partials.icon', ['name' => 'image'])Slider Images</a>
            <a href="{{ route('admin.images.index', 'banners') }}" @class(['on' => request()->is('admin/banners*')])>@include('admin.partials.icon', ['name' => 'banner'])Banner Images</a>
            <a href="{{ route('admin.categories.edit') }}" @class(['on' => request()->routeIs('admin.categories.*')])>@include('admin.partials.icon', ['name' => 'tag'])Result Categories</a>
            <a href="{{ route('admin.pages.edit', 'about') }}" @class(['on' => request()->is('admin/pages/about*')])>@include('admin.partials.icon', ['name' => 'info'])About page</a>
            <a href="{{ route('admin.pages.edit', 'faq') }}" @class(['on' => request()->is('admin/pages/faq*')])>@include('admin.partials.icon', ['name' => 'help'])FAQ page</a>
            <h4>Notifications</h4>
            <a href="{{ route('admin.push.index') }}" @class(['on' => request()->routeIs('admin.push.index')])>@include('admin.partials.icon', ['name' => 'send'])Send Notification</a>
            <a href="{{ route('admin.alerts.show') }}" @class(['on' => request()->routeIs('admin.alerts.*')])>@include('admin.partials.icon', ['name' => 'bell'])Result Alerts</a>
            <a href="{{ route('admin.push.firebase') }}" @class(['on' => request()->routeIs('admin.push.firebase*')])>@include('admin.partials.icon', ['name' => 'flame'])Firebase Settings</a>
            <a href="{{ route('admin.ai.show') }}" @class(['on' => request()->routeIs('admin.ai.*')])>@include('admin.partials.icon', ['name' => 'sparkle'])AI Summary</a>
        @endcan
        @can('manage-users')
            <h4>People</h4>
            @foreach (\App\Services\Admin\UserDirectory::PLURALS as $code => $plural)
                <a href="{{ route('admin.users.index', strtolower($plural)) }}" @class(['on' => request()->is('admin/'.strtolower($plural).'*')])>@include('admin.partials.icon', ['name' => $peopleIcons[$code] ?? 'patients']){{ $plural }}</a>
            @endforeach
        @endcan
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
                    <small>{{ $managedLab ? 'Lab manager · '.$managedLab : ($me->can('admin') ? 'Administrator' : 'Staff') }}</small>
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
