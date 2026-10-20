@extends('admin.layout')
@section('title', 'Add '.\App\Services\Admin\UserDirectory::TYPES[$type])
@section('content')
<h1>Add {{ \App\Services\Admin\UserDirectory::TYPES[$type] }}</h1>
<form method="post" action="{{ route('admin.users.store', $slug) }}" class="card" style="max-width:820px">
    @csrf
    @include('admin.users.fields', ['person' => null])
    <p class="hint" style="margin-top:14px">The account is created here only. No lab login is attempted and no SMS is sent: the lab token is issued the first time the user signs in.</p>
    <div class="actions sticky-actions">
        <button class="btn" type="submit">Add</button>
        <a class="btn secondary" href="{{ route('admin.users.index', $slug) }}">Cancel</a>
    </div>
</form>
@endsection
