<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Device;
use App\Models\LabAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Labs\LabApi;
use App\Services\Notifications\ResultPoller;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PollerTest extends NotificationsTestCase
{
    /** @var array<string, list<array>> lab host => current request rows */
    private array $labRows = [];

    private function verdunWithPatient(): User
    {
        $this->lab(5, 'Verdun Medical Center');
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'external_token' => 'good']);
        Device::create(['user_id' => $user->id, 'token' => 'fcm-nour', 'platform' => 'ios']);

        return $user;
    }

    private function fakeLabs(): void
    {
        Http::fake([
            ...$this->fcmFake(),
            '*' => function (Request $r) {
                $host = parse_url($r->url(), PHP_URL_HOST);

                return Http::response(['requests' => $this->labRows[$host] ?? []]);
            },
        ]);
    }

    private function poll(): array
    {
        return app(ResultPoller::class)->run(true);
    }

    private function fcmMessages(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))
            ->map(fn ($pair) => $pair[0]['message'])->values()->all();
    }

    public function test_first_poll_only_seeds_then_a_transition_alerts_once(): void
    {
        $user = $this->verdunWithPatient();
        $this->labRows['lab5.test'] = [
            ['request_nb' => '1001', 'status' => 'V', 'cat_name' => 'Hematology'],
            ['request_nb' => '1002', 'status' => 'P', 'cat_name' => 'BioChemistry'],
        ];
        $this->fakeLabs();

        $stats = $this->poll();
        $this->assertSame(1, $stats['seeded']);
        $this->assertSame(0, AppNotification::count(), 'history must not alert');
        $this->assertSame(['1001' => 'V', '1002' => 'P'], LabAccount::where('user_id', $user->id)->where('lab_id', 5)->first()->seen_status);

        $this->labRows['lab5.test'][1]['status'] = 'F';
        $this->labRows['lab5.test'][] = ['request_nb' => '1003', 'status' => 'C'];
        $this->labRows['lab5.test'][] = ['request_nb' => '1004', 'status' => 'P'];
        $this->assertSame(3, $this->poll()['notified']);

        $this->assertSame(
            ['BioChemistry results for request 1002 are now available in MedVault.', 'Lab results for request 1003 are now available in MedVault.'],
            AppNotification::where('type', 'result_ready')->orderBy('id')->pluck('body')->all(),
        );
        $this->assertSame(['1004'], AppNotification::where('type', 'new_request')->pluck('request_nb')->all());
        $message = collect($this->fcmMessages())->firstWhere('data.request_nb', '1002');
        $this->assertSame('fcm-nour', $message['token']);
        $this->assertSame(['title' => 'Your results are ready', 'body' => 'BioChemistry results for request 1002 are now available in MedVault.'], $message['notification']);
        $this->assertSame(['type' => 'result_ready', 'request_nb' => '1002', 'lab_id' => '5'], $message['data']);
        $this->assertSame(['payload' => ['aps' => ['sound' => 'default', 'badge' => 1]]], $message['apns']);

        // Nothing changed: nothing sent.
        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(3, AppNotification::count());
    }

    public function test_a_new_pending_order_alerts_once_and_again_when_ready(): void
    {
        $user = $this->verdunWithPatient();
        $this->labRows['lab5.test'] = [['request_nb' => '1001', 'status' => 'V']];
        $this->fakeLabs();
        $this->poll();

        $this->labRows['lab5.test'][] = ['request_nb' => '1002', 'status' => 'P', 'cat_name' => 'Hematology'];
        $this->assertSame(1, $this->poll()['notified']);
        $row = AppNotification::sole();
        $this->assertSame('new_request', $row->type);
        $this->assertSame("A new Hematology request (1002) has been added to MedVault. We'll let you know when the results are ready.", $row->body);
        $message = $this->fcmMessages()[0];
        $this->assertSame('New lab request', $message['notification']['title']);
        $this->assertSame(['type' => 'new_request', 'request_nb' => '1002', 'lab_id' => '5'], $message['data']);

        // Still pending: nothing more.
        $this->assertSame(0, $this->poll()['notified']);

        // Finishing it is a separate alert.
        $this->labRows['lab5.test'][1]['status'] = 'V';
        $this->assertSame(1, $this->poll()['notified']);
        $this->assertSame(['new_request', 'result_ready'], AppNotification::orderBy('id')->pluck('type')->all());
    }

    public function test_an_order_already_ready_when_first_seen_alerts_only_as_ready(): void
    {
        $this->verdunWithPatient();
        $this->labRows['lab5.test'] = [];
        $this->fakeLabs();
        $this->poll();

        $this->labRows['lab5.test'] = [['request_nb' => '2001', 'status' => 'F']];
        $this->assertSame(1, $this->poll()['notified']);
        $this->assertSame(['result_ready'], AppNotification::pluck('type')->all());
    }

    public function test_a_lost_seen_map_does_not_repeat_new_request_alerts(): void
    {
        $user = $this->verdunWithPatient();
        $this->labRows['lab5.test'] = [];
        $this->fakeLabs();
        $this->poll();
        $this->labRows['lab5.test'] = [['request_nb' => '3001', 'status' => 'P']];
        $this->assertSame(1, $this->poll()['notified']);

        // A map that has lost the order (restored, pruned) sees it as new again.
        LabAccount::where('user_id', $user->id)->update(['seen_status' => json_encode([])]);
        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(1, AppNotification::count());
    }

    public function test_a_lost_seen_map_reseeds_and_the_log_still_stops_duplicates(): void
    {
        $user = $this->verdunWithPatient();
        $this->labRows['lab5.test'] = [['request_nb' => '1001', 'status' => 'P']];
        $this->fakeLabs();
        $this->poll();
        $this->labRows['lab5.test'][0]['status'] = 'V';
        $this->assertSame(1, $this->poll()['notified']);

        // The map says "not ready" again, as if it had been restored from an old backup.
        LabAccount::where('user_id', $user->id)->update(['seen_status' => json_encode(['1001' => 'P'])]);
        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(1, AppNotification::count());
    }

    public function test_report_datetime_labs_store_and_compare_the_timestamp(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['ready_signal' => 'report_datetime']);
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'external_token' => 'good']);
        // The status never changes at such a lab; only the timestamp does.
        $this->labRows['lab5.test'] = [['request_nb' => '7', 'status' => 'V', 'report_datetime' => null]];
        $this->fakeLabs();

        $this->poll();
        $this->assertSame(['7' => ''], LabAccount::first()->seen_status);

        $this->labRows['lab5.test'][0]['report_datetime'] = '2026-10-19 14:47:00';
        $this->assertSame(1, $this->poll()['notified']);
        $this->assertSame(['7' => '2026-10-19 14:47:00'], LabAccount::first()->seen_status);
    }

    /**
     * Bekaa's status is a hardcoded 'V': a "ready" alert there would fire at
     * registration. Its orders raise "new request" only, worded without the
     * promise of a ready alert that will never come.
     */
    public function test_a_lab_with_no_ready_signal_alerts_new_orders_but_never_ready(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['ready_signal' => 'none']);
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'good']);
        Device::create(['user_id' => $user->id, 'token' => 'fcm-b', 'platform' => 'ios']);
        $this->labRows['lab9.test'] = [['request_nb' => '1', 'status' => 'V']];
        $this->fakeLabs();

        $this->assertSame(1, $this->poll()['seeded']);

        $this->labRows['lab9.test'][] = ['request_nb' => '2', 'status' => 'V', 'cat_name' => 'Microbiology'];
        $this->assertSame(1, $this->poll()['notified']);
        $row = AppNotification::sole();
        $this->assertSame('new_request', $row->type);
        $this->assertSame('A new Microbiology request (2) has been added to MedVault. Open MedVault to follow it.', $row->body);

        $this->assertSame(0, $this->poll()['notified']);
        $this->assertSame(0, AppNotification::where('type', 'result_ready')->count());
    }

    public function test_request_numbers_collide_across_labs_without_swallowing_alerts(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'external_token' => 'a']);
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'b']);
        $this->labRows = ['lab5.test' => [['request_nb' => '1', 'status' => 'P']], 'lab9.test' => [['request_nb' => '1', 'status' => 'P']]];
        $this->fakeLabs();
        $this->poll();

        $this->labRows['lab5.test'][0]['status'] = 'V';
        $this->labRows['lab9.test'][0]['status'] = 'V';
        $this->assertSame(2, $this->poll()['notified']);

        $this->assertEqualsCanonicalizing([5, 9], AppNotification::pluck('lab_id')->all());
        // With two labs live, the body names the lab.
        $this->assertContains('Lab results for request 1 at Bekaa Hospital are now available in MedVault.', AppNotification::pluck('body')->all());
    }

    public function test_one_lab_down_does_not_stop_the_other(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        $user = $this->patient();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'external_token' => 'a']);
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'b', 'seen_status' => ['3' => 'P']]);
        Http::fake([...$this->fcmFake(), 'lab5.test/*' => Http::response('', 503), 'lab9.test/*' => Http::response(['requests' => [['request_nb' => '3', 'status' => 'V']]])]);

        $stats = $this->poll();

        $this->assertSame(1, $stats['notified']);
        $this->assertSame('Lab API returned HTTP 503.', LabAccount::where('lab_id', 5)->value('poll_error'));
        $this->assertNull(LabAccount::where('lab_id', 9)->value('poll_error'));
    }

    public function test_doctors_parked_accounts_and_tokenless_users_are_not_polled(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $doctor = User::factory()->create(['function_type' => 'D']);
        LabAccount::create(['user_id' => $doctor->id, 'lab_id' => 5, 'external_token' => 't']);
        User::factory()->create();
        $parked = User::factory()->create(['function_type' => null]);
        LabAccount::create(['user_id' => $parked->id, 'lab_id' => 5, 'external_token' => 't', 'auth_failures' => 5]);
        Cache::put(LabApi::retryKey($parked->id, 5), true, now()->addHour()); // this hour's retry already spent
        Http::fake();

        $stats = $this->poll();

        $this->assertSame(1, $stats['total_users']);
        $this->assertSame(1, $stats['unauthorized']);
        Http::assertNothingSent();
    }

    public function test_an_expired_lab_token_is_reissued_by_the_poller(): void
    {
        $user = $this->verdunWithPatient();
        LabAccount::where('user_id', $user->id)->update(['seen_status' => json_encode(['9' => 'P'])]);
        Http::fake([...$this->fcmFake(), '*' => function (Request $r) {
            if (str_contains($r->url(), '/login')) {
                return Http::response(['success' => true, 'token' => 'fresh']);
            }

            return str_contains($r->url(), 'tcToken=fresh')
                ? Http::response(['requests' => [['request_nb' => '9', 'status' => 'V']]])
                : Http::response(['success' => false], 401);
        }]);

        $this->assertSame(1, $this->poll()['notified']);
        $this->assertSame('fresh', LabAccount::first()->external_token);
    }

    public function test_the_cursor_rotates_through_everyone_and_wraps(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['ready_signal' => 'status']);
        foreach (range(1, 5) as $i) {
            $u = User::factory()->create();
            LabAccount::create(['user_id' => $u->id, 'lab_id' => 5, 'external_token' => 't']);
        }
        Setting::put('poll_batch_size', 2);
        Http::fake(['*' => Http::response(['requests' => []])]);

        $offsets = [];
        foreach (range(1, 4) as $_) {
            $offsets[] = $this->poll()['offset'];
        }

        $this->assertSame([0, 2, 4, 0], $offsets);
    }

    public function test_overlapping_runs_are_refused_and_disabled_polling_waits_for_force(): void
    {
        $lock = Cache::lock(ResultPoller::LOCK, 600);
        $lock->get();
        $this->assertSame(['skipped' => true, 'message' => 'A poll is already running.'], $this->poll());
        $lock->release();

        Setting::put('poll_enabled', false);
        $this->assertSame('Polling is disabled.', app(ResultPoller::class)->run()['message']);
        $this->artisan('medvault:poll-results')->assertSuccessful();
        $this->assertNull(Setting::get('poll_last_run'));
        $this->artisan('medvault:poll-results --force')->expectsOutputToContain('Polled 0 of 0 users')->assertSuccessful();
    }

    public function test_the_interval_decides_when_the_scheduled_run_is_due(): void
    {
        Setting::put('poll_minutes', 10);
        $this->assertTrue(ResultPoller::isDue());
        Setting::put('poll_last_run', ['started_at' => now()->subMinutes(3)->format('Y-m-d H:i:s')]);
        $this->assertFalse(ResultPoller::isDue());
        $this->travel(7)->minutes();
        $this->assertTrue(ResultPoller::isDue());
    }

    public function test_old_notifications_are_pruned_at_the_start_of_a_cycle(): void
    {
        $user = $this->patient();
        AppNotification::create(['user_id' => $user->id, 'body' => 'old', 'created_at' => now()->subDays(91)]);
        AppNotification::create(['user_id' => $user->id, 'body' => 'new', 'created_at' => now()->subDays(89)]);
        Http::fake();

        $this->poll();

        $this->assertSame(['new'], AppNotification::pluck('body')->all());
    }
}
