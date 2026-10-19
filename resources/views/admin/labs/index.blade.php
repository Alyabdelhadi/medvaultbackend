@extends('admin.layout')
@section('title', 'Labs')
@section('content')
<h1>Labs</h1>
<div class="actions">
    <a class="btn" href="{{ route('admin.labs.create') }}">Add New Lab</a>
    <a href="{{ route('admin.labs.index') }}">All</a> ·
    <a href="{{ route('admin.labs.index', ['status' => 'trash']) }}">Trash ({{ $trashed }})</a>
</div>
<div class="table-wrap">
<table>
    <thead><tr><th>Image</th><th>Name</th><th>Location</th><th>Phone</th><th>Status</th><th>Results API</th></tr></thead>
    <tbody>
    @forelse ($labs as $lab)
        <tr>
            <td>@if ($lab->image_url ?: $lab->thumbnail_url)<img class="thumb" src="{{ $lab->image_url ?: $lab->thumbnail_url }}" alt="">@else — @endif</td>
            <td><a href="{{ route('admin.labs.edit', $lab) }}"><strong>{{ $lab->title }}</strong></a><div class="muted">#{{ $lab->id }}</div></td>
            <td>{{ $lab->location ?: '—' }}</td>
            <td>{{ $lab->phone ?: '—' }}</td>
            <td><span class="pill">{{ $lab->status }}</span></td>
            <td>
                @if ($lab->api_enabled && $lab->api_base)
                    Enabled · signal <code>{{ $lab->ready_signal ?: 'status' }}</code>
                    @if ($lab->ai_summary) · AI @endif
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
