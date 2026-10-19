<?php

namespace Tests\Feature\Api;

use App\Models\Device;
use App\Models\LabAccount;
use App\Models\Setting;
use App\Models\User;

class ProfileTest extends ApiTestCase
{
    public function test_profile_requires_the_exact_bearer_scheme(): void
    {
        [, $token] = $this->signedIn();

        $this->getJson(self::API.'/profile', $this->bearer($token))->assertOk()->assertJsonPath('data.user_id', 217);

        foreach ([[], ['Authorization' => 'bearer '.$token], ['Authorization' => 'Bearer nope']] as $headers) {
            $this->getJson(self::API.'/profile', $headers)
                ->assertStatus(401)
                ->assertExactJson([
                    'code' => 'unauthorized',
                    'message' => 'Invalid or missing authentication token. Send: Authorization: Bearer <token>',
                    'data' => ['status' => 401],
                ]);
        }
    }

    public function test_the_ai_flag_follows_the_global_switch(): void
    {
        [, $token] = $this->signedIn();
        Setting::put('ai_summary_enabled', false);

        $this->getJson(self::API.'/profile', $this->bearer($token))->assertJsonPath('data.ai_summary_enabled', false);
    }

    public function test_partial_update_and_clearing_images(): void
    {
        [$user, $token] = $this->signedIn(['id_image' => 'https://x.test/id.jpg', 'blood_type' => 'A+', 'gender' => 'female']);

        $this->postJson(self::API.'/profile/update', ['last_name' => 'Hariri', 'id_image' => '', 'blood_type' => ''], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.first_name', 'nour')
            ->assertJsonPath('data.last_name', 'Hariri')
            ->assertJsonPath('data.id_image', null)
            ->assertJsonPath('data.blood_type', null)
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonMissingPath('data.new_token');

        $this->assertSame('nour Hariri', $user->refresh()->display_name);
    }

    public function test_nothing_to_update(): void
    {
        [, $token] = $this->signedIn();

        $this->postJson(self::API.'/profile/update', ['first_name' => ''], $this->bearer($token))
            ->assertStatus(400)->assertJsonPath('code', 'nothing_to_update');
    }

    public function test_changing_the_password_rotates_the_token(): void
    {
        [, $token] = $this->signedIn();

        $new = $this->postJson(self::API.'/profile/update', ['password' => 'newpass1'], $this->bearer($token))
            ->assertOk()->json('data.new_token');

        $this->getJson(self::API.'/profile', $this->bearer($token))->assertStatus(401);
        $this->getJson(self::API.'/profile', $this->bearer($new))->assertOk();
        $this->postJson(self::API.'/login', ['mobile_number' => '70918679', 'password' => 'newpass1'])->assertOk();
    }

    public function test_a_number_or_email_owned_by_someone_else_is_refused(): void
    {
        User::factory()->create(['email' => 'taken@x.test', 'mobile_number' => '03123456', 'mobile_e164' => '9613123456', 'mobile_local' => '3123456']);
        [$user, $token] = $this->signedIn();

        $this->postJson(self::API.'/profile/update', ['mobile_number' => '+9613123456'], $this->bearer($token))
            ->assertStatus(409)->assertJsonPath('code', 'mobile_exists');
        $this->postJson(self::API.'/profile/update', ['email' => 'Taken@X.test'], $this->bearer($token))
            ->assertStatus(409)->assertJsonPath('code', 'email_exists');

        $this->postJson(self::API.'/profile/update', ['mobile_number' => '76 618 326'], $this->bearer($token))->assertOk();
        $this->assertSame('96176618326', $user->refresh()->mobile_e164);
        $this->assertSame('76 618 326', $user->mobile_number);
    }

    public function test_deleting_the_account_removes_everything_attached(): void
    {
        [$user, $token] = $this->signedIn();
        Device::create(['user_id' => $user->id, 'token' => 'fcm-1', 'platform' => 'ios']);
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'external_token' => 't']);

        $this->deleteJson(self::API.'/account/delete', [], $this->bearer($token))
            ->assertOk()->assertExactJson(['success' => true, 'message' => 'Account has been permanently deleted.']);

        $this->assertNull(User::find(217));
        $this->assertSame(0, Device::count());
        $this->assertSame(0, LabAccount::count());
        $this->postJson(self::API.'/account/delete', [], $this->bearer($token))->assertStatus(404);
    }
}
