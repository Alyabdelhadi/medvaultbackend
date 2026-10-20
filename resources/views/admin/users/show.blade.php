@extends('admin.layout')
@section('title', $user->display_name ?: $user->username)
@section('content')
<h1>{{ $user->display_name ?: $user->username }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<div class="card">
    <div class="table-wrap" style="border:0">
    <table>
        @foreach ([
            'Username' => $user->username, 'Type' => \App\Services\Admin\UserDirectory::TYPES[$user->functionType()] ?? $user->functionType(),
            'Mobile number' => $user->mobile_number, 'Email' => $user->email, 'Date of birth' => $user->dob, 'Gender' => $user->gender,
            'Blood type' => $user->blood_type, 'Registered' => $user->created_at?->format('F j, Y g:i a'), 'Role' => $user->role,
            'Signed in to the app' => $user->api_token_hash ? 'Yes' : 'No',
        ] as $label => $value)
            <tr><th style="width:220px">{{ $label }}</th><td>{{ $value ?: '—' }}</td></tr>
        @endforeach
        {{-- Identity documents stay with site-wide accounts: a lab's own account never sees them. --}}
        @if (\App\Services\Admin\UserDirectory::scopeFor(auth()->user()) === 0)
        @foreach (['Profile picture' => $user->profile_pic, 'National ID' => $user->id_image, 'Insurance card' => $user->insurance_image] as $label => $url)
            <tr><th>{{ $label }}</th><td>@if ($url)<a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="" style="max-width:220px;max-height:150px;border-radius:6px;border:1px solid var(--line)"></a>@else — @endif</td></tr>
        @endforeach
        @endif
    </table>
    </div>
</div>

<h2>Labs</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>Lab</th><th>Lab token</th><th>Refusals</th><th>Last poll</th><th>Alerts</th><th>Poll error</th></tr></thead>
    <tbody>
    @forelse ($accounts as $a)
        <tr>
            <td>{{ $a->lab_id === 0 ? 'Legacy Verdun account' : ($labNames[$a->lab_id] ?? '#'.$a->lab_id) }}</td>
            <td>{{ $a->getRawOriginal('external_token') ? 'Issued' : '—' }}</td>
            <td>{{ $a->auth_failures }}{{ $a->auth_failures >= config('medvault.labs.max_auth_failures') ? ' (no longer asked)' : '' }}</td>
            <td>{{ $a->polled_at?->format('Y-m-d H:i') ?? '—' }}</td>
            <td>{{ $a->seen_status === null ? 'Not seeded yet' : count($a->seen_status).' order(s) tracked' }}</td>
            <td>{{ $a->poll_error ?: '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No lab has issued this account a token yet. That is normal until the user signs in, and permanent for a number no lab knows.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
<p class="muted">{{ $devices->count() }} device(s) registered for push notifications.</p>

@can('perm', [$slug, 'edit'])
    <h2>Password</h2>
    <form method="post" action="{{ route('admin.users.password', [$slug, $user]) }}" class="card" style="max-width:680px">
        @csrf
        <label for="password">New password</label>
        <input id="password" name="password" type="text" autocomplete="new-password" minlength="6" required>
        <p class="hint">The app will ask the user to sign in again.</p>
        <div class="actions"><button class="btn secondary" type="submit">Set password</button></div>
    </form>
@endcan

@can('perm', [$slug, 'delete'])
    @if ($user->id !== auth()->id())
        <form method="post" action="{{ route('admin.users.destroy', [$slug, $user]) }}" onsubmit="return confirm('Permanently delete this account, its devices, lab accounts and notifications?')">
            @csrf @method('delete')
            <button class="btn danger small" type="submit">Delete account</button>
        </form>
    @endif
@endcan
@endsection
