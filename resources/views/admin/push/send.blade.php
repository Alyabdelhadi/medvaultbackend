@extends('admin.layout')
@section('title', 'Send Notification')
@section('content')
<h1>Send Push Notification</h1>
<p>Sends a notification to <strong>all MedVault users</strong> subscribed to the <code>medvault</code> topic.</p>
<form method="post" action="{{ route('admin.push.send') }}" class="card" onsubmit="return confirm('Send this to every MedVault user?')">
    @csrf
    <label for="title">Title</label>
    <input id="title" name="title" type="text" value="{{ old('title') }}" required>
    <label for="body">Message</label>
    <textarea id="body" name="body" required>{{ old('body') }}</textarea>
    <div class="actions"><button class="btn" type="submit">Send Notification</button></div>
</form>
@endsection
