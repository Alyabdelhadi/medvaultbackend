@extends('admin.layout')
@section('title', 'Edit '.($user->display_name ?: $user->username))
@section('content')
@include('admin.users.slip-styles')
<style>
.mv-edit{display:grid;grid-template-columns:minmax(0,820px) 380px;gap:28px;align-items:start}
@media (max-width:1100px){.mv-edit{grid-template-columns:1fr}}
.mv-edit > .card{margin:0}
.mv-pass{display:grid;grid-template-columns:minmax(0,1fr) 46px 46px;gap:8px}
.mv-pass input{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em}
.mv-pass button{border:1px solid var(--line-strong);border-radius:var(--radius-sm);background:#fff;color:var(--navy);cursor:pointer;display:flex;align-items:center;justify-content:center}
.mv-pass button:hover{border-color:var(--navy)}
.mv-pass button.copied{border-color:var(--teal,#2ba9a9);color:var(--teal,#2ba9a9)}
.mv-preview-label{font-size:12px;letter-spacing:.08em;color:var(--muted);font-weight:600;margin:0 0 10px}
</style>
<p class="crumbs"><a href="{{ route('admin.users.index', $slug) }}">{{ ucfirst($slug) }}</a> / <a href="{{ route('admin.users.show', [$slug, $user]) }}">{{ $user->display_name ?: $user->username }}</a> / Edit</p>
<h1>Edit {{ $user->display_name ?: $user->username }}</h1>
@if ($errors->any())<div class="notice error">Please correct the highlighted fields.</div>@endif
<div class="mv-edit">
<form method="post" action="{{ route('admin.users.update', [$slug, $user]) }}" class="card" id="mv-edit-form">
    @csrf @method('put')
    @include('admin.users.fields', ['person' => $user, 'type' => $user->functionType(), 'printPassword' => $printPassword])
    <div class="actions sticky-actions">
        <button class="btn" type="submit">Save</button>
        <button class="btn secondary" type="submit" name="after" value="print">@include('admin.partials.icon', ['name' => 'print']) Save &amp; Print</button>
        <a class="btn secondary" href="{{ route('admin.users.show', [$slug, $user]) }}">Cancel</a>
    </div>
</form>

<aside>
    <p class="mv-preview-label">PRINT PREVIEW</p>
    @include('admin.users.slip', [
        'slipName' => trim("{$user->first_name} {$user->last_name}") ?: $user->display_name,
        'slipLab' => $slipLab,
        'slipMobile' => $slipMobile,
        'slipPassword' => $printPassword,
        'slipDoctor' => in_array($user->functionType(), ['D', 'G'], true),
    ])
</aside>
</div>

<script>
(function () {
    var form = document.getElementById('mv-edit-form');
    var $ = function (id) { return document.getElementById(id); };
    var cc = form.querySelector('[name="mobile_cc"]');
    var lab = $('dg_lab_id');
    var initialLab = @json($slipLab);
    var field = $('password'), pending = $('print_password');
    function isDoctor() { return ['D', 'G'].indexOf($('mv-type').value) !== -1; }
    // What Save & Print will set: the typed password, else the generated one.
    function printed() { return field.value || pending.value; }
    function sync() {
        field.placeholder = pending.value;
        window.mvSlip({
            name: ($('first_name').value.trim() + ' ' + $('last_name').value.trim()).trim(),
            mobile: $('mobile_number').value,
            cc: cc.value,
            password: printed(),
            // A doctor's chosen lab; a patient keeps the lab that added them.
            lab: isDoctor() && lab && lab.value ? lab.options[lab.selectedIndex].text : (initialLab || ''),
            doctor: isDoctor(),
        });
    }

    // Readable from paper: no 0/O or 1/l/I. Mirrors UserController::newPassword().
    var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    function newPassword() {
        var out = '', bytes = new Uint32Array(8);
        crypto.getRandomValues(bytes);
        for (var i = 0; i < 8; i++) out += alphabet[bytes[i] % alphabet.length];
        return out;
    }
    // A fresh one replaces the generated password; anything typed is cleared
    // so the slip and Save & Print agree.
    $('mv-regen').addEventListener('click', function () { pending.value = newPassword(); field.value = ''; sync(); });
    var copyBtn = $('mv-copy');
    copyBtn.addEventListener('click', function () {
        var text = printed();
        function done() {
            copyBtn.classList.add('copied'); copyBtn.title = 'Copied';
            setTimeout(function () { copyBtn.classList.remove('copied'); copyBtn.title = 'Copy password'; }, 1500);
        }
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () {});
        } else {
            // Plain-http admin: the Clipboard API is unavailable there.
            var t = document.createElement('textarea');
            t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done();
        }
    });
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
