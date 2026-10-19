@extends('admin.layout')
@section('title', 'Log in')
@section('content')
<div class="card" style="max-width:420px;margin:40px auto">
    <h1>Log in</h1>
    @if ($errors->any())
        <div class="notice error">{{ $errors->first() }}</div>
    @endif
    <form method="post" action="{{ route('admin.login.submit') }}">
        @csrf
        <label for="login">Username or email</label>
        <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" required autofocus>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <div class="actions"><button class="btn" type="submit">Log in</button></div>
    </form>
</div>
@endsection
