@extends('admin.layout')
@section('title', 'Accounting')
@section('content')
@php $money = fn ($v) => '$'.number_format((float) $v, 2); @endphp
<div class="page-head">
    <h1>Accounting</h1>
    <form method="get" class="inline-form">
        <label for="year" class="sr-only">Year</label>
        <select id="year" name="year" onchange="this.form.submit()">
            @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach
        </select>
    </form>
</div>

@if ($due->isNotEmpty())
    <h2 style="margin-top:0">Reminders</h2>
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
@endif

<div class="stats" style="margin-top:18px">
    @foreach ($summary as $label => $value)
        <div class="stat"><span class="muted">{{ $label }}</span><b>{{ $label === 'Labs on a plan' ? $value : $money($value) }}</b></div>
    @endforeach
</div>

<div class="charts charts-wide">
    @include('admin.partials.bar-chart', ['title' => "Revenue received in {$year}, by month", 'tone' => 'green', 'data' => $revenue, 'money' => true])
</div>

<h2>Labs</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>Lab</th><th>Plan</th><th>Amount</th><th>Per year</th><th>Next payment</th><th>Received in {{ $year }}</th><th></th></tr></thead>
    <tbody>
    @foreach ($labs as $lab)
        @php $plan = $lab->plan; @endphp
        <tr>
            <td><a href="{{ route('admin.accounting.show', $lab) }}"><strong>{{ $lab->title }}</strong></a></td>
            @if ($plan)
                <td>{{ \App\Models\LabPlan::CYCLES[$plan->cycle] }}</td>
                <td>{{ $money($plan->amount) }}</td>
                <td>{{ $money($plan->annualValue()) }}</td>
                <td>@include('admin.accounting.state', ['plan' => $plan])</td>
            @else
                <td colspan="4" class="muted">No plan yet</td>
            @endif
            <td>{{ $money($receivedByLab[$lab->id] ?? 0) }}</td>
            <td style="white-space:nowrap;text-align:right">
                @can('perm', ['accounting', 'create'])
                    @if ($plan)<a class="btn small" href="{{ route('admin.accounting.payment', $lab) }}">Record payment</a>@endif
                @endcan
                @can('perm', ['accounting', 'edit'])
                    <a class="btn small secondary" href="{{ route('admin.accounting.plan', $lab) }}">{{ $plan ? 'Edit plan' : 'Set up plan' }}</a>
                @endcan
            </td>
        </tr>
    @endforeach
    </tbody>
    <tfoot><tr><th colspan="5">Total</th><th colspan="2">{{ $money($receivedByLab->sum()) }}</th></tr></tfoot>
</table>
</div>
@endsection
