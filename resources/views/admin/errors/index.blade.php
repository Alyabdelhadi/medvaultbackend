@extends('admin.layout')
@section('title', 'Error Log')
@section('content')
<div class="page-head">
    <h1>Error Log</h1>
    @if ($resolved && $resolvedCount > 0)
        @can('perm', ['errors', 'delete'])
            <form method="post" action="{{ route('admin.errors.clear') }}" data-confirm="Delete every resolved error?" data-confirm-action="Delete">@csrf @method('delete')<button class="btn secondary" type="submit">Delete resolved</button></form>
        @endcan
    @endif
</div>
<p class="muted" style="margin-top:-6px">Unexpected errors on the server, from the app's requests, this admin and the result poller. Each place an error comes from is one row, with how often it happened. Lab outages the app already handles are not errors here.</p>
<div class="actions">
    <a @class(['btn', 'secondary' => $resolved]) href="{{ route('admin.errors.index') }}">Open ({{ $openCount }})</a>
    <a @class(['btn', 'secondary' => ! $resolved]) href="{{ route('admin.errors.index', ['show' => 'resolved']) }}">Resolved ({{ $resolvedCount }})</a>
</div>
<div class="table-wrap">
<table class="cards">
    <thead><tr><th>Error</th><th>Where</th><th>Times</th><th>Last seen</th><th></th></tr></thead>
    <tbody>
    @forelse ($entries as $e)
        <tr>
            <td class="card-title"><a href="{{ route('admin.errors.show', $e) }}">{{ \Illuminate\Support\Str::limit($e->message, 140) }}</a><span class="cell-sub">{{ class_basename($e->class) }}</span></td>
            <td data-label="Where">{{ $e->method }} {{ $e->url ?: '—' }}<span class="cell-sub">{{ $e->file }}:{{ $e->line }}</span></td>
            <td data-label="Times">{{ number_format($e->count) }}</td>
            <td data-label="Last seen" style="white-space:nowrap" title="{{ $e->last_seen_at?->format('Y-m-d H:i:s') }}">{{ $e->last_seen_at?->diffForHumans() }}</td>
            <td class="card-actions" style="text-align:right;white-space:nowrap">
                @if (! $e->resolved_at)
                    @can('perm', ['errors', 'edit'])<form method="post" action="{{ route('admin.errors.resolve', $e) }}" style="display:inline">@csrf<button class="btn small secondary" type="submit">Resolved</button></form>@endcan
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">{{ $resolved ? 'No resolved errors.' : 'No open errors. Good.' }}</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@if ($entries->lastPage() > 1)
    <div class="pager">
        @if ($entries->currentPage() > 1)<a href="{{ $entries->previousPageUrl() }}">Newer</a>@endif
        <span>Page {{ $entries->currentPage() }} of {{ $entries->lastPage() }}</span>
        @if ($entries->hasMorePages())<a href="{{ $entries->nextPageUrl() }}">Older</a>@endif
    </div>
@endif
@endsection
