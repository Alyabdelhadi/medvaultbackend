<?php

namespace Tests\Feature\Admin;

use App\Models\LabPayment;
use App\Models\LabPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

class AccountingTest extends AdminTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function today(string $date): void
    {
        Carbon::setTestNow($date);
        CarbonImmutable::setTestNow($date);
    }

    public function test_a_payment_moves_the_due_date_one_period_without_overflowing(): void
    {
        $this->today('2026-01-20');
        $lab = $this->lab(9, 'Bekaa Hospital');
        $this->actingAs($this->admin());

        $this->put("/admin/accounting/labs/{$lab->id}/plan", ['cycle' => 'monthly', 'amount' => '150', 'starts_on' => '2026-01-31', 'remind_days' => 7, 'active' => '1'])
            ->assertRedirect("/admin/accounting/labs/{$lab->id}");
        $plan = LabPlan::firstWhere('lab_id', $lab->id);
        $this->assertSame('2026-01-31', $plan->next_due_on->toDateString());
        $this->assertSame(1800.0, $plan->annualValue());

        $this->get("/admin/accounting/labs/{$lab->id}/payments/new")->assertOk()->assertSee('2026-01-31')->assertSee('2026-02-27');
        $this->post("/admin/accounting/labs/{$lab->id}/payments", ['amount' => '150', 'paid_on' => '2026-01-30', 'period_start' => '2026-01-31', 'period_end' => '2026-02-27', 'method' => 'cash', 'advance' => '1'])
            ->assertRedirect("/admin/accounting/labs/{$lab->id}");
        // January 31 plus a month is February 28, not March 3.
        $this->assertSame('2026-02-28', $plan->refresh()->next_due_on->toDateString());

        // Without the tick the due date stays.
        $this->post("/admin/accounting/labs/{$lab->id}/payments", ['amount' => '20', 'paid_on' => '2026-01-30']);
        $this->assertSame('2026-02-28', $plan->refresh()->next_due_on->toDateString());
        $this->assertSame(2, LabPayment::count());

        $plan->update(['cycle' => 'yearly', 'amount' => 1200]);
        $this->post("/admin/accounting/labs/{$lab->id}/payments", ['amount' => '1200', 'paid_on' => '2026-02-01', 'advance' => '1']);
        $this->assertSame('2027-02-28', $plan->refresh()->next_due_on->toDateString());
        $this->assertSame(1200.0, $plan->annualValue());
    }

    public function test_reminders_open_inside_the_window_and_show_everywhere_until_paid(): void
    {
        $this->today('2026-03-10');
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(3, 'Hammoud Hospital');
        LabPlan::create(['lab_id' => 9, 'cycle' => 'monthly', 'amount' => 100, 'starts_on' => '2026-01-01', 'next_due_on' => '2026-03-15', 'remind_days' => 7]);
        LabPlan::create(['lab_id' => 5, 'cycle' => 'yearly', 'amount' => 1000, 'starts_on' => '2025-03-01', 'next_due_on' => '2026-03-01', 'remind_days' => 14]);
        LabPlan::create(['lab_id' => 3, 'cycle' => 'monthly', 'amount' => 50, 'starts_on' => '2026-01-01', 'next_due_on' => '2026-04-01', 'remind_days' => 7]);

        $states = LabPlan::orderBy('lab_id')->get()->mapWithKeys(fn (LabPlan $p) => [$p->lab_id => $p->state()])->all();
        $this->assertSame([3 => 'ok', 5 => 'overdue', 9 => 'due'], $states);
        $this->assertSame([5, 9], LabPlan::needingAttention()->pluck('lab_id')->all());

        $this->actingAs($this->admin());
        $this->get('/admin/accounting')->assertOk()->assertSee('Overdue by 9 day(s)')->assertSee('Due in 5 day(s)')
            ->assertSee('<span class="mv-badge" title="Payments due">2</span>', false);
        $this->get('/admin/dashboard')->assertSee('Payments due')->assertSee('Overdue by 9 day(s)')->assertSee('Verdun Medical Center');

        LabPlan::firstWhere('lab_id', 5)->update(['active' => false]);
        $this->assertSame([9], LabPlan::needingAttention()->pluck('lab_id')->all());
    }

    public function test_revenue_is_summed_by_year_and_expected_from_active_plans(): void
    {
        $this->today('2026-06-01');
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(5, 'Verdun Medical Center');
        LabPlan::create(['lab_id' => 9, 'cycle' => 'monthly', 'amount' => 100, 'starts_on' => '2026-01-01', 'next_due_on' => '2026-07-01']);
        LabPlan::create(['lab_id' => 5, 'cycle' => 'yearly', 'amount' => 1000, 'starts_on' => '2026-01-01', 'next_due_on' => '2027-01-01']);
        foreach ([['2026-01-05', 100, 9], ['2026-02-05', 100, 9], ['2026-01-02', 1000, 5], ['2025-12-20', 900, 5]] as [$on, $amount, $lab]) {
            LabPayment::create(['lab_id' => $lab, 'amount' => $amount, 'paid_on' => $on]);
        }
        $this->actingAs($this->admin());

        $this->get('/admin/accounting')->assertOk()
            ->assertSeeInOrder(['Expected per year', '$2,200.00', 'Received in 2026', '$1,200.00', 'Received in 2025', '$900.00'])
            ->assertSee('Revenue received in 2026, by month');
        $this->get('/admin/accounting?year=2025')->assertSeeInOrder(['Received in 2025', '$900.00']);
        $this->get('/admin/accounting/labs/5')->assertOk()->assertSeeInOrder(['2026', '$1,000.00', '2025', '$900.00']);

        $payment = LabPayment::firstWhere('amount', 900);
        $this->delete("/admin/accounting/payments/{$payment->id}")->assertRedirect('/admin/accounting/labs/5');
        $this->assertNull($payment->fresh());
    }
}
