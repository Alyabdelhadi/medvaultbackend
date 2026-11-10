<?php

namespace Tests\Feature\Api;

use App\Models\LabAccount;
use App\Services\Labs\LabRows;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class LabResultsTest extends ApiTestCase
{
    private function verdun(array $attributes = []): void
    {
        $this->lab(5, 'Verdun Medical Center', [
            'api_pdf_base' => 'https://lab5.test/storage/', 'api_pdf_mode' => 'static_base', ...$attributes,
        ]);
    }

    private function giveLabToken(int $userId, int $labId, string $token): void
    {
        LabAccount::create(['user_id' => $userId, 'lab_id' => $labId, 'external_token' => $token]);
    }

    private const REQUESTS = ['requests' => [
        ['request_nb' => '100', 'order_datetime' => '2026-01-01 10:00:00', 'status' => 'P', 'pdf_path' => null, 'name' => 'NOUR AL HARIRI'],
        ['request_nb' => '101', 'order_datetime' => '2026-03-01 10:00:00', 'status' => 'V', 'pdf_path' => 'ResultPDF/101.pdf', 'name' => 'NOUR AL HARIRI'],
    ]];

    private const RESULTS = ['results' => [
        ['test_name' => 'Hb', 'result' => '13', 'ref_range' => '<div>12-16</div>', 'result_status' => 'N', 'unit' => 'g/dL'],
    ]];

    public function test_lists_orders_newest_first_with_their_results(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 5, 'good');
        Http::fake([
            'lab5.test/api/mobile/request*' => Http::response(self::REQUESTS),
            'lab5.test/api/mobile/results*' => Http::response(self::RESULTS),
        ]);

        $response = $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('count', 2)
            ->assertJsonPath('truncated', false)->assertJsonPath('errors', [])
            ->assertJsonPath('data.0.request_nb', '101')
            ->assertJsonPath('data.0.is_ready', true)
            ->assertJsonPath('data.0.pdf_url', 'https://lab5.test/storage/ResultPDF/101.pdf')
            ->assertJsonPath('data.0.results.0.ref_range', '<div>12-16</div>')
            ->assertJsonPath('data.0.results_loaded', true)
            ->assertJsonPath('data.0.capabilities', ['ranges' => true, 'flags' => true, 'pdf' => true, 'ai' => false])
            ->assertJsonPath('data.1.request_nb', '100')
            ->assertJsonPath('data.1.is_ready', false);

        // The lab token is sent as tcToken and never appears in the response.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/request?tcToken=good'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/results?requestnb=101&tcToken=good'));
        $this->assertStringNotContainsString('good', $response->getContent());
    }

    public function test_an_expired_lab_token_is_reissued_once_and_replayed(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 5, 'STALE');
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/login')) {
                return Http::response(['success' => true, 'token' => 'FRESH']);
            }

            return str_contains($r->url(), 'STALE')
                ? Http::response(['success' => false], 401)
                : Http::response(['requests' => []]);
        });

        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()->assertJsonPath('errors', []);

        Http::assertSentCount(3);
        $this->assertSame('FRESH', LabAccount::where('user_id', $user->id)->where('lab_id', 5)->first()->external_token);
    }

    public function test_with_results_zero_and_the_time_budget_leave_rows_for_lazy_loading(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 5, 'good');
        Http::fake(['*/request*' => Http::response(self::REQUESTS), '*/results*' => Http::response(self::RESULTS)]);

        $this->getJson(self::API.'/lab-results?with_results=0', $this->bearer($token))
            ->assertJsonPath('truncated', false)
            ->assertJsonPath('data.0.results_loaded', false)
            ->assertJsonPath('data.0.results', []);
        Http::assertSentCount(1);

        config(['medvault.labs.results_time_budget' => 0.0]);
        $this->getJson(self::API.'/lab-results', $this->bearer($token))
            ->assertJsonPath('truncated', true)
            ->assertJsonPath('data.0.results_loaded', false);
    }

    public function test_one_lab_down_keeps_the_others_and_unknown_accounts_are_silent(): void
    {
        $this->verdun();
        $this->lab(9, 'Bekaa Hospital');
        $this->lab(12, 'Clinic That Never Heard Of You');
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 5, 'good');
        $this->giveLabToken($user->id, 9, 'good');
        Http::fake([
            'lab5.test/*/request*' => Http::response(self::REQUESTS),
            'lab5.test/*/results*' => Http::response(self::RESULTS),
            'lab9.test/*' => Http::response('<html>502</html>', 502),
            'lab12.test/*' => Http::response(['success' => false, 'message' => 'Invalid credentials'], 401),
        ]);

        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('errors', [['lab_id' => 9, 'lab_name' => 'Bekaa Hospital', 'message' => 'Lab system returned HTTP 502.']]);
    }

    /** A timed-out lab is reported, but never with its address or token. */
    public function test_an_unreachable_lab_is_reported_without_its_address(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 5, 'good');
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for https://lab5.test/api/mobile/request?tcToken=good'));

        $response = $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('errors.0.message', 'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received');
        $this->assertStringNotContainsString('lab5.test', $response->getContent());
        $this->assertStringNotContainsString('good', $response->getContent());
    }

    public function test_a_lab_that_keeps_refusing_is_asked_once_an_hour_until_it_knows_the_patient(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 5, 'auth_failures' => 5]);
        $registered = false;
        $logins = 0;
        Http::fake(function (Request $r) use (&$registered, &$logins) {
            if (str_contains($r->url(), '/login')) {
                $logins++;

                return Http::response($registered ? ['success' => true, 'token' => 'new'] : ['success' => false]);
            }

            return Http::response(str_contains($r->url(), '/results') ? self::RESULTS : self::REQUESTS);
        });

        // This hour's one attempt is refused; the rest of the hour costs nothing.
        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()->assertJsonPath('count', 0);
        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()->assertJsonPath('count', 0);
        $this->assertSame(1, $logins);

        // The patient visits the lab; the next hour's attempt gets through.
        $registered = true;
        $this->travel(61)->minutes();
        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(2, $logins);
        $this->assertSame(0, LabAccount::where('user_id', $user->id)->value('auth_failures'));
    }

    public function test_capabilities_blank_what_the_lab_never_recorded(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['caps_ranges' => false, 'caps_flags' => false, 'caps_pdf' => false, 'ready_signal' => 'none']);
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 9, 'good');
        Http::fake(['*/request*' => Http::response(self::REQUESTS), '*/results*' => Http::response(self::RESULTS)]);

        $this->getJson(self::API.'/lab-results', $this->bearer($token))
            ->assertJsonPath('data.0.is_ready', false)
            ->assertJsonPath('data.0.ready_known', false)
            ->assertJsonPath('data.0.pdf_url', null)
            ->assertJsonPath('data.0.pdf_urls', [])
            // "N" is an assertion this lab never made.
            ->assertJsonPath('data.0.results.0.result_status', '')
            ->assertJsonPath('data.0.results.0.ref_range', '');
    }

    public function test_single_order_lazy_load(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 5, 'good');
        Http::fake([
            '*/results?requestnb=101*' => Http::response("\xEF\xBB\xBF".json_encode(self::RESULTS)),
            '*/results?requestnb=999*' => Http::response(['success' => false], 404),
        ]);

        $this->getJson(self::API.'/lab-results/101?lab_id=5', $this->bearer($token))->assertOk()
            ->assertExactJson([
                'success' => true, 'lab_id' => 5, 'lab_name' => 'Verdun Medical Center', 'request_nb' => '101',
                'capabilities' => ['ranges' => true, 'flags' => true, 'pdf' => true, 'ai' => false],
                'results_loaded' => true, 'count' => 1,
                'data' => [[
                    'id' => '', 'name' => '', 'birthdate' => '', 'test_name' => 'Hb', 'group_name' => '', 'unit' => 'g/dL',
                    'ref_range' => '<div>12-16</div>', 'result' => '13', 'result_txt' => '', 'result_status' => 'N',
                    'sign' => '', 'is_title' => '', 'cat_name' => '',
                ]],
            ]);

        $this->getJson(self::API.'/lab-results/999?lab_id=5', $this->bearer($token))->assertStatus(404)
            ->assertExactJson(['code' => 'result_not_found', 'message' => 'These results are not available.', 'data' => ['status' => 404]]);
        $this->getJson(self::API.'/lab-results/101?lab_id=777', $this->bearer($token))->assertStatus(404)
            ->assertJsonPath('message', 'Unknown lab.');
        $this->getJson(self::API.'/lab-results/10.1', $this->bearer($token))->assertStatus(404)->assertJsonPath('code', 'rest_no_route');
    }

    public function test_doctor_requests_are_grouped_by_patient(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn(['function_type' => 'D']);
        $this->giveLabToken($user->id, 5, 'good');
        Http::fake(['*/request*' => Http::response(['requests' => [
            'Hematology' => [
                ['request_nb' => '1', 'order_datetime' => '2026-01-01 08:00:00', 'status' => 'V', 'name' => 'ZEINA'],
                ['request_nb' => '2', 'order_datetime' => '2026-02-01 08:00:00', 'status' => 'P', 'name' => 'ADAM'],
            ],
            'Chemistry' => [
                ['request_nb' => '3', 'order_datetime' => '2026-03-01 08:00:00', 'status' => 'V', 'patient_name' => 'ZEINA'],
                ['request_nb' => '4', 'order_datetime' => '2026-03-02 08:00:00', 'status' => 'V', 'name' => ''],
            ],
        ]])]);

        $response = $this->getJson(self::API.'/dg/requests', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('patients.0.patient_name', 'ADAM')
            ->assertJsonPath('patients.1.patient_name', 'ZEINA')
            ->assertJsonPath('patients.1.request_count', 2)
            ->assertJsonPath('patients.1.latest_datetime', '2026-03-01 08:00:00')
            ->assertJsonPath('patients.1.requests.0.cat_name', 'Chemistry')
            ->assertJsonPath('patients.1.requests.1.cat_name', 'Hematology');

        $this->assertSame(
            ['request_nb', 'order_datetime', 'status', 'is_ready', 'ready_known', 'pdf_path', 'pdf_url', 'pdf_paths', 'pdf_urls', 'cat_name', 'birth_date', 'lab_id', 'lab_name'],
            array_keys($response->json('patients.0.requests.0')),
        );
    }

    public function test_doctor_with_no_requests(): void
    {
        $this->verdun();
        [$user, $token] = $this->signedIn(['function_type' => 'G']);
        $this->giveLabToken($user->id, 5, 'good');
        Http::fake(['*' => Http::response(['requests' => []])]);

        $this->getJson(self::API.'/dg/requests', $this->bearer($token))
            ->assertExactJson(['success' => true, 'count' => 0, 'errors' => [], 'patients' => []]);
    }

    public function test_results_require_a_session(): void
    {
        foreach (['/lab-results', '/lab-results/1', '/dg/requests'] as $path) {
            $this->getJson(self::API.$path)->assertStatus(401)->assertJsonPath('code', 'unauthorized');
        }
    }

    public function test_signed_report_links_are_issued_for_proxied_labs(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://tunnel.test/']);
        [$user, $token] = $this->signedIn();
        $this->giveLabToken($user->id, 9, 'good');
        Http::fake(['*/request*' => Http::response(['requests' => [
            ['request_nb' => '7', 'order_datetime' => '2026-01-01', 'pdf_path' => 'ResultPDF/7_100.PDF', 'pdf_paths' => ['ResultPDF/7_100.PDF', 'ResultPDF/7_101.PDF']],
        ]]), '*/results*' => Http::response(['results' => []])]);

        $row = $this->getJson(self::API.'/lab-results', $this->bearer($token))->json('data.0');

        $this->assertCount(2, $row['pdf_urls']);
        $this->assertStringNotContainsString('tunnel.test', $row['pdf_url']);
        parse_str(parse_url($row['pdf_url'], PHP_URL_QUERY), $q);
        $this->assertSame(['lab_id' => '9', 'path' => 'ResultPDF/7_100.PDF', 'uid' => '217'], array_intersect_key($q, ['lab_id' => 1, 'path' => 1, 'uid' => 1]));
        $this->assertSame(LabRows::signature(217, 9, 'ResultPDF/7_100.PDF', (int) $q['exp']), $q['sig']);
    }
}
