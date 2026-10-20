@extends('admin.layout')
@section('title', $meta['title'])
@section('content')
<h1>{{ $meta['title'] }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif

<div class="card">
    <h2 style="margin-top:0">{{ $editing ? 'Edit '.$meta['singular'] : 'Add New '.$meta['singular'] }}</h2>
    <form method="post" enctype="multipart/form-data" action="{{ $editing ? route('admin.images.update', [$kind, $editing->id]) : route('admin.images.store', $kind) }}">
        @csrf
        @if ($editing) @method('put') @endif
        <label for="image_url">Image URL</label>
        <input id="image_url" name="image_url" type="url" value="{{ old('image_url', $editing?->image_url) }}">
        <input name="image_file" type="file" accept="image/*" style="margin-top:6px">
        <p class="hint">Paste a URL or upload an image.</p>
        @if ($kind === 'sliders')
            <label for="title">Title (optional)</label>
            <input id="title" name="title" type="text" value="{{ old('title', $editing?->title) }}">
        @endif
        <label for="link_url">Link URL {{ $kind === 'sliders' ? '(optional)' : '' }}</label>
        <input id="link_url" name="link_url" type="url" value="{{ old('link_url', $editing?->link_url) }}" @if ($kind === 'banners') required @endif>
        <div class="actions">
            <button class="btn" type="submit">{{ $editing ? 'Update' : 'Add' }} {{ $meta['singular'] }}</button>
            @if ($editing)<a href="{{ route('admin.images.index', $kind) }}">Cancel</a>@endif
        </div>
    </form>
</div>

<h2>All {{ $meta['title'] }} ({{ $items->count() }})</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>#</th><th>Image</th>@if ($kind === 'sliders')<th>Title</th>@endif<th>Link URL</th><th>Order</th><th>Actions</th></tr></thead>
    <tbody>
    @forelse ($items as $i => $item)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td><img class="thumb" src="{{ $item->image_url }}" alt=""></td>
            @if ($kind === 'sliders')<td>{{ $item->title ?: '—' }}</td>@endif
            <td>@if ($item->link_url)<a href="{{ $item->link_url }}" target="_blank" rel="noopener">{{ $item->link_url }}</a>@else — @endif</td>
            <td>
                <form method="post" action="{{ route('admin.images.move', [$kind, $item->id, 'up']) }}" style="display:inline">@csrf<button class="btn secondary small" @disabled($i === 0)>↑</button></form>
                <form method="post" action="{{ route('admin.images.move', [$kind, $item->id, 'down']) }}" style="display:inline">@csrf<button class="btn secondary small" @disabled($loop->last)>↓</button></form>
            </td>
            <td class="actions" style="margin:0">
                <a class="btn secondary small" href="{{ route('admin.images.index', [$kind, 'edit' => $item->id]) }}">Edit</a>
                <form method="post" action="{{ route('admin.images.destroy', [$kind, $item->id]) }}" data-confirm="Delete this {{ strtolower($meta['singular']) }}?">@csrf @method('delete')<button class="btn danger small">Delete</button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">None added yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
