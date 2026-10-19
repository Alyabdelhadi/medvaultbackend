<?php

namespace App\Services\WordPress;

use App\Models\Setting;
use App\Support\Mobile;
use App\Support\WordPress\Serialized;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Copies a MedVault WordPress database into the Laravel schema.
 *
 * Read-only on the WordPress side. Everything that identifies a record to the
 * outside world is carried over unchanged, because the app, the labs and
 * signed links already hold it:
 *
 *   * user and lab ids (signed PDF links, lab_id on every request),
 *   * bearer tokens (stored hashed; the plaintext the app holds still works),
 *   * password hashes (verified by WordPressPassword until first sign-in),
 *   * lab tokens, refusal counters and seen-status maps per (user, lab),
 *   * notification ids, and the cached AI summaries that would otherwise be
 *     re-billed.
 *
 * Seen-status maps are the one thing that must not be "cleaned up" on the
 * way: a missing map re-seeds the account at the next poll, and a re-seed
 * silently swallows every result that turned ready in between.
 */
final class WordPressImporter
{
    /** Tables this importer writes, children first so they can be cleared. */
    public const TARGET_TABLES = [
        'ai_summaries', 'notifications', 'devices', 'lab_accounts', 'users',
        'labs', 'sliders', 'banners', 'result_categories', 'pages', 'settings',
    ];

    private const LAB_META_PATTERN = '/^_(lab_external_token|lab_seen_status|lab_auth_failures|lab_poll_error|lab_polled_at)(?:_(\d+))?$/';

    private const USER_META_KEYS = [
        '_first_name', 'first_name', '_last_name', 'last_name', '_dob', '_gender',
        '_blood_type', '_profile_pic', '_id_image', '_insurance_image',
        '_mobile_number', '_mobile_e164', '_mobile_cc', '_mobile_local',
        '_function_type', '_api_auth_token', '_managed_lab_id', '_owner_lab_id',
        '_dg_lab_id', '_fcm_tokens',
    ];

    /** WordPress page ids the old /about and /faq endpoints were hardcoded to. */
    private const PAGES = ['about' => 12, 'faq' => 10];

    private array $report = [];

    private array $warnings = [];

    private ?DateTimeZone $wpZone = null;

    /** @var array<int, true> */
    private array $importedUserIds = [];

    /**
     * @param  ?string  $siteUrl  the WordPress site, e.g. https://medvaultapp.com/app,
     *                            used to fetch the rendered About/FAQ HTML
     * @param  ?Closure(string): void  $log
     */
    public function __construct(
        private readonly ConnectionInterface $wp,
        private readonly string $prefix = 'wp_',
        private readonly ?string $siteUrl = null,
        private readonly ?Closure $log = null,
    ) {}

    /**
     * @return array{counts: array<string, int>, warnings: list<string>}
     */
    public function run(bool $replace = false): array
    {
        $this->assertTargetEmpty($replace);

        DB::transaction(function () use ($replace) {
            if ($replace) {
                foreach (self::TARGET_TABLES as $table) {
                    DB::table($table)->delete();
                }
            }

            $this->importLabs();
            $this->importUsers();
            $this->importNotifications();
            $this->importHomeImages();
            $this->importResultCategories();
            $this->importPages();
            $this->importSettings();
            $this->importAiSummaries();
        });

        $this->resetSequences();
        $this->checkTimezone();

        return ['counts' => $this->report, 'warnings' => $this->warnings];
    }

    // ── Labs ────────────────────────────────────────────────────────────────

