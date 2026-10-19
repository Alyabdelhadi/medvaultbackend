@extends('admin.layout')
@section('title', 'Result Alerts')
@section('content')
<h1>Result Alerts</h1>
<p>Polls each lab and notifies a patient the moment one of their orders turns ready. The first poll for a patient at a lab only records the current statuses, so no alerts go out for results that were already finished. Labs set to "no ready signal" are never polled.</p>

@unless ($schedulerAlive)
    <div class="notice warning">The scheduler has not checked in for over five minutes. Alerts only go out while cron runs <code>* * * * * php artisan schedule:run</code> on the server.</div>
@endunless

<form method="post" action="{{ route('admin.alerts.save') }}" class="card" style="max-width:680px">
    @csrf
    <label class="check"><input type="checkbox" name="poll_enabled" value="1" @checked($enabled)> Polling enabled</label>
    <label for="poll_minutes">Interval (minutes)</label>
    <input id="poll_minutes" name="poll_minutes" type="number" min="5" value="{{ $minutes }}"> <span class="hint">Minimum 5.</span>
    <label for="poll_batch_size">Users per run</label>
    <input id="poll_batch_size" name="poll_batch_size" type="number" min="1" value="{{ $batch }}">
    <p class="hint">One lab call per user per lab. The cursor rotates through everyone across runs.</p>
    <div class="actions">
        <button class="btn" type="submit">Save Settings</button>
        <button class="btn secondary" type="submit" formaction="{{ route('admin.alerts.run') }}">Run Poll Now</button>
    </div>
</form>

<h2>Status</h2>
<div class="table-wrap" style="max-width:680px">
<table>
    <tr><td><strong>Cursor position</strong></td><td>{{ $cursor }}</td></tr>
    <tr><td><strong>Result alerts sent (all time)</strong></td><td>{{ number_format($sentTotal) }}</td></tr>
    @if ($lastRun)
        <tr><td><strong>Last run</strong></td><td>{{ $lastRun['finished_at'] ?? '—' }}</td></tr>
        <tr><td><strong>Last run result</strong></td><td>{{ $lastRun['polled'] ?? 0 }} of {{ $lastRun['total_users'] ?? 0 }} users polled · {{ $lastRun['notified'] ?? 0 }} notified · {{ $lastRun['seeded'] ?? 0 }} seeded · {{ $lastRun['unauthorized'] ?? 0 }} expired token(s) · {{ $lastRun['errors'] ?? 0 }} error(s)</td></tr>
    @endif
</table>
</div>

<h2>Recent notifications</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>When</th><th>User</th><th>Type</th><th>Lab</th><th>Request</th><th>Message</th><th>Read</th></tr></thead>
    <tbody>
    @forelse ($recent as $row)
        <tr>
            <td>{{ $row->getRawOriginal('created_at') }}</td>
            <td>{{ $row->user?->display_name ?: '#'.$row->user_id }}</td>
            <td>{{ $row->type }}</td>
            <td>{{ $row->lab_id ?: '—' }}</td>
            <td>{{ $row->request_nb }}</td>
            <td>{{ $row->body }}</td>
            <td>{{ $row->is_read ? 'Yes' : 'No' }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">Nothing sent yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
