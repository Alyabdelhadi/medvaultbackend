<?php

namespace Tests\Feature\Api;

use App\Models\LabAccount;
use App\Services\Labs\LabTokenService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class LoginTest extends ApiTestCase
{
    public function test_login_with_an_imported_wordpress_password_returns_the_old_shape(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $user = $this->patient();
        Http::fake(['lab5.test/*' => Http::response(['success' => true, 'token' => 'lab-tok', 'user' => '70918679', 'Lab' => 'Verdun Medical Center'])]);

        $response = $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user_id', 217)
            ->assertJsonPath('data.username', 'nouralhariri')
            ->assertJsonPath('data.email', '')
            ->assertJsonPath('data.mobile_number', '+96170918679')
            ->assertJsonPath('data.dob', null)
            ->assertJsonPath('data.function_type', 'P')
            ->assertJsonPath('data.ai_summary_enabled', true)
            ->assertJsonPath('data.lab_token', 'lab-tok')
            ->assertJsonPath('data.lab_name', 'Verdun Medical Center')
            ->assertJsonPath('data.lab_id', 5)
            ->assertJsonPath('data.labs.0.caps', ['ranges' => true, 'flags' => true, 'pdf' => true, 'ai' => false]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $response->json('data.token'));

        // The WordPress hash is replaced on first sign-in.
        $this->assertStringStartsWith('$2y$', $user->refresh()->password);
        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])->assertOk();

        // The lab got the documented login: JSON, username as a string, type, no password.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://lab5.test/api/mobile/login'
            && $r->method() === 'POST'
            && $r->body() === '{"username":"70918679","type":"P"}'
            && $r->hasHeader('Content-Type', 'application/json'));

        $this->assertSame('lab-tok', LabAccount::where('user_id', 217)->where('lab_id', 5)->first()->external_token);
    }

    public static function spellings(): array
    {
        return [['70918679'], ['070918679'], ['+96170918679'], ['0096170918679'], ['96170918679'], ['+961 70 918 679']];
    }

    #[DataProvider('spellings')]
    public function test_any_spelling_of_the_number_signs_in(string $spelling): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->patient();
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401)]);

        $this->postJson(self::API.'/login', ['mobile_number' => $spelling, 'password' => 'medvault2'])
            ->assertOk()->assertJsonPath('data.user_id', 217);
    }

    /** The case from 2026-10-19: the account exists, no lab knows the number. */
    public function test_a_number_no_lab_knows_still_signs_in_with_no_lab_token(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->patient();
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401)]);

        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])
            ->assertOk()
            ->assertJsonPath('data.lab_token', null)
            ->assertJsonPath('data.lab_id', null)
            ->assertJsonPath('data.labs', []);

        $this->assertSame(1, LabAccount::where('user_id', 217)->where('lab_id', 5)->value('auth_failures'));
    }

    public function test_wrong_password_and_unknown_number_look_the_same(): void
    {
        $this->patient();

        foreach ([['70918679', 'nope123'], ['71000000', 'medvault2']] as [$mobile, $password]) {
            $this->postJson(self::API.'/login', ['mobile_number' => $mobile, 'password' => $password])
                ->assertStatus(401)
                ->assertExactJson(['code' => 'invalid_credentials', 'message' => 'Invalid mobile number or password.', 'data' => ['status' => 401]]);
        }
    }

    public function test_missing_and_empty_parameters_fail_like_wordpress(): void
    {
        $this->postJson(self::API.'/login', ['mobile_number' => '70918679'])
            ->assertStatus(400)
            ->assertExactJson([
                'code' => 'rest_missing_callback_param',
                'message' => 'Missing parameter(s): password',
                'data' => ['status' => 400, 'params' => ['password']],
            ]);

        $this->postJson(self::API.'/login', ['mobile_number' => '', 'password' => 'x'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'missing_fields');
    }

    public function test_wrong_method_is_rest_no_route(): void
    {
        $this->getJson(self::API.'/login')
            ->assertStatus(404)
            ->assertExactJson(['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]]);
        $this->getJson(self::API.'/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'rest_no_route');
    }

    public function test_login_fans_out_and_the_lowest_priority_lab_is_primary(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['api_priority' => 20]);
        $this->lab(9, 'Bekaa Hospital', ['api_priority' => 10, 'mobile_format' => 'e164', 'api_headers' => 'X-Api-Key: k9']);
        $this->patient();
        Http::fake([
            'lab5.test/*' => Http::response(['success' => true, 'token' => 't5']),
            'lab9.test/*' => Http::response(['success' => true, 'token' => 't9']),
        ]);

        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])
            ->assertOk()
            ->assertJsonPath('data.lab_id', 9)
            ->assertJsonPath('data.labs.1.lab_id', 5);

        // Per-lab username shape and per-lab headers; the headers never reach the app.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'lab9') && $r->body() === '{"username":"96170918679","type":"P"}' && $r->hasHeader('X-Api-Key', 'k9'));
    }

    public function test_a_saved_priority_of_zero_still_means_ten(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['api_priority' => 0, 'created_at' => now()->subDay()]);
        $this->lab(9, 'Bekaa Hospital', ['api_priority' => 5]);
        $this->patient();
        Http::fake(['*' => Http::response(['success' => true, 'token' => 't'])]);

        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])
            ->assertJsonPath('data.lab_id', 9);
    }

    public function test_with_no_lab_configured_the_legacy_verdun_account_is_used(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['api_enabled' => false]);
        $this->patient();
        Http::fake(['verdunmedicalcenter.net/*' => Http::response("\xEF\xBB\xBF".json_encode(['success' => true, 'token' => 'legacy', 'Lab' => 'Verdun Medical Center']))]);

        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])
            ->assertOk()
            ->assertJsonPath('data.lab_token', 'legacy')
            // Named after the matching lab post, though the account is lab 0.
            ->assertJsonPath('data.lab_id', 5);

        $this->assertSame('legacy', LabAccount::where('user_id', 217)->where('lab_id', 0)->first()->external_token);
    }

    public function test_a_throttle_is_not_counted_against_the_account_and_backs_the_site_off(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->patient();
        // Bekaa answers a tripped limit with a 200 shaped like a refusal.
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Too many attempts'])]);

        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])->assertOk();

        $this->assertSame(0, (int) LabAccount::where('user_id', 217)->where('lab_id', 9)->value('auth_failures'));
        $this->assertTrue(Cache::has(LabTokenService::backoffKey(9)));

        // While backed off, nobody's login reaches that lab at all.
        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_429_honours_retry_after_and_counts_nothing(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->patient();
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '30'])]);

        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'medvault2'])->assertOk();

        $this->assertTrue(Cache::has(LabTokenService::backoffKey(9)));
        $this->assertSame(0, (int) LabAccount::where('user_id', 217)->where('lab_id', 9)->value('auth_failures'));
        $this->travel(31)->seconds();
        $this->assertFalse(Cache::has(LabTokenService::backoffKey(9)));
    }
}
