<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\UserController;
use App\Models\AdminGroup;
use App\Models\LabAccount;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Services\Accounts\Passwords;
use App\Support\Countries;
use App\Support\Mobile;

class UsersAdminTest extends AdminTestCase
{
    private function newPatient(array $overrides = []): array
    {
        return ['function_type' => 'P', 'first_name' => 'Rana', 'last_name' => 'Haddad', 'mobile_number' => '03 123 456', 'password' => 'secret12', ...$overrides];
    }

    public function test_adding_a_patient_mirrors_app_registration(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/patients', $this->newPatient(['blood_type' => 'O+', 'dob' => '1990-07-25']))->assertOk();
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
        $this->post('/admin/patients', $this->newPatient())->assertOk();

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
            // The slip page links to the account on the Doctors list.
            ->assertOk()->assertSee('/admin/doctors/', false);
        $this->assertSame(5, User::where('function_type', 'D')->value('dg_lab_id'));
    }

    public function test_a_managers_new_accounts_belong_to_their_lab_whatever_the_form_says(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        $this->actingAs($this->manager(9));

        $this->post('/admin/doctors', $this->newPatient(['function_type' => 'D', 'dg_lab_id' => '5', 'owner_lab_id' => '5', 'mobile_number' => '71234567']))->assertOk();

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
        $this->assertStringContainsString("Name,Username,Email,Mobile,\"Registration Date\",Type,\"How the account was made\"\n", $csv);
        $this->assertStringContainsString("\"Nour Al Hariri\",nour,,'+96170918679,\"2026-01-10 09:00:00\",P,\"Not recorded (created before tracking began)\"\n", $csv);
        // The administrator is an admin account, not a patient.
        $this->assertStringEndsWith("\nTotal,1\n", $csv);
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
        // Admin accounts are not reachable from these pages at all.
        $this->delete("/admin/patients/{$admin->id}")->assertNotFound();
        $this->assertNotNull($admin->fresh());
    }

    public function test_the_country_picker_builds_the_international_number(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/patients/new')->assertOk()->assertSee('value="961" selected>', false)->assertSee('title="Qatar" value="974"', false);

        $this->post('/admin/patients', $this->newPatient(['mobile_cc' => '974', 'mobile_number' => '7053 6332']))->assertOk();
        $qatar = User::firstWhere('mobile_e164', '97470536332');
        $this->assertSame('+97470536332', $qatar?->mobile_number);

        // Lebanon with the trunk zero, as patients write 03 numbers.
        $this->post('/admin/patients', $this->newPatient(['first_name' => 'Ali', 'mobile_cc' => '961', 'mobile_number' => '03 123 456']))->assertOk();
        $this->assertSame('9613123456', User::firstWhere('first_name', 'Ali')->mobile_e164);

        // A number typed with its own country code keeps it.
        $this->post('/admin/patients', $this->newPatient(['first_name' => 'Sara', 'mobile_cc' => '961', 'mobile_number' => '+971 50 123 4567']))->assertOk();
        $this->assertSame('971501234567', User::firstWhere('first_name', 'Sara')->mobile_e164);

        // The same number again, typed differently, is still a duplicate.
        $this->post('/admin/patients', $this->newPatient(['first_name' => 'Dup', 'mobile_cc' => '974', 'mobile_number' => '70536332']))
            ->assertSessionHasErrors(['mobile_number' => 'This mobile number is already registered.']);
    }

    public function test_every_country_in_the_picker_is_one_the_parser_knows(): void
    {
        foreach (Countries::codes() as $cc) {
            $this->assertSame($cc, Mobile::split('+'.$cc.'12345678')['cc'], "+{$cc} would be read as another country");
        }
    }

