@php $state = $plan->state(); @endphp
<span class="status status-{{ $state }}">
    {{ ['overdue' => 'Overdue', 'due' => 'Due soon', 'ok' => 'On track', 'paused' => 'Paused'][$state] }}
</span>
<span class="muted">{{ $plan->next_due_on->format('M j, Y') }}</span>
