{{-- A number box: $label, $value, optional $icon and $tone (accent | sky | navy | warn). --}}
<div class="stat">
    @isset($icon)<span class="stat-icon {{ $tone ?? '' }}">@include('admin.partials.icon', ['name' => $icon])</span>@endisset
    <span class="muted">{{ $label }}</span>
    <b>{{ $value }}</b>
</div>
