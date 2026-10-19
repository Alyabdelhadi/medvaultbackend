<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsWordPressParams;
use App\Http\WpError;
use App\Models\AiSummary;
use App\Services\Labs\LabConfig;
use App\Services\Labs\LabRegistry;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * POST /ai-summary: a patient-friendly summary of one order.
 *
 * Billed per call, so it is refused before anything is spent when the global
 * switch or the lab's own opt-in is off, whatever the app believes. Cached
 * per (lab, order, patient), because results do not change.
 *
 * The prompt is the plugin's, word for word. A lab that records no ranges or
 * no flags is said to, so the model cannot reassure a patient on data the
 * lab never supplied; and an absent flag is "not flagged by the lab", never
 * "Normal".
 */
class AiSummaryController extends Controller
{
    use ReadsWordPressParams;

    public function store(Request $request, LabRegistry $labs): JsonResponse
    {
        $this->requireParams($request, ['patient_name', 'category_label', 'request_nb', 'order_datetime', 'status', 'results']);

        if (! LabConfig::aiSummaryEnabled()) {
            throw new WpError('ai_summary_disabled', 'AI summaries are currently unavailable.', 403);
        }

        $labId = (int) ($request->input('lab_id') ?? 0);
        $cfg = $labs->find($labId);
        if ($cfg !== null && empty($cfg->caps['ai'])) {
            throw new WpError('ai_summary_disabled', 'AI summaries are not enabled for this lab.', 403);
        }

        $patientName = $this->text($request, 'patient_name');
        $categoryLabel = $this->text($request, 'category_label');
        $requestNb = $this->text($request, 'request_nb');
        $orderDatetime = $this->text($request, 'order_datetime');
        $rawResults = $request->input('results');

        // Absent means an app build from before the fields: behave as it did.
        $hasRanges = $request->input('has_ranges') === null ? true : (bool) $request->input('has_ranges');
        $hasFlags = $request->input('has_flags') === null ? true : (bool) $request->input('has_flags');

        if (! is_array($rawResults) || $rawResults === []) {
            throw new WpError('invalid_results', 'results must be a non-empty array.');
        }

        $cacheKey = md5($labId.'_'.$requestNb.'_'.$patientName);
        $cached = AiSummary::where('cache_key', $cacheKey)->where('expires_at', '>', now())->first();
        if ($cached !== null) {
            return response()->json(['success' => true, 'data' => $cached->data, 'cached' => true]);
        }

        $lines = [];
        foreach ($rawResults as $r) {
            if (! is_array($r)) {
                continue;
            }
            $result = Sanitize::textField($r['result'] ?? '');
            if ($result === '') {
                continue;
            }
            $testName = Sanitize::textField($r['test_name'] ?? '');
            $groupName = Sanitize::textField($r['group_name'] ?? 'General');
            $unit = Sanitize::textField($r['unit'] ?? '');
            $statusStr = match (Sanitize::textField($r['result_status'] ?? '')) {
                'H' => 'High',
                'L' => 'Low',
                'N' => 'Normal',
                default => 'not flagged by the lab',
            };
            $refRange = Sanitize::textField($r['ref_range'] ?? '');
            $rangeStr = ($hasRanges && $refRange !== '') ? " (reference range $refRange)" : '';

            $lines[] = "$groupName: $testName = $result $unit$rangeStr [$statusStr]";
        }

        if ($lines === []) {
            throw new WpError('no_results', 'No results with values found.');
        }

        $resultsText = implode("\n", $lines);

        $systemMsg = 'You are a medical assistant. Always respond with valid JSON only, no markdown, no code fences.';

        $userMsg = "Analyze these lab results and provide a clear, friendly summary for a patient.\n\n"
            ."Respond ONLY with a valid JSON object in exactly this format:\n"
            ."{\n"
            .'  "intro": "1-2 sentence overview",'."\n"
            .'  "sections": ['."\n"
            .'    {"title": "Group title", "description": "Patient-friendly explanation", "isAbnormal": false}'."\n"
            ."  ],\n"
            .'  "whatToNote": "Brief note on any abnormal or borderline values. Empty string if all normal.",'."\n"
            .'  "recommendation": "One practical health recommendation"'."\n"
            ."}\n\n"
            ."Test: $categoryLabel\n"
            ."Patient: $patientName\n"
            ."Date: $orderDatetime\n"
            ."Request: #$requestNb\n\n"
            ."Results:\n$resultsText\n\n";

        if (! $hasRanges || ! $hasFlags) {
            $missing = [];
            if (! $hasRanges) {
                $missing[] = 'reference ranges';
            }
            if (! $hasFlags) {
                $missing[] = 'high/low flags';
            }

            $userMsg .= 'IMPORTANT: This laboratory does not record '.implode(' or ', $missing).'. '
                .'You therefore CANNOT tell whether any value is normal, high or low. '
                .'Do NOT state or imply that results are normal, healthy, fine, or within range. '
                .'Describe what each test measures, set every "isAbnormal" to false, and in '
                .'"whatToNote" say that this lab does not supply reference ranges so the values '
                ."need to be reviewed by their doctor.\n\n";
        }

        $userMsg .= 'Keep language simple, reassuring, and non-alarming. Suitable for a non-medical audience.';

        $cfgAi = config('medvault.openai');

        try {
            $response = Http::timeout($cfgAi['timeout'])
                ->withToken((string) $cfgAi['api_key'])
                ->asJson()
                ->post($cfgAi['url'], [
                    'model' => $cfgAi['model'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemMsg],
                        ['role' => 'user', 'content' => $userMsg],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.3,
                    'max_tokens' => 1000,
                ]);
        } catch (ConnectionException $e) {
            throw new WpError('openai_request_failed', $e->getMessage(), 502);
        }

        $body = $response->json();
        if ($response->status() !== 200) {
            throw new WpError('openai_error', $body['error']['message'] ?? 'AI service error.', $response->status() === 429 ? 429 : 502);
        }

        $summary = json_decode((string) ($body['choices'][0]['message']['content'] ?? ''), true);
        if (! is_array($summary)) {
            throw new WpError('invalid_ai_response', 'Could not parse AI response.', 502);
        }

        AiSummary::updateOrCreate(
            ['cache_key' => $cacheKey],
            ['data' => $summary, 'expires_at' => now()->addDays((int) $cfgAi['cache_days'])],
        );

        return response()->json(['success' => true, 'data' => $summary, 'cached' => false]);
    }
}
