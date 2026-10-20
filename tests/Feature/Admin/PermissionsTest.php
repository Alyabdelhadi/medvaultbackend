<?php

namespace Tests\Feature\Admin;

use App\Models\AdminGroup;
use App\Models\AppNotification;
use App\Models\LabAccount;
use App\Models\PushMessage;
use App\Models\User;

class PermissionsTest extends AdminTestCase
{
    private function group(string $name, array $permissions, bool $forLabs = false): AdminGroup
    {
        return AdminGroup::create(['name' => $name, 'permissions' => $permissions, 'for_labs' => $forLabs]);
    }

    private function member(AdminGroup $group, ?int $labId = null, array $attributes = []): User
    {
        return User::factory()->create(['username' => 'staff'.$group->id, 'admin_group_id' => $group->id, 'managed_lab_id' => $labId, 'password' => $this->passwordHash('staff-pass'), ...$attributes]);
    }

    public function test_a_group_grants_exactly_its_ticks(): void
    {
        $lab = $this->lab(5, 'Verdun Medical Center');
        $this->actingAs($this->member($this->group('Directory editors', ['labs' => ['view']])));

        $this->get('/admin')->assertRedirect('/admin/labs');
        $this->get('/admin/labs')->assertOk()->assertSee('<span>Labs</span>', false)
            ->assertDontSee('<span>Accounting</span>', false)->assertDontSee('<span>Patients</span>', false);
        $this->get("/admin/labs/{$lab->id}/edit")->assertOk();
        $this->put("/admin/labs/{$lab->id}", ['title' => 'Renamed'])->assertForbidden();
        $this->get('/admin/labs/create')->assertForbidden();
        $this->get('/admin/patients')->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
    }

    public function test_an_account_with_no_page_cannot_sign_in(): void
    {
        $this->member($this->group('Empty', []));
        $labGroup = $this->group('Lab staff', ['patients' => ['view']], forLabs: true);
        User::factory()->create(['username' => 'nolab', 'admin_group_id' => $labGroup->id, 'password' => $this->passwordHash('staff-pass')]);

        foreach (['staff1', 'nolab'] as $login) {
            $this->from('/admin/login')->post('/admin/login', ['login' => $login, 'password' => 'staff-pass'])
                ->assertSessionHasErrors(['login' => 'Incorrect username or password.']);
        }
        $this->assertGuest();
    }

    public function test_a_lab_group_never_reaches_a_site_wide_page_whatever_is_stored(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        // Stored directly, as if the form's filtering had been bypassed.
        $group = $this->group('Lab staff', ['patients' => ['view'], 'labs' => ['view', 'edit'], 'accounting' => ['view']], forLabs: true);
        $this->actingAs($this->member($group, 9));

        $this->get('/admin/patients')->assertOk();
        $this->get('/admin/labs')->assertForbidden();
        $this->get('/admin/accounting')->assertForbidden();
    }

