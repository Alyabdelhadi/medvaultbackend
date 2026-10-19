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
<table>
    <thead><tr><th>Name</th><th>Username</th><th>Email</th><th>Mobile</th><th>Registration date</th><th>Type</th></tr></thead>
    <tbody>
    @forelse ($users as $u)
        <tr>
            <td><a href="{{ route('admin.users.show', [$slug, $u]) }}">{{ $u->display_name ?: '—' }}</a></td>
            <td>{{ $u->username }}</td>
            <td>{{ $u->email ?: '—' }}</td>
            <td>{{ $u->mobile_number ?: '—' }}</td>
            <td>{{ $u->created_at?->format('F j, Y g:i a') }}</td>
            <td>{{ $u->functionType() }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No {{ strtolower($title) }} found{{ $search !== '' ? ' for this search' : '' }}.</td></tr>
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
