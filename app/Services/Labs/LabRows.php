<?php

namespace App\Services\Labs;

use App\Support\WordPress\Sanitize;

/**
 * Turns a lab's raw rows into the shape the app reads, from
 * medvault_normalize_request_row() / medvault_normalize_result_row() and the
 * readiness and PDF helpers around them.
 *
 * Everything here is per lab and driven by LabConfig. A rule hardcoded for
 * one facility is how another one silently returns zero rows, or worse,
 * tells a patient their result is normal.
 */
final class LabRows
{
    /** The statuses that mean "the patient can see this now", for status-signal labs. */
    public static function statusIsReady(string $status): bool
    {
        return in_array(strtoupper(trim($status)), ['V', 'F', 'C'], true);
    }

    /**
     * Whether one normalized row means "results are available", per the
     * lab's configured signal. `none` is for a facility whose status is a
     * hardcoded literal: alerting on it would announce every order the moment
     * it is created, so it never alerts at all.
     */
    public static function isReady(array $row, LabConfig $cfg): bool
    {
        return match ($cfg->readySignal) {
            'none' => false,
            'report_datetime' => trim((string) ($row['report_datetime'] ?? '')) !== '',
            default => self::statusIsReady((string) ($row['status'] ?? '')),
        };
    }

    /** First present, non-null value among a driver's aliases. */
    public static function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Request lists arrive flat for patients and as a map keyed by category
     * for doctors and guarantors, where the category exists only as that
     * outer key. It is injected as cat_name, unless the row has its own.
     */
    public static function flatten(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $value) {
            if (is_array($value) && ! isset($value['request_nb']) && ! is_int($key)) {
                foreach ($value as $row) {
                    if (is_array($row)) {
                        $out[] = ['cat_name' => (string) $key, ...$row];
                    }
                }

                continue;
            }
            if (is_array($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /** One request row onto the canonical keys the app reads. */
    public static function request(array $row, LabConfig $cfg, int $userId = 0): array
    {
        $out = [];
        foreach ($cfg->driver['request_map'] as $canonical => $aliases) {
            $value = self::pick($row, $aliases);
            if (is_array($value)) {
                // A list field (pdf_paths): scalars only, empties dropped.
                $out[$canonical] = array_values(array_filter(
                    array_map(fn ($v) => is_scalar($v) ? Sanitize::textField((string) $v) : '', $value),
                    fn ($v) => $v !== '',
                ));

                continue;
            }
            $out[$canonical] = $value === null ? '' : Sanitize::textField(is_bool($value) ? ($value ? '1' : '') : $value);
        }

        $out['status'] = strtoupper($out['status']);
        $out['lab_id'] = $cfg->labId;
        $out['lab_name'] = $out['lab_name'] !== '' ? $out['lab_name'] : $cfg->labName;
        $out['is_ready'] = self::isReady($out, $cfg);
        // "Not ready" and "this lab never says" look alike in is_ready alone,
        // and showing the second as Pending is a claim nobody made.
        $out['ready_known'] = $cfg->readySignal !== 'none';

        // Every report for the visit. Used exactly as sent: the suffix varies
        // per visit (_100, _101, _107) and cannot be constructed. A lone
        // pdf_path becomes a one-element list, so the app reads one shape.
        $paths = is_array($out['pdf_paths'] ?? null) ? $out['pdf_paths'] : [];
        if ($out['pdf_path'] !== '' && ! in_array($out['pdf_path'], $paths, true)) {
            array_unshift($paths, $out['pdf_path']);
        }
        if ($out['pdf_path'] === '' && $paths !== []) {
            $out['pdf_path'] = $paths[0];
        }
        $out['pdf_paths'] = $paths;
        $out['pdf_url'] = self::pdfUrl($cfg, $out['pdf_path'], $userId);
        $out['pdf_urls'] = array_values(array_filter(array_map(fn ($p) => self::pdfUrl($cfg, $p, $userId), $paths)));

        return $out;
    }

    /**
     * One result row onto the canonical keys. Not sanitized: ref_range and
     * unit carry HTML and entities on purpose (<div>12-16</div>, &micro;g/dL).
     *
     * A lab that records no flags must not be reported as all-normal. The
     * app draws its Low/Normal/High gauge from result_status, and "N" is an
     * assertion; the same goes for a range the lab never recorded.
     */
    public static function result(array $row, LabConfig $cfg): array
    {
        $out = [];
        foreach ($cfg->driver['result_map'] as $canonical => $aliases) {
            $value = self::pick($row, $aliases);
            $out[$canonical] = $value ?? '';
        }

        if (! $cfg->caps['flags']) {
            $out['result_status'] = '';
        }
        if (! $cfg->caps['ranges']) {
            $out['ref_range'] = '';
        }

        return $out;
    }

    /**
     * What a study can claim, whatever the lab's own settings say. A study is
     * a report and nothing else: no rows, so no range or flag to interpret,
     * and nothing an AI summary could be given but a name.
     */
    public static function radiologyCaps(LabConfig $cfg): array
    {
        return ['ranges' => false, 'flags' => false, 'pdf' => $cfg->caps['pdf'], 'ai' => false];
    }

    /**
     * Whether a study has been reported. Judged on the report alone, never on
     * status: Bekaa's /radiology sends "V" for studies with no report, name or
     * modality, the hardcoded-literal trap ready_signal exists for. A study
     * shown as ready with nothing to open would be a claim nobody made.
     */
    public static function radiologyIsReady(array $row): bool
    {
        return trim((string) ($row['report_datetime'] ?? '')) !== ''
            || trim((string) ($row['pdf_path'] ?? '')) !== '';
    }

    /** The marker the poller stores for a study: whichever report field is set. */
    public static function radiologyMarker(array $row): string
    {
        return $row['report_datetime'] !== '' ? $row['report_datetime'] : $row['pdf_path'];
    }

    /**
     * One study onto the request keys, tagged kind=radiology. Goes through
     * request() so its PDFs are signed and listed exactly like an order's.
     */
    public static function radiology(array $row, LabConfig $cfg, int $userId = 0): array
    {
        $mapped = [];
        foreach ($cfg->driver['radiology_map'] as $canonical => $aliases) {
            $mapped[$canonical] = self::pick($row, $aliases);
        }
        // The name the X-Ray result category already matches.
        $mapped['cat_name'] = 'Radiology';

        $out = self::request($mapped, $cfg, $userId);
        $out['kind'] = 'radiology';
        $out['modality'] = $mapped['modality'] === null ? '' : Sanitize::textField((string) $mapped['modality']);
        $out['study_name'] = $mapped['study_name'] === null ? '' : Sanitize::textField((string) $mapped['study_name']);
        $out['is_ready'] = self::radiologyIsReady($out);
        $out['ready_known'] = true;

        return $out;
    }

    /**
     * A study as the app already reads an order: the request keys only, plus
     * one result row once it is reported, so it renders like any other result
     * with no app change. The row is text, never a value: with ranges and
     * flags off the app draws no gauge and no tick. An unreported study has no
     * rows, which the app already shows as "not ready yet".
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public static function radiologyAsOrder(array $study): array
    {
        $order = array_diff_key($study, array_flip(['kind', 'modality', 'study_name']));
        if (! $study['is_ready']) {
            return [$order, []];
        }

        $testName = $study['study_name'] !== '' ? $study['study_name']
            : ($study['modality'] !== '' ? $study['modality'] : 'Radiology report');
        $text = $study['pdf_url'] !== null
            ? 'Report available. Open the PDF to read it.'
            : 'Reported on '.$study['report_datetime'].'.';

        return [$order, [[
            // The app reads id as a number; '' would fail to parse.
            'id' => 0,
            'name' => $study['name'],
            'birthdate' => $study['birth_date'],
            'test_name' => $testName,
            'group_name' => $study['modality'] !== '' ? $study['modality'] : 'Radiology',
            'unit' => '',
            'ref_range' => '',
            'result' => '',
            'result_txt' => $text,
            'result_status' => '',
            'sign' => '',
            'is_title' => 'N',
            'cat_name' => 'Radiology',
        ]]];
    }

    /** The URL the app opens for a report, or null when there is none. */
    public static function pdfUrl(LabConfig $cfg, string $path, int $userId = 0): ?string
    {
        if ($path === '' || ! $cfg->caps['pdf']) {
            return null;
        }

        switch ($cfg->pdfMode) {
            case 'none':
                return null;

            case 'endpoint':
            case 'static_proxy':
                // Through MedVault: an endpoint lab's token must not travel to
                // the handset, and a proxied folder needs headers the app
                // never has. Signed, because the browser that opens it sends
                // none of the app's headers.
                if ($userId <= 0 || ($cfg->pdfMode === 'static_proxy' && $cfg->pdfBase === '')) {
                    return null;
                }
                $expires = time() + (int) config('medvault.labs.file_link_ttl');

                return url('wp-json/custom/v1/lab-file').'?'.http_build_query([
                    'lab_id' => $cfg->labId,
                    'path' => $path,
                    'uid' => $userId,
                    'exp' => $expires,
                    'sig' => self::signature($userId, $cfg->labId, $path, $expires),
                ], '', '&', PHP_QUERY_RFC3986);

            default:
                return $cfg->pdfBase === '' ? null : rtrim($cfg->pdfBase, '/\\').'/'.ltrim($path, '/');
        }
    }

    /**
     * Signs (user, lab, path, expiry) for /lab-file.
     *
     * The signature is also the ownership check: it is only ever minted from
     * a row this server just fetched for that user, so a path they were not
     * given cannot be signed. That is the check an endpoint lab makes for us
     * and a public folder makes for nobody.
     */
    public static function signature(int $userId, int $labId, string $path, int $expires): string
    {
        return hash_hmac('sha256', "{$userId}|{$labId}|{$path}|{$expires}", self::signingKey());
    }

    /**
     * A lab-supplied path resolved against the facility's static folder, or
     * null for anything that would leave it. The path returns to us as a
     * query parameter, so it is checked where it is used.
     */
    public static function staticFileUrl(LabConfig $cfg, string $path): ?string
    {
        if ($cfg->pdfBase === '' || $path === '') {
            return null;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '://')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return rtrim($cfg->pdfBase, '/\\').'/'.$path;
    }

    private static function signingKey(): string
    {
        return hash_hmac('sha256', 'medvault-lab-file', (string) config('app.key'));
    }
}
