@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
<h1>Dashboard</h1>
<div class="stats">
    @foreach ($stats as $label => $value)
        <div class="stat"><span class="muted">{{ $label }}</span><b>{{ number_format($value) }}</b></div>
    @endforeach
</div>
<h2>Result alerts</h2>
<div class="card">
    @if ($lastRun)
        Last poll {{ $lastRun['finished_at'] ?? '—' }}: {{ $lastRun['polled'] ?? 0 }} of {{ $lastRun['total_users'] ?? 0 }} users polled · {{ $lastRun['notified'] ?? 0 }} notified · {{ $lastRun['errors'] ?? 0 }} error(s).
    @else
        <span class="muted">The poller has not run on this server yet.</span>
    @endif
    @unless ($schedulerAlive)
        <div class="notice warning" style="margin:10px 0 0">The scheduler has not checked in for over five minutes. Result alerts only go out while <code>php artisan schedule:run</code> runs every minute from cron.</div>
    @endunless
</div>
@endsection
