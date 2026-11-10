<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use Illuminate\Support\Facades\Http;

class AuditLogTest extends AdminTestCase
{
    private const API = '/wp-json/custom/v1';

    private function newPatient(array $overrides = []): array
    {
        return ['function_type' => 'P', 'first_name' => 'Rana', 'last_name' => 'Haddad', 'mobile_number' => '03 123 456', 'password' => 'secret12', 'dob' => '1990-07-25', ...$overrides];
    }

    public function test_an_account_added_in_the_admin_records_who_added_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/patients', $this->newPatient())->assertOk();

        $user = User::where('username', 'ranahaddad')->firstOrFail();
        $this->assertSame([User::VIA_ADMIN, $admin->id, 'Site Admin'], [$user->created_via, $user->created_by_id, $user->created_by_name]);
        $this->get("/admin/patients/{$user->id}")->assertSee('Added in the admin by Site Admin');
        $this->get('/admin/patients')->assertSee('by Site Admin');

        $log = AuditLog::sole();
        $this->assertSame(['users.store', 'Added patient: Rana Haddad', 'admin', $admin->id, 'user', $user->id], [$log->action, $log->summary, $log->actor_kind, $log->actor_id, $log->subject_type, $log->subject_id]);
        $fields = $log->changes[0]['fields'];
        $this->assertSame([null, 'Rana'], $fields['first_name']);
        $this->assertSame(['(hidden)', '(changed)'], $fields['password']);
        $this->assertStringNotContainsString($user->password, json_encode($log->changes));
    }

    public function test_an_app_sign_up_is_marked_as_one(): void
    {
        Http::fake();
        $this->postJson(self::API.'/register', [
            'first_name' => 'Rana', 'last_name' => 'Haddad', 'dob' => '1990-07-25', 'gender' => 'female',
            'username' => 'ranahaddad', 'password' => 'secret12', 'mobile_number' => '+96103123456',
        ])->assertOk();

        $user = User::where('username', 'ranahaddad')->firstOrFail();
        $this->assertSame([User::VIA_APP, null], [$user->created_via, $user->created_by_id]);
        $this->assertSame('Signed up in the app', $user->createdViaLabel());

        $log = AuditLog::where('action', 'account.signup')->sole();
        $this->assertSame(['app', $user->id, 'Rana Haddad'], [$log->actor_kind, $log->actor_id, $log->actor_name]);
    }

    public function test_an_edit_keeps_before_and_after_and_a_rejected_form_writes_nothing(): void
    {
        $this->actingAs($this->admin())->post('/admin/patients', $this->newPatient())->assertOk();
        $user = User::where('username', 'ranahaddad')->firstOrFail();

        $this->from("/admin/patients/{$user->id}/edit")
            ->put("/admin/patients/{$user->id}", $this->newPatient(['mobile_number' => '', 'password' => '']))
            ->assertSessionHasErrors('mobile_number');
        $this->assertSame(1, AuditLog::count());

        $this->put("/admin/patients/{$user->id}", $this->newPatient(['first_name' => 'Rania', 'password' => '']))->assertRedirect();
        $log = AuditLog::latest('id')->first();
        $this->assertSame('Edited patient: Rania Haddad', $log->summary);
        $this->assertSame(['Rana', 'Rania'], $log->changes[0]['fields']['first_name']);
        $this->assertArrayNotHasKey('password', $log->changes[0]['fields']);
        $this->assertArrayNotHasKey('updated_at', $log->changes[0]['fields']);
    }

    public function test_a_deleted_account_keeps_its_history_and_reads_are_not_logged(): void
    {
        $this->actingAs($this->admin())->post('/admin/patients', $this->newPatient())->assertOk();
        $user = User::where('username', 'ranahaddad')->firstOrFail();
        $this->get('/admin/patients')->assertOk();
        $this->get("/admin/patients/{$user->id}")->assertOk();
        $this->assertSame(1, AuditLog::count());

        $this->delete("/admin/patients/{$user->id}")->assertRedirect();
        $this->get('/admin/patients/export')->assertOk();

        $this->assertSame(
            ['users.store', 'users.destroy', 'users.export'],
            AuditLog::orderBy('id')->pluck('action')->all(),
        );
        $this->get('/admin/audit-log?subject=user:'.$user->id)
            ->assertOk()->assertSee('Deleted patient: Rana Haddad')->assertSee('Added patient: Rana Haddad')->assertDontSee('Exported');
    }

    public function test_admin_sign_ins_and_failures_are_logged(): void
    {
        $admin = $this->admin(['password' => $this->passwordHash('correct-horse')]);

        $this->post('/admin/login', ['login' => 'admin', 'password' => 'wrong'])->assertSessionHasErrors('login');
        $this->post('/admin/login', ['login' => 'admin', 'password' => 'correct-horse'])->assertRedirect();
        $this->post('/admin/logout')->assertRedirect();

        $this->assertSame(['auth.login_failed', 'auth.login', 'auth.logout'], AuditLog::orderBy('id')->pluck('action')->all());
        $failed = AuditLog::where('action', 'auth.login_failed')->sole();
        $this->assertSame([null, 'admin', $admin->id], [$failed->actor_id, $failed->actor_name, $failed->subject_id]);
        $this->assertNotNull($failed->ip);
    }

    public function test_app_profile_changes_and_deletion_are_logged(): void
    {
        $user = User::factory()->create(['first_name' => 'Nour', 'last_name' => 'Hariri', 'display_name' => 'Nour Hariri']);
        $token = app(ApiTokens::class)->issue($user);
        $auth = ['Authorization' => "Bearer {$token}"];

        $this->postJson(self::API.'/profile/update', ['first_name' => 'Nora', 'password' => 'newpass1'], $auth)->assertOk();
        $log = AuditLog::where('action', 'account.profile')->sole();
        $this->assertSame(['Nour', 'Nora'], $log->changes[0]['fields']['first_name']);
        $this->assertSame(['(hidden)', '(changed)'], $log->changes[0]['fields']['password']);
        $this->assertArrayNotHasKey('api_token_hash', $log->changes[0]['fields']);

        $user->refresh();
        $token = app(ApiTokens::class)->issue($user);
        $this->deleteJson(self::API.'/account/delete', [], ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->assertSame('Nora Hariri', AuditLog::where('action', 'account.deleted')->sole()->subject_label);
    }

    public function test_secrets_on_other_pages_are_never_stored(): void
    {
        $this->actingAs($this->admin());
        $form = ['title' => 'Zahle Lab', 'status' => 'publish', 'api_enabled' => '1', 'api_driver' => 'vmc_v1', 'api_base' => 'https://zahle.test/api/mobile/', 'api_headers' => 'X-Api-Key: very-secret-key'];

        $this->post('/admin/labs', $form)->assertRedirect();
        $lab = Lab::sole();
        $this->put("/admin/labs/{$lab->id}", [...$form, 'api_headers' => 'X-Api-Key: another-secret-key'])->assertRedirect();

        $this->assertSame(['labs.store', 'labs.update'], AuditLog::orderBy('id')->pluck('action')->all());
        foreach (AuditLog::all() as $log) {
            $json = json_encode($log->changes);
            $this->assertStringNotContainsString('secret-key', $json);
            $this->assertSame(['(hidden)', '(changed)'], $log->changes[0]['fields']['api_headers']);
        }
        $this->assertSame('Edited lab: Zahle Lab', AuditLog::latest('id')->first()->summary);
    }

    public function test_only_site_wide_accounts_read_the_log(): void
    {
        $this->lab(9, 'Bekaa Lab');
        $patient = User::factory()->create(['owner_lab_id' => 9]);

        $this->actingAs($this->manager(9));
        $this->get('/admin/audit-log')->assertForbidden();
        $this->get("/admin/patients/{$patient->id}")->assertOk()->assertDontSee('Full history');

        $this->actingAs($this->admin(['username' => 'root']));
        $this->get('/admin/audit-log')->assertOk();
        $this->get("/admin/patients/{$patient->id}")->assertOk()->assertSee('Full history')
            ->assertSee('Not recorded (created before tracking began)');
    }

    public function test_tidy_users_leaves_one_row_when_it_writes(): void
    {
        User::factory()->create(['first_name' => 'rana', 'last_name' => 'Haddad', 'mobile_number' => '+96103123456', 'mobile_e164' => '9613123456']);

        $this->artisan('medvault:tidy-users')->assertSuccessful();
        $this->assertSame(0, AuditLog::count());

        $this->artisan('medvault:tidy-users', ['--apply' => true])->assertSuccessful();
        $log = AuditLog::sole();
        $this->assertSame(['console.tidy_users', 'system', null], [$log->action, $log->actor_kind, $log->actor_id]);
    }
}
