@extends('admin.layout')
@section('title', 'Add '.\App\Services\Admin\UserDirectory::TYPES[$type])
@section('content')
@php $err = fn ($f) => $errors->has($f) ? '<p class="error-text">'.e($errors->first($f)).'</p>' : ''; @endphp
<h1>Add {{ \App\Services\Admin\UserDirectory::TYPES[$type] }}</h1>
<form method="post" action="{{ route('admin.users.store', $slug) }}" class="card" style="max-width:720px">
    @csrf
    <label for="mv-type">Type</label>
    <select name="function_type" id="mv-type">
        @foreach (\App\Services\Admin\UserDirectory::TYPES as $code => $label)
            <option value="{{ $code }}" @selected(old('function_type', $type) === $code)>{{ $label }}</option>
        @endforeach
    </select>{!! $err('function_type') !!}
    <label for="first_name">First name *</label>
    <input id="first_name" name="first_name" type="text" value="{{ old('first_name') }}" required>{!! $err('first_name') !!}
    <label for="last_name">Last name *</label>
    <input id="last_name" name="last_name" type="text" value="{{ old('last_name') }}" required>{!! $err('last_name') !!}
    <label for="mobile_number">Mobile number *</label>
    <div class="phone-field">
        <select name="mobile_cc" aria-label="Country">
            @foreach (\App\Support\Countries::LIST as [$flag, $dial, $country])
                <option value="{{ $dial }}" @selected(old('mobile_cc', '961') === $dial)>{{ $flag }} {{ $country }} +{{ $dial }}</option>
            @endforeach
        </select>
        <input id="mobile_number" name="mobile_number" type="tel" inputmode="tel" value="{{ old('mobile_number') }}" placeholder="70 123 456" required>
    </div>
    <p class="hint">Choose the country, then type the number without its country code, for example <code>70536332</code> or <code>03123456</code> for Lebanon.</p>{!! $err('mobile_number') !!}
    <label for="password">Password *</label>
    <input id="password" name="password" type="text" autocomplete="new-password" required>
    <p class="hint">At least 6 characters. Shown so you can pass it on; it is not sent to the user.</p>{!! $err('password') !!}
    <label for="username">Username</label>
    <input id="username" name="username" type="text" value="{{ old('username') }}">
    <p class="hint">Leave blank to generate one from the name.</p>{!! $err('username') !!}
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email') }}">
    <p class="hint">Optional: accounts are identified by mobile number.</p>{!! $err('email') !!}
    <label for="dob">Date of birth</label>
    <input id="dob" name="dob" type="date" value="{{ old('dob') }}">{!! $err('dob') !!}
    <label for="gender">Gender</label>
    <select id="gender" name="gender">
        <option value="">—</option>
        @foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $g => $label)
            <option value="{{ $g }}" @selected(old('gender') === $g)>{{ $label }}</option>
        @endforeach
    </select>{!! $err('gender') !!}
    <label for="blood_type">Blood type</label>
    <select id="blood_type" name="blood_type">
        <option value="">—</option>
        @foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bt)
            <option value="{{ $bt }}" @selected(old('blood_type') === $bt)>{{ $bt }}</option>
        @endforeach
    </select>{!! $err('blood_type') !!}
    @if ($managerLab > 0)
        <p class="hint">The account will belong to <strong>{{ $labs[$managerLab] ?? 'your lab' }}</strong>.</p>
    @else
        <div id="mv-lab-row">
            <label for="dg_lab_id">Lab (doctors and guarantors)</label>
            <select id="dg_lab_id" name="dg_lab_id">
                <option value="">— none —</option>
                @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) old('dg_lab_id') === $id)>{{ $title }}</option>@endforeach
            </select>
        </div>
    @endif
    <p class="hint" style="margin-top:14px">The account is created here only. No lab login is attempted and no SMS is sent: the lab token is issued the first time the user signs in.</p>
    <div class="actions">
        <button class="btn" type="submit">Add</button>
        <a href="{{ route('admin.users.index', $slug) }}">Cancel</a>
    </div>
</form>
<script>
(function () {
    var type = document.getElementById('mv-type'), row = document.getElementById('mv-lab-row');
    if (!row) return;
    function sync() { row.style.display = type.value === 'P' ? 'none' : ''; }
    type.addEventListener('change', sync); sync();
})();
</script>
@endsection
