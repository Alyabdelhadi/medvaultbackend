@extends('admin.layout')
@section('title', 'Plan · '.$lab->title)
@section('content')
<p class="crumbs"><a href="{{ route('admin.accounting.index') }}">Accounting</a> / <a href="{{ route('admin.accounting.show', $lab) }}">{{ $lab->title }}</a> / Plan</p>
<h1>{{ $plan->exists ? 'Edit plan' : 'Set up plan' }}: {{ $lab->title }}</h1>
@if ($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('admin.accounting.plan.save', $lab) }}" class="card">
    @csrf @method('put')
    <div class="grid">
        <div>
            <label for="cycle">Billing</label>
            <select id="cycle" name="cycle">
                @foreach (\App\Models\LabPlan::CYCLES as $value => $label)<option value="{{ $value }}" @selected(old('cycle', $plan->cycle) === $value)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="amount">Amount per period (USD)</label>
            <input id="amount" name="amount" type="number" step="0.01" min="0" value="{{ old('amount', $plan->amount) }}" required>
        </div>
        <div>
            <label for="starts_on">Start date</label>
            <input id="starts_on" name="starts_on" type="date" value="{{ old('starts_on', $plan->starts_on?->toDateString()) }}" required>
        </div>
        <div>
            <label for="next_due_on">Next payment due</label>
            <input id="next_due_on" name="next_due_on" type="date" value="{{ old('next_due_on', $plan->next_due_on?->toDateString()) }}">
            <p class="hint">Empty means the start date. Recording a payment moves it on by one month or one year.</p>
        </div>
        <div>
            <label for="remind_days">Remind me this many days before</label>
            <input id="remind_days" name="remind_days" type="number" min="0" max="120" value="{{ old('remind_days', $plan->remind_days) }}" required>
        </div>
        <div>
            <label for="billing_contact">Billing contact</label>
            <input id="billing_contact" name="billing_contact" type="text" value="{{ old('billing_contact', $plan->billing_contact) }}" placeholder="Name, phone or email">
        </div>
    </div>
    <label for="notes">Notes</label>
    <textarea id="notes" name="notes">{{ old('notes', $plan->notes) }}</textarea>
    <label class="check" style="margin-top:14px"><input type="checkbox" name="active" value="1" @checked(old('active', $plan->active ?? true))> Active (paused plans get no reminders and do not count in expected revenue)</label>
    <div class="actions"><button class="btn" type="submit">Save plan</button><a class="btn secondary" href="{{ route('admin.accounting.show', $lab) }}">Cancel</a></div>
</form>
@endsection
