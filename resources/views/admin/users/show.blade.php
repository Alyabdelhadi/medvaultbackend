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
                    <span class="pill">{{ \App\Services\Admin\AdminUserCreator::FORM_TYPES[\App\Services\Admin\AdminUserCreator::formType($user)] ?? 'Patient' }}</span>
                    <span>{{ $user->mobile_number ?: 'No mobile number' }}</span>
                    @if ($user->email)<span>{{ $user->email }}</span>@endif
                </div>
            </div>
        </div>
        @if (! $canEdit)<span class="pill" title="Another lab created this account. Its details are theirs to change.">Shared with another lab</span>
        @else @can('perm', [$slug, 'edit'])<a class="btn" href="{{ route('admin.users.edit', [$slug, $user]) }}">Edit</a>@endcan @endif
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
            'Uses the app' => ($signedIn = \App\Models\AppSession::where('user_id', $user->id)->count()) > 0
                ? 'Signed in on '.$signedIn.' '.\Illuminate\Support\Str::plural('device', $signedIn)
                : ($user->api_token_hash ? 'Yes, signed in' : 'Not signed in'),
            'Labs' => $user->functionType() === 'P' && ! $user->also_doctor ? null : collect([$user->dg_lab_id])
                ->merge(\Illuminate\Support\Facades\DB::table('user_labs')->where('user_id', $user->id)->pluck('lab_id'))
                ->filter()->unique()->map(fn ($id) => $labNames[$id] ?? 'Lab #'.$id)->implode(', '),
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

{{-- Only the labs that know this person: a lab that refused the number, or has
     not been asked yet, is not listed. A lab's own account sees its own row
     only, never which other labs this person is connected to. --}}
@php
    $viewerLab = \App\Services\Admin\UserDirectory::scopeFor(auth()->user());
    // Any token counts: a lab whose radiology runs on its own server can
    // know this person there only, and the app lists those studies too.
    $connected = $accounts->filter(fn ($a) => (int) $a->lab_id !== 0
        && ($siteWide || (int) $a->lab_id === $viewerLab)
        && (
            ($a->getRawOriginal('external_token') && $a->auth_failures < $maxRefusals)
            || ($a->getRawOriginal('radiology_token') && $a->radiology_auth_failures < $maxRefusals)
            || ($a->getRawOriginal('doctor_token') && $a->doctor_auth_failures < $maxRefusals)));
@endphp
@if ($connected->isNotEmpty())
<h2>Labs connected</h2>
<div class="card lab-list">
    @foreach ($connected as $a)
        @php $logo = $labLogos[$a->lab_id] ?? null; @endphp
        <div class="lab-list-row">
            @if ($logo)<img class="lab-thumb" src="{{ $logo }}" alt="" loading="lazy">@else<span class="lab-thumb empty">@include('admin.partials.icon', ['name' => 'lab'])</span>@endif
            <span class="lab-list-name">{{ $labNames[$a->lab_id] ?? 'Lab #'.$a->lab_id }}</span>
            <span class="lab-list-time" @if ($a->polled_at) title="{{ $a->polled_at->format('Y-m-d H:i') }}" @endif>{{ $a->polled_at ? 'Checked '.$a->polled_at->diffForHumans() : 'Not checked yet' }}</span>
        </div>
    @endforeach
</div>
@endif

@if ($history !== null)
    <div class="page-head" style="margin:28px 0 12px">
        <h2 style="margin:0">History</h2>
        <a class="btn small secondary" href="{{ route('admin.audit.index', ['subject' => 'user:'.$user->id]) }}">Full history</a>
    </div>
    @include('admin.audit.rows', ['logs' => $history, 'compact' => true])
@endif

@if (auth()->user()->canAdmin($slug, 'edit') || (auth()->user()->canAdmin($slug, 'delete') && $user->id !== auth()->id()) || $linkedHere)
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
            @if ($linkedHere)
                @can('perm', [$slug, 'create'])
                    <form method="post" action="{{ route('admin.users.unlink', [$slug, $user]) }}"
                          data-confirm="Remove {{ $name }} from your lab?" data-confirm-detail="Their account stays as it is; it just stops being listed in your lab." data-confirm-action="Remove">
                        @csrf @method('delete')
                        <label style="margin-top:0">Remove from your lab</label>
                        <p class="hint" style="margin:0 0 10px">The account is not deleted. The lab that created it keeps it.</p>
                        <button class="btn secondary" type="submit">Remove from my lab</button>
                    </form>
                @endcan
            @endif
            @can('perm', [$slug, 'delete'])
                @if ($user->id !== auth()->id() && $canEdit)
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
