{{--
    The sign-in slip handed to a new patient, doctor or guarantor. One markup
    for the live preview on Add (JavaScript fills it through mvSlip) and for
    the page that prints it once the account exists (filled here).

    $slipName, $slipLab, $slipMobile: null shows a placeholder.
    $slipPassword: shown as typed; it exists only in this response.
    $slipDoctor: a doctor or guarantor, whose results are their patients'.
--}}
@php
    $lead = fn (bool $doctor) => $doctor ? "Your MedVault account, with your patients' results" : 'Your personal medical wallet, with your results';
@endphp
<div class="mv-slip" id="mv-slip">
    <div class="mv-slip-brand">
        <span class="mv-slip-mark"><img src="{{ \App\Support\AdminAssets::logo() }}" alt=""></span>
        <b>MedVault</b>
    </div>
    <p class="mv-slip-welcome">Welcome, <span data-slip="name" class="{{ $slipName ? '' : 'mv-slip-ph' }}">{{ $slipName ?: ($slipDoctor ? 'Doctor name' : 'Patient name') }}</span></p>
    <p class="mv-slip-text">
        <span data-slip="lead">{{ $lead($slipDoctor) }}</span><span data-slip="lab-wrap">{!! $slipLab ? ' from <b data-slip="lab">'.e($slipLab).'</b>' : ' from MedVault labs' !!}</span>.<br>
        Sign in to the app with:
    </p>
    <div class="mv-slip-cred"><span>Mobile</span><b data-slip="mobile" class="{{ $slipMobile ? '' : 'mv-slip-ph' }}">{{ $slipMobile ?: '—' }}</b></div>
    <div class="mv-slip-cred"><span>Password</span><b data-slip="password" class="mv-slip-mono">{{ $slipPassword }}</b></div>
    <div class="mv-slip-app">
        @include('admin.partials.download-qr')
        <div>
            <b>Download the app</b>
            <span>Scan with your phone camera</span>
            <small>medvaultapp.com/download</small>
        </div>
    </div>
    <p class="mv-slip-foot">Keep this slip private. You can change your password after signing in.</p>
</div>
<script>
window.mvSlip = function (s) {
    var root = document.getElementById('mv-slip');
    var put = function (key, text, placeholder) {
        var el = root.querySelector('[data-slip="' + key + '"]');
        if (!el) return;
        el.textContent = text || placeholder;
        el.classList.toggle('mv-slip-ph', !text);
    };
    put('name', s.name, s.doctor ? 'Doctor name' : 'Patient name');
    put('mobile', window.mvSlipMobile(s.cc, s.mobile), '—');
    put('password', s.password, '—');
    root.querySelector('[data-slip="lead"]').textContent = s.doctor
        ? "Your MedVault account, with your patients' results"
        : 'Your personal medical wallet, with your results';
    // A lab chosen on the form wins; a lab manager's own lab stays as rendered.
    if (s.lab !== undefined) {
        var wrap = root.querySelector('[data-slip="lab-wrap"]');
        wrap.textContent = '';
        if (s.lab) {
            var b = document.createElement('b');
            b.textContent = s.lab;
            wrap.append(' from ', b);
        } else {
            wrap.textContent = ' from MedVault labs';
        }
    }
};
// "70 143 335": the national number in readable groups, with the country
// code only when it is not Lebanon's. Mirrors UserController::slipMobile().
window.mvSlipMobile = function (cc, raw) {
    var digits = String(raw || '').replace(/\D/g, '');
    if (!digits) return '';
    var grouped = digits.length === 8 ? digits.replace(/^(\d{2})(\d{3})(\d{3})$/, '$1 $2 $3')
        : digits.length === 7 ? digits.replace(/^(\d)(\d{3})(\d{3})$/, '$1 $2 $3')
        : digits.replace(/(\d{3})(?=\d)/g, '$1 ');
    return cc && cc !== '961' ? '+' + cc + ' ' + grouped : grouped;
};
</script>
