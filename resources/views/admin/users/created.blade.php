@extends('admin.layout')
@section('title', (($updated ?? false) ? 'Saved ' : 'Added ').$user->display_name)
@section('content')
@include('admin.users.slip-styles')
<h1>{{ $user->display_name }} {{ ($updated ?? false) ? 'saved' : 'added' }}</h1>
@if ($linked ?? false)
    <div class="notice success">The existing account was added to {{ $labName }}. Nothing else about it changed.
        @if ($reset) The new password below is shown only on this page, so print the slip now; their phone will ask for it. @else They sign in with the password they already have. @endif</div>
@elseif ($updated ?? false)
<div class="notice success">Saved with a new password. It is shown only on this page, so print the slip now; the old password no longer works.</div>
@else
<div class="notice success">Account created. No lab login was attempted and no SMS was sent; the lab token is issued at their first sign-in. The password below is shown only on this page, so print the slip now.</div>
@endif
<div class="actions">
    <button class="btn" type="button" onclick="window.print()">@include('admin.partials.icon', ['name' => 'print']) Print slip</button>
    @unless ($updated ?? false)<a class="btn secondary" href="{{ route('admin.users.create', $slug) }}">Add another</a>@endunless
    <a class="btn secondary" href="{{ route('admin.users.show', [$slug, $user]) }}">Open account</a>
</div>
@include('admin.users.slip')
<script>
// Straight to the print dialog: this is what Add & Print promised.
window.addEventListener('load', function () { window.print(); });
</script>
@endsection
