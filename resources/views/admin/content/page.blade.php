@extends('admin.layout')
@section('title', $meta['title'].' page')
@section('content')
<div class="page-head">
    <h1>{{ $meta['title'] }} page</h1>
    <div class="segmented">
        <a class="btn small {{ $key === 'about' ? '' : 'secondary' }}" href="{{ route('admin.pages.edit', 'about') }}">About</a>
        <a class="btn small {{ $key === 'faq' ? '' : 'secondary' }}" href="{{ route('admin.pages.edit', 'faq') }}">FAQ</a>
    </div>
</div>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('admin.pages.update', $key) }}" class="card">
    @csrf @method('put')
    <label for="title">Title</label>
    <input id="title" name="title" type="text" value="{{ old('title', $page->title) }}" required>
    <label for="content">Content (HTML)</label>
    <textarea id="content" name="content" style="min-height:360px;max-width:none;font-family:monospace">{{ old('content', $page->content) }}</textarea>
    <p class="hint">Served to the app exactly as written, as <code>/{{ $key }}</code>. Use <code>&lt;p&gt;</code> for paragraphs.</p>
    <label for="excerpt">Excerpt (optional)</label>
    <textarea id="excerpt" name="excerpt">{{ old('excerpt', $page->excerpt) }}</textarea>
    <p class="hint">Left empty, the first 55 words of the content are used, as WordPress did.</p>
    <label for="status">Status</label>
    <select id="status" name="status">
        <option value="publish" @selected(old('status', $page->status) === 'publish')>Published</option>
        <option value="draft" @selected(old('status', $page->status) === 'draft')>Draft (the app gets "page not found")</option>
    </select>
    <div class="actions"><button class="btn" type="submit">Save</button></div>
</form>
@endsection
