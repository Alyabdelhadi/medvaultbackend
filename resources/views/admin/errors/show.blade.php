@extends('admin.layout')
@section('title', 'Error')
@section('content')
<div class="page-head">
    <h1>{{ class_basename($error->class) }}</h1>
    <div style="display:flex;gap:8px">
        @if (! $error->resolved_at)
            @can('perm', ['errors', 'edit'])<form method="post" action="{{ route('admin.errors.resolve', $error) }}">@csrf<button class="btn" type="submit">Mark resolved</button></form>@endcan
        @endif
        @can('perm', ['errors', 'delete'])
            <form method="post" action="{{ route('admin.errors.destroy', $error) }}" data-confirm="Delete this error?" data-confirm-action="Delete">@csrf @method('delete')<button class="btn secondary" type="submit">Delete</button></form>
        @endcan
    </div>
</div>
<div class="card">
    <p style="margin-top:0;font-weight:500;white-space:pre-wrap;word-break:break-word">{{ $error->message }}</p>
    <dl class="details">
        @foreach ([
            'Status' => $error->resolved_at ? 'Resolved '.$error->resolved_at->format('M j, Y H:i') : 'Open',
            'Happened' => number_format($error->count).' time(s)',
            'First seen' => $error->first_seen_at?->format('M j, Y H:i'),
            'Last seen' => $error->last_seen_at?->format('M j, Y H:i'),
            'Last request' => trim($error->method.' '.$error->url),
            'Signed-in account' => $error->user_id ? '#'.$error->user_id : 'None',
            'Thrown at' => $error->file.':'.$error->line,
            'Type' => $error->class,
        ] as $label => $value)
            <div><dt>{{ $label }}</dt><dd style="word-break:break-word">{{ $value ?: '—' }}</dd></div>
        @endforeach
    </dl>
</div>
<h2>Stack trace</h2>
<p class="muted" style="margin-top:-6px">For whoever fixes it. The most recent occurrence; function arguments and request data are never kept.</p>
<pre class="trace">{{ $error->trace ?: 'No trace.' }}</pre>
<p><a href="{{ route('admin.errors.index') }}">Back to the Error Log</a></p>
@endsection
