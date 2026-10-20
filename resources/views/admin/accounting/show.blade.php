@extends('admin.layout')
@section('title', $lab->title.' · Accounting')
@section('content')
@php $money = fn ($v) => '$'.number_format((float) $v, 2); @endphp
<p class="crumbs"><a href="{{ route('admin.accounting.index') }}">Accounting</a> / {{ $lab->title }}</p>
<div class="page-head">
    <h1>{{ $lab->title }}</h1>
    <div class="actions" style="margin:0">
        @can('perm', ['accounting', 'create'])
            @if ($plan)<a class="btn" href="{{ route('admin.accounting.payment', $lab) }}">Record payment</a>@endif
        @endcan
        @can('perm', ['accounting', 'edit'])
            <a class="btn secondary" href="{{ route('admin.accounting.plan', $lab) }}">{{ $plan ? 'Edit plan' : 'Set up plan' }}</a>
        @endcan
    </div>
</div>

@if ($plan)
    <div class="stats">
        <div class="stat"><span class="muted">Plan</span><b>{{ $money($plan->amount) }}</b><span class="muted">{{ \App\Models\LabPlan::CYCLES[$plan->cycle] }}</span></div>
        <div class="stat"><span class="muted">Per year</span><b>{{ $money($plan->annualValue()) }}</b></div>
        <div class="stat"><span class="muted">Next payment</span><b>{{ $plan->next_due_on->format('M j, Y') }}</b>@include('admin.accounting.state', ['plan' => $plan])</div>
        <div class="stat"><span class="muted">Client since</span><b>{{ $plan->starts_on->format('M j, Y') }}</b><span class="muted">Reminder {{ $plan->remind_days }} day(s) before</span></div>
    </div>
    @if ($plan->billing_contact || $plan->notes)
        <div class="card" style="margin-top:16px">
            @if ($plan->billing_contact)<p style="margin-top:0"><strong>Billing contact:</strong> {{ $plan->billing_contact }}</p>@endif
            @if ($plan->notes)<p class="muted" style="margin:0;white-space:pre-line">{{ $plan->notes }}</p>@endif
        </div>
    @endif
@else
    <div class="notice warning">No plan yet. Set one up to get payment reminders for this lab.</div>
@endif

@if ($byYear->isNotEmpty())
    <h2>Revenue by year</h2>
    <div class="table-wrap" style="max-width:420px">
    <table>
        <thead><tr><th>Year</th><th>Received</th></tr></thead>
        <tbody>@foreach ($byYear as $y => $sum)<tr><td>{{ $y }}</td><td>{{ $money($sum) }}</td></tr>@endforeach</tbody>
    </table>
    </div>
@endif

<h2>Payments</h2>
<div class="table-wrap">
<table>
    <thead><tr><th>Paid on</th><th>Amount</th><th>Covers</th><th>Method</th><th>Reference</th><th>Recorded by</th><th></th></tr></thead>
    <tbody>
    @forelse ($payments as $p)
        <tr>
            <td style="white-space:nowrap">{{ $p->paid_on->format('M j, Y') }}</td>
            <td><strong>{{ $money($p->amount) }}</strong></td>
            <td>{{ $p->period_start ? $p->period_start->format('M j, Y').' – '.($p->period_end?->format('M j, Y') ?? '…') : '—' }}</td>
            <td>{{ \App\Models\LabPayment::METHODS[$p->method] ?? '—' }}</td>
            <td>{{ $p->reference ?: '—' }}@if ($p->notes)<br><span class="muted">{{ $p->notes }}</span>@endif</td>
            <td>{{ $p->recorder?->display_name ?: $p->recorder?->username ?: '—' }}</td>
            <td style="text-align:right">
                @can('perm', ['accounting', 'delete'])
                    <form method="post" action="{{ route('admin.accounting.payment.destroy', $p) }}" onsubmit="return confirm('Delete this payment?')">
                        @csrf @method('delete')
                        <button class="btn small secondary" type="submit">Delete</button>
                    </form>
                @endcan
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No payments recorded yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
