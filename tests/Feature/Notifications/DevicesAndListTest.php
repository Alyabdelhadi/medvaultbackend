<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Device;
use App\Models\User;
use App\Services\Accounts\ApiTokens;

class DevicesAndListTest extends NotificationsTestCase
{
    public function test_a_token_moves_to_the_account_that_registered_it_last(): void
    {
        [$a, $tokenA] = $this->signedIn();
        $b = User::factory()->create();
        $tokenB = app(ApiTokens::class)->issue($b);

        $this->postJson(self::API.'/device/register', ['device_token' => 'fcm-1', 'platform' => 'iOS'], $this->bearer($tokenA))
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Device registered for notifications.', 'devices' => 1]);
        $this->postJson(self::API.'/device/register', ['device_token' => 'fcm-1', 'platform' => 'ios'], $this->bearer($tokenB))->assertOk();

        $this->assertSame([$b->id], Device::where('token', 'fcm-1')->pluck('user_id')->all());
        $this->assertSame(0, Device::where('user_id', $a->id)->count());
        $this->assertSame('ios', Device::sole()->platform);
    }

    public function test_an_account_keeps_its_ten_newest_devices(): void
    {
        [$user, $token] = $this->signedIn();
        for ($i = 1; $i <= 12; $i++) {
            $this->travel(1)->minutes();
            $this->postJson(self::API.'/device/register', ['device_token' => "fcm-{$i}"], $this->bearer($token));
        }

        $this->assertSame(10, Device::where('user_id', $user->id)->count());
        $this->assertFalse(Device::where('token', 'fcm-1')->exists());
        $this->assertSame('unknown', Device::where('token', 'fcm-12')->value('platform'));
    }

    public function test_unregister_only_touches_this_account(): void
    {
        [$user, $token] = $this->signedIn();
        $other = User::factory()->create();
        Device::create(['user_id' => $other->id, 'token' => 'theirs']);
        Device::create(['user_id' => $user->id, 'token' => 'mine']);

        $this->postJson(self::API.'/device/unregister', ['device_token' => 'theirs'], $this->bearer($token))->assertOk();
        $this->postJson(self::API.'/device/unregister', ['device_token' => 'mine'], $this->bearer($token))
            ->assertExactJson(['success' => true, 'message' => 'Device unregistered.']);

        $this->assertSame(['theirs'], Device::pluck('token')->all());
        $this->postJson(self::API.'/device/register', [], $this->bearer($token))->assertStatus(400)->assertJsonPath('code', 'rest_missing_callback_param');
        $this->postJson(self::API.'/device/register', ['device_token' => ' '], $this->bearer($token))->assertStatus(400)->assertJsonPath('code', 'missing_token');
    }

    public function test_lists_notifications_newest_first_with_the_unread_count(): void
    {
        [$user, $token] = $this->signedIn();
        $other = User::factory()->create();
        $ids = [];
        foreach ([['2026-10-01 09:00:00', false], ['2026-10-03 21:05:00', false], ['2026-10-02 10:00:00', true]] as $i => [$at, $read]) {
            // Ids from the rows, not assumed: MySQL does not reset auto-increment between tests.
            $ids[] = AppNotification::create(['user_id' => $user->id, 'title' => "T{$i}", 'body' => "B{$i}", 'type' => 'result_ready', 'lab_id' => 5, 'request_nb' => (string) (100 + $i), 'is_read' => $read, 'created_at' => $at])->id;
        }
        AppNotification::create(['user_id' => $other->id, 'title' => 'x', 'body' => 'x', 'created_at' => now()]);

        $this->getJson(self::API.'/notifications', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 3)->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0', [
                'id' => $ids[1], 'title' => 'T1', 'body' => 'B1', 'type' => 'result_ready', 'lab_id' => 5, 'request_nb' => '101',
                'is_read' => false, 'created_at' => '2026-10-03 21:05:00', 'date' => 'Oct 3, 2026 • 9:05 PM',
            ])
            ->assertJsonPath('data.2.request_nb', '100');

        $this->getJson(self::API.'/notifications?per_page=1&page=2', $this->bearer($token))->assertJsonPath('data.0.id', $ids[2])->assertJsonCount(1, 'data');
        $this->getJson(self::API.'/notifications?per_page=500', $this->bearer($token))->assertJsonCount(3, 'data');
    }

    public function test_mark_one_or_all_read_counts_only_changed_rows(): void
    {
        [$user, $token] = $this->signedIn();
        $first = AppNotification::create(['user_id' => $user->id, 'body' => 'a', 'created_at' => now()]);
        AppNotification::create(['user_id' => $user->id, 'body' => 'b', 'created_at' => now()]);
        $theirs = AppNotification::create(['user_id' => User::factory()->create()->id, 'body' => 'c', 'created_at' => now()]);

        $this->postJson(self::API.'/notifications/read', ['id' => $first->id], $this->bearer($token))->assertExactJson(['success' => true, 'updated' => 1]);
        $this->postJson(self::API.'/notifications/read', ['id' => $first->id], $this->bearer($token))->assertJsonPath('updated', 0);
        $this->postJson(self::API.'/notifications/read', ['id' => $theirs->id], $this->bearer($token))->assertJsonPath('updated', 0);
        $this->postJson(self::API.'/notifications/read', [], $this->bearer($token))->assertJsonPath('updated', 1);
        $this->assertFalse($theirs->refresh()->is_read);
    }
}
