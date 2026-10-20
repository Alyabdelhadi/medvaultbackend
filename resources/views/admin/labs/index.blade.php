@extends('admin.layout')
@section('title', 'Labs')
@section('content')
<div class="page-head">
    <h1>Labs</h1>
    @can('perm', ['labs', 'create'])<a class="btn" href="{{ route('admin.labs.create') }}">Add New Lab</a>@endcan
</div>
<div class="actions" style="margin-top:-6px">
    <a class="btn small {{ request('status') === 'trash' ? 'secondary' : '' }}" href="{{ route('admin.labs.index') }}">All labs</a>
    <a class="btn small {{ request('status') === 'trash' ? '' : 'secondary' }}" href="{{ route('admin.labs.index', ['status' => 'trash']) }}">Trash ({{ $trashed }})</a>
</div>
<div class="table-wrap">
<table class="cards">
    <thead><tr><th style="width:60px"></th><th>Name</th><th>Location</th><th>Phone</th><th>Status</th><th>Results</th></tr></thead>
    <tbody>
    @forelse ($labs as $lab)
        @php $img = $lab->image_url ?: $lab->thumbnail_url; @endphp
        <tr>
            <td class="hide-sm">@if ($img)<img class="lab-thumb" src="{{ $img }}" alt="">@else<span class="lab-thumb empty">@include('admin.partials.icon', ['name' => 'lab'])</span>@endif</td>
            <td class="card-title"><a href="{{ route('admin.labs.edit', $lab) }}">{{ $lab->title }}</a><span class="cell-sub">#{{ $lab->id }}</span></td>
            <td data-label="Location">{{ $lab->location ?: '—' }}</td>
            <td data-label="Phone">{{ $lab->phone ?: '—' }}</td>
            <td data-label="Status"><span class="status status-{{ ['publish' => 'ok', 'draft' => 'paused', 'trash' => 'overdue'][$lab->status] ?? 'paused' }}">{{ ['publish' => 'Published', 'draft' => 'Draft', 'trash' => 'In trash', 'private' => 'Private', 'pending' => 'Pending'][$lab->status] ?? ucfirst($lab->status) }}</span></td>
            <td data-label="Results">
                @if ($lab->api_enabled && $lab->api_base)
                    Connected{{ $lab->ai_summary ? ' · AI summaries' : '' }}
                @else
                    <span class="muted">Directory only</span>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No labs here.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
