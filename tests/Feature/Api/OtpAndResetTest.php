<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\LabAccount;
use App\Services\Otp\OtpService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OtpAndResetTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['medvault.sms.api_key' => 'k', 'medvault.sms.api_secret' => 's']);
    }

    private function sentCode(): string
    {
        $code = null;
        Http::assertSent(function (Request $r) use (&$code) {
            $code = $r['variables'];

            return true;
        });

        return (string) $code;
    }

    public function test_sends_a_code_to_the_gateway_in_international_form(): void
    {
        Http::fake(['bestsmsbulk.com/*' => Http::response('123;96103123456;1')]);

        $this->postJson(self::API.'/otp/send', ['mobile_number' => '03 123 456'])
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Verification code sent.']);

        Http::assertSent(fn (Request $r) => $r['destination'] === '+96103123456' && $r['template'] === 'otp_1' && preg_match('/^\d{6}$/', $r['variables']));
    }

    public function test_the_fourth_request_in_ten_minutes_is_refused(): void
    {
        Http::fake(['*' => Http::response('1;2;1')]);

        foreach (['70918679', '+96170918679', '070918679'] as $spelling) {
            $this->postJson(self::API.'/otp/send', ['mobile_number' => $spelling])->assertOk();
        }
        $this->postJson(self::API.'/otp/send', ['mobile_number' => '96170918679'])
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    }

    public function test_a_gateway_failure_reports_the_detail_and_leaves_no_code(): void
    {
        Http::fake(['*' => Http::response('ERR invalid destination', 200)]);

        $this->postJson(self::API.'/otp/send', ['mobile_number' => '70918679'])
            ->assertStatus(502)
            ->assertJsonPath('code', 'sms_failed')
            ->assertJsonPath('message', 'Failed to send verification SMS: HTTP 200: ERR invalid destination');

        $this->assertNull(Cache::get(OtpService::codeKey('96170918679')));
    }

    public function test_reset_purpose_checks_the_account_before_paying_for_a_message(): void
    {
        Http::fake();

        $this->postJson(self::API.'/otp/send', ['mobile_number' => '70918679', 'purpose' => 'reset'])
            ->assertStatus(404)->assertJsonPath('code', 'user_not_found');
        Http::assertNothingSent();
    }

    public function test_verify_consumes_the_code_across_spellings(): void
    {
        Http::fake(['*' => Http::response('1;2;1')]);
        $this->postJson(self::API.'/otp/send', ['mobile_number' => '+96170918679'])->assertOk();
        $code = $this->sentCode();

        $this->postJson(self::API.'/otp/verify', ['mobile_number' => '70918679', 'otp' => '000000'])
            ->assertStatus(400)->assertJsonPath('code', 'otp_invalid');
        $this->postJson(self::API.'/otp/verify', ['mobile_number' => '70918679', 'otp' => $code])
            ->assertOk()->assertJsonPath('message', 'Phone number verified.');
        $this->postJson(self::API.'/otp/verify', ['mobile_number' => '70918679', 'otp' => $code])
            ->assertStatus(400)->assertJsonPath('code', 'otp_expired');
    }

    public function test_password_reset_ends_sessions_devices_and_lab_parking(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        [$user, $token] = $this->signedIn();
        Device::create(['user_id' => $user->id, 'token' => 'fcm-1', 'platform' => 'ios']);
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'auth_failures' => 5]);
        Http::fake(['bestsmsbulk.com/*' => Http::response('1;2;1'), '*' => Http::response(['success' => false], 401)]);

        $this->postJson(self::API.'/otp/send', ['mobile_number' => '70918679', 'purpose' => 'reset'])->assertOk();
        $code = $this->sentCode();

        $this->postJson(self::API.'/password/reset', ['mobile_number' => '+96170918679', 'otp' => $code, 'new_password' => 'fresh123'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Password reset successfully. Please log in with your new password.']);

        $this->getJson(self::API.'/profile', $this->bearer($token))->assertStatus(401);
        $this->assertSame(0, Device::count());
        $this->assertSame(0, LabAccount::where('lab_id', 5)->value('auth_failures'));
        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'fresh123'])->assertOk();
        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])->assertStatus(401);
    }

    public function test_password_reset_without_a_code(): void
    {
        $this->patient();

        $this->postJson(self::API.'/password/reset', ['mobile_number' => '70918679', 'otp' => '123456', 'new_password' => 'fresh123'])
            ->assertStatus(400)->assertJsonPath('code', 'otp_expired');
        $this->postJson(self::API.'/password/reset', ['mobile_number' => '70918679', 'otp' => '123456', 'new_password' => '123'])
            ->assertStatus(400)->assertJsonPath('code', 'invalid_password');
    }
}
