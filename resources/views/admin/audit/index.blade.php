@extends('admin.layout')
@section('title', 'Audit Log')
@section('content')
<h1>Audit Log</h1>
<p class="muted" style="margin-top:-6px">Every change made in this admin, plus sign-ups, profile changes and account deletions from the app. Passwords and keys are never shown, only that they changed.</p>
<form method="get" class="actions audit-filters">
    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Name, action or IP address">
    <select name="area" aria-label="Area">
        <option value="">Everything</option>
        @foreach ($areas as $key => $label)<option value="{{ $key }}" @selected($filters['area'] === $key)>{{ $label }}</option>@endforeach
    </select>
    <input type="date" name="from" value="{{ $filters['from'] }}" aria-label="From">
    <input type="date" name="to" value="{{ $filters['to'] }}" aria-label="To">
    @if ($filters['subject'] !== '')<input type="hidden" name="subject" value="{{ $filters['subject'] }}">@endif
    <button class="btn secondary" type="submit">Filter</button>
    @if (array_filter($filters))<a class="btn secondary" href="{{ route('admin.audit.index') }}">Clear</a>@endif
</form>
@if ($filters['subject'] !== '')
    <div class="notice">Showing only what was done to one record. <a href="{{ route('admin.audit.index', array_filter([...$filters, 'subject' => null])) }}">Show everything</a></div>
@endif
@include('admin.audit.rows', ['logs' => $logs])
@if ($logs->lastPage() > 1)
    <div class="pager">
        @if ($logs->currentPage() > 1)<a href="{{ $logs->previousPageUrl() }}">Newer</a>@endif
        <span>Page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}</span>
        @if ($logs->hasMorePages())<a href="{{ $logs->nextPageUrl() }}">Older</a>@endif
    </div>
@endif
@endsection
