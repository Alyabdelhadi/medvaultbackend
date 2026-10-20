{{-- Payment reminder cards: one per plan that is due soon or overdue. --}}
@php $money = fn ($v) => '$'.number_format((float) $v, 2); @endphp
<div class="reminders">
    @foreach ($due as $plan)
        @php $state = $plan->state(); $days = (int) now()->startOfDay()->diffInDays($plan->next_due_on, false); @endphp
        <a class="reminder reminder-{{ $state }}" href="{{ route('admin.accounting.show', $plan->lab_id) }}">
            <strong>{{ $plan->lab?->title }}</strong>
            <span>{{ $money($plan->amount) }} · {{ \App\Models\LabPlan::CYCLES[$plan->cycle] }}</span>
            <em>{{ $state === 'overdue' ? 'Overdue by '.abs($days).' day(s)' : ($days === 0 ? 'Due today' : 'Due in '.$days.' day(s)') }} · {{ $plan->next_due_on->format('M j, Y') }}</em>
        </a>
    @endforeach
</div>
