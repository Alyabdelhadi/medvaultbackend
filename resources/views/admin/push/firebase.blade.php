@extends('admin.layout')
@section('title', 'Firebase Settings')
@section('content')
<h1>Firebase Settings</h1>
<div class="card">
    @if ($projectId)
        Configured for project <code>{{ $projectId }}</code> as <code>{{ $clientEmail }}</code>.
        <p class="hint">The key is stored encrypted and is not shown again. Paste a new file to replace it.</p>
    @else
        <div class="notice warning" style="margin:0">Not configured: push notifications will fail until a key is saved.</div>
    @endif
</div>
<form method="post" action="{{ route('admin.push.firebase.save') }}" class="card">
    @csrf
    <label for="credentials">Service account JSON</label>
    <textarea id="credentials" name="credentials" style="min-height:260px;max-width:none;font-family:monospace;font-size:12px" placeholder='{"type": "service_account", "project_id": "...", ...}'></textarea>
    <p class="hint">Firebase console → Project Settings → Service Accounts → Generate new private key. Paste the whole file.</p>
    <div class="actions"><button class="btn" type="submit">Save Credentials</button></div>
</form>
@endsection
