{{--
    The person form, shared by Add and Edit. $person is the account being
    edited, or null when adding. $managerLab > 0 for a lab's own account,
    which cannot move anyone to another lab.
--}}
@php
    $err = fn ($f) => $errors->has($f) ? '<p class="error-text">'.e($errors->first($f)).'</p>' : '';
    $val = fn ($field, $default = null) => old($field, $person?->{$field} ?? $default);
    // The stored number, split for the picker: country and the rest.
    $split = $person ? \App\Support\Mobile::split((string) $person->mobile_number) : ['cc' => '', 'national' => ''];
    $known = in_array($split['cc'], \App\Support\Countries::codes(), true);
    $cc = old('mobile_cc', $known ? $split['cc'] : '961');
    $number = old('mobile_number', $person ? ($known ? $split['national'] : $person->mobile_number) : '');
@endphp
<label for="mv-type">Type</label>
<select name="function_type" id="mv-type">
    @foreach (\App\Services\Admin\UserDirectory::TYPES as $code => $label)
        <option value="{{ $code }}" @selected(old('function_type', $person?->functionType() ?? $type) === $code)>{{ $label }}</option>
    @endforeach
</select>{!! $err('function_type') !!}
<div class="grid">
    <div>
        <label for="first_name">First name *</label>
        <input id="first_name" name="first_name" type="text" value="{{ $val('first_name') }}" required>{!! $err('first_name') !!}
    </div>
    <div>
        <label for="last_name">Last name *</label>
        <input id="last_name" name="last_name" type="text" value="{{ $val('last_name') }}" required>{!! $err('last_name') !!}
    </div>
</div>
<label for="mobile_number">Mobile number *</label>
<div class="phone-field">
    <select name="mobile_cc" aria-label="Country">
        @foreach (\App\Support\Countries::LIST as [$flag, $dial, $country])
            <option value="{{ $dial }}" @selected($cc === $dial)>{{ $flag }} {{ $country }} +{{ $dial }}</option>
        @endforeach
    </select>
    <input id="mobile_number" name="mobile_number" type="tel" inputmode="tel" value="{{ $number }}" placeholder="70 123 456" required>
</div>
<p class="hint">Choose the country, then type the number without its country code, for example <code>70536332</code> or <code>03123456</code> for Lebanon.@if ($person) Changing the number signs the account out of its labs: they are asked again with the new number.@endif</p>{!! $err('mobile_number') !!}
<label for="password">{{ $person ? 'New password' : 'Password *' }}</label>
<input id="password" name="password" type="text" autocomplete="new-password" @unless ($person) required @endunless>
<p class="hint">At least 6 characters.{{ $person ? ' Leave empty to keep the current one.' : ' Shown so you can pass it on; it is not sent to the user.' }}</p>{!! $err('password') !!}
<div class="grid">
    <div>
        <label for="username">Username</label>
        @if ($person)
            <input id="username" type="text" value="{{ $person->username }}" disabled>
            <p class="hint">Usernames do not change.</p>
        @else
            <input id="username" name="username" type="text" value="{{ old('username') }}">
            <p class="hint">Leave blank to generate one from the name.</p>{!! $err('username') !!}
        @endif
    </div>
    <div>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ $val('email') }}">
        <p class="hint">Optional: accounts are identified by mobile number.</p>{!! $err('email') !!}
    </div>
    <div>
        <label for="dob">Date of birth</label>
        <input id="dob" name="dob" type="date" value="{{ $val('dob') }}">{!! $err('dob') !!}
    </div>
    <div>
        <label for="gender">Gender</label>
        <select id="gender" name="gender">
            <option value="">—</option>
            @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $g => $label)
                <option value="{{ $g }}" @selected(strtolower((string) $val('gender')) === $g)>{{ $label }}</option>
            @endforeach
        </select>{!! $err('gender') !!}
    </div>
    <div>
        <label for="blood_type">Blood type</label>
        <select id="blood_type" name="blood_type">
            <option value="">—</option>
            @foreach (\App\Services\Admin\AdminUserCreator::BLOOD_TYPES as $bt)
                <option value="{{ $bt }}" @selected($val('blood_type') === $bt)>{{ $bt }}</option>
            @endforeach
        </select>{!! $err('blood_type') !!}
    </div>
</div>
@if ($managerLab > 0)
    <p class="hint">{{ $person ? 'This account belongs to' : 'The account will belong to' }} <strong>{{ $labs[$managerLab] ?? 'your lab' }}</strong>.</p>
@else
    <div id="mv-lab-row">
        <label for="dg_lab_id">Lab (doctors and guarantors)</label>
        <select id="dg_lab_id" name="dg_lab_id">
            <option value="">— none —</option>
            @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) $val('dg_lab_id') === $id)>{{ $title }}</option>@endforeach
        </select>
    </div>
@endif
<script>
(function () {
    var type = document.getElementById('mv-type'), row = document.getElementById('mv-lab-row');
    if (!row) return;
    function sync() { row.style.display = type.value === 'P' ? 'none' : ''; }
    type.addEventListener('change', sync); sync();
})();
</script>
