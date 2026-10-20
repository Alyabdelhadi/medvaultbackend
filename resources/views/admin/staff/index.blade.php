@extends('admin.layout')
@section('title', 'Admin Accounts')
@section('content')
<div class="page-head">
    <h1>Admin Accounts</h1>
    @can('perm', ['staff', 'create'])<a class="btn" href="{{ route('admin.staff.create') }}">New account</a>@endcan
</div>
<p class="muted" style="margin-top:-6px">Who can sign in here. Give each lab its own account in a lab group: it sees only that lab's patients.</p>
<div class="table-wrap">
<table class="cards">
    <thead><tr><th>Name</th><th>Username</th><th>Access</th><th>Lab</th><th></th></tr></thead>
    <tbody>
    @foreach ($accounts as $a)
        <tr>
            <td class="card-title">{{ $a->display_name ?: $a->username }}@if ($a->email)<span class="cell-sub">{{ $a->email }}</span>@endif</td>
            <td>{{ $a->username }}</td>
            <td>
                @if ($a->isAdministrator()) <span class="pill pill-navy">Administrator</span>
                @elseif ($a->adminGroup) <span class="pill">{{ $a->adminGroup->name }}</span>
                @else <span class="pill">Lab Manager (imported)</span>
                @endif
            </td>
            <td>{{ $a->managed_lab_id ? ($labNames[$a->managed_lab_id] ?? '#'.$a->managed_lab_id) : '—' }}</td>
            <td class="card-actions" style="white-space:nowrap;text-align:right">
                @if (! $a->isAdministrator() || auth()->user()->isAdministrator())
                    @can('perm', ['staff', 'edit'])<a class="btn small secondary" href="{{ route('admin.staff.edit', $a) }}">Edit</a>@endcan
                    @can('perm', ['staff', 'delete'])
                        @if ($a->id !== auth()->id())
                            <form method="post" action="{{ route('admin.staff.destroy', $a) }}" style="display:inline" data-confirm="Delete this admin account? It can no longer sign in.">@csrf @method('delete')<button class="btn small secondary" type="submit">Delete</button></form>
                        @endif
                    @endcan
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endsection
