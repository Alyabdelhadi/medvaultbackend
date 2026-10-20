@extends('admin.layout')
@section('title', 'Edit '.($user->display_name ?: $user->username))
@section('content')
<p class="crumbs"><a href="{{ route('admin.users.index', $slug) }}">{{ ucfirst($slug) }}</a> / <a href="{{ route('admin.users.show', [$slug, $user]) }}">{{ $user->display_name ?: $user->username }}</a> / Edit</p>
<h1>Edit {{ $user->display_name ?: $user->username }}</h1>
@if ($errors->any())<div class="notice error">Please correct the highlighted fields.</div>@endif
<form method="post" action="{{ route('admin.users.update', [$slug, $user]) }}" class="card" style="max-width:820px">
    @csrf @method('put')
    @include('admin.users.fields', ['person' => $user, 'type' => $user->functionType()])
    <div class="actions sticky-actions">
        <button class="btn" type="submit">Save</button>
        <a class="btn secondary" href="{{ route('admin.users.show', [$slug, $user]) }}">Cancel</a>
    </div>
</form>
@endsection
