<?php

namespace Tests\Feature\Admin;

use App\Http\WpError;
use App\Models\ErrorLog;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class ErrorLogTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);
        Route::get('wp-json/custom/v1/boom', fn () => throw new RuntimeException('kaboom'));
    }

    public function test_the_same_failure_is_one_row_with_a_count_and_a_resolved_one_comes_back(): void
    {
        $this->getJson('/wp-json/custom/v1/boom?sig=secret-signature')->assertStatus(500)->assertJsonPath('code', 'internal_server_error');
        $this->getJson('/wp-json/custom/v1/boom')->assertStatus(500);

        $error = ErrorLog::sole();
        $this->assertSame([RuntimeException::class, 'kaboom', 2, 'GET', '/wp-json/custom/v1/boom'], [$error->class, $error->message, $error->count, $error->method, $error->url]);
        $this->assertStringNotContainsString('secret-signature', json_encode($error->toArray()));
        $this->assertStringStartsWith('tests/Feature/Admin/ErrorLogTest.php', $error->file);

        $error->forceFill(['resolved_at' => now()])->save();
        $this->getJson('/wp-json/custom/v1/boom')->assertStatus(500);
        $this->assertNull($error->refresh()->resolved_at);
        $this->assertSame(3, $error->count);
    }

    public function test_expected_errors_are_not_recorded(): void
    {
        Route::get('wp-json/custom/v1/refused', fn () => throw new WpError('nope', 'No.', 400));

        $this->getJson('/wp-json/custom/v1/refused')->assertStatus(400);
        $this->getJson('/wp-json/custom/v1/no-such-route')->assertNotFound();

        $this->assertSame(0, ErrorLog::count());
    }

    public function test_admins_read_resolve_and_clear_errors_and_lab_accounts_cannot(): void
    {
        $this->getJson('/wp-json/custom/v1/boom');
        $error = ErrorLog::sole();

        $this->lab(9, 'Bekaa Lab');
        $this->actingAs($this->manager(9))->get('/admin/errors')->assertForbidden();

        $this->actingAs($this->admin());
        $this->get('/admin/errors')->assertOk()->assertSee('kaboom');
        $this->get("/admin/errors/{$error->id}")->assertOk()->assertSee('ErrorLogTest.php');
        $this->post("/admin/errors/{$error->id}/resolve")->assertRedirect();
        $this->assertNotNull($error->refresh()->resolved_at);
        $this->get('/admin/errors')->assertSee('No open errors');

        $this->delete('/admin/errors')->assertRedirect();
        $this->assertSame(0, ErrorLog::count());
    }

    public function test_old_logs_are_pruned_but_open_errors_stay(): void
    {
        ErrorLog::create(['fingerprint' => 'a', 'class' => 'X', 'message' => 'old, resolved', 'resolved_at' => now()->subDays(200), 'last_seen_at' => now()->subDays(200)]);
        ErrorLog::create(['fingerprint' => 'b', 'class' => 'X', 'message' => 'old, open', 'last_seen_at' => now()->subDays(400)]);

        $this->artisan('medvault:prune-logs')->assertSuccessful();

        $this->assertSame(['old, open'], ErrorLog::pluck('message')->all());
    }
}
