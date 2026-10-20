<!doctype html>
<html lang="en">
<head>
@section('title', 'Sign in')
@include('admin.partials.head')
</head>
<body>
<div class="mv-auth">
    <section class="mv-auth-hero">
        <div class="mv-brand" style="padding:0;border:0;height:auto">
            <span class="mv-brand-mark"><img src="{{ \App\Support\AdminAssets::logo() }}" alt=""></span>
            <span class="mv-brand-text"><b>MedVault</b><small>Admin</small></span>
        </div>
        <div>
            <h2>Your lab results, <em>in one vault</em>.</h2>
            <p>Manage labs, patients, result alerts and everything the MedVault app shows, in one place.</p>
        </div>
        <div class="mv-auth-facts">
            <div><b>Labs</b><span>Results from every facility</span></div>
            <div><b>Alerts</b><span>The moment results are ready</span></div>
        </div>
    </section>
    <section class="mv-auth-form">
        <form method="post" action="{{ route('admin.login.submit') }}">
            @csrf
            <h1>Welcome back</h1>
            <p class="lead">Sign in to the MedVault dashboard.</p>
            @if ($errors->any())
                <div class="notice error">{{ $errors->first() }}</div>
            @endif
            <label for="login">Username or email</label>
            <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" required autofocus>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <label class="check" style="margin-top:16px"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
            <button class="btn" type="submit">Sign in</button>
        </form>
    </section>
</div>
</body>
</html>
