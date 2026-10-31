@extends('admin.layout')
@section('title', 'Add '.\App\Services\Admin\UserDirectory::TYPES[$type])
@section('content')
@php
    $err = fn ($f) => $errors->has($f) ? '<p class="error-text">'.e($errors->first($f)).'</p>' : '';
    $formType = old('function_type', $formType);
    $cc = old('mobile_cc', '961');
    // Pre-filled so the slip is complete without JavaScript; the refresh
    // button replaces it. Never stored: it is hashed on save.
    $password = old('password') ?: $suggestedPassword;
@endphp
@include('admin.users.slip-styles')
<style>
.mv-add{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:28px;align-items:start;max-width:1240px}
@media (max-width:1100px){.mv-add{grid-template-columns:1fr}}
.mv-add > .card{padding:26px 28px;margin:0}
.mv-row{display:grid;gap:4px 18px;margin-bottom:16px}
.mv-row.r3{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr) minmax(0,.85fr)}
.mv-row.r2{grid-template-columns:1fr 1fr}
@media (max-width:760px){.mv-row.r3,.mv-row.r2{grid-template-columns:1fr}}
.mv-add label{margin-top:0}
.mv-add .req{color:var(--err)}
.mv-dob{display:grid;grid-template-columns:1fr 1fr 1.5fr;gap:8px}
.mv-dob input{text-align:center;min-width:0;padding-left:4px;padding-right:4px}
.mv-seg{display:flex;border:1px solid var(--line-strong);border-radius:var(--radius-sm);overflow:hidden}
.mv-seg input{position:absolute;opacity:0;pointer-events:none}
.mv-seg label{flex:1;margin:0;padding:10px 0;text-align:center;cursor:pointer;color:var(--ink-2);font-weight:500;background:#fff}
.mv-seg label + input + label{border-left:1px solid var(--line-strong)}
.mv-seg input:checked + label{background:var(--navy);color:#fff}
.mv-seg input:focus-visible + label{box-shadow:inset 0 0 0 3px rgba(43,169,169,.35)}
.mv-phone{display:grid;grid-template-columns:140px minmax(0,1fr);gap:8px}
.mv-pass{display:grid;grid-template-columns:minmax(0,1fr) 46px;gap:8px}
.mv-pass input{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.04em}
.mv-pass button{border:1px solid var(--line-strong);border-radius:var(--radius-sm);background:#fff;color:var(--navy);cursor:pointer;display:flex;align-items:center;justify-content:center}
.mv-pass button:hover{border-color:var(--navy)}
.mv-more{border-top:1px solid var(--line);border-bottom:1px solid var(--line);margin:4px 0 20px;padding:14px 0}
.mv-more summary{cursor:pointer;color:#2f55d4;font-weight:500;list-style:none}
.mv-more summary::-webkit-details-marker{display:none}
.mv-more[open] summary{margin-bottom:14px}
.mv-submit{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.mv-submit .btn{padding:12px 22px;font-size:15px}
.mv-submit .hint{flex:1;min-width:220px;margin:0}
.mv-preview-label{font-size:12px;font-weight:600;letter-spacing:.08em;color:var(--muted);margin:2px 0 10px}
</style>

<h1>Add {{ \App\Services\Admin\UserDirectory::TYPES[$type] }}</h1>
<div class="mv-add">
<form method="post" action="{{ route('admin.users.store', $slug) }}" class="card" id="mv-add-form">
    @csrf
    <div class="mv-row r3">
        <div>
            <label for="mv-type">Type</label>
            <select name="function_type" id="mv-type">
                @foreach (\App\Services\Admin\AdminUserCreator::FORM_TYPES as $code => $label)
                    <option value="{{ $code }}" @selected($formType === $code)>{{ $label }}</option>
                @endforeach
            </select>{!! $err('function_type') !!}
        </div>
        <div>
            <label for="dob_d">Date of birth</label>
            <div class="mv-dob">
                <input id="dob_d" name="dob_d" type="text" inputmode="numeric" maxlength="2" placeholder="DD" value="{{ old('dob_d') }}" autocomplete="off" aria-label="Day">
                <input id="dob_m" name="dob_m" type="text" inputmode="numeric" maxlength="2" placeholder="MM" value="{{ old('dob_m') }}" autocomplete="off" aria-label="Month">
                <input id="dob_y" name="dob_y" type="text" inputmode="numeric" maxlength="4" placeholder="YYYY" value="{{ old('dob_y') }}" autocomplete="off" aria-label="Year">
            </div>
            <p class="hint">Type digits, it jumps ahead. "85" = 1985</p>{!! $err('dob') !!}
        </div>
        <div>
            <label>Gender</label>
            <div class="mv-seg" role="radiogroup" aria-label="Gender">
                <input type="radio" name="gender" id="g-m" value="male" @checked(old('gender') === 'male')><label for="g-m">Male</label>
                <input type="radio" name="gender" id="g-f" value="female" @checked(old('gender') === 'female')><label for="g-f">Female</label>
            </div>{!! $err('gender') !!}
        </div>
    </div>

    <div class="mv-row r2">
        <div>
            <label for="first_name">First name <span class="req">*</span></label>
            <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required autocomplete="off">{!! $err('first_name') !!}
        </div>
        <div>
            <label for="last_name">Last name <span class="req">*</span></label>
            <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required autocomplete="off">{!! $err('last_name') !!}
        </div>
    </div>

    <div class="mv-row r2">
        <div>
            <label for="mobile_number">Mobile number <span class="req">*</span></label>
            <div class="mv-phone">
                <select name="mobile_cc" id="mobile_cc" aria-label="Country">
                    @foreach (\App\Support\Countries::LIST as [$flag, $dial, $country])
                        <option title="{{ $country }}" value="{{ $dial }}" @selected($cc === $dial)>{{ $flag }} +{{ $dial }}</option>
                    @endforeach
                </select>
                <input id="mobile_number" name="mobile_number" type="tel" inputmode="tel" value="{{ old('mobile_number') }}" required autocomplete="off">
            </div>
            <p class="hint">Without country code, e.g. 70536332 or 03123456</p>{!! $err('mobile_number') !!}
        </div>
        <div>
            <label for="password">Password <span class="req">*</span></label>
            <div class="mv-pass">
                <input id="password" name="password" type="text" value="{{ $password }}" required autocomplete="off" spellcheck="false">
                <button type="button" id="mv-regen" title="New password" aria-label="Generate a new password">@include('admin.partials.icon', ['name' => 'refresh'])</button>
            </div>
            <p class="hint">Auto-generated. Printed on the slip, not sent.</p>{!! $err('password') !!}
        </div>
    </div>

    <details class="mv-more" @if ($errors->hasAny(['username', 'email', 'blood_type'])) open @endif>
        <summary>+ More details (optional)</summary>
        <div class="mv-row r2">
            <div>
                <label for="email">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}">{!! $err('email') !!}
            </div>
            <div>
                <label for="username">Username</label>
                <input id="username" name="username" type="text" value="{{ old('username') }}" placeholder="Generated from the name">{!! $err('username') !!}
            </div>
            <div>
                <label for="blood_type">Blood type</label>
                <select id="blood_type" name="blood_type">
                    <option value="">—</option>
                    @foreach (\App\Services\Admin\AdminUserCreator::BLOOD_TYPES as $bt)
                        <option value="{{ $bt }}" @selected(old('blood_type') === $bt)>{{ $bt }}</option>
                    @endforeach
                </select>{!! $err('blood_type') !!}
            </div>
            @if ($managerLab === 0)
                <div>
                    <label for="lab_id">Lab</label>
                    <select id="lab_id" name="lab_id">
                        <option value="">— none —</option>
                        @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) old('lab_id') === $id)>{{ $title }}</option>@endforeach
                    </select>
                    <p class="hint">Named on the slip; a doctor or guarantor is attached to it.</p>
                </div>
            @endif
        </div>
    </details>

    <div class="mv-submit">
        <button class="btn" type="submit">@include('admin.partials.icon', ['name' => 'print']) Add &amp; Print</button>
        <a class="btn secondary" href="{{ route('admin.users.index', $slug) }}">Cancel</a>
        <p class="hint">Account is created here only. No SMS is sent; the lab token is issued at first sign-in.</p>
    </div>
</form>

<aside>
    <p class="mv-preview-label">PRINT PREVIEW</p>
    @include('admin.users.slip', [
        'slipName' => null,
        'slipLab' => $managerLab > 0 ? ($labs[$managerLab] ?? null) : null,
        'slipMobile' => null,
        'slipPassword' => $password,
        'slipDoctor' => in_array($formType, ['D', 'G'], true),
    ])
</aside>
</div>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var d = $('dob_d'), m = $('dob_m'), y = $('dob_y');

    // Digits only, and on to the next box once this one is full.
    function jump(from, to, len) {
        from.addEventListener('input', function () {
            from.value = from.value.replace(/\D/g, '').slice(0, len);
            if (to && from.value.length >= len) to.focus();
        });
    }
    jump(d, m, 2); jump(m, y, 2); jump(y, null, 4);
    // "85" is 1985, "07" is 2007: a two-digit year past this one is last century.
    y.addEventListener('blur', function () {
        if (y.value.length === 2) {
            var yy = parseInt(y.value, 10), now = new Date().getFullYear() % 100;
            y.value = (yy > now ? 1900 : 2000) + yy;
        }
    });

    // Readable from paper: no 0/O or 1/l/I.
    var alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    function newPassword() {
        var out = '', bytes = new Uint32Array(8);
        crypto.getRandomValues(bytes);
        for (var i = 0; i < 8; i++) out += alphabet[bytes[i] % alphabet.length];
        return out;
    }

    var lab = $('lab_id');
    function sync() {
        window.mvSlip({
            name: ($('first_name').value.trim() + ' ' + $('last_name').value.trim()).trim(),
            mobile: $('mobile_number').value,
            cc: $('mobile_cc').value,
            password: $('password').value,
            lab: lab && lab.value ? lab.options[lab.selectedIndex].text : undefined,
            doctor: ['D', 'G'].indexOf($('mv-type').value) !== -1,
        });
    }
    $('mv-regen').addEventListener('click', function () { $('password').value = newPassword(); sync(); });
    ['first_name', 'last_name', 'mobile_number', 'password'].forEach(function (id) { $(id).addEventListener('input', sync); });
    ['mobile_cc', 'mv-type'].concat(lab ? ['lab_id'] : []).forEach(function (id) { $(id).addEventListener('change', sync); });
    sync();
})();
</script>
@endsection
