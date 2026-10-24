@extends('admin.layout')
@section('title', $user->display_name ?: $user->username)
@section('content')
@php
    $name = $user->display_name ?: $user->username;
    $initials = collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $siteWide = \App\Services\Admin\UserDirectory::scopeFor(auth()->user()) === 0;
    $maxRefusals = (int) config('medvault.labs.max_auth_failures');
@endphp
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif

<div class="card">
    <div class="page-head" style="margin:0">
        <div class="person-head">
            @if ($siteWide && $user->profile_pic)
                <a href="{{ $user->profile_pic }}" data-lightbox="person" data-caption="{{ $name }}" class="zoom-thumb" style="border-radius:50%"><img class="person-avatar" src="{{ $user->profile_pic }}" alt="{{ $name }}"></a>
            @else
                <span class="person-avatar">{{ $initials ?: '?' }}</span>
            @endif
            <div>
                <h1>{{ $name }}</h1>
                <div class="person-meta">
                    <span class="pill">{{ \App\Services\Admin\UserDirectory::TYPES[$user->functionType()] ?? 'Patient' }}</span>
                    <span>{{ $user->mobile_number ?: 'No mobile number' }}</span>
                    @if ($user->email)<span>{{ $user->email }}</span>@endif
                </div>
            </div>
        </div>
        @can('perm', [$slug, 'edit'])<a class="btn" href="{{ route('admin.users.edit', [$slug, $user]) }}">Edit</a>@endcan
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0">Details</h2>
    <dl class="details">
        @foreach ([
            'Username' => $user->username,
            'Date of birth' => $user->dob ? \Illuminate\Support\Carbon::parse($user->dob)->format('M j, Y') : null,
            'Gender' => $user->gender ? ucfirst($user->gender) : null,
            'Blood type' => $user->blood_type,
            'Registered' => $user->created_at?->format('M j, Y'),
            'How the account was made' => $user->createdViaLabel(),
            'Uses the app' => $user->api_token_hash ? 'Yes, signed in' : 'Not signed in',
            'Phones for notifications' => $devices->count() ?: 'None',
        ] as $label => $value)
            <div><dt>{{ $label }}</dt><dd>{{ $value ?: '—' }}</dd></div>
        @endforeach
    </dl>
</div>

{{-- Identity documents stay with site-wide accounts: a lab's own account never sees them. --}}
@if ($siteWide && ($user->id_image || $user->insurance_image))
    <div class="card">
        <h2 style="margin-top:0">Documents</h2>
        <div class="docs">
            @foreach (['National ID' => $user->id_image, 'Insurance card' => $user->insurance_image] as $label => $url)
                @if ($url)<figure><figcaption>{{ $label }}</figcaption><a href="{{ $url }}" data-lightbox="docs" data-caption="{{ $label }}" class="zoom-thumb"><img src="{{ $url }}" alt="{{ $label }}"></a></figure>@endif
            @endforeach
        </div>
    </div>
@endif

<h2>Lab results</h2>
<div class="table-wrap">
<table class="cards">
    <thead><tr><th>Lab</th><th>Connection</th><th>Last checked</th><th>Result alerts</th></tr></thead>
    <tbody>
    @forelse ($accounts as $a)
        <tr>
            <td class="card-title">{{ $a->lab_id === 0 ? 'Verdun Medical Center (original)' : ($labNames[$a->lab_id] ?? 'Lab #'.$a->lab_id) }}</td>
            <td>
                @if ($a->auth_failures >= $maxRefusals)
                    <span class="status status-paused">Not known to this lab</span>
                @elseif ($a->getRawOriginal('external_token'))
                    <span class="status status-ok">Connected</span>
                @else
                    <span class="status status-due">Not connected yet</span>
                @endif
                @if ($a->poll_error)<span class="cell-sub">Last check failed: {{ \Illuminate\Support\Str::limit($a->poll_error, 80) }}</span>@endif
            </td>
            <td>{{ $a->polled_at ? $a->polled_at->diffForHumans() : 'Not yet' }}</td>
            <td>{{ $a->seen_status === null ? 'Start at the next check' : 'Watching '.count($a->seen_status).' order(s)' }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="muted">No lab is connected yet. That is normal until this person signs in to the app; a number no lab recognises stays this way.</td></tr>
    @endforelse
    </tbody>
</table>
</div>

@if ($history !== null)
    <div class="page-head" style="margin:28px 0 0">
        <h2 style="margin:0">History</h2>
        <a class="btn small secondary" href="{{ route('admin.audit.index', ['subject' => 'user:'.$user->id]) }}">Full history</a>
    </div>
    @include('admin.audit.rows', ['logs' => $history, 'compact' => true])
@endif

@if (auth()->user()->canAdmin($slug, 'edit') || (auth()->user()->canAdmin($slug, 'delete') && $user->id !== auth()->id()))
    <div class="card danger-zone" style="margin-top:28px">
        <h2>Danger zone</h2>
        <div class="split">
            @can('perm', [$slug, 'edit'])
                <form method="post" action="{{ route('admin.users.password', [$slug, $user]) }}">
                    @csrf
                    <label for="password" style="margin-top:0">Set a new password</label>
                    <div class="phone-field">
                        <input id="password" name="password" type="text" autocomplete="new-password" minlength="6" required>
                        <button class="btn secondary" type="submit">Set password</button>
                    </div>
                    <p class="hint">The app signs this person out and asks for the new password.</p>
                </form>
            @endcan
            @can('perm', [$slug, 'delete'])
                @if ($user->id !== auth()->id())
                    <form method="post" action="{{ route('admin.users.destroy', [$slug, $user]) }}"
                          data-confirm="Delete {{ $name }}?" data-confirm-detail="Their account, phones, lab connections and notifications are removed for good." data-confirm-action="Delete account">
                        @csrf @method('delete')
                        <label style="margin-top:0">Delete this account</label>
                        <p class="hint" style="margin:0 0 10px">Removes the account, its phones, lab connections and notifications. This cannot be undone.</p>
                        <button class="btn danger" type="submit">Delete account</button>
                    </form>
                @endif
            @endcan
        </div>
    </div>
@endif
@endsection
