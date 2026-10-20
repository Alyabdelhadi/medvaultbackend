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
    <label for="content">Content</label>
    <div class="editor-wrap">
        <textarea id="content" name="content" data-rich-editor style="min-height:360px;max-width:none;font-family:monospace">{{ old('content', $page->content) }}</textarea>
    </div>
    <p class="hint">Shown in the app as <code>/{{ $key }}</code>. Use <strong>Source</strong> in the toolbar to see or paste HTML; anything the editor has no button for is kept as written.</p>
    <label for="excerpt">Excerpt (optional)</label>
    <textarea id="excerpt" name="excerpt">{{ old('excerpt', $page->excerpt) }}</textarea>
    <p class="hint">Left empty, the first 55 words of the content are used, as WordPress did.</p>
    <label for="status">Status</label>
    <select id="status" name="status">
        <option value="publish" @selected(old('status', $page->status) === 'publish')>Published</option>
        <option value="draft" @selected(old('status', $page->status) === 'draft')>Draft (the app gets "page not found")</option>
    </select>
    <div class="actions sticky-actions"><button class="btn" type="submit">Save</button></div>
</form>

{{--
    CKEditor 5, the super-build: it is the build with General HTML Support,
    which keeps every element, class and style it has no button for. Without
    it the editor would silently drop parts of pages copied from WordPress
    on the first save. If the CDN does not load, the plain HTML box stays.
--}}
<script src="https://cdn.ckeditor.com/ckeditor5/41.4.2/super-build/ckeditor.js"></script>
<script>
(function () {
    var area = document.querySelector('[data-rich-editor]');
    if (!area || !window.CKEDITOR || !CKEDITOR.ClassicEditor) return;
    area.removeAttribute('required');
    CKEDITOR.ClassicEditor.create(area, {
        toolbar: {
            items: ['heading', '|', 'bold', 'italic', 'underline', 'link', '|', 'bulletedList', 'numberedList', 'blockQuote', '|',
                'alignment', 'insertTable', 'horizontalLine', '|', 'removeFormat', 'undo', 'redo', '|', 'sourceEditing'],
            shouldNotGroupWhenFull: false,
        },
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading2', view: 'h2', title: 'Heading', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Subheading', class: 'ck-heading_heading3' },
            ],
        },
        link: { defaultProtocol: 'https://', addTargetToExternalLinks: true },
        table: { contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells'] },
        htmlSupport: { allow: [{ name: /.*/, attributes: true, classes: true, styles: true }] },
        // Commercial or cloud features in the super-build: not used, and they
        // refuse to start without a licence or a service.
        removePlugins: ['AIAssistant', 'CKBox', 'CKFinder', 'EasyImage', 'RealTimeCollaborativeComments',
            'RealTimeCollaborativeTrackChanges', 'RealTimeCollaborativeRevisionHistory', 'PresenceList', 'Comments',
            'TrackChanges', 'TrackChangesData', 'RevisionHistory', 'Pagination', 'WProofreader', 'MathType',
            'SlashCommand', 'Template', 'DocumentOutline', 'FormatPainter', 'TableOfContents', 'PasteFromOfficeEnhanced',
            'CaseChange', 'MultiLevelList', 'ExportPdf', 'ExportWord'],
    }).catch(function (error) {
        // The plain HTML box keeps working.
        area.setAttribute('required', 'required');
        console.error('Editor did not start:', error);
    });
})();
</script>
@endsection
