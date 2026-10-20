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
        <input name="image_file" type="file" accept="image/*">
        <p class="hint">Paste a URL or upload an image.</p>
        @if ($editing?->image_url)
            <a class="image-preview" href="{{ $editing->image_url }}" data-lightbox="current" data-caption="Current image">
                <img src="{{ $editing->image_url }}" alt="Current image">
            </a>
        @endif
        @if ($kind === 'sliders')
            <label for="title">Title (optional)</label>
            <input id="title" name="title" type="text" value="{{ old('title', $editing?->title) }}">
        @endif
        <label for="link_url">Link URL {{ $kind === 'sliders' ? '(optional)' : '' }}</label>
        <input id="link_url" name="link_url" type="url" value="{{ old('link_url', $editing?->link_url) }}" @if ($kind === 'banners') required @endif>
        <div class="actions">
            <button class="btn" type="submit">{{ $editing ? 'Update' : 'Add' }} {{ $meta['singular'] }}</button>
            @if ($editing)<a class="btn secondary" href="{{ route('admin.images.index', $kind) }}">Cancel</a>@endif
        </div>
    </form>
</div>

<h2>All {{ $meta['title'] }} ({{ $items->count() }})</h2>
<div class="table-wrap">
<table class="cards image-list">
    <thead><tr><th style="width:84px">Order</th><th style="width:132px">Image</th>@if ($kind === 'sliders')<th>Title</th>@endif<th>Link</th><th style="width:1%"></th></tr></thead>
    <tbody>
    @forelse ($items as $i => $item)
        <tr @class(['is-editing' => $editing?->id === $item->id])>
            <td data-label="Order">
                <div class="row-actions">
                    <span class="order-num">{{ $i + 1 }}</span>
                    <form method="post" action="{{ route('admin.images.move', [$kind, $item->id, 'up']) }}">@csrf<button class="icon-btn" title="Move up" aria-label="Move up" @disabled($i === 0)>@include('admin.partials.icon', ['name' => 'up'])</button></form>
                    <form method="post" action="{{ route('admin.images.move', [$kind, $item->id, 'down']) }}">@csrf<button class="icon-btn" title="Move down" aria-label="Move down" @disabled($loop->last)>@include('admin.partials.icon', ['name' => 'chevron'])</button></form>
                </div>
            </td>
            <td class="card-title">
                <a href="{{ $item->image_url }}" data-lightbox="{{ $kind }}" data-caption="{{ $item->title ?: $meta['singular'].' '.($i + 1) }}" class="zoom-thumb" title="Open full size">
                    <img class="thumb" src="{{ $item->image_url }}" alt="{{ $item->title ?: $meta['singular'].' '.($i + 1) }}" loading="lazy">
                </a>
            </td>
            @if ($kind === 'sliders')<td data-label="Title">{{ $item->title ?: '—' }}</td>@endif
            <td data-label="Link" class="cell-ellipsis">@if ($item->link_url)<a href="{{ $item->link_url }}" target="_blank" rel="noopener" title="{{ $item->link_url }}">{{ $item->link_url }}</a>@else <span class="muted">No link</span> @endif</td>
            <td class="card-actions">
                <div class="row-actions">
                    <a class="btn secondary small" href="{{ route('admin.images.index', [$kind, 'edit' => $item->id]) }}">Edit</a>
                    <form method="post" action="{{ route('admin.images.destroy', [$kind, $item->id]) }}" data-confirm="Delete this {{ strtolower($meta['singular']) }}?" data-confirm-detail="It disappears from the app's home screen.">@csrf @method('delete')<button class="btn danger small">Delete</button></form>
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">None added yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
