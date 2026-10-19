<?php

namespace Tests\Feature\Notifications;

use App\Models\Device;
use App\Models\Setting;
use App\Services\Notifications\Fcm;
use App\Services\Notifications\Notifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class FcmTest extends NotificationsTestCase
{
    public function test_signs_a_service_account_jwt_and_caches_the_access_token(): void
    {
        Http::fake($this->fcmFake());

        app(Fcm::class)->sendToTopic('Hello', 'World');
        app(Fcm::class)->sendToTopic('Again', 'World');

        $oauth = Http::recorded(fn (Request $r) => str_contains($r->url(), 'oauth2'))->values();
        $this->assertCount(1, $oauth);
        [$header, $claims, $signature] = explode('.', $oauth[0][0]['assertion']);
        $decode = fn ($s) => json_decode(base64_decode(strtr($s, '-_', '+/')), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $decode($header));
        $this->assertSame('push@medvault-test.iam.gserviceaccount.com', $decode($claims)['iss']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $decode($claims)['scope']);
        $public = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey))['key'];
        $this->assertSame(1, openssl_verify("$header.$claims", base64_decode(strtr($signature, '-_', '+/')), $public, OPENSSL_ALGO_SHA256));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/medvault-test/messages:send'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['topic'] === 'medvault'
            && $r['message']['android'] === ['priority' => 'high', 'notification' => ['channel_id' => 'medvault_notifications']]);
    }

    /** Only a gone install is pruned; a payload bug must not wipe every device. */
    public function test_prunes_dead_installs_but_not_on_invalid_argument(): void
    {
        $user = $this->patient();
        foreach (['gone', 'missing', 'bad-payload', 'fine'] as $token) {
            Device::create(['user_id' => $user->id, 'token' => $token]);
        }
        Http::fake($this->fcmFake(function (Request $r) {
            return match ($r['message']['token']) {
                'gone' => Http::response(['error' => ['status' => 'UNREGISTERED', 'message' => 'Requested entity was not found.']], 404),
                'missing' => Http::response(['error' => ['status' => 'NOT_FOUND', 'message' => 'not found']], 404),
                'bad-payload' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload']], 400),
                default => Http::response(['name' => 'ok']),
            };
        }));

        $result = app(Notifier::class)->push($user, 'T', 'B', ['n' => 5]);

        $this->assertSame(['success' => true, 'sent' => 1, 'message' => 'Sent to 1 device(s).'], $result);
        $this->assertEqualsCanonicalizing(['bad-payload', 'fine'], Device::pluck('token')->all());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm') && ($r['message']['data'] ?? null) === ['n' => '5']);
    }

    public function test_missing_credentials_fail_cleanly(): void
    {
        Setting::query()->delete();
        Http::fake();

        $this->assertSame(
            ['success' => false, 'message' => 'FCM error (0): Firebase project ID missing. Check your credentials JSON.'],
            app(Fcm::class)->sendToTopic('T', 'B'),
        );
        $this->assertSame('No registered devices for this user.', app(Notifier::class)->push($this->patient(), 'T', 'B')['message']);
        Http::assertNothingSent();
    }
}
