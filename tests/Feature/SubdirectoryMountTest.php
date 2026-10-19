<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production serves Laravel from public_html/app/medvault.php, reached by
 * rewrites from /app/wp-json/... (deploy/htaccess-app.conf). The app's base
 * URL must keep working without any route knowing about the /app prefix.
 */
class SubdirectoryMountTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER = [
        'SCRIPT_NAME' => '/app/medvault.php',
        'PHP_SELF' => '/app/medvault.php',
        'SCRIPT_FILENAME' => '/home/medvaultapp/public_html/app/medvault.php',
        'HTTP_HOST' => 'medvaultapp.com',
        'HTTPS' => 'on',
        'SERVER_PORT' => 443,
        'HTTP_ACCEPT' => 'application/json',
    ];

    public function test_the_apps_base_url_reaches_the_api_through_the_front_controller(): void
    {
        $this->call('GET', 'https://medvaultapp.com/app/wp-json/custom/v1/phone', [], [], [], self::SERVER)
            ->assertOk()->assertJsonPath('data.phone', '+96176618326');

        $this->call('POST', 'https://medvaultapp.com/app/wp-json/custom/v1/login', [], [], [], self::SERVER)
            ->assertStatus(400)->assertJsonPath('code', 'rest_missing_callback_param');
    }

    public function test_generated_links_keep_the_app_prefix(): void
    {
        $this->call('GET', 'https://medvaultapp.com/app/admin/login', [], [], [], self::SERVER)
            ->assertOk()
            ->assertSee('action="https://medvaultapp.com/app/admin/login"', false);
    }
}
