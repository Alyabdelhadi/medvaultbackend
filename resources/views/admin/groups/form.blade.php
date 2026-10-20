@extends('admin.layout')
@section('title', $group->exists ? 'Edit group' : 'New group')
@section('content')
@php
    $granted = old('permissions', $group->permissions ?? []);
    $has = fn ($module, $action) => in_array($action, (array) ($granted[$module] ?? []), true);
    $me = auth()->user();
    $forLabs = (bool) old('for_labs', $group->for_labs);
@endphp
<p class="crumbs"><a href="{{ route('admin.groups.index') }}">Groups &amp; Permissions</a> / {{ $group->exists ? $group->name : 'New group' }}</p>
<h1>{{ $group->exists ? 'Edit '.$group->name : 'New group' }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ $group->exists ? route('admin.groups.update', $group) : route('admin.groups.store') }}" data-perm-form>
    @csrf
    @if ($group->exists) @method('put') @endif
    <div class="card">
        <div class="grid">
            <div>
                <label for="name">Group name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $group->name) }}" placeholder="e.g. Lab staff, Finance, Support" required>
            </div>
            <div>
                <label for="description">Description</label>
                <input id="description" name="description" type="text" value="{{ old('description', $group->description) }}" placeholder="What this group is for">
            </div>
        </div>
        <label class="check" style="margin-top:16px"><input type="checkbox" name="for_labs" value="1" data-for-labs @checked($forLabs)>
            <span><strong>For a lab's own accounts.</strong> Each account in this group belongs to one lab, sees only that lab's patients and sends notifications only to them. Site-wide pages cannot be given.</span></label>
    </div>

    <div class="card" style="padding:0;overflow:hidden">
        <div class="toolbar">
            <h3 style="margin:0">Permissions</h3>
            <div class="actions" style="margin:0">
                <button type="button" class="btn small secondary" data-perm-all="view">View all</button>
                <button type="button" class="btn small secondary" data-perm-all="*">Tick everything</button>
                <button type="button" class="btn small secondary" data-perm-all="">Clear</button>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table class="perm-table">
            <thead><tr><th>Page</th>@foreach (\App\Support\AdminModules::ACTION_LABELS as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach (\App\Support\AdminModules::sections() as $section => $modules)
                <tr class="perm-section"><td colspan="5">{{ $section }}</td></tr>
                @foreach ($modules as $key => $module)
                    <tr @if ($module['site_wide'] ?? false) data-site-wide @endif>
                        <td>
                            <span class="perm-page">@include('admin.partials.icon', ['name' => $module['icon']]) {{ $module['label'] }}</span>
                            @if ($module['site_wide'] ?? false)<span class="muted perm-note">Site-wide</span>@endif
                        </td>
                        @foreach (\App\Support\AdminModules::ACTIONS as $action)
                            <td>
                                @if (in_array($action, $module['actions'], true))
                                    @php $allowed = $me->canAdmin($key, $action); @endphp
                                    <input type="checkbox" name="permissions[{{ $key }}][]" value="{{ $action }}" data-action="{{ $action }}"
                                        @checked($has($key, $action)) @disabled(! $allowed) @unless ($allowed) title="You do not have this permission yourself" @endunless>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
    <p class="hint">Any tick on a page also gives View.</p>
    <div class="actions"><button class="btn" type="submit">Save group</button><a class="btn secondary" href="{{ route('admin.groups.index') }}">Cancel</a></div>
</form>
<script>
(function () {
    var form = document.querySelector('[data-perm-form]');
    var forLabs = form.querySelector('[data-for-labs]');
    function sync() {
        form.querySelectorAll('tr[data-site-wide]').forEach(function (row) {
            row.classList.toggle('is-off', forLabs.checked);
            row.querySelectorAll('input').forEach(function (i) { if (forLabs.checked) i.checked = false; i.dataset.locked = forLabs.checked ? '1' : ''; });
        });
    }
    forLabs.addEventListener('change', sync); sync();
    form.querySelectorAll('[data-perm-all]').forEach(function (b) {
        b.addEventListener('click', function () {
            var which = b.getAttribute('data-perm-all');
            form.querySelectorAll('input[data-action]').forEach(function (i) {
                if (i.disabled || i.dataset.locked) return;
                i.checked = which === '*' || (which !== '' && i.dataset.action === which);
            });
        });
    });
    form.addEventListener('change', function (e) {
        var i = e.target;
        if (!i.dataset || !i.dataset.action) return;
        if (i.dataset.locked) { i.checked = false; return; }
        var row = i.closest('tr');
        var view = row.querySelector('input[data-action=view]');
        if (i.checked && view && !view.disabled) view.checked = true;
        if (!i.checked && i.dataset.action === 'view') row.querySelectorAll('input[data-action]').forEach(function (o) { if (!o.disabled) o.checked = false; });
    });
})();
</script>
@endsection
