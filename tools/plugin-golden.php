<?php

/**
 * Records what the WordPress plugin outputs for lab rows, so the Laravel port
 * can be held to it byte for byte (tests/Feature/Labs/PluginParityTest).
 *
 * Runs the plugin's own code through Nova's test/php/wp-stubs.php, with one
 * correction: the stub sanitize_text_field() does not collapse whitespace and
 * WordPress does, so the real one is lifted from wp-includes/formatting.php.
 *
 *   php tools/plugin-golden.php \
 *       --nova=../Nova \
 *       --formatting=/path/to/wordpress/wp-includes/formatting.php \
 *       [--kit=~/Downloads/MedVault-Dev-Kit/Data/Seed]
 *
 * Writes tests/Fixtures/plugin-golden/synthetic.json (no patient data, safe to
 * commit) and, with --kit, storage/testing/plugin-golden-kit.json (vendor
 * fixtures, which may hold real names; ignored by git).
 */
$opts = getopt('', ['nova:', 'formatting:', 'kit::']);
$nova = rtrim($opts['nova'] ?? '', '/');
$formatting = $opts['formatting'] ?? '';
$kit = $opts['kit'] ?? null;
if (! is_file("$nova/functions .php") || ! is_file($formatting)) {
    fwrite(STDERR, "Usage: php tools/plugin-golden.php --nova=../Nova --formatting=wp-includes/formatting.php [--kit=...]\n");
    exit(2);
}

// The real WordPress sanitizer and its helpers, from formatting.php.
$source = file_get_contents($formatting);
foreach (['_sanitize_text_fields', 'wp_check_invalid_utf8', 'wp_pre_kses_less_than', 'wp_pre_kses_less_than_callback'] as $fn) {
    if (! preg_match('/^function '.preg_quote($fn).'\(.*?^}/ms', $source, $m)) {
        fwrite(STDERR, "{$fn} not found in {$formatting}\n");
        exit(2);
    }
    eval($m[0]);
}
function sanitize_text_field($s)
{
    return _sanitize_text_fields($s, false);
}
function is_utf8_charset($c = null)
{
    return true;
}
function wp_is_valid_utf8($s)
{
    return mb_check_encoding($s, 'UTF-8');
}

// Nova's stubs, minus the stub sanitizer.
eval('?>'.preg_replace('/^function sanitize_text_field\(.*$/m', '', file_get_contents("$nova/test/php/wp-stubs.php")));
require "$nova/functions .php";

$labs = [
    'verdun' => [11, 'Verdun Medical Center', ['_lab_api_enabled' => '1', '_lab_api_base' => MEDVAULT_VERDUN_API_BASE, '_lab_api_pdf_base' => MEDVAULT_VERDUN_PDF_BASE, '_lab_api_pdf_mode' => 'static_base', '_lab_mobile_format' => 'lb_trunk_zero', '_lab_ready_signal' => 'status', '_lab_caps_ranges' => '1', '_lab_caps_flags' => '1', '_lab_caps_pdf' => '1']],
    'bekaa_live' => [22, 'Bekaa Hospital Lab', ['_lab_api_enabled' => '1', '_lab_api_base' => 'https://bekaa.example.net/api/mobile', '_lab_api_pdf_mode' => 'none', '_lab_mobile_format' => 'national', '_lab_ready_signal' => 'none', '_lab_caps_ranges' => '', '_lab_caps_flags' => '', '_lab_caps_pdf' => '']],
    'bekaa_proxy' => [33, 'Bekaa Proxy', ['_lab_api_enabled' => '1', '_lab_api_base' => 'https://tunnel.example.dev/api/mobile', '_lab_api_pdf_base' => 'https://tunnel.example.dev/', '_lab_api_pdf_mode' => 'static_proxy', '_lab_ready_signal' => 'report_datetime', '_lab_caps_ranges' => '1', '_lab_caps_flags' => '', '_lab_caps_pdf' => '1']],
    'endpoint' => [44, 'Endpoint Lab', ['_lab_api_enabled' => '1', '_lab_api_base' => 'https://ep.example.net/api', '_lab_api_pdf_mode' => 'endpoint', '_lab_ready_signal' => 'status', '_lab_caps_ranges' => '', '_lab_caps_flags' => '1', '_lab_caps_pdf' => '1']],
];
foreach ($labs as [$id, $title, $meta]) {
    test_add_lab($id, $title, $meta);
}

