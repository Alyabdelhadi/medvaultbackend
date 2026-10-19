<?php

namespace Tests\Feature\Labs;

use App\Models\Lab;
use App\Services\Labs\LabConfig;
use App\Services\Labs\LabRows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Holds row normalization to what the WordPress plugin produces, byte for
 * byte, using golden files recorded by tools/plugin-golden.php from the
 * plugin's own code. This is how "Verdun's output must not change" is
 * checked rather than asserted.
 *
 * Signed /lab-file links are the one expected difference: they carry an
 * expiry and a signature from a different key. Those are compared by
 * meaning (same lab, path and user; a signature that verifies here).
 */
class PluginParityTest extends TestCase
{
    use RefreshDatabase;

    private const LABS = [
        'verdun' => [11, 'Verdun Medical Center', ['api_base' => 'https://verdunmedicalcenter.net/public/index.php/api/mobile', 'api_pdf_base' => 'https://verdunmedicalcenter.net/storage/app/7mJ~33/', 'api_pdf_mode' => 'static_base', 'mobile_format' => 'lb_trunk_zero', 'ready_signal' => 'status', 'caps_ranges' => true, 'caps_flags' => true, 'caps_pdf' => true]],
        'bekaa_live' => [22, 'Bekaa Hospital Lab', ['api_base' => 'https://bekaa.example.net/api/mobile', 'api_pdf_mode' => 'none', 'mobile_format' => 'national', 'ready_signal' => 'none']],
        'bekaa_proxy' => [33, 'Bekaa Proxy', ['api_base' => 'https://tunnel.example.dev/api/mobile', 'api_pdf_base' => 'https://tunnel.example.dev/', 'api_pdf_mode' => 'static_proxy', 'ready_signal' => 'report_datetime', 'caps_ranges' => true, 'caps_pdf' => true]],
        'endpoint' => [44, 'Endpoint Lab', ['api_base' => 'https://ep.example.net/api', 'api_pdf_mode' => 'endpoint', 'ready_signal' => 'status', 'caps_flags' => true, 'caps_pdf' => true]],
    ];

    /** @var array<string, LabConfig>|null */
    private ?array $configs = null;

    /** @return array<string, LabConfig> */
    private function configs(): array
    {
        if ($this->configs !== null) {
            return $this->configs;
        }
        $out = [];
        foreach (self::LABS as $key => [$id, $title, $attributes]) {
            $lab = new Lab;
            $lab->forceFill(['id' => $id, 'title' => $title, 'slug' => $key, 'status' => 'publish', 'api_enabled' => true, ...$attributes])->save();
            $out[$key] = LabConfig::fromLab($lab);
        }

        return $this->configs = $out;
    }

    public function test_synthetic_rows_match_the_plugin(): void
    {
        $golden = json_decode(file_get_contents(base_path('tests/Fixtures/plugin-golden/synthetic.json')), true);
        $this->assertRowsMatch($golden);

        $this->assertSame($golden['flatten_dg'], LabRows::flatten([
            'Hematology' => [['request_nb' => '10', 'name' => 'A'], ['request_nb' => '11', 'name' => 'B', 'cat_name' => 'Explicit']],
            'Chemistry' => [['request_nb' => '12', 'name' => 'C']],
            0 => ['request_nb' => '13', 'name' => 'D'],
        ]));

        $proxy = $this->configs()['bekaa_proxy'];
        $paths = ['ResultPDF/x_101.PDF', '/ResultPDF/x.pdf', '../../wp-config.php', 'ResultPDF/../../etc/passwd', 'https://evil.example/x.pdf', '..\\..\\secret.pdf', 'ResultPDF//x.pdf', ''];
        $this->assertSame($golden['static_files'], array_map(fn ($p) => LabRows::staticFileUrl($proxy, $p), $paths));
    }

    /** The vendor kit's real rows; skipped where the kit golden was not generated. */
    public function test_vendor_kit_rows_match_the_plugin(): void
    {
        $file = storage_path('testing/plugin-golden-kit.json');
        $kit = rtrim((string) (getenv('MEDVAULT_KIT') ?: getenv('HOME').'/Downloads/MedVault-Dev-Kit/Data/Seed'), '/');
        if (! is_file($file) || ! is_dir($kit)) {
            $this->markTestSkipped('Run tools/plugin-golden.php --kit=... to record the vendor-kit golden file.');
        }

        $this->assertRowsMatch(json_decode(file_get_contents($file), true), $kit);
    }

