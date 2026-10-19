<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Services\Accounts\Passwords;

class UsersAdminTest extends AdminTestCase
{
    private function newPatient(array $overrides = []): array
    {
        return ['function_type' => 'P', 'first_name' => 'Rana', 'last_name' => 'Haddad', 'mobile_number' => '03 123 456', 'password' => 'secret12', ...$overrides];
    }

    public function test_adding_a_patient_mirrors_app_registration(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/patients', $this->newPatient(['blood_type' => 'O+', 'dob' => '1990-07-25']))->assertRedirect();
        $user = User::where('username', 'ranahaddad')->firstOrFail();

        $this->assertSame(['9613123456', 'P', 'O+', 'Rana Haddad'], [$user->mobile_e164, $user->function_type, $user->blood_type, $user->display_name]);
        $this->assertTrue(app(Passwords::class)->check($user, 'secret12'));
        $this->get("/admin/patients?created={$user->id}")->assertSee('ranahaddad');

        // The app can sign in with it.
        $this->postJson('/wp-json/custom/v1/login', ['mobile_number' => '+9613123456', 'password' => 'secret12'])->assertOk();
    }

    public function test_validation_and_duplicate_numbers(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/patients', $this->newPatient())->assertRedirect();

        $this->from('/admin/patients/new')->post('/admin/patients', $this->newPatient(['mobile_number' => '+96103123456', 'password' => '123', 'email' => 'no', 'gender' => 'x', 'dob' => '2999-01-01']))
            ->assertRedirect('/admin/patients/new')
            ->assertSessionHasErrors([
                'mobile_number' => 'This mobile number is already registered.',
                'password' => 'Password must be at least 6 characters.',
                'gender' => 'Gender must be male, female or other.',
                'dob' => 'Date of birth must be in the past.',
            ])
            ->assertSessionMissing('_old_input.password');
        $this->assertSame(1, User::where('function_type', 'P')->count());
    }

    public function test_a_doctor_added_from_the_patients_screen_lands_on_the_doctors_list(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->actingAs($this->admin());

        $this->post('/admin/patients', $this->newPatient(['function_type' => 'D', 'dg_lab_id' => '5', 'mobile_number' => '71234567']))
            ->assertRedirectContains('/admin/doctors?created=');
        $this->assertSame(5, User::where('function_type', 'D')->value('dg_lab_id'));
    }

    public function test_a_managers_new_accounts_belong_to_their_lab_whatever_the_form_says(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        $this->actingAs($this->manager(9));

        $this->post('/admin/doctors', $this->newPatient(['function_type' => 'D', 'dg_lab_id' => '5', 'owner_lab_id' => '5', 'mobile_number' => '71234567']))->assertRedirect();

        $doctor = User::where('function_type', 'D')->sole();
        $this->assertSame([9, 9], [$doctor->dg_lab_id, $doctor->owner_lab_id]);
        $this->get('/admin/doctors')->assertSee('Rana Haddad');
    }

    public function test_search_matches_names_and_any_spelling_of_the_number(): void
    {
        User::factory()->create(['display_name' => 'Nour Al Hariri', 'mobile_number' => '+961 76 618 326']);
        User::factory()->create(['display_name' => 'Someone Else', 'mobile_number' => '70111222']);
        $this->actingAs($this->admin());

        foreach (['hariri', '76618326', '+96176618326', '76 618 326'] as $term) {
            $this->get('/admin/patients?s='.urlencode($term))->assertSee('Nour Al Hariri')->assertDontSee('Someone Else');
        }
    }

    public function test_csv_export_opens_cleanly_in_excel(): void
    {
        User::factory()->create(['display_name' => 'Nour Al Hariri', 'username' => 'nour', 'mobile_number' => '+96170918679', 'created_at' => '2026-01-10 09:00:00']);
        $this->actingAs($this->admin());

        $response = $this->get('/admin/patients/export')->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("Name,Username,Email,Mobile,\"Registration Date\",Type\n", $csv);
        $this->assertStringContainsString("\"Nour Al Hariri\",nour,,'+96170918679,\"2026-01-10 09:00:00\",P\n", $csv);
        $this->assertStringEndsWith("\nTotal,2\n", $csv);
        $this->assertStringContainsString('medvault-patients-', $response->headers->get('Content-Disposition'));
    }

    public function test_setting_a_password_ends_app_sessions_and_deleting_cascades(): void
    {
        $patient = User::factory()->create();
        $token = app(ApiTokens::class)->issue($patient);
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->post("/admin/patients/{$patient->id}/password", ['password' => 'fresh-one'])->assertRedirect();
        $this->getJson('/wp-json/custom/v1/profile', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertTrue(app(Passwords::class)->check($patient->refresh(), 'fresh-one'));

        $this->delete("/admin/patients/{$patient->id}")->assertRedirect('/admin/patients');
        $this->assertNull(User::find($patient->id));
        $this->delete("/admin/patients/{$admin->id}")->assertForbidden();
    }
}