$dgMap = [
    'Hematology' => [['request_nb' => '10', 'name' => 'A'], ['request_nb' => '11', 'name' => 'B', 'cat_name' => 'Explicit']],
    'Chemistry' => [['request_nb' => '12', 'name' => 'C']],
    0 => ['request_nb' => '13', 'name' => 'D'],
];
$synthetic = [
    ['request_nb' => '900123', 'order_datetime' => '2026-05-01 09:00:00', 'status' => 'v', 'pdf_path' => 'a/b.pdf', 'name' => 'SAMI HADDAD', 'cat_name' => 'Hematology ', 'lab_name' => 'Verdun Sub-Lab'],
    ['request_nb' => '26L007429', 'order_datetime' => '2026-08-20 10:00:00', 'status' => 'V', 'patient_name' => 'MAYA  KHALIL', 'pdf_path' => 'ResultPDF/26L007429_100.PDF', 'pdf_paths' => ['ResultPDF/26L007429_100.PDF', 'ResultPDF/26L007429_101.PDF', null, '', ['nested']]],
    ['request_nb' => '1', 'pdf_path' => 'a.pdf', 'pdf_paths' => ['b.pdf']],
    ['request_nb' => '21L010867', 'pdf_path' => null, 'pdf_paths' => []],
    ['request_nb' => '2', 'pdf_path' => 'x.pdf', 'pdf_paths' => 'x.pdf'],
    ['request_nb' => 3, 'status' => 'f', 'report_datetime' => '2026-03-25 14:47:00', 'name' => null, 'patient_name' => '<b>Bold</b>  Name', 'birthdate' => '1990-01-01', 'dob' => 'ignored'],
    ['request_nb' => '', 'status' => 'V'],
    ['status' => 'C', 'cat_name' => true, 'order_datetime' => 20260101],
    ...medvault_flatten_request_rows($dgMap),
];
$syntheticResults = [
    ['id' => 1, 'name' => 'A', 'test_name' => 'Hb', 'group_name' => 'CBC', 'unit' => 'g/dL', 'ref_range' => '<div>12-16</div>', 'result' => '13.1', 'result_status' => 'N', 'sign' => '', 'is_title' => 'N', 'cat_name' => 'Hematology '],
    ['patient_name' => 'B', 'birth_date' => '1990-01-01', 'test_name' => 'K', 'unit' => '&micro;g/dL', 'ref_range' => '<div>&lt; 5</div>', 'result' => '7', 'result_status' => 'H', 'result_txt' => null],
    ['test_name' => 'Note', 'is_title' => 'Y', 'result' => null],
];
$paths = ['ResultPDF/x_101.PDF', '/ResultPDF/x.pdf', '../../wp-config.php', 'ResultPDF/../../etc/passwd', 'https://evil.example/x.pdf', '..\\..\\secret.pdf', 'ResultPDF//x.pdf', ''];

$build = function (array $requests, array $results) use ($labs) {
    $out = [];
    foreach ($labs as $key => [$id]) {
        $cfg = medvault_lab_config($id);
        $out[$key] = [
            'requests' => array_map(fn ($r) => medvault_normalize_request_row($r, $cfg, 7), $requests),
            'results' => array_map(fn ($r) => medvault_normalize_result_row($r, $cfg), $results),
        ];
    }

    return $out;
};

$json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
$root = dirname(__DIR__);

$golden = [
    'flatten_dg' => medvault_flatten_request_rows($dgMap),
    'static_files' => array_map(fn ($p) => medvault_lab_static_file_url(medvault_lab_config(33), $p), $paths),
    'labs' => $build($synthetic, $syntheticResults),
];
file_put_contents("$root/tests/Fixtures/plugin-golden/synthetic.json", $json($golden));
echo "wrote tests/Fixtures/plugin-golden/synthetic.json\n";

if ($kit !== null && $kit !== false) {
    $kit = rtrim(str_replace('~', getenv('HOME'), $kit), '/');
    $decode = fn ($f) => json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents("$kit/$f")), true);
    $kitGolden = ['labs' => $build($decode('requests-70143335.json')['requests'], $decode('results-2603250003.json')['results'])];
    @mkdir("$root/storage/testing", 0775, true);
    file_put_contents("$root/storage/testing/plugin-golden-kit.json", $json($kitGolden));
    echo "wrote storage/testing/plugin-golden-kit.json\n";
}
