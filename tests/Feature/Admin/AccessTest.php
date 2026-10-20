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
            '/admin/patients', '/admin/doctors', '/admin/guarantors', '/admin/patients/new', "/admin/patients/{$patient->id}",
            '/admin/accounting', "/admin/accounting/labs/{$lab->id}", "/admin/accounting/labs/{$lab->id}/plan", "/admin/accounting/labs/{$lab->id}/payments/new",
            '/admin/accounts', '/admin/accounts/new', '/admin/groups', '/admin/groups/new'] as $path) {
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

        // An imported manager keeps the plugin's pages, plus a dashboard and
        // notifications for its own lab.
        $this->get('/admin')->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')->assertOk()->assertSee('Bekaa Hospital');
        $page = $this->get('/admin/patients')->assertOk()->assertSee('Owned Patient')->assertSee('Token Patient')
            ->assertDontSee('Verdun Patient')->assertDontSee('Refused At Bekaa')->assertDontSee('Labs</a>', false);
        $this->get('/admin/doctors')->assertSee('Bekaa Doctor');

        $csv = $this->get('/admin/patients/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Owned Patient', $csv);
        $this->assertStringNotContainsString('Verdun Patient', $csv);

        $this->get("/admin/patients/{$other->id}")->assertNotFound();
        $this->get("/admin/patients/{$tokened->id}")->assertOk()->assertDontSee('secret-id.jpg');

        foreach (['/admin/labs', '/admin/sliders', '/admin/result-alerts', '/admin/accounting', '/admin/accounts', '/admin/groups', '/admin/push/firebase'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post("/admin/patients/{$owned->id}/password", ['password' => 'hijacked'])->assertForbidden();
        $this->delete("/admin/patients/{$owned->id}")->assertForbidden();
    }
}
