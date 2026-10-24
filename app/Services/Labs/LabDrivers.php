<?php

namespace App\Services\Labs;

/**
 * Response-shape drivers, from medvault_lab_drivers().
 *
 * Each field maps a canonical key to the response keys that may carry it, in
 * priority order. Verdun sends the patient name as "name", the newer VMC-
 * contract server as "patient_name", and one alias list absorbs both. A
 * facility with a genuinely different JSON shape gets its own entry here;
 * reading a raw response key anywhere outside a driver map is how a lab
 * silently returns zero rows.
 */
final class LabDrivers
{
    public const DEFAULT = 'vmc_v1';

    public static function all(): array
    {
        return [
            'vmc_v1' => [
                'label' => 'VMC / Verdun contract (v1)',
                'login_path' => '/login',
                'requests_path' => '/request',
                'results_path' => '/results',
                'file_path' => '/file',
                'token_param' => 'tcToken',
                'requests_key' => 'requests',
                'results_key' => 'results',
                'request_map' => [
                    'request_nb' => ['request_nb'],
                    'order_datetime' => ['order_datetime'],
                    'status' => ['status'],
                    'pdf_path' => ['pdf_path'],
                    'pdf_paths' => ['pdf_paths'],
                    'report_datetime' => ['report_datetime'],
                    'name' => ['name', 'patient_name'],
                    'cat_name' => ['cat_name'],
                    'birth_date' => ['birth_date', 'birthdate', 'dob'],
                    'lab_name' => ['lab_name'],
                ],
                'result_map' => [
                    'id' => ['id'],
                    'name' => ['name', 'patient_name'],
                    'birthdate' => ['birthdate', 'birth_date'],
                    'test_name' => ['test_name'],
                    'group_name' => ['group_name'],
                    'unit' => ['unit'],
                    'ref_range' => ['ref_range'],
                    'result' => ['result'],
                    'result_txt' => ['result_txt'],
                    'result_status' => ['result_status'],
                    'sign' => ['sign'],
                    'is_title' => ['is_title'],
                    'cat_name' => ['cat_name'],
                ],
                // Imaging: a second list on the same host, keyed by study and
                // report-only (there is no per-study rows endpoint). Mapped
                // onto the request keys so the app reads one shape. Called
                // only for a lab with radiology_enabled.
                'radiology_path' => '/radiology',
                'radiology_key' => 'studies',
                'radiology_map' => [
                    'request_nb' => ['study_nb', 'request_nb'],
                    'order_datetime' => ['order_datetime'],
                    'status' => ['status'],
                    'pdf_path' => ['pdf_path'],
                    'pdf_paths' => ['pdf_paths'],
                    'report_datetime' => ['report_datetime'],
                    'name' => ['patient_name', 'name'],
                    'modality' => ['modality'],
                    'study_name' => ['study_name'],
                    'birth_date' => ['birth_date', 'birthdate', 'dob'],
                    'lab_name' => ['lab_name'],
                ],
            ],
        ];
    }

    public static function get(?string $key): array
    {
        $all = self::all();

        return $all[$key] ?? $all[self::DEFAULT];
    }

    public static function keyOrDefault(?string $key): string
    {
        return $key !== null && isset(self::all()[$key]) ? $key : self::DEFAULT;
    }
}
