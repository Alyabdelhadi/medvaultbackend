<?php

namespace Tests\Feature\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The tri-state the app's LabTokenResult depends on. Collapsing "a lab is
 * down" into "sign out" logs out every user during an outage.
 */
class LabTokenRefreshTest extends ApiTestCase
{
    public function test_every_lab_refusing_is_lab_account_invalid(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        [, $token] = $this->signedIn();
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401)]);

        $this->postJson(self::API.'/lab-token/refresh', [], $this->bearer($token))
            ->assertStatus(401)
            ->assertExactJson([
                'code' => 'lab_account_invalid',
                'message' => 'This account is no longer registered with the lab. Please sign in again.',
                'data' => ['status' => 401],
            ]);
    }

    public function test_one_unreachable_lab_makes_it_an_outage(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        [, $token] = $this->signedIn();
        Http::fake([
            'lab5.test/*' => Http::response(['success' => false], 401),
            'lab9.test/*' => Http::response('<html>Bad gateway</html>', 503),
        ]);

        $this->postJson(self::API.'/lab-token/refresh', [], $this->bearer($token))
            ->assertStatus(502)->assertJsonPath('code', 'external_api_error');
    }

    public function test_a_throttle_is_an_outage_not_a_refusal(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        [, $token] = $this->signedIn();
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Too many attempts'])]);

        $this->postJson(self::API.'/lab-token/refresh', [], $this->bearer($token))
            ->assertStatus(502)->assertJsonPath('code', 'external_api_error');
    }

    public function test_a_connection_failure_is_an_outage(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        [, $token] = $this->signedIn();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->postJson(self::API.'/lab-token/refresh', [], $this->bearer($token))
            ->assertStatus(502)->assertJsonPath('code', 'external_api_error');
    }

    public function test_success_returns_the_primary_lab_and_all_labs(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital', ['api_priority' => 20]);
        [, $token] = $this->signedIn();
        Http::fake([
            'lab5.test/*' => Http::response(['success' => false], 401),
            'lab9.test/*' => Http::response(['success' => true, 'token' => 'b9']),
        ]);

        $this->postJson(self::API.'/lab-token/refresh', [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('message', 'Lab token refreshed.')
            ->assertJsonPath('data.lab_token', 'b9')
            ->assertJsonPath('data.lab_id', 9)
            ->assertJsonCount(1, 'data.labs');
    }

    public function test_requires_a_session(): void
    {
        $this->postJson(self::API.'/lab-token/refresh')->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    }
}
