<?php

namespace Tests\Feature\Api;

use App\Models\Lab;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected const API = '/wp-json/custom/v1';

    /** The WordPress 6.8 hash of "medvault2", as imported. */
    protected const WP_HASH = '$wp$2y$12$cGtK26iHeFQufASHDwz76O9Bf1bVebh0346cteh3Y9o4iCwLvaqkS';

    protected function lab(int $id, string $title, array $attributes = []): Lab
    {
        $lab = new Lab;
        $lab->forceFill([
            'id' => $id,
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'publish',
            'api_enabled' => true,
            'api_base' => "https://lab{$id}.test/api/mobile",
            'caps_ranges' => true,
            'caps_flags' => true,
            'caps_pdf' => true,
            ...$attributes,
        ])->save();

        return $lab;
    }

    protected function patient(array $attributes = []): User
    {
        return User::factory()->create([
            'id' => 217,
            'username' => 'nouralhariri',
            'first_name' => 'nour',
            'last_name' => 'al hariri',
            'display_name' => 'nour al hariri',
            'password' => self::WP_HASH,
            'mobile_number' => '+96170918679',
            'mobile_e164' => '96170918679',
            'mobile_cc' => '961',
            'mobile_local' => '70918679',
            ...$attributes,
        ]);
    }

    /** @return array{0: User, 1: string} */
    protected function signedIn(array $attributes = []): array
    {
        $user = $this->patient($attributes);

        return [$user, app(ApiTokens::class)->issue($user)];
    }

    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }
}
