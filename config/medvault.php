<?php

return [

    /*
    | The WordPress site this backend replaces. Lab permalinks were
    | {site}/labs/{slug}/ and the app may still open them.
    */
    'site_url' => rtrim(env('WP_SITE_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
    | The WordPress database being replaced (the 'wordpress' connection). Read
    | through config, never env(): env() returns null once config is cached,
    | which every deploy does.
    */
    'wordpress' => [
        'prefix' => env('WP_DB_PREFIX', 'wp_'),
        'site_url' => env('WP_SITE_URL'),
    ],

    // get_permalink() for a lab. The live site uses PATHINFO permalinks, hence
    // the index.php segment.
    'lab_permalink' => env('MEDVAULT_LAB_PERMALINK', '{site}/index.php/labs/{slug}/'),

    // LAB_GLOBAL_REFER_LINK: used when a lab has no refer link of its own.
    'refer_link' => env('MEDVAULT_REFER_LINK', 'https://example.com/refer'),

    // GET /phone. Hardcoded in the plugin.
    'support_phone' => env('MEDVAULT_SUPPORT_PHONE', '+96176618326'),

    /*
    | Uploads from the app (profile, ID and insurance photos), replacing the
    | WordPress media library. Anonymous, because the app uploads during
    | sign-up, before an account exists; images only, capped and rate limited.
    */
    'uploads' => [
        'disk' => env('MEDVAULT_UPLOAD_DISK', 'public'),
        'max_kb' => (int) env('MEDVAULT_UPLOAD_MAX_KB', 15360),
        'per_minute' => (int) env('MEDVAULT_UPLOADS_PER_MINUTE', 20),
        'mimes' => [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
        ],
    ],

    /*
    | The original hardcoded Verdun endpoints. Used as the lab_id 0 fallback
    | when no lab has a results API configured, so an install whose labs table
    | is still being filled in never takes results offline.
    */
    'legacy_lab' => [
        'name' => 'Verdun Medical Center',
        'base' => 'https://verdunmedicalcenter.net/public/index.php/api/mobile',
        'pdf_base' => 'https://verdunmedicalcenter.net/storage/app/7mJ~33/',
    ],

    'labs' => [
        // How long the whole site stops logging in to a lab that throttled
        // us. Labs rate-limit per client IP and every call comes from this
        // one server, so retrying into a full bucket keeps it full.
        'backoff_seconds' => 120,
        // Ceiling on a lab's own Retry-After.
        'max_backoff_seconds' => 900,
        // Refusals before an account stops being polled or retried.
        'max_auth_failures' => 5,
        // A 200 whose message is one of these is a throttle, not a refusal.
        // Bekaa answers a tripped limit with 200 {"success":false,"message":
        // "Too many attempts"}, byte-for-byte the shape of a refusal. Keep the
        // list specific: a phrase that also appears in a genuine refusal means
        // a dead account never stops costing a login.
        'throttle_phrases' => ['too many attempts', 'too many requests', 'rate limit', 'throttl'],
        // Seconds /lab-results may spend inlining result rows before the rest
        // are left for the app to fetch per order. Each order is one chained
        // call, and a request killed by PHP's time limit is an HTML 502 the app
        // cannot parse, so a heavy account must degrade to lazy loading.
        'results_time_budget' => (float) env('MEDVAULT_RESULTS_TIME_BUDGET', 12.0),
        // How long a signed /lab-file link works. The OS browser opens it and
        // sends no Authorization header, so the link carries the authority.
        'file_link_ttl' => 86400,
    ],

    /*
    | BestSmsBulk WhatsApp template endpoint, used for OTP codes. These were
    | hardcoded in the WordPress plugin; they belong here, in .env.
    */
    'sms' => [
        'url' => env('BSB_URL', 'https://www.bestsmsbulk.com/bestsmsbulkapi/common/sendSmsWpAPITemplate.php'),
        'api_key' => env('BSB_API_KEY'),
        'api_secret' => env('BSB_API_SECRET'),
        'sender_id' => env('BSB_SENDER_ID', '15559173701'),
        'route' => env('BSB_ROUTE', 'wp'),
        'template' => env('BSB_TEMPLATE', 'otp_1'),
        'timeout' => 15,
    ],

    // POST /ai-summary. The key was hardcoded in the plugin; rotate it.
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1/chat/completions'),
        // Below the server's own request limit, so a stalled call ends as a
        // JSON error rather than a killed worker.
        'timeout' => 20,
        'cache_days' => 30,
    ],

    /*
    | Audit Log and Error Log retention. Audit rows are kept long, since they
    | answer "who changed this" months later; resolved errors only while
    | they are still useful.
    */
    'logs' => [
        'audit_days' => (int) env('MEDVAULT_AUDIT_DAYS', 730),
        'resolved_error_days' => (int) env('MEDVAULT_RESOLVED_ERROR_DAYS', 90),
    ],

    'otp' => [
        'ttl_seconds' => 600,
        // Per number, per ttl window.
        'max_sends' => 3,
    ],

];
