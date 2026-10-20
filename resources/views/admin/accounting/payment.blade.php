@extends('admin.layout')
@section('title', 'Record payment · '.$lab->title)
@section('content')
<p class="crumbs"><a href="{{ route('admin.accounting.index') }}">Accounting</a> / <a href="{{ route('admin.accounting.show', $lab) }}">{{ $lab->title }}</a> / Record payment</p>
<h1>Record payment: {{ $lab->title }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('admin.accounting.payment.store', $lab) }}" class="card">
    @csrf
    <div class="grid">
        <div>
            <label for="amount">Amount (USD)</label>
            <input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount', $defaults['amount']) }}" required>
        </div>
        <div>
            <label for="paid_on">Paid on</label>
            <input id="paid_on" name="paid_on" type="date" value="{{ old('paid_on', $defaults['paid_on']) }}" required>
        </div>
        <div>
            <label for="period_start">Covers from</label>
            <input id="period_start" name="period_start" type="date" value="{{ old('period_start', $defaults['period_start']) }}">
        </div>
        <div>
            <label for="period_end">Covers until</label>
            <input id="period_end" name="period_end" type="date" value="{{ old('period_end', $defaults['period_end']) }}">
        </div>
        <div>
            <label for="method">Method</label>
            <select id="method" name="method">
                <option value="">—</option>
                @foreach (\App\Models\LabPayment::METHODS as $value => $label)<option value="{{ $value }}" @selected(old('method') === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="reference">Reference</label>
            <input id="reference" name="reference" type="text" value="{{ old('reference') }}" placeholder="Invoice or receipt number">
        </div>
    </div>
    <label for="notes">Notes</label>
    <textarea id="notes" name="notes">{{ old('notes') }}</textarea>
    @if ($plan)
        <label class="check" style="margin-top:14px"><input type="checkbox" name="advance" value="1" @checked(old('advance', true))>
            Move the next due date from {{ $plan->next_due_on->format('M j, Y') }} to {{ $plan->advance($plan->next_due_on)->format('M j, Y') }}</label>
    @endif
    <div class="actions sticky-actions"><button class="btn" type="submit">Record payment</button><a class="btn secondary" href="{{ route('admin.accounting.show', $lab) }}">Cancel</a></div>
</form>
@endsection