    private function importLabs(): void
    {
        $posts = $this->wp->table($this->t('posts'))
            ->where('post_type', 'lab')
            ->whereNotIn('post_status', ['auto-draft', 'inherit'])
            ->orderBy('ID')
            ->get();

        $metas = $this->postMetas($posts->pluck('ID')->all());
        $uploads = $this->uploadsBaseUrl();
        $rows = [];

        foreach ($posts as $post) {
            $m = $metas[$post->ID] ?? [];
            $text = fn (string $key) => $this->nonEmpty($m[$key] ?? null);
            $flag = fn (string $key) => (string) ($m[$key] ?? '') === '1';
            $int = function (string $key) use ($m): ?int {
                $value = $m[$key] ?? null;

                return $value === null || $value === '' ? null : (int) $value;
            };

            $slides = Serialized::decode($m['_lab_slides'] ?? null);
            $slides = is_array($slides)
                ? array_values(array_filter(array_map('strval', $slides), fn ($u) => $u !== ''))
                : null;

            $rows[] = [
                'id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'slug' => (string) $post->post_name,
                'content' => (string) $post->post_content,
                'status' => (string) $post->post_status,
                'menu_order' => (int) ($post->menu_order ?? 0),
                'image_url' => $text('_lab_image_url'),
                'thumbnail_url' => $this->attachmentUrl((int) ($m['_thumbnail_id'] ?? 0), $uploads),
                'location' => $text('_lab_location'),
                'google_map_url' => $text('_lab_google_map_url'),
                'latitude' => $text('_lab_latitude'),
                'longitude' => $text('_lab_longitude'),
                'appointment_phone' => $text('_lab_appointment_phone'),
                'mobile_phone' => $text('_lab_mobile_phone'),
                'phone' => $text('_lab_phone'),
                'email' => $text('_lab_email'),
                'website' => $text('_lab_website'),
                'refer_link' => $text('_lab_refer_link'),
                'refer_text' => $text('_lab_refer_text'),
                'facebook' => $text('_lab_facebook'),
                'instagram' => $text('_lab_instagram'),
                'twitter' => $text('_lab_twitter'),
                'linkedin' => $text('_lab_linkedin'),
                'youtube' => $text('_lab_youtube'),
                'tiktok' => $text('_lab_tiktok'),
                'slides' => $slides === null ? null : json_encode($slides),
                'api_enabled' => $flag('_lab_api_enabled'),
                'api_driver' => $text('_lab_api_driver'),
                'api_base' => $text('_lab_api_base'),
                'api_pdf_base' => $text('_lab_api_pdf_base'),
                'api_pdf_mode' => $text('_lab_api_pdf_mode'),
                'api_headers' => ($h = $text('_lab_api_headers')) === null ? null : Crypt::encryptString($h),
                'api_timeout' => $int('_lab_api_timeout'),
                'api_priority' => $int('_lab_api_priority'),
                'mobile_format' => $text('_lab_mobile_format'),
                'ready_signal' => $text('_lab_ready_signal'),
                'caps_ranges' => $flag('_lab_caps_ranges'),
                'caps_flags' => $flag('_lab_caps_flags'),
                'caps_pdf' => $flag('_lab_caps_pdf'),
                'ai_summary' => $flag('_lab_ai_summary'),
                'created_at' => $this->gmtToApp($post->post_date_gmt ?? null),
                'updated_at' => $this->gmtToApp($post->post_modified_gmt ?? null),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('labs')->insert($chunk);
        }

        $this->count('labs', count($rows));
        $this->count('labs_with_api', count(array_filter($rows, fn ($r) => $r['api_enabled'])));
    }

    // ── Users, lab accounts, devices ────────────────────────────────────────

    private function importUsers(): void
    {
        $capabilitiesKey = $this->prefix.'capabilities';
        $devices = [];

        $this->wp->table($this->t('users'))
            ->select(['ID', 'user_login', 'user_pass', 'user_email', 'user_registered', 'display_name'])
            ->orderBy('ID')
            ->chunk(500, function ($users) use ($capabilitiesKey, &$devices) {
                $ids = $users->pluck('ID')->map(fn ($id) => (int) $id)->all();
                $metas = $this->userMetas($ids, $capabilitiesKey);

                $userRows = [];
                $accountRows = [];

                foreach ($users as $u) {
                    $id = (int) $u->ID;
                    $m = $metas[$id] ?? [];

                    $userRows[] = $this->userRow($u, $m, $capabilitiesKey);
                    $this->importedUserIds[$id] = true;

                    foreach ($this->labAccountRows($id, $m) as $row) {
                        $accountRows[] = $row;
                    }

                    $tokens = Serialized::decode($m['_fcm_tokens'] ?? null);
                    foreach (is_array($tokens) ? $tokens : [] as $entry) {
                        $token = is_array($entry) ? trim((string) ($entry['token'] ?? '')) : '';
                        if ($token === '') {
                            continue;
                        }
                        $updated = $this->localToApp($entry['updated'] ?? null);
                        // A token is unique across accounts. WordPress
                        // detached it from everyone else on register, so a
                        // duplicate is a race; the most recent owner wins.
                        if (! isset($devices[$token]) || strcmp((string) $updated, (string) $devices[$token]['updated_at']) > 0) {
                            $devices[$token] = [
                                'user_id' => $id,
                                'token' => $token,
                                'platform' => strtolower((string) ($entry['platform'] ?? '')) ?: 'unknown',
                                'created_at' => $updated,
                                'updated_at' => $updated,
                            ];
                        }
                    }
                }

                DB::table('users')->insert($userRows);
                foreach (array_chunk($accountRows, 500) as $chunk) {
                    DB::table('lab_accounts')->insert($chunk);
                }

                $this->count('users', count($userRows));
                $this->count('lab_accounts', count($accountRows));
            });

        foreach (array_chunk(array_values($devices), 500) as $chunk) {
            DB::table('devices')->insert($chunk);
        }
        $this->count('devices', count($devices));
    }

    private function userRow(object $u, array $m, string $capabilitiesKey): array
    {
        $raw = (string) ($m['_mobile_number'] ?? '');

        // The derived columns WordPress already wrote win; accounts from
        // before they existed are indexed now, which is what the plugin's
        // backfill would eventually have done.
        $index = Mobile::indexColumns($raw);
        if ($this->nonEmpty($m['_mobile_e164'] ?? null) !== null) {
            $index = [
                'mobile_e164' => (string) $m['_mobile_e164'],
                'mobile_cc' => $this->nonEmpty($m['_mobile_cc'] ?? null),
                'mobile_local' => $this->nonEmpty($m['_mobile_local'] ?? null),
            ];
        }

        $token = $this->nonEmpty($m['_api_auth_token'] ?? null);
        $type = strtoupper(trim((string) ($m['_function_type'] ?? '')));

        return [
            'id' => (int) $u->ID,
            'username' => (string) $u->user_login,
            'email' => $this->nonEmpty($u->user_email),
            'password' => (string) $u->user_pass,
            'display_name' => (string) $u->display_name,
            // build_profile_data(): the underscored meta, else the core field.
            'first_name' => $this->nonEmpty($m['_first_name'] ?? null) ?? $this->nonEmpty($m['first_name'] ?? null),
            'last_name' => $this->nonEmpty($m['_last_name'] ?? null) ?? $this->nonEmpty($m['last_name'] ?? null),
            'dob' => $this->nonEmpty($m['_dob'] ?? null),
            'gender' => $this->nonEmpty($m['_gender'] ?? null),
            'blood_type' => $this->nonEmpty($m['_blood_type'] ?? null),
            'profile_pic' => $this->nonEmpty($m['_profile_pic'] ?? null),
            'id_image' => $this->nonEmpty($m['_id_image'] ?? null),
            'insurance_image' => $this->nonEmpty($m['_insurance_image'] ?? null),
            'mobile_number' => $this->nonEmpty($raw),
            ...$index,
            'function_type' => in_array($type, ['P', 'D', 'G'], true) ? $type : null,
            'role' => $this->role(Serialized::decode($m[$capabilitiesKey] ?? null)),
            'managed_lab_id' => $this->positiveInt($m['_managed_lab_id'] ?? null),
            'owner_lab_id' => $this->positiveInt($m['_owner_lab_id'] ?? null),
            'dg_lab_id' => $this->positiveInt($m['_dg_lab_id'] ?? null),
            'api_token_hash' => $token === null ? null : hash('sha256', $token),
            'created_at' => $this->gmtToApp($u->user_registered),
            'updated_at' => $this->gmtToApp($u->user_registered),
        ];
    }

    /**
     * One row per lab the user has any per-lab meta for. Un-suffixed keys
     * are the legacy account, lab_id 0, and are kept even where the plugin's
     * bootstrap already copied them onto the Verdun lab: an old app build
     * that sends no lab_id still reads them.
     */
    private function labAccountRows(int $userId, array $m): array
    {
        $byLab = [];
        foreach ($m as $key => $value) {
            if (! preg_match(self::LAB_META_PATTERN, (string) $key, $match)) {
                continue;
            }
            $byLab[(int) ($match[2] ?? 0)][$match[1]] = $value;
        }

        $rows = [];
        foreach ($byLab as $labId => $fields) {
            $token = $this->nonEmpty($fields['lab_external_token'] ?? null);

            // Only an array is a seeded map; anything else is what the poller
            // itself treated as "not seeded yet".
            $seen = array_key_exists('lab_seen_status', $fields)
                ? Serialized::decode($fields['lab_seen_status'])
                : null;
            $seen = is_array($seen) ? $seen : null;

            $rows[] = [
                'user_id' => $userId,
                'lab_id' => $labId,
                'external_token' => $token === null ? null : Crypt::encryptString($token),
                'auth_failures' => max(0, (int) ($fields['lab_auth_failures'] ?? 0)),
                // Always a JSON object, so request numbers that happen to run
                // 0..n are not re-indexed into a list; {} is seeded-but-empty.
                'seen_status' => $seen === null ? null : json_encode((object) $seen),
                'poll_error' => $this->nonEmpty($fields['lab_poll_error'] ?? null),
                'polled_at' => $this->localToApp($fields['lab_polled_at'] ?? null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $rows;
    }

    private function role(mixed $capabilities): string
    {
        if (! is_array($capabilities)) {
            return 'subscriber';
        }

        $roles = array_keys(array_filter($capabilities));
        foreach (['administrator', 'medvault_lab_manager'] as $privileged) {
            if (in_array($privileged, $roles, true)) {
                return $privileged === 'medvault_lab_manager' ? 'lab_manager' : $privileged;
            }
        }

        return (string) ($roles[0] ?? 'subscriber');
    }

    // ── Notifications ───────────────────────────────────────────────────────

    private function importNotifications(): void
    {
        $table = $this->t('medvault_notifications');
        if (! $this->wp->getSchemaBuilder()->hasTable($table)) {
            $this->warn("No {$table} table; the notification log starts empty.");

            return;
        }

        $orphans = 0;
        $imported = 0;

        $this->wp->table($table)->orderBy('id')->chunk(1000, function ($rows) use (&$orphans, &$imported) {
            $insert = [];
            foreach ($rows as $row) {
                // WordPress left a deleted user's notifications behind.
                if (! isset($this->importedUserIds[(int) $row->user_id])) {
                    $orphans++;

                    continue;
                }
                $insert[] = [
                    'id' => (int) $row->id,
                    'user_id' => (int) $row->user_id,
                    'title' => (string) $row->title,
                    'body' => (string) $row->body,
                    'type' => (string) $row->type,
                    'lab_id' => (int) ($row->lab_id ?? 0),
                    'request_nb' => (string) $row->request_nb,
                    'is_read' => (bool) (int) $row->is_read,
                    'created_at' => $this->localToApp($row->created_at),
                ];
            }
            DB::table('notifications')->insert($insert);
            $imported += count($insert);
        });

        $this->count('notifications', $imported);
        if ($orphans > 0) {
            $this->warn("Skipped {$orphans} notification(s) belonging to deleted users.");
        }
    }

    // ── Content ─────────────────────────────────────────────────────────────

    private function importHomeImages(): void
    {
        $now = now();

        $slides = Serialized::decode($this->option('custom_slider_items'));
        $rows = [];
        foreach (is_array($slides) ? array_values($slides) : [] as $i => $s) {
            $rows[] = [
                'position' => $i,
                'image_url' => (string) ($s['image_url'] ?? ''),
                'title' => $this->nonEmpty($s['title'] ?? null),
                'link_url' => $this->nonEmpty($s['link_url'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('sliders')->insert($rows);
        $this->count('sliders', count($rows));

        $banners = Serialized::decode($this->option('custom_banner_items'));
        $rows = [];
        foreach (is_array($banners) ? array_values($banners) : [] as $i => $b) {
            $rows[] = [
                'position' => $i,
                'image_url' => (string) ($b['image_url'] ?? ''),
                'link_url' => $this->nonEmpty($b['link_url'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('banners')->insert($rows);
        $this->count('banners', count($rows));
    }

    private function importResultCategories(): void
    {
        // Absent or empty means "use the built-in defaults", which an empty
        // table also means, so nothing is written for it.
        $categories = Serialized::decode($this->option('medvault_result_categories'));
        $rows = [];
        foreach (is_array($categories) ? array_values($categories) : [] as $i => $c) {
            if (! is_array($c)) {
                continue;
            }
            $rows[] = [
                'position' => $i,
                'label' => (string) ($c['label'] ?? ''),
                'cat_names' => json_encode(array_values((array) ($c['cat_names'] ?? []))),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('result_categories')->insert($rows);
        $this->count('result_categories', count($rows));
    }

    /**
     * The About and FAQ pages, as the HTML the app already receives.
     *
     * That HTML is post_content run through WordPress's the_content filters
     * (paragraphs, blocks, shortcodes), which cannot be reproduced here. So
     * the rendered copy is taken from the live endpoint when a site URL is
     * given, and the raw post_content is the fallback, with a warning.
     */
    private function importPages(): void
    {
        $posts = $this->wp->table($this->t('posts'))
            ->whereIn('ID', array_values(self::PAGES))
            ->get()
            ->keyBy('ID');

        foreach (self::PAGES as $key => $postId) {
            $post = $posts[$postId] ?? null;
            if ($post === null) {
                $this->warn("WordPress page {$postId} ({$key}) does not exist; /{$key} will return 404.");

                continue;
            }

            $rendered = $this->fetchRenderedPage($key);
            if ($rendered === null) {
                $this->warn("Imported the {$key} page as raw post_content. Re-run with --site-url to keep the HTML the app shows today.");
            }

            DB::table('pages')->insert([
                'id' => (int) $post->ID,
                'key' => $key,
                'title' => (string) ($rendered['title'] ?? $post->post_title),
                'content' => (string) ($rendered['content'] ?? $post->post_content),
                'excerpt' => $rendered['excerpt'] ?? $this->nonEmpty($post->post_excerpt ?? null),
                'status' => (string) $post->post_status,
                'created_at' => $this->gmtToApp($post->post_date_gmt ?? null),
                'updated_at' => $this->gmtToApp($post->post_modified_gmt ?? null),
            ]);
            $this->count('pages', 1);
        }
    }

    private function fetchRenderedPage(string $key): ?array
    {
        if ($this->siteUrl === null || $this->siteUrl === '') {
            return null;
        }

        try {
            $response = Http::timeout(20)->acceptJson()
                ->get(rtrim($this->siteUrl, '/')."/wp-json/custom/v1/{$key}");
            $data = $response->json('data');

            return $response->successful() && is_array($data) && isset($data['content']) ? $data : null;
        } catch (Throwable $e) {
            $this->warn("Could not fetch /{$key} from {$this->siteUrl}: {$e->getMessage()}");

            return null;
        }
    }

    private function importSettings(): void
    {
        $settings = [
            // Default on: an install that never saved the page had it on.
            'ai_summary_enabled' => (string) ($this->option('medvault_ai_summary_enabled') ?? '1') !== '0',
            // Always off after an import: WordPress may still be polling, and two
            // pollers send every alert twice (each only sees its own log). A
            // staging copy must never poll either: it holds real device tokens.
            'poll_enabled' => false,
            'poll_minutes' => max(5, (int) ($this->option('medvault_poll_minutes') ?? 10)),
            'poll_batch_size' => max(1, (int) ($this->option('medvault_poll_batch_size') ?? 50)),
            'poll_cursor' => max(0, (int) ($this->option('medvault_poll_cursor') ?? 0)),
            'lab_api_bootstrapped' => (bool) $this->option('medvault_lab_api_bootstrapped'),
            // What WP_Query falls back to for a page size of 0.
            'posts_per_page' => max(1, (int) ($this->option('posts_per_page') ?? 10)),
        ];

        $lastRun = Serialized::decode($this->option('medvault_poll_last_run'));
        if (is_array($lastRun)) {
            $settings['poll_last_run'] = $lastRun;
        }

        foreach ($settings as $key => $value) {
            Setting::put($key, $value);
        }

        if ((bool) (int) ($this->option('medvault_poll_enabled') ?? 1)) {
            $this->warn('Result polling was on in WordPress and is OFF here. Turn it on in Admin → Result Alerts only after turning it off in WordPress.');
        }

        $firebase = $this->nonEmpty($this->option('medvault_firebase_credentials'));
        if ($firebase !== null) {
            Setting::putSecret('firebase_credentials', $firebase);
        } else {
            $this->warn('No Firebase credentials in WordPress; push notifications will fail until they are set.');
        }

        $this->count('settings', count($settings) + ($firebase === null ? 0 : 1));
    }

    /** Unexpired _transient_ai_summary_* options; each one lost is re-billed. */
    private function importAiSummaries(): void
    {
        $options = $this->wp->table($this->t('options'))
            ->where(fn ($q) => $this->whereStartsWith($q, 'option_name', '_transient_ai_summary_')
                ->orWhere(fn ($q) => $this->whereStartsWith($q, 'option_name', '_transient_timeout_ai_summary_')))
            ->get(['option_name', 'option_value']);

        $values = [];
        $expiry = [];
        foreach ($options as $o) {
            if (str_starts_with($o->option_name, '_transient_timeout_ai_summary_')) {
                $expiry[substr($o->option_name, strlen('_transient_timeout_'))] = (int) $o->option_value;
            } else {
                $values[substr($o->option_name, strlen('_transient_'))] = $o->option_value;
            }
        }

        $rows = [];
        $now = time();
        foreach ($values as $key => $raw) {
            $expires = $expiry[$key] ?? 0;
            $data = Serialized::decode($raw);
            if ($expires <= $now || ! is_array($data)) {
                continue;
            }
            $rows[] = [
                'cache_key' => substr($key, strlen('ai_summary_')),
                'data' => json_encode($data),
                'expires_at' => CarbonImmutable::createFromTimestamp($expires, config('app.timezone')),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ai_summaries')->insert($chunk);
        }
        $this->count('ai_summaries', count($rows));
    }

    // ── WordPress reads ─────────────────────────────────────────────────────

    private function t(string $table): string
    {
        return $this->prefix.$table;
    }

    private function option(string $name): mixed
    {
        return $this->wp->table($this->t('options'))->where('option_name', $name)->value('option_value');
    }

    /** @return array<int, array<string, mixed>> post id => meta key => first value */
    private function postMetas(array $postIds): array
    {
        $out = [];
        foreach (array_chunk($postIds, 500) as $chunk) {
            $rows = $this->wp->table($this->t('postmeta'))
                ->whereIn('post_id', $chunk)
                ->orderBy('meta_id')
                ->get(['post_id', 'meta_key', 'meta_value']);
            foreach ($rows as $row) {
                // get_post_meta($id, $key, true) returns the first row.
                $out[(int) $row->post_id][$row->meta_key] ??= $row->meta_value;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> user id => meta key => first value */
    private function userMetas(array $userIds, string $capabilitiesKey): array
    {
        $rows = $this->wp->table($this->t('usermeta'))
            ->whereIn('user_id', $userIds)
            ->where(function ($q) use ($capabilitiesKey) {
                $q->whereIn('meta_key', [...self::USER_META_KEYS, $capabilitiesKey])
                    ->orWhere(fn ($q) => $this->whereStartsWith($q, 'meta_key', '_lab_'));
            })
            ->orderBy('umeta_id')
            ->get(['user_id', 'meta_key', 'meta_value']);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->user_id][$row->meta_key] ??= $row->meta_value;
        }

        return $out;
    }

    /** A prefix match that treats _ and % literally on MySQL and SQLite alike. */
    private function whereStartsWith($query, string $column, string $prefix)
    {
        $escaped = str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $prefix);

        return $query->whereRaw("{$column} LIKE ? ESCAPE '!'", [$escaped.'%']);
    }

    private function uploadsBaseUrl(): string
    {
        $custom = $this->nonEmpty($this->option('upload_url_path'));
        if ($custom !== null) {
            return rtrim($custom, '/');
        }

        return rtrim((string) $this->option('siteurl'), '/').'/wp-content/uploads';
    }

    /** get_the_post_thumbnail_url($id, 'full'), from the attachment's file. */
    private function attachmentUrl(int $attachmentId, string $uploads): ?string
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $file = $this->wp->table($this->t('postmeta'))
            ->where('post_id', $attachmentId)
            ->where('meta_key', '_wp_attached_file')
            ->value('meta_value');
        if ($this->nonEmpty($file) !== null) {
            return str_contains($file, '://') ? $file : $uploads.'/'.ltrim($file, '/');
        }

        return $this->nonEmpty(
            $this->wp->table($this->t('posts'))->where('ID', $attachmentId)->value('guid')
        );
    }

    // ── Time ────────────────────────────────────────────────────────────────

    /**
     * The site's own timezone. current_time('mysql') wrote local wall-clock
     * time into notifications, poll timestamps and device registrations.
     */
    private function wpZone(): DateTimeZone
    {
        if ($this->wpZone !== null) {
            return $this->wpZone;
        }

        $name = $this->nonEmpty($this->option('timezone_string'));
        if ($name !== null) {
            try {
                return $this->wpZone = new DateTimeZone($name);
            } catch (Throwable) {
                // Fall through to the offset.
            }
        }

        $offset = (float) ($this->option('gmt_offset') ?? 0);
        $sign = $offset < 0 ? '-' : '+';
        $abs = abs($offset);

        return $this->wpZone = new DateTimeZone(sprintf('%s%02d:%02d', $sign, (int) $abs, (int) round(fmod($abs, 1) * 60)));
    }

    private function localToApp(mixed $value): ?string
    {
        return $this->convertTime($value, $this->wpZone());
    }

    private function gmtToApp(mixed $value): ?string
    {
        return $this->convertTime($value, new DateTimeZone('UTC'));
    }

    private function convertTime(mixed $value, DateTimeZone $from): ?string
    {
        $value = $this->nonEmpty($value);
        if ($value === null || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $from)
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function checkTimezone(): void
    {
        $wp = $this->wpZone()->getName();
        $app = (string) config('app.timezone');
        $probe = CarbonImmutable::now();
        if ($probe->setTimezone($wp)->getOffset() !== $probe->setTimezone($app)->getOffset()) {
            $this->warn("WordPress ran on {$wp} and this app on {$app}. Times were converted, but the notification list formats dates in app.timezone; set APP_TIMEZONE={$wp} to keep them as patients saw them.");
        }
    }

    // ── Bookkeeping ─────────────────────────────────────────────────────────

    private function assertTargetEmpty(bool $replace): void
    {
        if ($replace) {
            return;
        }
        foreach (self::TARGET_TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException(
                    "The {$table} table already has rows. Re-run with --replace to clear every imported table first. "
                    .'Never do that after cutover: it would overwrite passwords and tokens changed since.'
                );
            }
        }
    }

    /** Explicit ids leave Postgres sequences behind; move them past the data. */
    private function resetSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::TARGET_TABLES as $table) {
            if ($table === 'settings') {
                continue;
            }
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 0) + 1, false)");
        }
    }

    private function nonEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return trim($value) === '' ? null : $value;
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function count(string $what, int $n): void
    {
        $this->report[$what] = ($this->report[$what] ?? 0) + $n;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
