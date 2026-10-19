<?php

namespace Tests\Feature\Admin;

use App\Models\LabAccount;
use App\Models\User;

class AccessTest extends AdminTestCase
{
    /** The WordPress 6.8 hash of "medvault2". */
    private const WP_HASH = '$wp$2y$12$cGtK26iHeFQufASHDwz76O9Bf1bVebh0346cteh3Y9o4iCwLvaqkS';

    public function test_an_imported_administrator_signs_in_with_their_wordpress_password(): void
    {
        $admin = $this->admin(['email' => 'Admin@MedVault.test', 'password' => self::WP_HASH]);

        $this->post('/admin/login', ['login' => 'admin@medvault.test', 'password' => 'medvault2'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertRedirect('/admin/dashboard');
        $this->assertStringStartsWith('$2y$', $admin->refresh()->password);
    }

    public function test_patients_and_unassigned_managers_get_the_same_answer_as_a_wrong_password(): void
    {
        User::factory()->create(['username' => 'patient', 'password' => $this->passwordHash('secret12')]);
        $this->manager(0, ['username' => 'nolab', 'managed_lab_id' => null, 'password' => $this->passwordHash('secret12')]);
        $this->admin(['password' => $this->passwordHash('right-one')]);

        foreach ([['patient', 'secret12'], ['nolab', 'secret12'], ['admin', 'wrong']] as [$login, $password]) {
            $this->from('/admin/login')->post('/admin/login', compact('login', 'password'))
                ->assertRedirect('/admin/login')->assertSessionHasErrors(['login' => 'Incorrect username or password.']);
        }
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->admin(['password' => $this->passwordHash('right-one')]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['login' => 'admin', 'password' => 'nope']);
        }
        $this->post('/admin/login', ['login' => 'admin', 'password' => 'right-one'])->assertStatus(429);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        foreach (['/admin', '/admin/dashboard', '/admin/labs', '/admin/patients', '/'] as $path) {
            $this->get($path)->assertRedirect();
        }
        $this->get('/admin/labs')->assertRedirect('/admin/login');
    }

    public function test_every_admin_page_renders(): void
    {
        $lab = $this->lab(5, 'Verdun Medical Center', ['api_enabled' => true, 'api_base' => 'https://x.test', 'slides' => ['https://x.test/s.jpg']]);
        $patient = User::factory()->create(['id_image' => 'https://x.test/id.jpg']);
        LabAccount::create(['user_id' => $patient->id, 'lab_id' => 5, 'external_token' => 't', 'seen_status' => ['1' => 'V']]);
        $this->actingAs($this->admin());

        foreach (['/admin/dashboard', '/admin/labs', '/admin/labs?status=trash', '/admin/labs/create', "/admin/labs/{$lab->id}/edit",
            '/admin/sliders', '/admin/banners', '/admin/result-categories', '/admin/pages/about', '/admin/pages/faq',
            '/admin/push', '/admin/push/firebase', '/admin/result-alerts', '/admin/ai-summary',
            '/admin/patients', '/admin/doctors', '/admin/guarantors', '/admin/patients/new', "/admin/patients/{$patient->id}"] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_a_lab_manager_sees_only_their_lab(): void
    {
        $this->lab(5, 'Verdun Medical Center');
        $this->lab(9, 'Bekaa Hospital');
        $owned = User::factory()->create(['display_name' => 'Owned Patient', 'owner_lab_id' => 9]);
        $tokened = User::factory()->create(['display_name' => 'Token Patient', 'id_image' => 'https://x.test/secret-id.jpg']);
        LabAccount::create(['user_id' => $tokened->id, 'lab_id' => 9, 'external_token' => 't']);
        $refusedOnly = User::factory()->create(['display_name' => 'Refused At Bekaa']);
        LabAccount::create(['user_id' => $refusedOnly->id, 'lab_id' => 9, 'auth_failures' => 3]);
        $doctor = User::factory()->create(['display_name' => 'Bekaa Doctor', 'function_type' => 'D', 'dg_lab_id' => 9]);
        $other = User::factory()->create(['display_name' => 'Verdun Patient', 'owner_lab_id' => 5]);
        $this->actingAs($this->manager(9));

        $this->get('/admin')->assertRedirect('/admin/patients');
        $page = $this->get('/admin/patients')->assertOk()->assertSee('Owned Patient')->assertSee('Token Patient')
            ->assertDontSee('Verdun Patient')->assertDontSee('Refused At Bekaa')->assertDontSee('Labs</a>', false);
        $this->get('/admin/doctors')->assertSee('Bekaa Doctor');

        $csv = $this->get('/admin/patients/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Owned Patient', $csv);
        $this->assertStringNotContainsString('Verdun Patient', $csv);

        $this->get("/admin/patients/{$other->id}")->assertNotFound();
        $this->get("/admin/patients/{$tokened->id}")->assertOk()->assertDontSee('secret-id.jpg');

        foreach (['/admin/dashboard', '/admin/labs', '/admin/sliders', '/admin/push', '/admin/result-alerts'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post("/admin/patients/{$owned->id}/password", ['password' => 'hijacked'])->assertForbidden();
        $this->post("/admin/patients/{$owned->id}/access", ['managed_lab_id' => 5])->assertForbidden();
        $this->delete("/admin/patients/{$owned->id}")->assertForbidden();
    }

    public function test_assigning_a_lab_switches_the_role_and_never_demotes_an_admin(): void
    {
        $this->lab(9, 'Bekaa Hospital');
        $admin = $this->admin();
        $staff = User::factory()->create(['display_name' => 'Staff']);
        $this->actingAs($admin);

        $this->post("/admin/patients/{$staff->id}/access", ['managed_lab_id' => 9])->assertRedirect();
        $this->assertSame([User::ROLE_LAB_MANAGER, 9], [$staff->refresh()->role, $staff->managed_lab_id]);
        $this->post("/admin/patients/{$staff->id}/access", ['managed_lab_id' => 0]);
        $this->assertSame([User::ROLE_SUBSCRIBER, null], [$staff->refresh()->role, $staff->managed_lab_id]);

        $this->post("/admin/patients/{$admin->id}/access", ['managed_lab_id' => 9]);
        $this->assertSame([User::ROLE_ADMIN, 9], [$admin->refresh()->role, $admin->managed_lab_id]);
        // Still unscoped.
        $this->get('/admin/labs')->assertOk();
    }
}
