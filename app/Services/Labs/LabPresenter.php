<?php

namespace App\Services\Labs;

use App\Models\Lab;
use App\Support\WordPress\Sanitize;

/** format_lab_response(): one lab as GET /labs and /labs/{id} return it. */
final class LabPresenter
{
    private const FIELDS = [
        'image_url', 'location', 'google_map_url', 'appointment_phone',
        'mobile_phone', 'phone', 'email', 'website',
    ];

    private const SOCIAL = ['facebook', 'instagram', 'twitter', 'linkedin', 'youtube', 'tiktok'];

    public static function present(Lab $lab): array
    {
        $data = [
            'id' => (int) $lab->id,
            'name' => (string) $lab->title,
            'description' => Sanitize::stripAllTags((string) $lab->content),
            'slug' => (string) $lab->slug,
            'permalink' => strtr((string) config('medvault.lab_permalink'), ['{site}' => config('medvault.site_url'), '{slug}' => $lab->slug]),
            // Filled below. Empty stays a JSON array, as PHP encoded it.
            'social_media' => [],
        ];

        foreach (self::FIELDS as $field) {
            $data[$field] = $lab->{$field} ?: null;
        }

        // The featured image stands in for an empty image URL.
        if ($data['image_url'] === null && $lab->thumbnail_url) {
            $data['image_url'] = $lab->thumbnail_url;
        }

        $data['refer_link'] = $lab->refer_link ?: config('medvault.refer_link');
        $data['refer_text'] = $lab->refer_text ?: null;

        $data['latitude'] = is_numeric($lab->latitude) ? (float) $lab->latitude : null;
        $data['longitude'] = is_numeric($lab->longitude) ? (float) $lab->longitude : null;

        $data['slides'] = array_map(
            fn ($url, $i) => ['id' => $i + 1, 'image_url' => $url],
            array_values($lab->slides ?? []),
            array_keys(array_values($lab->slides ?? [])),
        );

        // What the results API can supply. The app hides the matching UI
        // rather than drawing an empty version of it: a gauge with no range
        // reads to a patient as "normal", an assertion nobody made.
        $cfg = LabConfig::fromLab($lab);
        $data['results_api'] = [
            'enabled' => $cfg?->enabled ?? false,
            'capabilities' => $cfg?->caps ?? ['ranges' => false, 'flags' => false, 'pdf' => false, 'ai' => false],
        ];

        foreach (self::SOCIAL as $platform) {
            if ($lab->{$platform}) {
                $data['social_media'][$platform] = $lab->{$platform};
            }
        }

        return $data;
    }
}
