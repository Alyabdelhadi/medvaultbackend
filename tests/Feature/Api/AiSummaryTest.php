<?php

namespace Tests\Feature\Api;

use App\Models\AiSummary;
use App\Models\Setting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class AiSummaryTest extends ApiTestCase
{
    private const SUMMARY = ['intro' => 'Your results.', 'sections' => [], 'whatToNote' => '', 'recommendation' => 'Drink water.'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['medvault.openai.api_key' => 'sk-test']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'patient_name' => 'NOUR AL HARIRI', 'category_label' => 'Blood Test', 'request_nb' => '101',
            'order_datetime' => '2026-03-01 10:00:00', 'status' => 'V', 'lab_id' => 5,
            'results' => [
                ['test_name' => 'Hb', 'group_name' => 'CBC', 'result' => '13', 'unit' => 'g/dL', 'ref_range' => '12-16', 'result_status' => 'N'],
                ['test_name' => 'K', 'result' => '7', 'unit' => 'mmol/L', 'result_status' => ''],
                ['test_name' => 'Empty', 'result' => ''],
                'not-a-row',
            ],
            ...$overrides,
        ];
    }

    private function openAi(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode(self::SUMMARY)]]]])]);
    }

    /** The prompt Verdun's summaries are written from, unchanged. */
    public function test_builds_the_plugins_prompt_and_caches_the_answer(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['ai_summary' => true]);
        [, $token] = $this->signedIn();
        $this->openAi();

        $this->postJson(self::API.'/ai-summary', $this->payload(), $this->bearer($token))
            ->assertOk()->assertExactJson(['success' => true, 'data' => self::SUMMARY, 'cached' => false]);

        Http::assertSent(function (Request $r) {
            $user = $r['messages'][1]['content'];

            return $r['model'] === 'gpt-4o-mini'
                && $r['response_format'] === ['type' => 'json_object'] && $r['temperature'] === 0.3 && $r['max_tokens'] === 1000
                && $r['messages'][0]['content'] === 'You are a medical assistant. Always respond with valid JSON only, no markdown, no code fences.'
                && str_contains($user, "Test: Blood Test\nPatient: NOUR AL HARIRI\nDate: 2026-03-01 10:00:00\nRequest: #101\n\n")
                && str_contains($user, "Results:\nCBC: Hb = 13 g/dL (reference range 12-16) [Normal]\nGeneral: K = 7 mmol/L [not flagged by the lab]\n\n")
                && ! str_contains($user, 'IMPORTANT')
                && str_ends_with($user, 'Keep language simple, reassuring, and non-alarming. Suitable for a non-medical audience.')
                && $r->hasHeader('Authorization', 'Bearer sk-test');
        });

        $this->postJson(self::API.'/ai-summary', $this->payload(), $this->bearer($token))->assertJsonPath('cached', true);
        Http::assertSentCount(1);
        $this->assertSame(md5('5_101_NOUR AL HARIRI'), AiSummary::sole()->cache_key);
    }

    public function test_a_lab_without_ranges_or_flags_must_not_be_called_normal(): void
    {
        $this->lab(5, 'Bekaa Hospital', ['ai_summary' => true]);
        [, $token] = $this->signedIn();
        $this->openAi();

        $this->postJson(self::API.'/ai-summary', $this->payload(['has_ranges' => false, 'has_flags' => false]), $this->bearer($token))->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'], 'CBC: Hb = 13 g/dL [Normal]')
            && str_contains($r['messages'][1]['content'], 'IMPORTANT: This laboratory does not record reference ranges or high/low flags. You therefore CANNOT tell'));
    }

    public function test_refuses_before_spending_when_switched_off(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['ai_summary' => false]);
        [, $token] = $this->signedIn();
        Http::fake();

        $this->postJson(self::API.'/ai-summary', $this->payload(), $this->bearer($token))
            ->assertStatus(403)->assertJsonPath('message', 'AI summaries are not enabled for this lab.');

        Setting::put('ai_summary_enabled', false);
        $this->postJson(self::API.'/ai-summary', $this->payload(), $this->bearer($token))
            ->assertStatus(403)->assertExactJson(['code' => 'ai_summary_disabled', 'message' => 'AI summaries are currently unavailable.', 'data' => ['status' => 403]]);

        Http::assertNothingSent();
    }

    public function test_bad_input_and_upstream_errors(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['ai_summary' => true]);
        [, $token] = $this->signedIn();

        $this->postJson(self::API.'/ai-summary', $this->payload(['results' => []]), $this->bearer($token))
            ->assertStatus(400)->assertJsonPath('code', 'invalid_results');
        $this->postJson(self::API.'/ai-summary', $this->payload(['results' => null]), $this->bearer($token))
            ->assertStatus(400)->assertJsonPath('code', 'rest_missing_callback_param');
        $this->postJson(self::API.'/ai-summary', $this->payload(['results' => [['result' => '']]]), $this->bearer($token))
            ->assertStatus(400)->assertJsonPath('code', 'no_results');

        Http::fake(['*' => Http::response(['error' => ['message' => 'Rate limit reached']], 429)]);
        $this->postJson(self::API.'/ai-summary', $this->payload(), $this->bearer($token))
            ->assertStatus(429)->assertJsonPath('message', 'Rate limit reached');
        $this->assertSame(0, AiSummary::count());
    }
}
