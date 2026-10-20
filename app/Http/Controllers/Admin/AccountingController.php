<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lab;
use App\Models\LabPayment;
use App\Models\LabPlan;
use App\Services\Admin\AdminStats;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * What each lab pays us. A lab has one plan (an amount, monthly or yearly,
 * and the date the next payment is due) and any number of payments.
 * Recording a payment moves the due date one period on; a plan whose due
 * date is inside its reminder window shows up here, on the dashboard and as
 * a badge in the sidebar until it is paid.
 */
class AccountingController extends Controller
{
    public function index(Request $request): View
    {
        $year = (int) $request->query('year', now()->year);
        $labs = Lab::where('status', '!=', 'trash')->with('plan')->orderBy('title')->get();
        $payments = LabPayment::whereYear('paid_on', $year)->get(['lab_id', 'paid_on', 'amount']);
        $active = $labs->pluck('plan')->filter(fn (?LabPlan $p) => $p?->active);

        $years = LabPayment::pluck('paid_on')->map(fn ($d) => $d->year)->push((int) now()->year, $year)->unique()->sortDesc()->values();

        return view('admin.accounting.index', [
            'year' => $year,
            'years' => $years,
            'labs' => $labs,
            'receivedByLab' => $payments->groupBy('lab_id')->map->sum('amount'),
            'summary' => [
                'Expected per year' => $active->sum(fn (LabPlan $p) => $p->annualValue()),
                "Received in {$year}" => (float) $payments->sum('amount'),
                'Received in '.($year - 1) => (float) LabPayment::whereYear('paid_on', $year - 1)->sum('amount'),
                'Labs on a plan' => $active->count(),
            ],
            'due' => LabPlan::needingAttention(),
            'revenue' => AdminStats::monthsOfYear($payments, 'paid_on', 'amount', $year),
        ]);
    }

    public function show(Lab $lab): View
    {
        $payments = $lab->payments()->with('recorder:id,display_name,username')->orderByDesc('paid_on')->orderByDesc('id')->get();

        return view('admin.accounting.show', [
            'lab' => $lab,
            'plan' => $lab->plan,
            'payments' => $payments,
            'byYear' => $payments->groupBy(fn (LabPayment $p) => $p->paid_on->year)->map->sum('amount')->sortKeysDesc(),
        ]);
    }

    public function editPlan(Lab $lab): View
    {
        return view('admin.accounting.plan', [
            'lab' => $lab,
            'plan' => $lab->plan ?? new LabPlan(['cycle' => 'monthly', 'remind_days' => 7, 'active' => true, 'starts_on' => CarbonImmutable::today()]),
        ]);
    }

    public function savePlan(Request $request, Lab $lab): RedirectResponse
    {
        $data = $request->validate([
            'cycle' => ['required', Rule::in(array_keys(LabPlan::CYCLES))],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'starts_on' => ['required', 'date'],
            'next_due_on' => ['nullable', 'date'],
            'remind_days' => ['required', 'integer', 'min:0', 'max:120'],
            'billing_contact' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['next_due_on'] ??= $data['starts_on'];

        LabPlan::updateOrCreate(['lab_id' => $lab->id], $data);

        return redirect()->route('admin.accounting.show', $lab)->with('status', "Plan saved for {$lab->title}.");
    }

    public function newPayment(Lab $lab): View|RedirectResponse
    {
        $plan = $lab->plan;
        $start = $plan?->next_due_on;

        return view('admin.accounting.payment', [
            'lab' => $lab,
            'plan' => $plan,
            'defaults' => [
                'amount' => $plan?->amount,
                'paid_on' => CarbonImmutable::today()->toDateString(),
                'period_start' => $start?->toDateString(),
                'period_end' => $start ? $plan->advance($start)->subDay()->toDateString() : null,
            ],
        ]);
    }

    public function storePayment(Request $request, Lab $lab): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999'],
            'paid_on' => ['required', 'date'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'method' => ['nullable', Rule::in(array_keys(LabPayment::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $lab->payments()->create([...$data, 'recorded_by' => $request->user()->id]);

        $message = 'Payment of $'.number_format((float) $data['amount'], 2)." recorded for {$lab->title}.";
        $plan = $lab->plan;
        if ($plan !== null && $request->boolean('advance')) {
            $plan->update(['next_due_on' => $plan->advance($plan->next_due_on)]);
            $message .= ' Next payment due '.$plan->next_due_on->format('F j, Y').'.';
        }

        return redirect()->route('admin.accounting.show', $lab)->with('status', $message);
    }

    public function destroyPayment(LabPayment $payment): RedirectResponse
    {
        $lab = $payment->lab;
        $payment->delete();

        return redirect()->route('admin.accounting.show', $lab)->with('status', 'Payment deleted. The next due date was not changed; edit the plan if it should move back.');
    }
}