    public function test_editing_a_person_keeps_the_rules_and_resets_labs_only_on_a_new_number(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->actingAs($this->admin());
        $this->post('/admin/patients', $this->newPatient())->assertOk();
        $rana = User::firstWhere('first_name', 'Rana');
        $other = User::factory()->create(['email' => 'taken@x.test', 'mobile_number' => '+96170111222', 'mobile_e164' => '96170111222']);
        LabAccount::create(['user_id' => $rana->id, 'lab_id' => 9, 'external_token' => 't', 'seen_status' => ['1' => 'V']]);

        $this->get('/admin/patients')->assertSee("/admin/patients/{$rana->id}/edit");
        $this->get("/admin/patients/{$rana->id}/edit")->assertOk()->assertSee('value="3123456"', false)->assertSee('<option value="961" selected>', false);

        // Same number typed another way: the stored spelling and the lab tokens stay.
        $this->put("/admin/patients/{$rana->id}", [...$this->newPatient(['first_name' => 'Rana Maria', 'mobile_cc' => '961', 'mobile_number' => '3123456', 'blood_type' => 'AB-', 'password' => '']), 'dg_lab_id' => 9])
            ->assertRedirect("/admin/patients/{$rana->id}");
        $rana->refresh();
        $this->assertSame(['Rana Maria Haddad', 'AB-', '03 123 456', null], [$rana->display_name, $rana->blood_type, $rana->mobile_number, $rana->dg_lab_id]);
        $this->assertTrue(app(Passwords::class)->check($rana, 'secret12'));
        $this->assertSame(1, $rana->labAccounts()->count());

        // Someone else's email or number is refused.
        $this->put("/admin/patients/{$rana->id}", $this->newPatient(['email' => 'taken@x.test', 'mobile_cc' => '961', 'mobile_number' => '70111222']))
            ->assertSessionHasErrors(['email', 'mobile_number']);

        // A new number drops the lab accounts, so labs are asked again; a new password is set.
        $this->put("/admin/patients/{$rana->id}", $this->newPatient(['mobile_cc' => '974', 'mobile_number' => '70536332', 'password' => 'newpass1']))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'labs will be asked again'));
        $rana->refresh();
        $this->assertSame(['97470536332', '+97470536332', 0], [$rana->mobile_e164, $rana->mobile_number, $rana->labAccounts()->count()]);
        $this->assertTrue(app(Passwords::class)->check($rana, 'newpass1'));

        // Changing the type moves them to the other list.
        $this->put("/admin/patients/{$rana->id}", $this->newPatient(['function_type' => 'D', 'mobile_cc' => '974', 'mobile_number' => '70536332', 'dg_lab_id' => 9]))
            ->assertRedirect("/admin/doctors/{$rana->id}");
        $this->assertSame(['D', 9], [$rana->refresh()->function_type, $rana->dg_lab_id]);
    }

    public function test_a_lab_account_edits_only_its_own_people_and_cannot_move_them(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(5, 'Verdun Medical Center');
        $group = AdminGroup::create(['name' => 'Lab staff', 'for_labs' => true, 'permissions' => ['doctors' => ['view', 'edit'], 'patients' => ['view']]]);
        $doctor = User::factory()->create(['first_name' => 'D', 'last_name' => 'R', 'function_type' => 'D', 'dg_lab_id' => 9, 'mobile_number' => '+96170999888', 'mobile_e164' => '96170999888']);
        $theirs = User::factory()->create(['function_type' => 'D', 'dg_lab_id' => 5]);
        $patient = User::factory()->create(['owner_lab_id' => 9]);
        $this->actingAs(User::factory()->create(['admin_group_id' => $group->id, 'managed_lab_id' => 9]));

        $this->put("/admin/doctors/{$doctor->id}", $this->newPatient(['function_type' => 'D', 'first_name' => 'Dana', 'mobile_number' => '+96170999888', 'dg_lab_id' => 5]))->assertRedirect();
        $this->assertSame(['Dana', 9], [$doctor->refresh()->first_name, $doctor->dg_lab_id]);
        $this->get("/admin/doctors/{$theirs->id}/edit")->assertNotFound();
        // No edit permission on patients.
        $this->get("/admin/patients/{$patient->id}/edit")->assertForbidden();
        $this->get('/admin/patients')->assertDontSee('/edit');
    }

    public function test_add_offers_patient_and_doctor_and_prints_the_slip(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/patients/new')->assertOk()
            ->assertSee('Patient + Doctor')->assertSee('PRINT PREVIEW')->assertSee('Add &amp; Print', false);

        $this->post('/admin/patients', $this->newPatient([
            'function_type' => 'PD', 'mobile_cc' => '961', 'mobile_number' => '70143335', 'password' => 'Lpgnx7VW',
            'dob_d' => '7', 'dob_m' => '3', 'dob_y' => '85', 'gender' => 'female', 'lab_id' => '5',
        ]))->assertOk()
            // The slip: the name, the number as patients read it, the password.
            ->assertSee('Rana Haddad')->assertSee('70 143 335')->assertSee('Lpgnx7VW');

        $user = User::firstWhere('mobile_e164', '96170143335');
        $this->assertSame(['P', true, '1985-03-07', 'female', 5, 5], [
            $user->function_type, $user->also_doctor, $user->dob, $user->gender, $user->owner_lab_id, $user->dg_lab_id,
        ]);
        // Listed under both, and edited as what it is.
        $this->get('/admin/patients')->assertSee('Rana Haddad');
        $this->get('/admin/doctors')->assertSee('Rana Haddad');
        $this->get("/admin/patients/{$user->id}/edit")->assertSee('<option value="PD" selected>', false);
        // The password exists only in that response.
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_dob_boxes_follow_the_two_digit_rule_and_reject_impossible_dates(): void
    {
        $this->assertSame('2007-01-02', UserController::dobFromParts('2', '1', '07'));
        $this->assertSame('1999-12-31', UserController::dobFromParts('31', '12', '1999'));
        $this->assertNull(UserController::dobFromParts('', '', ''));

        $this->actingAs($this->admin());
        $this->post('/admin/patients', $this->newPatient(['dob_d' => '31', 'dob_m' => '02', 'dob_y' => '1990']))
            ->assertSessionHasErrors(['dob']);
    }
}
