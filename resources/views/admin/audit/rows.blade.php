{{-- Audit rows: $logs, and optionally $compact (the person page). --}}
@php $kinds = ['admin' => 'Admin', 'app' => 'App', 'system' => 'System']; @endphp
<div class="table-wrap">
<table class="cards">
    <thead><tr><th>When</th><th>Who</th><th>What</th>@unless ($compact ?? false)<th>From</th>@endunless</tr></thead>
    <tbody>
    @forelse ($logs as $log)
        <tr>
            <td data-label="When" style="white-space:nowrap" title="{{ $log->created_at?->format('Y-m-d H:i:s') }}">{{ $log->created_at?->format('M j, Y H:i') }}<span class="cell-sub">{{ $log->created_at?->diffForHumans() }}</span></td>
            <td data-label="Who">{{ $log->actor_name ?: '—' }}<span class="cell-sub">{{ $kinds[$log->actor_kind] ?? $log->actor_kind }}</span></td>
            <td class="card-title">
                {{ $log->summary }}
                @if ($log->changes)
                    <details class="audit-details"><summary>{{ count($log->changes) === 1 && count($log->changes[0]['fields'] ?? []) ? count($log->changes[0]['fields']).' field(s)' : count($log->changes).' change(s)' }}</summary>
                        @include('admin.audit.changes', ['changes' => $log->changes])
                    </details>
                @endif
            </td>
            @unless ($compact ?? false)
                <td data-label="From">{{ $log->ip ?: '—' }}@if ($log->user_agent)<span class="cell-sub" title="{{ $log->user_agent }}">{{ \Illuminate\Support\Str::limit($log->user_agent, 40) }}</span>@endif</td>
            @endunless
        </tr>
    @empty
        <tr><td colspan="4" class="muted">Nothing recorded{{ ($compact ?? false) ? ' for this account yet' : ' for these filters' }}.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
