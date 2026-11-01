<?php

namespace Tests\Feature\Api;

use App\Models\AppSession;
use App\Models\Device;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Several signed-in devices per account, listed and signed out from the app. */
class SessionsTest extends ApiTestCase
{
    private function login(array $device = []): string
    {
        return $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2', ...$device])
            ->assertOk()->json('data.token');
    }

    public function test_signing_in_on_a_second_phone_keeps_the_first_signed_in(): void
    {
        $this->patient();
        Http::fake();

        $first = $this->login(['device_name' => 'iPhone 15', 'platform' => 'ios', 'app_version' => '12.0.2 (15)']);
        $second = $this->login(['device_name' => 'Pixel 8', 'platform' => 'android']);

        $this->getJson(self::API.'/profile', $this->bearer($first))->assertOk();
        $this->getJson(self::API.'/profile', $this->bearer($second))->assertOk();

        $this->getJson(self::API.'/sessions', $this->bearer($second))->assertOk()
            ->assertJsonPath('count', 2)
            // This device first.
            ->assertJsonPath('data.0.device_name', 'Pixel 8')
            ->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.1.device_name', 'iPhone 15')
            ->assertJsonPath('data.1.platform', 'ios')
            ->assertJsonPath('data.1.app_version', '12.0.2 (15)')
            ->assertJsonPath('data.1.current', false)
            ->assertJsonMissingPath('data.0.token_hash');
    }

    public function test_one_device_can_sign_another_out_but_not_someone_elses(): void
    {
        $this->patient();
        Http::fake();
        $first = $this->login(['device_name' => 'iPhone 15']);
        $second = $this->login(['device_name' => 'Pixel 8']);
        $firstId = AppSession::where('device_name', 'iPhone 15')->value('id');

        $stranger = User::factory()->create();
        $theirs = app(ApiTokens::class)->issue($stranger);
        $theirId = AppSession::where('user_id', $stranger->id)->value('id');

        $this->postJson(self::API.'/sessions/revoke', ['id' => $theirId], $this->bearer($second))->assertJsonPath('deleted', 0);
        $this->getJson(self::API.'/profile', $this->bearer($theirs))->assertOk();

        // This device signs itself out with Log out, not from the list.
        $secondId = AppSession::where('device_name', 'Pixel 8')->value('id');
        $this->postJson(self::API.'/sessions/revoke', ['id' => $secondId], $this->bearer($second))->assertStatus(400);

        $this->postJson(self::API.'/sessions/revoke', ['id' => $firstId], $this->bearer($second))->assertJsonPath('deleted', 1);
        $this->getJson(self::API.'/profile', $this->bearer($first))->assertStatus(401);
        $this->getJson(self::API.'/profile', $this->bearer($second))->assertOk();
    }

    public function test_sign_out_others_and_log_out(): void
    {
        $this->patient();
        Http::fake();
        $a = $this->login();
        $b = $this->login();
        $c = $this->login();

        $this->postJson(self::API.'/sessions/revoke-others', [], $this->bearer($c))->assertJsonPath('deleted', 2);
        $this->getJson(self::API.'/profile', $this->bearer($a))->assertStatus(401);
        $this->getJson(self::API.'/profile', $this->bearer($b))->assertStatus(401);

        $this->postJson(self::API.'/logout', [], $this->bearer($c))->assertOk();
        $this->getJson(self::API.'/profile', $this->bearer($c))->assertStatus(401);
    }

    public function test_a_token_from_before_sessions_keeps_working_and_becomes_one(): void
    {
        $user = $this->patient();
        $legacy = str_repeat('ab', 32);
        $user->forceFill(['api_token_hash' => hash('sha256', $legacy)])->save();

        $this->getJson(self::API.'/profile', $this->bearer($legacy))->assertOk();
        $this->assertNull($user->fresh()->api_token_hash);
        $this->assertSame(1, AppSession::where('user_id', $user->id)->count());
        $this->getJson(self::API.'/sessions', $this->bearer($legacy))->assertJsonPath('data.0.current', true);
    }

    public function test_an_account_keeps_its_ten_most_recent_sessions(): void
    {
        $user = $this->patient();
        $tokens = app(ApiTokens::class);
        $oldest = $tokens->issue($user);
        AppSession::query()->update(['last_used_at' => now()->subDay()]);
        for ($i = 0; $i < ApiTokens::MAX_SESSIONS; $i++) {
            $tokens->issue($user);
        }

        $this->assertSame(ApiTokens::MAX_SESSIONS, AppSession::where('user_id', $user->id)->count());
        $this->getJson(self::API.'/profile', $this->bearer($oldest))->assertStatus(401);
    }

    public function test_a_doctor_can_sign_up_with_several_labs(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        Http::fake(['*' => Http::response(['success' => false])]);

        $this->postJson(self::API.'/dg/register', [
            'first_name' => 'Sami', 'last_name' => 'Khoury', 'phone_number' => '71234567',
            'email' => 'sami@clinic.test', 'password' => 'secret12', 'function_type' => 'D',
            // 404 is not a lab: dropped.
            'lab_ids' => [9, 5, 404],
        ])->assertOk()->assertJsonPath('data.lab_id', 9);

        $user = User::where('username', 'samikhoury')->firstOrFail();
        $this->assertSame(9, $user->dg_lab_id);
        $this->assertEqualsCanonicalizing([5, 9], DB::table('user_labs')->where('user_id', $user->id)->pluck('lab_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_removing_a_device_also_stops_its_result_alerts(): void
    {
        $this->patient();
        Http::fake();
        $phoneA = $this->login(['device_name' => 'iPhone 15']);
        $phoneB = $this->login(['device_name' => 'Pixel 8']);
        $this->postJson(self::API.'/device/register', ['device_token' => 'fcm-a', 'platform' => 'ios'], $this->bearer($phoneA))->assertOk();
        $this->postJson(self::API.'/device/register', ['device_token' => 'fcm-b', 'platform' => 'android'], $this->bearer($phoneB))->assertOk();

        $this->postJson(self::API.'/sessions/revoke', ['id' => AppSession::where('device_name', 'iPhone 15')->value('id')], $this->bearer($phoneB))
            ->assertJsonPath('deleted', 1);

        $this->assertSame(['fcm-b'], Device::pluck('token')->all());

        // Logging out removes this phone's own registration the same way.
        $this->postJson(self::API.'/logout', [], $this->bearer($phoneB))->assertOk();
        $this->assertSame(0, Device::count());
    }
}
