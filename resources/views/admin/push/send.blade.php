@extends('admin.layout')
@section('title', 'Send Notification')
@section('content')
<h1>Send Notification</h1>
@php $audience = old('audience', $scope > 0 ? 'lab' : 'all'); @endphp
<form method="post" action="{{ route('admin.push.send') }}" class="card" data-push-form data-reach="{{ json_encode($reach) }}" data-scope="{{ $scope }}"
      data-confirm-tone="primary" data-confirm-action="Send" data-confirm="Send this notification?">
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
        <div data-for="lab" class="field-narrow">
            <label for="lab_id">Lab</label>
            <select id="lab_id" name="lab_id">
                @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) old('lab_id') === $id)>{{ $title }}</option>@endforeach
            </select>
            <p class="hint">Patients the lab added, or whose results the lab's system knows.</p>
        </div>
    @endif

    <div data-for="user" class="field-narrow">
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
    <p class="hint">Shows as a push on the phone and in the app's Notifications list.</p>
    <div class="notice success" style="margin:16px 0 0" data-reach-text aria-live="polite"></div>
    <div class="actions"><button class="btn" type="submit">Send</button></div>
</form>

<h2>Recently sent</h2>
<div class="table-wrap">
<table class="cards">
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
    // Reach, before sending: it is also what the confirmation says.
    var reach = JSON.parse(form.getAttribute('data-reach'));
    var scope = parseInt(form.getAttribute('data-scope'), 10);
    var out = form.querySelector('[data-reach-text]');
    function describe() {
        var value = (form.querySelector('input[name=audience]:checked') || {}).value, text, n = 0;
        if (value === 'all') {
            n = reach.all;
            text = 'Goes to every phone with the MedVault app: about ' + n + ' people.';
        } else if (value === 'lab') {
            var lab = scope > 0 ? scope : parseInt((form.querySelector('#lab_id') || {}).value, 10);
            var r = reach.labs[lab] || [0, 0];
            n = r[0];
            text = 'Goes to ' + r[0] + ' patient(s): all see it in the app, ' + r[1] + ' also get a push on their phone.';
        } else {
            var sel = form.querySelector('#user_id');
            var name = sel && sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text.split(' · ')[0] : '';
            n = name ? 1 : 0;
            text = name ? 'Goes to ' + name + ' only.' : 'Choose the patient below.';
        }
        out.textContent = text;
        out.className = 'notice ' + (n > 0 ? 'success' : 'warning');
        form.dataset.confirm = n > 0 ? text.replace(/^Goes to/, 'Send to').replace(/:.*/, '').replace(/\.$/, '') + '?' : 'Send this notification?';
        form.dataset.confirmDetail = text;
    }
    form.addEventListener('change', describe);
    describe();
})();
</script>
@endsection
