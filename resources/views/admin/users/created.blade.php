@extends('admin.layout')
@section('title', 'Added '.$user->display_name)
@section('content')
@include('admin.users.slip-styles')
<h1>{{ $user->display_name }} added</h1>
<div class="notice success">Account created. No lab login was attempted and no SMS was sent; the lab token is issued at their first sign-in. The password below is shown only on this page, so print the slip now.</div>
<div class="actions">
    <button class="btn" type="button" onclick="window.print()">@include('admin.partials.icon', ['name' => 'print']) Print slip</button>
    <a class="btn secondary" href="{{ route('admin.users.create', $slug) }}">Add another</a>
    <a class="btn secondary" href="{{ route('admin.users.show', [$slug, $user]) }}">Open account</a>
</div>
@include('admin.users.slip')
<script>
// Straight to the print dialog: this is what Add & Print promised.
window.addEventListener('load', function () { window.print(); });
</script>
@endsection
