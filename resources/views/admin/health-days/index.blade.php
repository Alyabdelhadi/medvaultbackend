@extends('admin.layout')
@section('title', 'Health Days')
@section('content')
@php
    $err = fn ($f) => $errors->has($f) ? '<p class="error-text">'.e($errors->first($f)).'</p>' : '';
    $form = $editing ?? ($adding || $errors->any() ? new \App\Models\HealthDay(['send_time' => '10:00']) : null);
    $months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $active = $days->where('is_active', true)->count();
@endphp
<style>
.hd-form .grid2{display:grid;grid-template-columns:1fr 1fr;gap:4px 18px}
@media (max-width:760px){.hd-form .grid2{grid-template-columns:1fr}}
.hd-form .when{display:grid;grid-template-columns:1.4fr .6fr .7fr;gap:8px}
.hd-preview{border:1px solid var(--line);border-radius:14px;padding:10px 12px;background:#f7f8fb;font-size:13px;margin-top:6px}
.hd-preview b{display:block;font-size:13px}
.hd-preview[dir=rtl]{text-align:right}
.hd-date{white-space:nowrap;font-weight:600}
</style>

<div class="page-head">
    <h1 style="margin:0">Health Days</h1>
    @can('perm', ['healthdays', 'create'])<a class="btn" href="{{ route('admin.healthdays.index', ['new' => 1]) }}">Add a day</a>@endcan
</div>
<p class="muted" style="max-width:820px">Awareness notifications sent on their date every year, in English or Arabic depending on the app's language. They reach every member who has notifications on and has not turned off <b>Health tips</b> in the app; turning tips off never stops result alerts. Sent at the time shown, Beirut time; nothing goes out after 20:00. {{ $active }} of {{ $days->count() }} active.</p>
@if ($days->isNotEmpty() && $active === 0)
    <div class="notice warning">None is active yet. Read each day's wording, then switch on the ones you want sent.</div>
@endif

@if ($form)
<div class="card hd-form">
    <h2 style="margin-top:0">{{ $form->exists ? 'Edit '.$form->name : 'Add a day' }}</h2>
    <form method="post" action="{{ $form->exists ? route('admin.healthdays.update', $form) : route('admin.healthdays.store') }}">
        @csrf @if ($form->exists) @method('put') @endif
        <div class="grid2">
            <div>
                <label for="name">Name (admin only)</label>
                <input id="name" name="name" type="text" value="{{ old('name', $form->name) }}" required>{!! $err('name') !!}
            </div>
            <div>
                <label>Date and time (Beirut)</label>
                <div class="when">
                    <select name="month" aria-label="Month">@foreach ($months as $n => $m)<option value="{{ $n }}" @selected((int) old('month', $form->month) === $n)>{{ $m }}</option>@endforeach</select>
                    <input name="day" type="number" min="1" max="31" value="{{ old('day', $form->day) }}" aria-label="Day" required>
                    <input name="send_time" type="time" value="{{ old('send_time', $form->send_time) }}" max="19:59" aria-label="Time" required>
                </div>{!! $err('day') !!}{!! $err('month') !!}{!! $err('send_time') !!}
            </div>
            <div>
                <label for="title_en">English title</label>
                <input id="title_en" name="title_en" type="text" maxlength="120" value="{{ old('title_en', $form->title_en) }}" required>{!! $err('title_en') !!}
                <label for="body_en">English text</label>
                <textarea id="body_en" name="body_en" rows="3" maxlength="300" required>{{ old('body_en', $form->body_en) }}</textarea>{!! $err('body_en') !!}
            </div>
            <div dir="rtl">
                <label for="title_ar">العنوان بالعربية</label>
                <input id="title_ar" name="title_ar" type="text" maxlength="120" value="{{ old('title_ar', $form->title_ar) }}" required>{!! $err('title_ar') !!}
                <label for="body_ar">النص بالعربية</label>
                <textarea id="body_ar" name="body_ar" rows="3" maxlength="300" required>{{ old('body_ar', $form->body_ar) }}</textarea>{!! $err('body_ar') !!}
            </div>
        </div>
        <p class="hint">Keep the text under about 110 characters so phones show it in full. Describe and encourage a check-up; never diagnose or promise a result.</p>
        <div class="grid2">
            <div>
                <label for="link_url">Link when tapped (optional)</label>
                <input id="link_url" name="link_url" type="url" placeholder="https://" value="{{ old('link_url', $form->link_url) }}">{!! $err('link_url') !!}
                <p class="hint">For example a lab's offer page. Without a link the tap just opens the app.</p>
            </div>
            <div>
                <label for="sponsor_lab_id">Sponsored by (optional)</label>
                <select id="sponsor_lab_id" name="sponsor_lab_id">
                    <option value="">— none —</option>
                    @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) old('sponsor_lab_id', $form->sponsor_lab_id) === $id)>{{ $title }}</option>@endforeach
                </select>{!! $err('sponsor_lab_id') !!}
                <p class="hint">For your records and billing. Not shown in the notification.</p>
            </div>
        </div>
        <div class="actions">
            <button class="btn" type="submit">{{ $form->exists ? 'Save' : 'Add' }}</button>
            <a class="btn secondary" href="{{ route('admin.healthdays.index') }}">Cancel</a>
        </div>
    </form>
</div>
@endif

<div class="table-wrap">
<table class="cards">
    <thead><tr><th>Next</th><th>Day</th><th>Notification</th><th>Status</th><th>Last sent</th><th style="width:1%"></th></tr></thead>
    <tbody>
    @forelse ($days as $d)
        <tr @class(['is-editing' => $editing?->id === $d->id])>
            <td class="hd-date">{{ $d->nextDate()->format('j M Y') }}<span class="cell-sub">{{ $d->send_time }}</span></td>
            <td class="card-title">{{ $d->name }}@if ($d->sponsor_lab_id)<span class="cell-sub">Sponsored by {{ $labs[$d->sponsor_lab_id] ?? 'lab #'.$d->sponsor_lab_id }}</span>@endif</td>
            <td>
                <div class="hd-preview"><b>{{ $d->title_en }}</b>{{ $d->body_en }}</div>
                <div class="hd-preview" dir="rtl"><b>{{ $d->title_ar }}</b>{{ $d->body_ar }}</div>
            </td>
            <td>@if ($d->is_active)<span class="status status-ok">Active</span>@else<span class="status status-paused">Draft</span>@endif</td>
            <td>{{ $d->last_sent_en?->format('j M Y') ?? '—' }}</td>
            <td class="card-actions">
                <div class="row-actions">
                    @can('perm', ['healthdays', 'edit'])
                        <form method="post" action="{{ route('admin.healthdays.toggle', $d) }}">@csrf<button class="btn small {{ $d->is_active ? 'secondary' : '' }}">{{ $d->is_active ? 'Turn off' : 'Activate' }}</button></form>
                        <a class="btn secondary small" href="{{ route('admin.healthdays.index', ['edit' => $d->id]) }}">Edit</a>
                    @endcan
                    @can('perm', ['healthdays', 'delete'])
                        <form method="post" action="{{ route('admin.healthdays.destroy', $d) }}" data-confirm="Delete {{ $d->name }}?">@csrf @method('delete')<button class="btn danger small">Delete</button></form>
                    @endcan
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No health days yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
