@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
<h1>{{ $lab ? $lab->title : 'Dashboard' }}</h1>

@if ($billing && $billing['due']->isNotEmpty())
    <h2 style="margin-top:0">Payments due</h2>
    @include('admin.accounting.reminders', ['due' => $billing['due']])
    <div style="height:18px"></div>
@endif

<div class="stats stats-3 {{ count($stats) === 6 ? 'stats-6' : '' }}">
    @foreach ($stats as $label => [$value, $icon, $tone])
        @include('admin.partials.stat', ['label' => $label, 'value' => number_format($value), 'icon' => $icon, 'tone' => $tone])
    @endforeach
</div>

<div class="charts">
    @foreach ($charts as $chart)
        @include('admin.partials.bar-chart', $chart)
    @endforeach
    @if ($billing)
        @include('admin.partials.bar-chart', ['title' => 'Revenue received in '.$billing['year'], 'tone' => 'accent', 'data' => $billing['revenue'], 'money' => true, 'wide' => true])
    @endif
</div>

@if ($showPoller)
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
@endif
@endsection
