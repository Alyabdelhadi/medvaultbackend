@extends('admin.layout')
@section('title', $title)
@section('content')
<h1>{{ $title }}</h1>
@if ($created)
    <div class="notice success"><strong>{{ $created->display_name }}</strong> added (username <code>{{ $created->username }}</code>, mobile <code>{{ $created->mobile_number ?: '—' }}</code>). No lab login was attempted and no SMS was sent; the lab token is issued at their first sign-in.</div>
@endif
<div class="actions">
    <a class="btn" href="{{ route('admin.users.create', $slug) }}">Add New</a>
    @if ($users->total() > 0)
        <a class="btn secondary" href="{{ route('admin.users.export', [$slug, 's' => $search ?: null]) }}">Export to Excel{{ $search !== '' ? ' (filtered)' : '' }}</a>
    @endif
    <form method="get" style="margin-left:auto;display:flex;gap:6px">
        <input type="search" name="s" value="{{ $search }}" placeholder="Name or phone number" style="width:240px">
        <button class="btn secondary" type="submit">Search</button>
        @if ($search !== '')<a class="btn secondary" href="{{ route('admin.users.index', $slug) }}">Clear</a>@endif
    </form>
</div>
<p class="muted">
    @if ($search !== '')
        <strong>{{ number_format($users->total()) }}</strong> matching of <strong>{{ number_format($total) }}</strong> total {{ strtolower($title) }}
    @else
        Total {{ strtolower($title) }}: <strong>{{ number_format($total) }}</strong>
    @endif
</p>
<div class="table-wrap">
<table class="cards">
    <thead><tr><th>Name</th><th>Mobile</th><th>Email</th><th>Registered</th><th></th></tr></thead>
    <tbody>
    @forelse ($users as $u)
        <tr>
            <td class="card-title"><a href="{{ route('admin.users.show', [$slug, $u]) }}">{{ $u->display_name ?: '—' }}</a><span class="cell-sub">{{ $u->username }}</span></td>
            <td data-label="Mobile">{{ $u->mobile_number ?: '—' }}</td>
            <td data-label="Email">{{ $u->email ?: '—' }}</td>
            <td data-label="Registered" style="white-space:nowrap">{{ $u->created_at?->format('M j, Y') }}</td>
            <td class="card-actions" style="text-align:right;white-space:nowrap">@can('perm', [$slug, 'edit'])<a class="btn small secondary" href="{{ route('admin.users.edit', [$slug, $u]) }}">Edit</a>@endcan</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No {{ strtolower($title) }} found{{ $search !== '' ? ' for this search' : '' }}.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@if ($users->lastPage() > 1)
    <div class="pager">
        @for ($p = 1; $p <= $users->lastPage(); $p++)
            @if ($p === $users->currentPage())<span>{{ $p }}</span>@else<a href="{{ $users->url($p) }}">{{ $p }}</a>@endif
        @endfor
    </div>
@endif
@endsection
