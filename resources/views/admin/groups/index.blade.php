@extends('admin.layout')
@section('title', 'Groups & Permissions')
@section('content')
<div class="page-head">
    <h1>Groups &amp; Permissions</h1>
    @can('perm', ['groups', 'create'])<a class="btn" href="{{ route('admin.groups.create') }}">New group</a>@endcan
</div>
<p class="muted" style="margin-top:-6px">Every admin account except administrators gets its permissions from one group. Administrators can always do everything.</p>
<div class="table-wrap">
<table>
    <thead><tr><th>Group</th><th>Kind</th><th>Pages</th><th>Accounts</th><th></th></tr></thead>
    <tbody>
    @forelse ($groups as $group)
        <tr>
            <td><strong>{{ $group->name }}</strong>@if ($group->description)<br><span class="muted">{{ $group->description }}</span>@endif</td>
            <td>@if ($group->for_labs)<span class="pill">For a lab</span>@else<span class="pill pill-navy">Site-wide</span>@endif</td>
            <td>{{ collect($group->permissions)->keys()->map(fn ($k) => \App\Support\AdminModules::get($k)['label'] ?? $k)->implode(', ') ?: '—' }}</td>
            <td>{{ $group->users_count }}</td>
            <td style="white-space:nowrap;text-align:right">
                @can('perm', ['groups', 'edit'])<a class="btn small secondary" href="{{ route('admin.groups.edit', $group) }}">Edit</a>@endcan
                @can('perm', ['groups', 'delete'])
                    @if ($group->users_count === 0)
                        <form method="post" action="{{ route('admin.groups.destroy', $group) }}" style="display:inline" onsubmit="return confirm('Delete this group?')">@csrf @method('delete')<button class="btn small secondary" type="submit">Delete</button></form>
                    @endif
                @endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No groups yet. Create one, for example <strong>Lab staff</strong> with Patients and Send Notification, then give each lab an account in it.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
