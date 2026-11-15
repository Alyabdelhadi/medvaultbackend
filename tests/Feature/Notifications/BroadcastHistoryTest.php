<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\Device;
use App\Models\PushMessage;
use App\Services\Notifications\Notifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Topic pushes reach the history too, and the iPhone badge is the unread count. */
class BroadcastHistoryTest extends NotificationsTestCase
{
    private function message(string $audience, string $title, ?string $lang = null, string $at = 'now'): PushMessage
    {
        $m = PushMessage::create(['sender_id' => null, 'audience' => $audience, 'lang' => $lang, 'title' => $title, 'body' => "{$title} body", 'recipients' => 0]);
        $m->forceFill(['created_at' => $at === 'now' ? now() : $at])->save();

        return $m;
    }

    public function test_everyone_and_health_days_show_once_in_the_users_language(): void
    {
        [$user, $token] = $this->signedIn();
        $this->travel(1)->minutes();
        $this->message('all', 'App update');
        $this->message('health', 'World Diabetes Day', 'en');
        $this->message('health', 'اليوم العالمي للسكري', 'ar');
        $this->message('lab', 'Lab only');

        $this->getJson(self::API.'/notifications?lang=ar', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 2)->assertJsonPath('unread_count', 2);
        $titles = AppNotification::where('user_id', $user->id)->orderBy('id')->pluck('type', 'title')->all();
        $this->assertSame(['App update' => 'broadcast', 'اليوم العالمي للسكري' => 'health_tip'], $titles);

        // Read and deleted ones stay that way: nothing is written twice.
        $this->postJson(self::API.'/notifications/read', [], $this->bearer($token));
        $id = AppNotification::where('user_id', $user->id)->where('type', 'broadcast')->value('id');
        $this->postJson(self::API.'/notifications/delete', ['id' => $id], $this->bearer($token));
        $this->getJson(self::API.'/notifications?lang=ar', $this->bearer($token))
            ->assertJsonPath('count', 1)->assertJsonPath('unread_count', 0);
        $this->assertSame(2, AppNotification::where('user_id', $user->id)->count());
    }

    public function test_older_builds_get_english_and_nothing_from_before_the_account(): void
    {
        $this->message('all', 'Before you joined', null, '2020-01-01 10:00:00');
        $this->travel(1)->minutes();
        [, $token] = $this->signedIn();
        $this->travel(1)->minutes();
        $this->message('health', 'World Heart Day', 'en');
        $this->message('health', 'اليوم العالمي للقلب', 'ar');

        $this->getJson(self::API.'/notifications', $this->bearer($token))
            ->assertJsonPath('count', 1)->assertJsonPath('data.0.title', 'World Heart Day')->assertJsonPath('data.0.type', 'health_tip');
    }

    public function test_the_badge_is_the_unread_count(): void
    {
        Http::fake($this->fcmFake());
        [$user] = $this->signedIn();
        Device::create(['user_id' => $user->id, 'token' => 'fcm-1']);
        AppNotification::create(['user_id' => $user->id, 'title' => 'old', 'body' => 'x', 'is_read' => false, 'created_at' => now()]);
        AppNotification::create(['user_id' => $user->id, 'title' => 'read', 'body' => 'x', 'is_read' => true, 'created_at' => now()]);

        app(Notifier::class)->notify($user, 'Results ready', 'Tap to view', 'result_ready');

        $sent = Http::recorded(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com'))->first()[0]['message'];
        $this->assertSame(2, $sent['apns']['payload']['aps']['badge']);
    }
}
