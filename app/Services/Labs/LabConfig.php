<?php

namespace App\Services\Labs;

use App\Models\Lab;
use App\Models\Setting;

/**
 * One lab's results-API configuration, as medvault_lab_config() built it.
 *
 * The defaults are the plugin's, quirks included, because a facility's
 * behaviour must not change by moving servers: an unsaved or zero priority is
 * 10, an unset or zero timeout is 15, an unknown driver is vmc_v1.
 */
final class LabConfig
{
    public const DEFAULT_TIMEOUT = 15;

    public const DEFAULT_PRIORITY = 10;

    /**
     * @param  array<string, string>  $headers  never sent to the app
     * @param  array{ranges: bool, flags: bool, pdf: bool, ai: bool}  $caps
     */
    public function __construct(
        public readonly int $labId,
        public readonly string $labName,
        public readonly bool $enabled,
        public readonly string $driverKey,
        public readonly array $driver,
        public readonly string $base,
        public readonly string $pdfBase,
        public readonly string $pdfMode,
        public readonly array $headers,
        public readonly int $timeout,
        public readonly int $priority,
        public readonly string $mobileFormat,
        public readonly string $readySignal,
        public readonly array $caps,
    ) {}

    /** Null when the lab has no base URL, as before: such a lab has no API. */
    public static function fromLab(Lab $lab): ?self
    {
        $base = rtrim(trim((string) $lab->api_base), '/\\');
        if ($base === '') {
            return null;
        }

        $driverKey = LabDrivers::keyOrDefault($lab->api_driver ?: null);
        $timeout = (int) $lab->api_timeout;

        return new self(
            labId: (int) $lab->id,
            labName: (string) $lab->title,
            enabled: (bool) $lab->api_enabled,
            driverKey: $driverKey,
            driver: LabDrivers::get($driverKey),
            base: $base,
            pdfBase: trim((string) $lab->api_pdf_base),
            pdfMode: $lab->api_pdf_mode ?: 'static_base',
            headers: self::parseHeaderLines((string) $lab->api_headers),
            timeout: $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
            priority: (int) ($lab->api_priority ?: self::DEFAULT_PRIORITY),
            mobileFormat: $lab->mobile_format ?: 'lb_trunk_zero',
            readySignal: $lab->ready_signal ?: 'status',
            caps: [
                'ranges' => (bool) $lab->caps_ranges,
                'flags' => (bool) $lab->caps_flags,
                'pdf' => (bool) $lab->caps_pdf,
                // Off unless this lab pays for it, and only while the global
                // switch is on.
                'ai' => self::aiSummaryEnabled() && (bool) $lab->ai_summary,
            ],
        );
    }

    /**
     * The lab_id 0 fallback: the original Verdun endpoints, used only while
     * no lab has an enabled results API.
     */
    public static function legacy(): self
    {
        $legacy = config('medvault.legacy_lab');

        return new self(
            labId: 0,
            labName: $legacy['name'],
            enabled: true,
            driverKey: LabDrivers::DEFAULT,
            driver: LabDrivers::get(LabDrivers::DEFAULT),
            base: $legacy['base'],
            pdfBase: $legacy['pdf_base'],
            pdfMode: 'static_base',
            headers: [],
            timeout: self::DEFAULT_TIMEOUT,
            priority: self::DEFAULT_PRIORITY,
            mobileFormat: 'lb_trunk_zero',
            readySignal: 'status',
            caps: ['ranges' => true, 'flags' => true, 'pdf' => true, 'ai' => true],
        );
    }

    /** The global AI switch. Default on, as an install that never saved it was. */
    public static function aiSummaryEnabled(): bool
    {
        return Setting::get('ai_summary_enabled', true) !== false;
    }

    /** "Key: Value" per line into a header map. */
    public static function parseHeaderLines(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\R/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $headers[$key] = trim($value);
            }
        }

        return $headers;
    }

    /** A full URL for one of the driver's paths. */
    public function url(string $pathKey, array $query = []): string
    {
        $url = $this->base.($this->driver[$pathKey] ?? '');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