    public function test_saving_a_lab_group_drops_site_wide_pages_and_adds_view(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/groups', [
            'name' => 'Lab staff', 'for_labs' => '1',
            'permissions' => ['patients' => ['create'], 'push' => ['create'], 'labs' => ['view', 'edit'], 'staff' => ['create'], 'nonsense' => ['view']],
        ])->assertRedirect('/admin/groups');

        // assertEquals: MySQL's JSON column does not keep key order.
        $this->assertEquals(['patients' => ['view', 'create'], 'push' => ['view', 'create']], AdminGroup::firstWhere('name', 'Lab staff')->permissions);
    }

    public function test_nobody_grants_a_permission_they_do_not_hold(): void
    {
        $editor = $this->member($this->group('Group editors', ['groups' => ['view', 'create', 'edit'], 'labs' => ['view']]));
        $this->actingAs($editor);

        $this->post('/admin/groups', ['name' => 'Escalated', 'permissions' => ['labs' => ['view', 'edit', 'delete'], 'staff' => ['create'], 'groups' => ['view']]]);
        $this->assertEquals(['labs' => ['view'], 'groups' => ['view']], AdminGroup::firstWhere('name', 'Escalated')->permissions);

        // What the editor lacks stays as it was on an existing group.
        $finance = $this->group('Finance', ['accounting' => ['view', 'edit']]);
        $this->put("/admin/groups/{$finance->id}", ['name' => 'Finance', 'permissions' => ['labs' => ['view']]]);
        $this->assertEquals(['labs' => ['view'], 'accounting' => ['view', 'edit']], $finance->refresh()->permissions);
    }

    public function test_only_administrators_make_administrators_and_groups_are_limited_to_your_own_ticks(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $hr = $this->group('HR', ['staff' => ['view', 'create', 'edit'], 'patients' => ['view']]);
        $weaker = $this->group('Lab staff', ['patients' => ['view']], forLabs: true);
        $stronger = $this->group('Finance', ['accounting' => ['view']]);
        $admin = $this->admin();
        $this->actingAs($this->member($hr));

        $this->post('/admin/accounts', ['display_name' => 'X', 'username' => 'sneaky', 'password' => 'long-enough', 'access' => 'admin'])
            ->assertSessionHasErrors('access');
        $this->post('/admin/accounts', ['display_name' => 'X', 'username' => 'sneaky', 'password' => 'long-enough', 'access' => 'group', 'admin_group_id' => $stronger->id])
            ->assertSessionHasErrors('admin_group_id');
        $this->post('/admin/accounts', ['display_name' => 'Bekaa Lab', 'username' => 'bekaa', 'password' => 'long-enough', 'access' => 'group', 'admin_group_id' => $weaker->id])
            ->assertSessionHasErrors('managed_lab_id');
        $this->post('/admin/accounts', ['display_name' => 'Bekaa Lab', 'username' => 'bekaa', 'password' => 'long-enough', 'access' => 'group', 'admin_group_id' => $weaker->id, 'managed_lab_id' => 9])
            ->assertRedirect('/admin/accounts');
        $this->assertSame([$weaker->id, 9, User::ROLE_SUBSCRIBER], [User::firstWhere('username', 'bekaa')->admin_group_id, 9, User::firstWhere('username', 'bekaa')->role]);

        $this->get("/admin/accounts/{$admin->id}/edit")->assertForbidden();
    }

    public function test_an_administrator_gives_a_lab_its_account_which_signs_in_to_its_own_dashboard(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(5, 'Verdun Medical Center');
        $group = $this->group('Lab staff', ['dashboard' => ['view'], 'patients' => ['view', 'create'], 'push' => ['view', 'create']], forLabs: true);
        User::factory()->create(['display_name' => 'Bekaa Patient', 'owner_lab_id' => 9]);
        User::factory()->create(['display_name' => 'Verdun Patient', 'owner_lab_id' => 5]);
        $this->actingAs($this->admin());

        $this->post('/admin/accounts', ['display_name' => 'Bekaa Hospital Lab', 'username' => 'bekaa', 'password' => 'bekaa-pass-1', 'access' => 'group', 'admin_group_id' => $group->id, 'managed_lab_id' => 9])
            ->assertRedirect('/admin/accounts');
        $this->get('/admin/accounts')->assertSee('Bekaa Hospital Lab');
        $this->get('/admin/patients')->assertDontSee('Bekaa Hospital Lab');

        $this->post('/admin/logout');
        $this->post('/admin/login', ['login' => 'bekaa', 'password' => 'bekaa-pass-1'])->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')->assertOk()->assertSee('Bekaa Hospital')->assertSee('New patients, last 12 months')
            ->assertDontSee('Revenue')->assertDontSee('Published labs');
        $this->get('/admin/patients')->assertSee('Bekaa Patient')->assertDontSee('Verdun Patient');
    }

    public function test_a_lab_account_notifies_only_its_own_patients(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(5, 'Verdun Medical Center');
        $mine = User::factory()->create(['display_name' => 'Bekaa Patient', 'owner_lab_id' => 9]);
        $tokened = User::factory()->create(['display_name' => 'Token Patient']);
        LabAccount::create(['user_id' => $tokened->id, 'lab_id' => 9, 'external_token' => 't']);
        $doctor = User::factory()->create(['function_type' => 'D', 'dg_lab_id' => 9]);
        $theirs = User::factory()->create(['display_name' => 'Verdun Patient', 'owner_lab_id' => 5]);
        $group = $this->group('Lab staff', ['push' => ['view', 'create']], forLabs: true);
        $this->actingAs($this->member($group, 9));

        $this->get('/admin/push')->assertOk()->assertSee('All patients of Bekaa Hospital')->assertDontSee('Everyone with the app')->assertDontSee('Verdun Patient');

        // A posted "everyone" or another lab is overridden by the account's lab.
        $this->post('/admin/push', ['audience' => 'all', 'lab_id' => 5, 'title' => 'Closed Friday', 'body' => 'The lab is closed on Friday.'])
            ->assertRedirect('/admin/push')->assertSessionHas('status');
        $this->assertEqualsCanonicalizing([$mine->id, $tokened->id], AppNotification::where('type', 'lab_message')->pluck('user_id')->all());
        $this->assertSame([9, 'lab', 2], [PushMessage::first()->lab_id, PushMessage::first()->audience, PushMessage::first()->recipients]);

        $this->post('/admin/push', ['audience' => 'user', 'user_id' => $theirs->id, 'title' => 'Hi', 'body' => 'Hello'])
            ->assertSessionHas('status_type', 'error');
        $this->post('/admin/push', ['audience' => 'user', 'user_id' => $doctor->id, 'title' => 'Hi', 'body' => 'Hello'])
            ->assertSessionHas('status_type', 'error');
        $this->assertSame(0, AppNotification::where('user_id', $theirs->id)->count());

        $this->post('/admin/push', ['audience' => 'user', 'user_id' => $mine->id, 'title' => 'Your report', 'body' => 'Please call us.'])
            ->assertRedirect('/admin/push');
        $this->assertSame(2, AppNotification::where('user_id', $mine->id)->count());
        $this->get('/admin/push')->assertSee('Closed Friday')->assertSee('Your report');
    }

    public function test_an_administrator_can_message_one_labs_patients(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(5, 'Verdun Medical Center');
        User::factory()->create(['owner_lab_id' => 9]);
        $theirs = User::factory()->create(['owner_lab_id' => 5]);
        $this->actingAs($this->admin());

        $this->post('/admin/push', ['audience' => 'lab', 'lab_id' => 5, 'title' => 'Verdun news', 'body' => 'New hours.'])->assertRedirect('/admin/push');
        $this->assertSame([$theirs->id], AppNotification::where('type', 'lab_message')->pluck('user_id')->all());
    }
}
