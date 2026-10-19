<?php

namespace Tests\Feature\Admin;

use App\Models\Lab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected function admin(array $attributes = []): User
    {
        return User::factory()->create(['username' => 'admin', 'role' => User::ROLE_ADMIN, 'display_name' => 'Site Admin', 'function_type' => null, ...$attributes]);
    }

    protected function manager(int $labId, array $attributes = []): User
    {
        return User::factory()->create(['username' => 'bekaamanager', 'role' => User::ROLE_LAB_MANAGER, 'managed_lab_id' => $labId, ...$attributes]);
    }

    protected function lab(int $id, string $title, array $attributes = []): Lab
    {
        $lab = new Lab;
        $lab->forceFill(['id' => $id, 'title' => $title, 'slug' => str($title)->slug(), 'status' => 'publish', ...$attributes])->save();

        return $lab;
    }

    protected function passwordHash(string $password): string
    {
        return Hash::make($password);
    }
}