    private function assertRowsMatch(array $golden, ?string $kit = null): void
    {
        $configs = $this->configs();

        if ($kit !== null) {
            $decode = fn ($f) => json_decode(preg_replace('/^\xEF\xBB\xBF/', '', file_get_contents("$kit/$f")), true);
            $requests = $decode('requests-70143335.json')['requests'];
            $results = $decode('results-2603250003.json')['results'];
        } else {
            [$requests, $results] = $this->syntheticInputs();
        }

        foreach ($configs as $key => $cfg) {
            $expected = $golden['labs'][$key];

            foreach ($requests as $i => $row) {
                $ours = LabRows::request($row, $cfg, 7);
                $theirs = $expected['requests'][$i];
                $this->assertSame($this->withoutSignedLinks($theirs), $this->withoutSignedLinks($ours), "{$key} request #{$i}");
                $this->assertSignedLinksEquivalent($theirs, $ours, $cfg, "{$key} request #{$i}");
            }

            foreach ($results as $i => $row) {
                $this->assertSame($expected['results'][$i], LabRows::result($row, $cfg), "{$key} result #{$i}");
            }
        }
    }

    private function withoutSignedLinks(array $row): array
    {
        $strip = fn (?string $url) => $url !== null && str_contains($url, '/lab-file?') ? 'SIGNED' : $url;
        $row['pdf_url'] = $strip($row['pdf_url']);
        $row['pdf_urls'] = array_map($strip, $row['pdf_urls']);

        return $row;
    }

    private function assertSignedLinksEquivalent(array $theirs, array $ours, LabConfig $cfg, string $label): void
    {
        $pairs = [[$theirs['pdf_url'], $ours['pdf_url']], ...array_map(null, $theirs['pdf_urls'], $ours['pdf_urls'])];
        foreach ($pairs as [$wp, $laravel]) {
            if ($wp === null || ! str_contains($wp, '/lab-file?')) {
                continue;
            }
            parse_str(parse_url($wp, PHP_URL_QUERY), $a);
            parse_str(parse_url($laravel, PHP_URL_QUERY), $b);

            $this->assertStringEndsWith('/wp-json/custom/v1/lab-file', parse_url($laravel, PHP_URL_PATH), $label);
            $this->assertSame([$a['lab_id'], $a['path'], $a['uid']], [$b['lab_id'], $b['path'], $b['uid']], $label);
            $this->assertSame(array_keys($a), array_keys($b), $label);
            $this->assertSame(LabRows::signature(7, $cfg->labId, $b['path'], (int) $b['exp']), $b['sig'], $label);
            $this->assertEqualsWithDelta(time() + 86400, (int) $b['exp'], 5, $label);
        }
    }

    /** The inputs tools/plugin-golden.php recorded. Keep the two in step. */
    private function syntheticInputs(): array
    {
        $flat = LabRows::flatten([
            'Hematology' => [['request_nb' => '10', 'name' => 'A'], ['request_nb' => '11', 'name' => 'B', 'cat_name' => 'Explicit']],
            'Chemistry' => [['request_nb' => '12', 'name' => 'C']],
            0 => ['request_nb' => '13', 'name' => 'D'],
        ]);

        return [
            [
                ['request_nb' => '900123', 'order_datetime' => '2026-05-01 09:00:00', 'status' => 'v', 'pdf_path' => 'a/b.pdf', 'name' => 'SAMI HADDAD', 'cat_name' => 'Hematology ', 'lab_name' => 'Verdun Sub-Lab'],
                ['request_nb' => '26L007429', 'order_datetime' => '2026-08-20 10:00:00', 'status' => 'V', 'patient_name' => 'MAYA  KHALIL', 'pdf_path' => 'ResultPDF/26L007429_100.PDF', 'pdf_paths' => ['ResultPDF/26L007429_100.PDF', 'ResultPDF/26L007429_101.PDF', null, '', ['nested']]],
                ['request_nb' => '1', 'pdf_path' => 'a.pdf', 'pdf_paths' => ['b.pdf']],
                ['request_nb' => '21L010867', 'pdf_path' => null, 'pdf_paths' => []],
                ['request_nb' => '2', 'pdf_path' => 'x.pdf', 'pdf_paths' => 'x.pdf'],
                ['request_nb' => 3, 'status' => 'f', 'report_datetime' => '2026-03-25 14:47:00', 'name' => null, 'patient_name' => '<b>Bold</b>  Name', 'birthdate' => '1990-01-01', 'dob' => 'ignored'],
                ['request_nb' => '', 'status' => 'V'],
                ['status' => 'C', 'cat_name' => true, 'order_datetime' => 20260101],
                ...$flat,
            ],
            [
                ['id' => 1, 'name' => 'A', 'test_name' => 'Hb', 'group_name' => 'CBC', 'unit' => 'g/dL', 'ref_range' => '<div>12-16</div>', 'result' => '13.1', 'result_status' => 'N', 'sign' => '', 'is_title' => 'N', 'cat_name' => 'Hematology '],
                ['patient_name' => 'B', 'birth_date' => '1990-01-01', 'test_name' => 'K', 'unit' => '&micro;g/dL', 'ref_range' => '<div>&lt; 5</div>', 'result' => '7', 'result_status' => 'H', 'result_txt' => null],
                ['test_name' => 'Note', 'is_title' => 'Y', 'result' => null],
            ],
        ];
    }
}
