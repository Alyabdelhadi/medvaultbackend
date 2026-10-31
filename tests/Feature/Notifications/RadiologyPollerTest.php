<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Device;
use App\Models\Lab;
use App\Models\LabAccount;
use App\Models\User;
use App\Services\Labs\LabRegistry;
use App\Services\Notifications\ResultPoller;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class RadiologyPollerTest extends NotificationsTestCase
{
    /** @var list<array> current /radiology rows */
    private array $studies = [];

    private function bekaaWithPatient(array $attributes = []): User
    {
        // ready_signal none, as Bekaa is: lab orders are never polled.
        $this->lab(9, 'Bekaa Hospital', ['ready_signal' => 'none', 'radiology_enabled' => true, ...$attributes]);
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'good']);
        Device::create(['user_id' => $user->id, 'token' => 'fcm-nour', 'platform' => 'ios']);

        return $user;
    }

    private function fakeLab(): void
    {
        Http::fake([
            ...$this->fcmFake(),
            '*/radiology*' => fn () => Http::response(['studies' => $this->studies]),
            '*' => fn () => Http::response(['requests' => [['request_nb' => '26L1', 'status' => 'V']]]),
        ]);
    }

    private function poll(): array
    {
        return app(ResultPoller::class)->run(true);
    }

    private static function study(string $nb, ?string $reportedAt = null, ?string $name = null): array
    {
        return ['study_nb' => $nb, 'status' => 'V', 'study_name' => $name, 'pdf_path' => null, 'report_datetime' => $reportedAt];
    }

    public function test_first_poll_seeds_then_a_report_alerts_once(): void
    {
        $user = $this->bekaaWithPatient();
        $this->studies = [self::study('26X1', '2026-04-01 12:00:00'), self::study('26X2')];
        $this->fakeLab();

        $this->assertSame(1, $this->poll()['seeded']);
        $this->assertSame(0, AppNotification::count(), 'a past report must not alert');
        $account = LabAccount::where('user_id', $user->id)->where('lab_id', 9)->first();
        $this->assertSame(['26X1' => '2026-04-01 12:00:00', '26X2' => ''], $account->radiology_seen_status);
        // The order map is left alone: this lab's orders are not polled at all.
        $this->assertNull($account->seen_status);

        $this->studies[1] = self::study('26X2', '2026-10-12 15:00:00', 'CT Chest');
        $this->assertSame(1, $this->poll()['notified']);
        $this->assertSame(['Radiology results for request 26X2 are now available in MedVault.'], AppNotification::pluck('body')->all());
        $this->assertSame(9, AppNotification::first()->lab_id);

        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(1, AppNotification::count());
    }

    /** status "V" on every study is a literal, not a report. */
    public function test_status_alone_never_alerts(): void
    {
        $this->bekaaWithPatient();
        $this->studies = [];
        $this->fakeLab();
        $this->poll();

        $this->studies = [self::study('26X3')];
        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(0, AppNotification::count());
    }

    /** Switching radiology on for a long-seeded lab must not flood. */
    public function test_enabling_radiology_seeds_its_own_map(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'external_token' => 'good', 'seen_status' => ['26L1' => 'V']]);
        $this->studies = [self::study('26X1', '2026-04-01 12:00:00')];
        $this->fakeLab();
        $this->poll();

        Lab::find(5)->forceFill(['radiology_enabled' => true])->save();
        app(LabRegistry::class)->flush();

        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(['26X1' => '2026-04-01 12:00:00'], LabAccount::first()->radiology_seen_status);
    }

    public function test_off_by_default(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['ready_signal' => 'none']);
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'good']);
        Http::fake();

        $this->poll();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/radiology'));
    }

    /** A radiology-only post polls studies and never its /request. */
    public function test_a_radiology_only_post_never_polls_orders(): void
    {
        $user = $this->bekaaWithPatient(['ready_signal' => 'status', 'orders_enabled' => false]);
        $this->studies = [self::study('26X1')];
        $this->fakeLab();

        $this->assertSame(1, $this->poll()['seeded']);
        $this->assertNull(LabAccount::where('user_id', $user->id)->first()->seen_status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/request'));
    }

    /** A separate radiology server is polled with its own token, never the lab's. */
    public function test_a_separate_radiology_host_is_polled_with_its_own_token(): void
    {
        $user = $this->bekaaWithPatient(['radiology_base' => 'https://rad9.test/api/mobile']);
        $this->studies = [self::study('26X1')];
        Http::fake([
            ...$this->fcmFake(),
            'rad9.test/api/mobile/login' => Http::response(['success' => true, 'token' => 'radtok']),
            'rad9.test/api/mobile/radiology*' => fn () => Http::response(['studies' => $this->studies]),
            '*' => Http::response(['requests' => []]),
        ]);

        $this->assertSame(1, $this->poll()['seeded']);
        $this->studies = [self::study('26X1', '2026-10-12 15:00:00', 'CT Chest')];
        $this->assertSame(1, $this->poll()['notified']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'lab9.test/api/mobile/radiology'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'rad9.test') && str_contains($r->url(), 'tcToken=good'));
        $this->assertSame('radtok', LabAccount::where('user_id', $user->id)->first()->radiology_token);
    }
}
