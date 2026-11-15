<?php

namespace Tests\Feature\Notifications;

use App\Models\HealthDay;
use App\Models\Lab;
use App\Models\PushMessage;
use App\Models\User;
use App\Services\HealthDays\HealthDaySender;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class HealthDaysTest extends NotificationsTestCase
{
    private function at(string $beirut): CarbonImmutable
    {
        return CarbonImmutable::parse($beirut, HealthDaySender::TZ);
    }

    /** @return list<array> the FCM `message` objects sent */
    private function sent(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))
            ->map(fn ($pair) => $pair[0]['message'])->values()->all();
    }

    private function diabetesDay(): HealthDay
    {
        return HealthDay::where('name', 'World Diabetes Day')->firstOrFail();
    }

    public function test_the_calendar_ships_as_drafts_and_sends_nothing(): void
    {
        Http::fake($this->fcmFake());
        $this->assertSame(13, HealthDay::count());
        $this->assertSame(0, HealthDay::where('is_active', true)->count());

        $this->assertSame([], app(HealthDaySender::class)->sendDue($this->at('2026-11-14 10:30')));
        $this->assertSame([], $this->sent());
    }

    public function test_an_active_day_goes_to_each_language_topic_once(): void
    {
        Http::fake($this->fcmFake());
        $day = $this->diabetesDay();
        $day->update(['is_active' => true, 'link_url' => 'https://bekaa.example/offer']);
        $sender = app(HealthDaySender::class);

        $this->assertSame([], $sender->sendDue($this->at('2026-11-14 09:59')));
        $this->assertSame([], $sender->sendDue($this->at('2026-11-13 10:30')));

        $sender->sendDue($this->at('2026-11-14 10:05'));
        $sent = $this->sent();
        $this->assertSame(['health_tips_en', 'health_tips_ar'], array_column($sent, 'topic'));
        $this->assertSame('World Diabetes Day', $sent[0]['notification']['title']);
        $this->assertSame('اليوم العالمي للسكري', $sent[1]['notification']['title']);
        $this->assertSame(['type' => 'health_tip', 'health_day_id' => (string) $day->id, 'link_url' => 'https://bekaa.example/offer'], $sent[0]['data']);
        // Never the topic every install is on.
        $this->assertNotContains('medvault', array_column($sent, 'topic'));

        // The next runs that day, and every run after, send nothing more.
        $sender->sendDue($this->at('2026-11-14 10:10'));
        $sender->sendDue($this->at('2026-11-14 15:00'));
        $this->assertCount(2, $this->sent());
        $this->assertSame(2, PushMessage::where('audience', 'health')->count());

        // Next year it goes again.
        $sender->sendDue($this->at('2027-11-14 10:00'));
        $this->assertCount(4, $this->sent());
    }

    public function test_a_failed_language_is_retried_alone_and_never_at_night(): void
    {
        $failAr = true;
        Http::fake($this->fcmFake(function (Request $r) use (&$failAr) {
            return $failAr && $r['message']['topic'] === 'health_tips_ar'
                ? Http::response(['error' => ['message' => 'Unavailable', 'status' => 'UNAVAILABLE']], 503)
                : Http::response(['name' => 'x']);
        }));
        $this->diabetesDay()->update(['is_active' => true]);
        $sender = app(HealthDaySender::class);

        $sender->sendDue($this->at('2026-11-14 10:00'));
        $failAr = false;
        $sender->sendDue($this->at('2026-11-14 10:05'));

        $this->assertSame(['health_tips_en', 'health_tips_ar', 'health_tips_ar'], array_column($this->sent(), 'topic'));
        $this->assertSame('2026-11-14', $this->diabetesDay()->last_sent_ar->toDateString());

        // A server down all day does not send at night.
        HealthDay::where('name', 'World Heart Day')->update(['is_active' => true]);
        $this->assertSame([], $sender->sendDue($this->at('2026-09-29 20:00')));
    }

    public function test_the_admin_page_adds_checks_and_switches_days(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'function_type' => null]);
        $this->actingAs($admin);

        $this->get('/admin/health-days')->assertOk()->assertSee('World Diabetes Day')->assertSee('None is active yet');

        $form = ['name' => 'Kidney Day', 'month' => 3, 'day' => 12, 'send_time' => '10:00',
            'title_en' => 'World Kidney Day', 'body_en' => 'Check your kidneys.', 'title_ar' => 'يوم الكلى', 'body_ar' => 'افحص كليتيك.'];
        $this->post('/admin/health-days', [...$form, 'day' => 31, 'month' => 4])->assertSessionHasErrors('day');
        $this->post('/admin/health-days', [...$form, 'send_time' => '21:00'])->assertSessionHasErrors('send_time');
        $this->post('/admin/health-days', [...$form, 'link_url' => 'http://insecure.test'])->assertSessionHasErrors('link_url');
        $this->post('/admin/health-days', $form)->assertRedirect('/admin/health-days');

        $kidney = HealthDay::firstWhere('name', 'Kidney Day');
        $this->assertFalse($kidney->is_active);
        $this->post("/admin/health-days/{$kidney->id}/toggle")->assertSessionHas('status');
        $this->assertTrue($kidney->fresh()->is_active);
    }

    public function test_a_lab_account_cannot_reach_the_calendar(): void
    {
        $lab = new Lab;
        $lab->forceFill(['id' => 9, 'title' => 'Verdun', 'slug' => 'verdun', 'status' => 'publish'])->save();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_MANAGER, 'managed_lab_id' => 9]));

        $this->get('/admin/health-days')->assertForbidden();
    }
}
