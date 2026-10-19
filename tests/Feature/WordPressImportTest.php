<?php

namespace Tests\Feature;

use App\Models\Lab;
use App\Models\LabAccount;
use App\Models\Setting;
use App\Models\User;
use App\Support\WordPress\WordPressPassword;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Runs the importer against a WordPress-shaped SQLite database seeded with
 * the cases the plugin has to get right: legacy un-suffixed lab metas next
 * to suffixed ones, a seen-status map whose keys look like list indexes, a
 * seeded-but-empty map, an account parked by refusals, an account from
 * before the E.164 index, a device token claimed twice, an orphaned
 * notification and an expired AI summary.
 */
class WordPressImportTest extends TestCase
{
    use RefreshDatabase;

    private const WP_HASH_OF_MEDVAULT2 = '$wp$2y$12$cGtK26iHeFQufASHDwz76O9Bf1bVebh0346cteh3Y9o4iCwLvaqkS';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.wordpress' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('wordpress');

        $this->buildWordPressSchema();
        $this->seedWordPress();
    }

    public function test_imports_users_with_their_credentials_intact(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $nour = User::findOrFail(217);
        $this->assertSame('nouralhariri', $nour->username);
        $this->assertSame('nour', $nour->first_name);
        $this->assertSame('al hariri', $nour->last_name);
        $this->assertSame('+96170918679', $nour->mobile_number);
        $this->assertSame('96170918679', $nour->mobile_e164);
        $this->assertSame('70918679', $nour->mobile_local);
        $this->assertNull($nour->email);
        $this->assertSame('P', $nour->functionType());
        $this->assertSame(User::ROLE_SUBSCRIBER, $nour->role);

        // The password hash is copied verbatim and still verifies.
        $this->assertSame(self::WP_HASH_OF_MEDVAULT2, $nour->password);
        $this->assertTrue(WordPressPassword::check('medvault2', $nour->password));

        // The bearer token the app holds keeps working: only its hash is stored.
        $this->assertSame(hash('sha256', 'apptoken217'), $nour->api_token_hash);
        $this->assertStringNotContainsString('apptoken217', json_encode(DB::table('users')->where('id', 217)->first()));

        // registered 2026-01-10 09:00 GMT, app.timezone is UTC in tests.
        $this->assertSame('2026-01-10 09:00:00', $nour->created_at->format('Y-m-d H:i:s'));
    }

    public function test_indexes_accounts_from_before_the_e164_column(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $old = User::findOrFail(8);
        $this->assertSame('03 123 456', $old->mobile_number);
        $this->assertSame('9613123456', $old->mobile_e164);
        $this->assertSame('3123456', $old->mobile_local);
        $this->assertSame('D', $old->function_type);
        $this->assertSame(5, $old->dg_lab_id);
    }

    public function test_maps_roles_and_lab_scoping(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $this->assertSame(User::ROLE_ADMIN, User::findOrFail(3)->role);
        $manager = User::findOrFail(4);
        $this->assertSame(User::ROLE_LAB_MANAGER, $manager->role);
        $this->assertSame(9, $manager->managed_lab_id);
    }

    public function test_keeps_every_lab_account_and_its_poll_state(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $accounts = LabAccount::where('user_id', 217)->get()->keyBy('lab_id');
        $this->assertSame([0, 5, 9], $accounts->keys()->sort()->values()->all());

        // The legacy un-suffixed account survives next to the migrated one.
        $this->assertSame('legacy-token', $accounts[0]->external_token);
        $this->assertSame('verdun-token', $accounts[5]->external_token);
        // Encrypted at rest.
        $raw = DB::table('lab_accounts')->where('user_id', 217)->where('lab_id', 5)->value('external_token');
        $this->assertStringNotContainsString('verdun-token', $raw);

        // Keys "0" and "1" must stay request numbers, not become list indexes.
        $this->assertSame(['0' => 'V', '1' => 'P', '1001' => 'F'], $accounts[5]->seen_status);
        $this->assertStringStartsWith('{', DB::table('lab_accounts')->where('user_id', 217)->where('lab_id', 5)->value('seen_status'));

        // Seeded but empty is not "never seeded".
        $this->assertSame([], $accounts[9]->seen_status);
        $this->assertNotNull(DB::table('lab_accounts')->where('user_id', 217)->where('lab_id', 9)->value('seen_status'));
        $this->assertSame(5, $accounts[9]->auth_failures);
        $this->assertNull($accounts[9]->external_token);

        // The legacy account had no seen map at all: it must re-seed, not alert.
        $this->assertNull($accounts[0]->seen_status);

        // polled_at was Beirut wall-clock (UTC+3 in October); stored as UTC here.
        $this->assertSame('2026-10-01 07:00:00', $accounts[5]->polled_at->format('Y-m-d H:i:s'));
        $this->assertSame('Lab API returned HTTP 503.', $accounts[5]->poll_error);
    }

    public function test_a_device_token_ends_up_with_its_most_recent_owner(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $this->assertSame(217, DB::table('devices')->where('token', 'tok-ios-217')->value('user_id'));
        $this->assertSame('ios', DB::table('devices')->where('token', 'tok-ios-217')->value('platform'));
        // Registered to 217 at 10:00 and to user 8 at 11:00; user 8 wins.
        $this->assertSame(8, DB::table('devices')->where('token', 'tok-shared')->value('user_id'));
        // The empty token is dropped, the shared one is stored once.
        $this->assertSame(2, DB::table('devices')->count());
    }

    public function test_imports_labs_with_their_api_configuration(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $verdun = Lab::findOrFail(5);
        $this->assertTrue($verdun->api_enabled);
        $this->assertSame('https://verdunmedicalcenter.net/public/index.php/api/mobile', $verdun->api_base);
        $this->assertSame('static_base', $verdun->api_pdf_mode);
        $this->assertTrue($verdun->caps_ranges && $verdun->caps_flags && $verdun->caps_pdf);
        $this->assertFalse($verdun->ai_summary);
        $this->assertSame(['https://x.test/a.jpg', 'https://x.test/b.jpg'], $verdun->slides);
        $this->assertSame('X-Api-Key: secret', $verdun->api_headers);
        $this->assertStringNotContainsString('secret', DB::table('labs')->where('id', 5)->value('api_headers'));
        $this->assertArrayNotHasKey('api_headers', $verdun->toArray());

        $bekaa = Lab::findOrFail(9);
        $this->assertSame('static_proxy', $bekaa->api_pdf_mode);
        $this->assertSame('none', $bekaa->ready_signal);
        // Saved as "0"; kept raw so the plugin's `?: 10` default can be applied.
        $this->assertSame(0, $bekaa->api_priority);
        $this->assertFalse($bekaa->caps_flags);

        $directory = Lab::findOrFail(11);
        $this->assertSame('draft', $directory->status);
        $this->assertFalse($directory->api_enabled);
        $this->assertSame('https://medvaultapp.com/app/wp-content/uploads/2026/03/clinic.png', $directory->thumbnail_url);
        $this->assertNull($directory->api_timeout);
    }

    public function test_imports_notifications_and_drops_orphans(): void
    {
        $this->artisan('medvault:import-wordpress')
            ->expectsOutputToContain('Skipped 1 notification(s) belonging to deleted users.')
            ->assertSuccessful();

        $row = DB::table('notifications')->where('id', 41)->first();
        $this->assertSame(217, (int) $row->user_id);
        $this->assertSame(5, (int) $row->lab_id);
        $this->assertSame('1001', $row->request_nb);
        $this->assertSame('2026-10-01 09:00:00', $row->created_at);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_imports_content_settings_and_live_ai_summaries(): void
    {
        $this->artisan('medvault:import-wordpress')
            ->expectsOutputToContain('Imported the about page as raw post_content')
            ->expectsOutputToContain('set APP_TIMEZONE=Asia/Beirut')
            ->assertSuccessful();

        $this->assertSame(['First', null], DB::table('sliders')->orderBy('position')->pluck('title')->all());
        $this->assertSame(1, DB::table('banners')->count());
        // An empty option means "built-in defaults", which an empty table also means.
        $this->assertSame(0, DB::table('result_categories')->count());

        $this->assertSame('<p>About MedVault</p>', DB::table('pages')->where('key', 'about')->value('content'));
        $this->assertSame(10, (int) DB::table('pages')->where('key', 'faq')->value('id'));

        $this->assertFalse(Setting::get('ai_summary_enabled'));
        $this->assertSame(50, Setting::get('poll_cursor'));
        $this->assertSame(25, Setting::get('poll_batch_size'));
        $this->assertSame(3, Setting::get('poll_last_run')['notified']);
        $this->assertSame('{"project_id":"medvault-test"}', Setting::secret('firebase_credentials'));

        $this->assertSame(['abc123'], DB::table('ai_summaries')->pluck('cache_key')->all());
        $this->assertSame('Looks good', json_decode(DB::table('ai_summaries')->value('data'), true)['intro']);
    }

    /** Explicit ids must not leave Postgres sequences pointing at taken ids. */
    public function test_new_rows_after_import_do_not_collide_with_imported_ids(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $this->assertGreaterThan(217, User::factory()->create()->id);
        $this->assertGreaterThan(41, DB::table('notifications')->insertGetId([
            'user_id' => 217, 'body' => 'new', 'created_at' => now(),
        ]));
    }

    public function test_refuses_to_overwrite_an_imported_database_without_replace(): void
    {
        $this->artisan('medvault:import-wordpress')->assertSuccessful();

        $this->artisan('medvault:import-wordpress')
            ->expectsOutputToContain('already has rows')
            ->assertFailed();

        $this->artisan('medvault:import-wordpress --replace')->assertSuccessful();
        $this->assertSame(4, User::count());
    }

    // ── Fixture ─────────────────────────────────────────────────────────────

    private function buildWordPressSchema(): void
    {
        $schema = Schema::connection('wordpress');

        $schema->create('wp_users', function (Blueprint $t) {
            $t->bigIncrements('ID');
            $t->string('user_login');
            $t->string('user_pass');
            $t->string('user_email')->default('');
            $t->dateTime('user_registered');
            $t->string('display_name')->default('');
        });
        $schema->create('wp_usermeta', function (Blueprint $t) {
            $t->bigIncrements('umeta_id');
            $t->unsignedBigInteger('user_id');
            $t->string('meta_key');
            $t->longText('meta_value')->nullable();
        });
        $schema->create('wp_posts', function (Blueprint $t) {
            $t->bigIncrements('ID');
            $t->string('post_type');
            $t->string('post_status');
            $t->string('post_title')->default('');
            $t->string('post_name')->default('');
            $t->longText('post_content')->default('');
            $t->text('post_excerpt')->default('');
            $t->integer('menu_order')->default(0);
            $t->string('guid')->default('');
            $t->dateTime('post_date_gmt')->nullable();
            $t->dateTime('post_modified_gmt')->nullable();
        });
        $schema->create('wp_postmeta', function (Blueprint $t) {
            $t->bigIncrements('meta_id');
            $t->unsignedBigInteger('post_id');
            $t->string('meta_key');
            $t->longText('meta_value')->nullable();
        });
        $schema->create('wp_options', function (Blueprint $t) {
            $t->bigIncrements('option_id');
            $t->string('option_name')->unique();
            $t->longText('option_value');
        });
        $schema->create('wp_medvault_notifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('title')->default('');
            $t->text('body');
            $t->string('type', 40)->default('general');
            $t->unsignedBigInteger('lab_id')->default(0);
            $t->string('request_nb', 64)->default('');
            $t->boolean('is_read')->default(false);
            $t->dateTime('created_at');
        });
    }

    private function seedWordPress(): void
    {
        $wp = DB::connection('wordpress');

        $options = [
            'siteurl' => 'https://medvaultapp.com/app',
            'timezone_string' => 'Asia/Beirut',
            'custom_slider_items' => serialize([
                ['image_url' => 'https://x.test/s1.jpg', 'title' => 'First', 'link_url' => ''],
                ['image_url' => 'https://x.test/s2.jpg', 'title' => '', 'link_url' => 'https://x.test'],
            ]),
            'custom_banner_items' => serialize([['image_url' => 'https://x.test/b.jpg', 'link_url' => 'https://x.test/b']]),
            'medvault_result_categories' => serialize([]),
            'medvault_ai_summary_enabled' => '0',
            'medvault_firebase_credentials' => '{"project_id":"medvault-test"}',
            'medvault_poll_cursor' => '50',
            'medvault_poll_batch_size' => '25',
            'medvault_poll_last_run' => serialize(['notified' => 3, 'polled' => 25]),
            'medvault_lab_api_bootstrapped' => '1',
            '_transient_ai_summary_abc123' => serialize(['intro' => 'Looks good', 'sections' => []]),
            '_transient_timeout_ai_summary_abc123' => (string) (time() + 86400),
            '_transient_ai_summary_old' => serialize(['intro' => 'Expired']),
            '_transient_timeout_ai_summary_old' => (string) (time() - 60),
        ];
        foreach ($options as $name => $value) {
            $wp->table('wp_options')->insert(['option_name' => $name, 'option_value' => $value]);
        }

        $wp->table('wp_posts')->insert([
            ['ID' => 5, 'post_type' => 'lab', 'post_status' => 'publish', 'post_title' => 'Verdun Medical Center', 'post_name' => 'verdun', 'post_date_gmt' => '2025-05-01 08:00:00', 'post_modified_gmt' => '2026-09-01 08:00:00', 'guid' => ''],
            ['ID' => 9, 'post_type' => 'lab', 'post_status' => 'publish', 'post_title' => 'Bekaa Hospital', 'post_name' => 'bekaa', 'post_date_gmt' => '2026-09-01 08:00:00', 'post_modified_gmt' => '2026-09-09 08:00:00', 'guid' => ''],
            ['ID' => 11, 'post_type' => 'lab', 'post_status' => 'draft', 'post_title' => 'Directory Clinic', 'post_name' => 'clinic', 'post_date_gmt' => '2026-03-01 08:00:00', 'post_modified_gmt' => '2026-03-01 08:00:00', 'guid' => ''],
            ['ID' => 13, 'post_type' => 'lab', 'post_status' => 'auto-draft', 'post_title' => 'Auto Draft', 'post_name' => '', 'post_date_gmt' => null, 'post_modified_gmt' => null, 'guid' => ''],
            ['ID' => 20, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'clinic', 'post_name' => 'clinic-png', 'post_date_gmt' => null, 'post_modified_gmt' => null, 'guid' => 'https://medvaultapp.com/app/?attachment_id=20'],
            ['ID' => 10, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'FAQ', 'post_name' => 'faq', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_modified_gmt' => '2025-01-01 00:00:00', 'guid' => ''],
            ['ID' => 12, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_name' => 'about', 'post_date_gmt' => '2025-01-01 00:00:00', 'post_modified_gmt' => '2025-01-01 00:00:00', 'guid' => ''],
        ]);
        $wp->table('wp_posts')->where('ID', 12)->update(['post_content' => '<p>About MedVault</p>']);

        $postMeta = [
            5 => [
                '_lab_api_enabled' => '1', '_lab_api_driver' => 'vmc_v1',
                '_lab_api_base' => 'https://verdunmedicalcenter.net/public/index.php/api/mobile',
                '_lab_api_pdf_base' => 'https://verdunmedicalcenter.net/storage/app/7mJ~33/',
                '_lab_api_pdf_mode' => 'static_base', '_lab_mobile_format' => 'lb_trunk_zero',
                '_lab_ready_signal' => 'status', '_lab_api_timeout' => '15', '_lab_api_priority' => '10',
                '_lab_caps_ranges' => '1', '_lab_caps_flags' => '1', '_lab_caps_pdf' => '1', '_lab_ai_summary' => '',
                '_lab_api_headers' => 'X-Api-Key: secret',
                '_lab_slides' => serialize(['https://x.test/a.jpg', '', 'https://x.test/b.jpg']),
                '_lab_phone' => '01 123 456', '_lab_latitude' => '33.8938',
            ],
            9 => [
                '_lab_api_enabled' => '1', '_lab_api_base' => 'https://tunnel.test/api/mobile',
                '_lab_api_pdf_mode' => 'static_proxy', '_lab_ready_signal' => 'none',
                '_lab_api_priority' => '0', '_lab_caps_ranges' => '1', '_lab_caps_flags' => '',
            ],
            11 => ['_thumbnail_id' => '20', '_lab_location' => 'Hamra'],
            20 => ['_wp_attached_file' => '2026/03/clinic.png'],
        ];
        foreach ($postMeta as $postId => $metas) {
            foreach ($metas as $key => $value) {
                $wp->table('wp_postmeta')->insert(['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $value]);
            }
        }

        $wp->table('wp_users')->insert([
            ['ID' => 3, 'user_login' => 'admin', 'user_pass' => '$P$Bcouc16czTkeDtRk/W0Eh2vJBUuXzw.', 'user_email' => 'admin@medvault.test', 'user_registered' => '2025-01-01 00:00:00', 'display_name' => 'Admin'],
            ['ID' => 4, 'user_login' => 'bekaamanager', 'user_pass' => '$P$Bn/ZykQ07hip7mWsL0/bN8yRKCVBj0/', 'user_email' => '', 'user_registered' => '2026-09-01 00:00:00', 'display_name' => 'Bekaa Manager'],
            ['ID' => 8, 'user_login' => 'olddoctor', 'user_pass' => 'e10adc3949ba59abbe56e057f20f883e', 'user_email' => 'doc@medvault.test', 'user_registered' => '2024-05-05 05:05:05', 'display_name' => 'Old Doctor'],
            ['ID' => 217, 'user_login' => 'nouralhariri', 'user_pass' => self::WP_HASH_OF_MEDVAULT2, 'user_email' => '', 'user_registered' => '2026-01-10 09:00:00', 'display_name' => 'nour al hariri'],
        ]);

        $userMeta = [
            3 => ['wp_capabilities' => serialize(['administrator' => true])],
            4 => ['wp_capabilities' => serialize(['medvault_lab_manager' => true]), '_managed_lab_id' => '9'],
            8 => [
                'wp_capabilities' => serialize(['subscriber' => true]),
                // Registered before _mobile_e164 existed.
                '_mobile_number' => '03 123 456', '_function_type' => 'D', '_dg_lab_id' => '5',
                'first_name' => 'Old', 'last_name' => 'Doctor',
                '_fcm_tokens' => serialize([['token' => 'tok-shared', 'platform' => 'android', 'updated' => '2026-10-01 11:00:00']]),
            ],
            217 => [
                'wp_capabilities' => serialize(['subscriber' => true]),
                '_first_name' => 'nour', '_last_name' => 'al hariri',
                '_mobile_number' => '+96170918679', '_mobile_e164' => '96170918679', '_mobile_cc' => '961', '_mobile_local' => '70918679',
                '_function_type' => 'P', '_api_auth_token' => 'apptoken217', '_id_image' => '',
                // Legacy single-lab metas, left behind by the bootstrap.
                '_lab_external_token' => 'legacy-token',
                '_lab_external_token_5' => 'verdun-token',
                '_lab_seen_status_5' => serialize(['0' => 'V', '1' => 'P', '1001' => 'F']),
                '_lab_polled_at_5' => '2026-10-01 10:00:00',
                '_lab_poll_error_5' => 'Lab API returned HTTP 503.',
                '_lab_seen_status_9' => serialize([]),
                '_lab_auth_failures_9' => '5',
                '_fcm_tokens' => serialize([
                    ['token' => 'tok-ios-217', 'platform' => 'ios', 'updated' => '2026-10-01 09:00:00'],
                    ['token' => 'tok-shared', 'platform' => 'android', 'updated' => '2026-10-01 10:00:00'],
                    ['token' => '', 'platform' => 'android', 'updated' => '2026-10-01 10:00:00'],
                ]),
            ],
        ];
        foreach ($userMeta as $userId => $metas) {
            foreach ($metas as $key => $value) {
                $wp->table('wp_usermeta')->insert(['user_id' => $userId, 'meta_key' => $key, 'meta_value' => $value]);
            }
        }

        $wp->table('wp_medvault_notifications')->insert([
            ['id' => 41, 'user_id' => 217, 'title' => 'Your results are ready', 'body' => 'Hematology results for request 1001 are now available in MedVault.', 'type' => 'result_ready', 'lab_id' => 5, 'request_nb' => '1001', 'is_read' => 0, 'created_at' => '2026-10-01 12:00:00'],
            ['id' => 42, 'user_id' => 999, 'title' => 'Gone', 'body' => 'Deleted user', 'type' => 'result_ready', 'lab_id' => 5, 'request_nb' => '7', 'is_read' => 0, 'created_at' => '2026-10-01 12:00:00'],
        ]);
    }
}
