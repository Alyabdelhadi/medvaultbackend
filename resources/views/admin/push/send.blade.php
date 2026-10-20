@extends('admin.layout')
@section('title', 'Send Notification')
@section('content')
<h1>Send Notification</h1>
@php $audience = old('audience', $scope > 0 ? 'lab' : 'all'); @endphp
<form method="post" action="{{ route('admin.push.send') }}" class="card" data-push-form>
    @csrf
    <label>Send to</label>
    <div class="segmented">
        @if ($scope === 0)
            <label class="check"><input type="radio" name="audience" value="all" @checked($audience === 'all')> Everyone with the app</label>
            <label class="check"><input type="radio" name="audience" value="lab" @checked($audience === 'lab')> A lab's patients</label>
        @else
            <label class="check"><input type="radio" name="audience" value="lab" @checked($audience === 'lab')> All patients of {{ $scopeLab?->title }}</label>
        @endif
        <label class="check"><input type="radio" name="audience" value="user" @checked($audience === 'user')> One patient</label>
    </div>

    @if ($scope === 0)
        <div data-for="lab">
            <label for="lab_id">Lab</label>
            <select id="lab_id" name="lab_id">
                @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) old('lab_id') === $id)>{{ $title }}</option>@endforeach
            </select>
            <p class="hint">Patients the lab added, or whose results the lab's system knows.</p>
        </div>
    @endif

    <div data-for="user">
        <label for="user_id">Patient</label>
        <input type="search" placeholder="Filter by name or number" data-filter="user_id" style="margin-bottom:6px">
        <select id="user_id" name="user_id" size="6">
            @foreach ($patients as $p)
                <option value="{{ $p->id }}" @selected((int) old('user_id') === $p->id)>{{ $p->display_name ?: $p->username }}{{ $p->mobile_number ? ' · '.$p->mobile_number : '' }}</option>
            @endforeach
        </select>
        <p class="hint">{{ $patients->count() }} patient(s){{ $scope > 0 ? ' in your lab' : '' }}.</p>
    </div>

    <label for="title">Title</label>
    <input id="title" name="title" type="text" value="{{ old('title') }}" maxlength="120" required>
    <label for="body">Message</label>
    <textarea id="body" name="body" maxlength="1000" required>{{ old('body') }}</textarea>
    <p class="hint">Shows as a push on the patient's phone and in the app's Notifications list.</p>
    <div class="actions"><button class="btn" type="submit">Send</button></div>
</form>

<h2>Recently sent</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>When</th><th>To</th><th>Message</th><th>By</th></tr></thead>
    <tbody>
    @forelse ($history as $m)
        <tr>
            <td style="white-space:nowrap">{{ $m->created_at->format('Y-m-d H:i') }}</td>
            <td>
                @if ($m->audience === 'all') <span class="pill">Everyone</span>
                @elseif ($m->audience === 'lab') <span class="pill">{{ $m->lab?->title ?? 'Lab' }}</span> {{ $m->recipients }} patient(s)
                @else <span class="pill">One patient</span>
                @endif
            </td>
            <td><strong>{{ $m->title }}</strong><br><span class="muted">{{ \Illuminate\Support\Str::limit($m->body, 140) }}</span></td>
            <td>{{ $m->sender?->display_name ?: $m->sender?->username ?: '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">Nothing sent yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>

<script>
(function () {
    var form = document.querySelector('[data-push-form]');
    function sync() {
        var value = (form.querySelector('input[name=audience]:checked') || {}).value;
        form.querySelectorAll('[data-for]').forEach(function (el) {
            el.hidden = el.getAttribute('data-for') !== value;
            el.querySelectorAll('select').forEach(function (s) { s.required = !el.hidden; });
        });
    }
    form.addEventListener('change', sync);
    sync();
    form.querySelectorAll('[data-filter]').forEach(function (input) {
        var select = document.getElementById(input.getAttribute('data-filter'));
        input.addEventListener('input', function () {
            var term = input.value.toLowerCase();
            Array.prototype.forEach.call(select.options, function (o) { o.hidden = term && o.text.toLowerCase().indexOf(term) === -1; });
        });
    });
    form.addEventListener('submit', function (e) {
        var value = form.querySelector('input[name=audience]:checked').value;
        var who = value === 'all' ? 'every MedVault user' : value === 'lab' ? 'all of this lab\'s patients' : 'this patient';
        if (!confirm('Send this to ' + who + '?')) e.preventDefault();
    });
})();
</script>
@endsection
