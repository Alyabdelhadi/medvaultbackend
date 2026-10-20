<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TidyUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_then_capitalizes_names_and_adds_the_country_code(): void
    {
        $patient = User::factory()->create([
            'first_name' => 'jean-paul', 'last_name' => 'haddad', 'display_name' => 'jean-paul haddad',
            'mobile_number' => '03 123 456', 'mobile_e164' => '9613123456', 'mobile_cc' => '961', 'mobile_local' => '3123456',
        ]);
        $doctor = User::factory()->create(['function_type' => 'D', 'first_name' => 'rami', 'display_name' => 'Dr Rami',
            'mobile_number' => '+97470536332', 'mobile_e164' => '97470536332', 'mobile_cc' => '974', 'mobile_local' => null]);
        $arabic = User::factory()->create(['first_name' => 'علي', 'mobile_number' => '+96170111222',
            'mobile_e164' => '96170111222', 'mobile_local' => '70111222']);
        $odd = User::factory()->create(['first_name' => 'Nour', 'mobile_number' => '70999888', 'mobile_e164' => '96170000000']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'first_name' => 'admin', 'mobile_number' => '70555444']);

        $this->artisan('medvault:tidy-users')->expectsOutputToContain('Would update 2 first names and 1 mobile numbers')->assertSuccessful();
        $this->assertSame('jean-paul', $patient->fresh()->first_name);

        $this->artisan('medvault:tidy-users --apply')->assertSuccessful();

        $patient->refresh();
        $this->assertSame('Jean-Paul', $patient->first_name);
        $this->assertSame('Jean-Paul haddad', $patient->display_name);
        $this->assertSame('+9613123456', $patient->mobile_number);
        $this->assertSame('9613123456', $patient->mobile_e164);
        $this->assertSame('Rami', $doctor->fresh()->first_name);
        $this->assertSame('Dr Rami', $doctor->fresh()->display_name);
        $this->assertSame('+97470536332', $doctor->fresh()->mobile_number);
        $this->assertSame('علي', $arabic->fresh()->first_name);
        $this->assertSame('70999888', $odd->fresh()->mobile_number);
        $this->assertSame('admin', $admin->fresh()->first_name);
        $this->assertSame('70555444', $admin->fresh()->mobile_number);

        $this->artisan('medvault:tidy-users')->expectsOutputToContain('Would update 0 first names and 0 mobile numbers')->assertSuccessful();
    }
}
