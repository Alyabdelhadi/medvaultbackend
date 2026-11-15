<?php

namespace Tests\Feature\Admin;

use App\Models\AdminGroup;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Accounts\Passwords;
use Illuminate\Support\Facades\DB;

/**
 * A number registered at one lab, walking into another: the second lab adds
 * the existing account instead of being refused, and the account stays the
 * first lab's to edit.
 */
class LinkExistingPatientTest extends AdminTestCase
{
    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lab(9, 'Verdun Medical Center');
        $this->lab(12, 'Bekaa Hospital');

        // Created by Verdun, password "verdun12".
        $this->actingAs($this->manager(9, ['username' => 'verdunmgr']));
        $this->post('/admin/patients', [
            'function_type' => 'P', 'first_name' => 'Youmna', 'last_name' => 'Mourad',
            'mobile_cc' => '961', 'mobile_number' => '70536332', 'password' => 'verdun12', 'dob' => '1990-07-25',
        ])->assertOk();
        $this->patient = User::firstWhere('first_name', 'Youmna');
        $this->assertSame(9, (int) $this->patient->owner_lab_id);

        $this->actingAs($this->bekaa());
    }

    /** Bekaa's account, with edit rights on patients (the counter's password reset). */
    private function bekaa(): User
    {
        return User::firstWhere('username', 'bekaastaff') ?? User::factory()->create([
            'username' => 'bekaastaff', 'managed_lab_id' => 12,
            'admin_group_id' => AdminGroup::create(['name' => 'Lab staff', 'for_labs' => true, 'permissions' => ['patients' => ['view', 'create', 'edit', 'delete']]])->id,
        ]);
    }

    private function addAgain(array $overrides = [])
    {
        return $this->from('/admin/patients/new')->post('/admin/patients', [
            'function_type' => 'P', 'first_name' => 'Youmna', 'last_name' => 'Mourad',
            'mobile_cc' => '961', 'mobile_number' => '70 536 332', 'password' => 'bekaa123',
            'dob_d' => '25', 'dob_m' => '07', 'dob_y' => '1990', ...$overrides,
        ]);
    }

    public function test_a_taken_number_offers_the_existing_account_masked(): void
    {
        $this->addAgain()->assertRedirect('/admin/patients/new')->assertSessionHas('existing_user', $this->patient->id);
        $this->assertSame(1, User::where('mobile_e164', '96170536332')->count());

        $this->get('/admin/patients/new')->assertOk()
            ->assertSee('This number already has a MedVault account')
            ->assertSee('Y****a M****d')
            ->assertDontSee('Youmna Mourad')
            ->assertSee('match this account');
    }

    public function test_a_different_name_is_flagged_before_linking(): void
    {
        $this->addAgain(['first_name' => 'Karim']);
        $this->get('/admin/patients/new')->assertSee('does not match this account');
    }

    public function test_linking_keeps_the_account_and_its_password(): void
    {
        $this->get('/admin/patients/'.$this->patient->id)->assertNotFound();

        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '70536332'])
            ->assertOk()->assertSee('Your current MedVault password')->assertSee('Forgot Password');

        $this->assertTrue(DB::table('user_labs')->where(['user_id' => $this->patient->id, 'lab_id' => 12])->exists());
        $fresh = $this->patient->fresh();
        $this->assertTrue(app(Passwords::class)->check($fresh, 'verdun12'));
        $this->assertSame(['Youmna Mourad', 9], [$fresh->display_name, (int) $fresh->owner_lab_id]);

        // Both labs list them now.
        $this->get('/admin/patients')->assertSee('Youmna Mourad');
        $this->actingAs(User::firstWhere('username', 'verdunmgr'))->get('/admin/patients')->assertSee('Youmna Mourad');

        $log = AuditLog::where('action', 'users.link')->firstOrFail();
        $this->assertSame([12, $this->patient->id], [(int) $log->lab_id, (int) $log->subject_id]);
        $this->assertStringContainsString('Bekaa Hospital', $log->summary);
    }

    public function test_a_new_password_only_when_asked_for(): void
    {
        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '70536332', 'reset_password' => '1', 'password' => 'Bk7mNp2q'])
            ->assertOk()->assertSee('Bk7mNp2q');

        $this->assertTrue(app(Passwords::class)->check($this->patient->fresh(), 'Bk7mNp2q'));
        $this->assertStringContainsString('set a new password', AuditLog::where('action', 'users.link')->value('summary'));
    }

    public function test_the_link_is_found_by_number_never_by_id(): void
    {
        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '71999999', 'user' => $this->patient->id])->assertNotFound();
        $this->assertFalse(DB::table('user_labs')->where('user_id', $this->patient->id)->exists());
    }

    public function test_the_linking_lab_cannot_edit_or_delete_but_can_reset_and_unlink(): void
    {
        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '70536332']);
        $id = $this->patient->id;

        $this->get("/admin/patients/{$id}")->assertOk()->assertSee('Shared with another lab')->assertSee('Remove from my lab')->assertDontSee('Delete account');
        $this->get("/admin/patients/{$id}/edit")->assertForbidden();
        $this->put("/admin/patients/{$id}", ['function_type' => 'P', 'first_name' => 'X', 'last_name' => 'Y', 'mobile_number' => '70536332', 'dob' => '1990-07-25'])->assertForbidden();
        $this->delete("/admin/patients/{$id}")->assertForbidden();

        $this->post("/admin/patients/{$id}/password", ['password' => 'counter1'])->assertRedirect();
        $this->assertTrue(app(Passwords::class)->check($this->patient->fresh(), 'counter1'));

        $this->delete("/admin/patients/{$id}/link")->assertRedirect('/admin/patients');
        $this->assertNotNull($this->patient->fresh());
        $this->get("/admin/patients/{$id}")->assertNotFound();

        // The creating lab still owns it.
        $this->actingAs(User::firstWhere('username', 'verdunmgr'))->get("/admin/patients/{$id}")->assertOk()->assertDontSee('Shared with another lab');
        $this->assertTrue(app(\App\Services\Admin\UserDirectory::class)->canEdit(User::firstWhere('username', 'verdunmgr'), $this->patient->fresh()));
    }

    public function test_a_default_lab_manager_can_link_and_unlink(): void
    {
        $this->actingAs($this->manager(12));
        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '70536332'])->assertOk();
        $this->get('/admin/patients/'.$this->patient->id)->assertOk()->assertSee('Shared with another lab')->assertSee('Remove from my lab');
        $this->delete('/admin/patients/'.$this->patient->id.'/link')->assertRedirect('/admin/patients');
    }

    public function test_a_number_already_in_this_lab_just_points_to_it(): void
    {
        $this->actingAs(User::firstWhere('username', 'verdunmgr'));
        $this->addAgain();
        $this->get('/admin/patients/new')->assertSee('This number is already in your lab')->assertDontSee('Add to my lab');
    }

    public function test_a_site_admin_chooses_the_lab(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '70536332'])->assertSessionHasErrors('lab_id');
        $this->post('/admin/patients/link', ['mobile_cc' => '961', 'mobile_number' => '70536332', 'lab_id' => 12])->assertOk();
        $this->assertTrue(DB::table('user_labs')->where(['user_id' => $this->patient->id, 'lab_id' => 12])->exists());
    }
}
