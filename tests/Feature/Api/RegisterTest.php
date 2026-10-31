<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Accounts\Passwords;
use Illuminate\Support\Facades\Http;

class RegisterTest extends ApiTestCase
{
    private function payload(array $overrides = []): array
    {
        return [
            'first_name' => 'Rana',
            'last_name' => 'Haddad',
            'dob' => '1990-07-25',
            'gender' => 'Female',
            'username' => 'ranahaddad',
            'password' => 'secret12',
            'mobile_number' => '+96103123456',
            ...$overrides,
        ];
    }

    public function test_registers_a_patient_and_returns_the_profile(): void
    {
        $this->postJson(self::API.'/register', $this->payload(['blood_type' => 'O+', 'id_image' => 'https://medvaultapp.com/app/wp-content/uploads/id.jpg']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Account created successfully.')
            ->assertJsonPath('data.first_name', 'Rana')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.email', '')
            ->assertJsonPath('data.blood_type', 'O+')
            ->assertJsonPath('data.function_type', 'P')
            ->assertJsonMissingPath('data.token');

        $user = User::where('username', 'ranahaddad')->firstOrFail();
        $this->assertSame('9613123456', $user->mobile_e164);
        $this->assertSame('+96103123456', $user->mobile_number);
        $this->assertSame('Rana Haddad', $user->display_name);
        $this->assertTrue(app(Passwords::class)->check($user, 'secret12'));
    }

    public function test_the_same_number_cannot_register_twice_in_another_spelling(): void
    {
        $this->postJson(self::API.'/register', $this->payload())->assertOk();

        $this->postJson(self::API.'/register', $this->payload(['username' => 'other', 'mobile_number' => '3123456']))
            ->assertStatus(409)
            ->assertExactJson(['code' => 'mobile_exists', 'message' => 'This mobile number is already registered.', 'data' => ['status' => 409]]);
    }

    public function test_usernames_collide_regardless_of_case(): void
    {
        $this->postJson(self::API.'/register', $this->payload())->assertOk();

        $this->postJson(self::API.'/register', $this->payload(['username' => 'RanaHaddad', 'mobile_number' => '70111222']))
            ->assertStatus(409)->assertJsonPath('code', 'username_exists');
    }

    public function test_validation_messages_match_the_plugin(): void
    {
        $cases = [
            [['dob' => '25/07/1990'], 'invalid_dob', 'Date of birth must be in YYYY-MM-DD format (e.g. 1990-07-25).'],
            [['dob' => now()->addDay()->format('Y-m-d')], 'invalid_dob', 'Date of birth must be in the past.'],
            [['gender' => 'x'], 'invalid_gender', 'Gender must be one of: male, female, other.'],
            [['blood_type' => 'C+'], 'invalid_blood_type', 'Blood type must be one of: A+, A-, B+, B-, AB+, AB-, O+, O-.'],
            [['password' => '12345'], 'invalid_password', 'Password must be at least 6 characters.'],
            [['first_name' => '  '], 'missing_first_name', 'First name is required.'],
            // Stripped to nothing by the strict username sanitizer.
            [['username' => '!!!'], 'missing_username', 'Username is required.'],
        ];

        foreach ($cases as [$overrides, $code, $message]) {
            $this->postJson(self::API.'/register', $this->payload($overrides))
                ->assertStatus(400)
                ->assertJsonPath('code', $code)
                ->assertJsonPath('message', $message);
        }
        $this->assertSame(0, User::count());
    }

    /**
     * sanitize_email() runs as the route sanitizer and turns an unusable
     * address into "", which the handler then reads as "no email". The
     * plugin registered such accounts without one, and so does this.
     */
    public function test_an_unusable_email_is_dropped_not_rejected(): void
    {
        $this->postJson(self::API.'/register', $this->payload(['email' => 'not-an-email']))
            ->assertOk()->assertJsonPath('data.email', '');
    }

    public function test_html_is_stripped_from_names(): void
    {
        $this->postJson(self::API.'/register', $this->payload(['first_name' => '<b>Rana</b>  ']))
            ->assertOk()->assertJsonPath('data.first_name', 'Rana');
    }

    public function test_doctor_registration_generates_a_username_and_logs_in_to_labs(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        Http::fake(['*' => Http::response(['success' => true, 'token' => 'dg-tok'])]);
        User::factory()->create(['username' => 'samikhoury']);

        $this->postJson(self::API.'/dg/register', [
            'first_name' => 'Sami', 'last_name' => 'Khoury', 'phone_number' => '71234567',
            'email' => 'sami@clinic.test', 'password' => 'secret12', 'function_type' => 'd', 'lab_id' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('data.username', 'samikhoury1')
            ->assertJsonPath('data.function_type', 'D')
            ->assertJsonPath('data.email', 'sami@clinic.test')
            ->assertJsonPath('data.lab_id', 5)
            ->assertJsonPath('data.lab_token', 'dg-tok');

        $this->assertSame(5, User::where('username', 'samikhoury1')->value('dg_lab_id'));
        Http::assertSent(fn ($r) => $r->body() === '{"username":"71234567","type":"D"}');
    }

    public function test_doctor_registration_requires_d_or_g(): void
    {
        $this->postJson(self::API.'/dg/register', [
            'first_name' => 'Sami', 'last_name' => 'Khoury', 'phone_number' => '71234567',
            'email' => 'sami@clinic.test', 'password' => 'secret12', 'function_type' => 'P',
        ])->assertStatus(400)->assertJsonPath('message', "function_type must be 'D' (Doctor), 'G' (Guarantor) or 'PD' (Patient and Doctor).");
    }

    public function test_doctor_sign_up_can_be_a_patient_too(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        Http::fake(['*' => Http::response(['success' => true, 'token' => 'p-tok'])]);

        $this->postJson(self::API.'/dg/register', [
            'first_name' => 'Sami', 'last_name' => 'Khoury', 'phone_number' => '71234567',
            'email' => 'sami@clinic.test', 'password' => 'secret12', 'function_type' => 'pd', 'lab_id' => 5,
        ])->assertOk()
            ->assertJsonPath('data.function_type', 'P')
            ->assertJsonPath('data.roles', ['P', 'D']);

        $user = User::where('username', 'samikhoury')->firstOrFail();
        $this->assertTrue($user->isPatientAndDoctor());
        $this->assertSame(5, $user->dg_lab_id);
        // Signed in at the lab as the patient; the doctor login comes on first use.
        Http::assertSent(fn ($r) => $r->body() === '{"username":"71234567","type":"P"}');
    }

    public function test_patient_sign_up_can_be_a_doctor_too(): void
    {
        $this->postJson(self::API.'/register', $this->payload(['also_doctor' => true]))->assertOk()
            ->assertJsonPath('data.function_type', 'P')
            ->assertJsonPath('data.roles', ['P', 'D']);

        $this->postJson(self::API.'/register', $this->payload(['username' => 'other', 'mobile_number' => '+96170111222']))
            ->assertJsonPath('data.roles', ['P']);
    }
}
