@extends('admin.layout')
@section('title', $account->exists ? 'Edit account' : 'New admin account')
@section('content')
@php
    $access = old('access', $account->exists ? ($account->isAdministrator() ? 'admin' : 'group') : 'group');
    $groupId = (int) old('admin_group_id', $account->admin_group_id);
    $labGroups = $groups->where('for_labs', true)->pluck('id')->all();
@endphp
<p class="crumbs"><a href="{{ route('admin.staff.index') }}">Admin Accounts</a> / {{ $account->exists ? ($account->display_name ?: $account->username) : 'New account' }}</p>
<h1>{{ $account->exists ? 'Edit '.($account->display_name ?: $account->username) : 'New admin account' }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
@if ($groups->isEmpty() && ! $isSelf)
    <div class="notice warning">There are no groups you can give yet. <a href="{{ route('admin.groups.create') }}">Create a group</a> first, for example <strong>Lab staff</strong>.</div>
@endif
<form method="post" action="{{ $account->exists ? route('admin.staff.update', $account) : route('admin.staff.store') }}" class="card" data-staff-form data-lab-groups="{{ json_encode($labGroups) }}">
    @csrf
    @if ($account->exists) @method('put') @endif
    <div class="grid">
        <div>
            <label for="display_name">Name</label>
            <input id="display_name" name="display_name" type="text" value="{{ old('display_name', $account->display_name) }}" placeholder="e.g. Bekaa Hospital Lab" required>
        </div>
        <div>
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="{{ old('username', $account->username) }}" autocomplete="off" required>
        </div>
        <div>
            <label for="email">Email <span class="muted">(optional)</span></label>
            <input id="email" name="email" type="email" value="{{ old('email', $account->email) }}">
        </div>
        <div>
            <label for="password">{{ $account->exists ? 'New password' : 'Password' }}</label>
            <input id="password" name="password" type="text" autocomplete="new-password" minlength="8" @unless ($account->exists) required @endunless>
            <p class="hint">At least 8 characters.{{ $account->exists ? ' Leave empty to keep the current one.' : '' }} Give it to the lab yourself.</p>
        </div>
    </div>

    @if ($isSelf)
        <p class="hint" style="margin-top:16px">You cannot change your own access. Ask another administrator.</p>
    @else
        <label style="margin-top:20px">Access</label>
        <div class="segmented">
            @if ($canMakeAdmins)
                <label class="check"><input type="radio" name="access" value="admin" @checked($access === 'admin')> Administrator <span class="muted">· everything</span></label>
            @endif
            <label class="check"><input type="radio" name="access" value="group" @checked($access === 'group')> Member of a group</label>
        </div>
        <div class="grid" data-for-access="group">
            <div>
                <label for="admin_group_id">Group</label>
                <select id="admin_group_id" name="admin_group_id">
                    <option value="">— choose —</option>
                    @foreach ($groups as $g)<option value="{{ $g->id }}" @selected($groupId === $g->id)>{{ $g->name }}{{ $g->for_labs ? ' (for a lab)' : '' }}</option>@endforeach
                </select>
                <p class="hint"><a href="{{ route('admin.groups.index') }}">Groups &amp; Permissions</a> sets what each group can do.</p>
            </div>
            <div data-lab-field>
                <label for="managed_lab_id">Lab</label>
                <select id="managed_lab_id" name="managed_lab_id">
                    <option value="">— choose —</option>
                    @foreach ($labs as $id => $title)<option value="{{ $id }}" @selected((int) old('managed_lab_id', $account->managed_lab_id) === $id)>{{ $title }}</option>@endforeach
                </select>
                <p class="hint">This account sees only this lab's patients, and sends notifications only to them.</p>
            </div>
        </div>
    @endif
    <div class="actions sticky-actions"><button class="btn" type="submit">{{ $account->exists ? 'Save' : 'Create account' }}</button><a class="btn secondary" href="{{ route('admin.staff.index') }}">Cancel</a></div>
</form>
<script>
(function () {
    var form = document.querySelector('[data-staff-form]');
    var labGroups = JSON.parse(form.getAttribute('data-lab-groups'));
    function sync() {
        var access = (form.querySelector('input[name=access]:checked') || {}).value;
        var group = form.querySelector('[data-for-access=group]');
        if (!group) return;
        group.hidden = access !== 'group';
        var gid = parseInt((form.querySelector('#admin_group_id') || {}).value, 10);
        form.querySelector('[data-lab-field]').hidden = labGroups.indexOf(gid) === -1;
    }
    form.addEventListener('change', sync); sync();
})();
</script>
@endsection
