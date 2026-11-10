<?php

namespace Tests\Feature\Api;

use App\Models\LabAccount;
use App\Services\Labs\LabApi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * /radiology alongside /request, in the shape Bekaa's live server answers
 * (2026-10-24): studies with study_nb, a hardcoded status "V", and modality,
 * study_name, pdf_path and report_datetime all null until reported.
 */
class RadiologyTest extends ApiTestCase
{
    private const REQUESTS = ['requests' => [
        ['request_nb' => '26L000001', 'order_datetime' => '2026-04-24 09:37:00', 'status' => 'V', 'pdf_path' => null, 'patient_name' => 'NOUR AL HARIRI', 'cat_name' => 'CHEMISTRY'],
    ]];

    private const STUDIES = ['studies' => [
        ['study_nb' => '26X000001', 'order_datetime' => '2026-04-01 09:15:00', 'status' => 'V', 'modality' => null, 'study_name' => null, 'pdf_path' => null, 'report_datetime' => null, 'patient_name' => 'NOUR AL HARIRI'],
        ['study_nb' => '26X000002', 'order_datetime' => '2026-10-12 11:20:00', 'status' => 'V', 'modality' => 'CT', 'study_name' => 'CT Chest', 'pdf_path' => 'RadPDF/26X000002_100.PDF', 'report_datetime' => '2026-10-12 15:00:00', 'patient_name' => 'NOUR AL HARIRI'],
    ]];

    private function bekaa(array $attributes = []): void
    {
        $this->lab(9, 'Bekaa Hospital', [
            'ready_signal' => 'none', 'caps_ranges' => false, 'caps_flags' => false,
            'api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://pdf9.test/', ...$attributes,
        ]);
    }

    private function signedInAtBekaa(): string
    {
        [$user, $token] = $this->signedIn();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'good']);

        return $token;
    }

    private function fakeBekaa(): void
    {
        Http::fake([
            'lab9.test/api/mobile/request*' => Http::response(self::REQUESTS),
            'lab9.test/api/mobile/results*' => Http::response(['results' => []]),
            'lab9.test/api/mobile/radiology*' => Http::response(self::STUDIES),
        ]);
    }

    public function test_off_by_default_and_never_called(): void
    {
        $this->bekaa();
        $token = $this->signedInAtBekaa();
        $this->fakeBekaa();

        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonMissingPath('data.0.kind');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/radiology'));
    }

    public function test_studies_are_listed_with_the_orders_as_report_only_rows(): void
    {
        $this->bekaa(['radiology_enabled' => true]);
        $token = $this->signedInAtBekaa();
        $this->fakeBekaa();

        $response = $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 3)->assertJsonPath('errors', []);

        $rows = collect($response->json('data'))->keyBy('request_nb');
        $reported = $rows['26X000002'];
        // The shape of an order, nothing more: the app renders it unchanged.
        $this->assertSame(array_keys($rows['26L000001']), array_keys($reported));
        $this->assertSame('Radiology', $reported['cat_name']);
        $this->assertSame('NOUR AL HARIRI', $reported['name']);
        $this->assertTrue($reported['is_ready']);
        $this->assertTrue($reported['ready_known']);
        $this->assertSame(['RadPDF/26X000002_100.PDF'], $reported['pdf_paths']);
        $this->assertStringContainsString('/lab-file?', $reported['pdf_url']);
        $this->assertTrue($reported['results_loaded']);
        // One text row, so the order reads as a finished result.
        $this->assertCount(1, $reported['results']);
        $row = $reported['results'][0];
        $this->assertSame('CT Chest', $row['test_name']);
        $this->assertSame('CT', $row['group_name']);
        $this->assertSame('Radiology', $row['cat_name']);
        $this->assertSame('Report available. Open the PDF to read it.', $row['result_txt']);
        $this->assertSame('report', $row['kind']);
        $this->assertSame(0, $row['id']);
        $this->assertSame('', $row['result_status']);
        $this->assertSame('', $row['ref_range']);
        // No rows, so nothing to interpret or summarise, whatever the lab is set to.
        $this->assertSame(['ranges' => false, 'flags' => false, 'pdf' => true, 'ai' => false], $reported['capabilities']);

        // Status "V" with no report is not ready: the status is a literal.
        $unreported = $rows['26X000001'];
        $this->assertFalse($unreported['is_ready']);
        $this->assertTrue($unreported['ready_known']);
        $this->assertNull($unreported['pdf_url']);
        // No rows: the app's existing "not ready yet".
        $this->assertSame([], $unreported['results']);

        // The lab order is untouched by radiology being on.
        $this->assertFalse($rows['26L000001']['ready_known']);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/radiology?tcToken=good'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'requestnb=26X'));
    }

    public function test_a_radiology_outage_keeps_the_lab_orders(): void
    {
        $this->bekaa(['radiology_enabled' => true]);
        $token = $this->signedInAtBekaa();
        Http::fake([
            'lab9.test/api/mobile/request*' => Http::response(self::REQUESTS),
            'lab9.test/api/mobile/results*' => Http::response(['results' => []]),
            'lab9.test/api/mobile/radiology*' => Http::response('Bad gateway', 502),
        ]);

        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.request_nb', '26L000001')
            ->assertJsonPath('errors.0.lab_id', 9)
            ->assertJsonPath('errors.0.message', 'Radiology system returned HTTP 502.');
    }

    public function test_a_signed_study_pdf_streams_through_the_proxy(): void
    {
        $this->bekaa(['radiology_enabled' => true]);
        $token = $this->signedInAtBekaa();
        $this->fakeBekaa();

        $url = collect($this->getJson(self::API.'/lab-results', $this->bearer($token))->json('data'))
            ->firstWhere('request_nb', '26X000002')['pdf_url'];

        Http::fake(['pdf9.test/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);
        $this->get(substr($url, strpos($url, '/wp-json')))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://pdf9.test/RadPDF/26X000002_100.PDF');
    }

    /**
     * Bekaa's radiology answers on its own server: a second lab post with
     * orders off. Its /request is a different database and must not be listed.
     */
    public function test_a_radiology_only_post_lists_studies_and_never_its_orders(): void
    {
        $this->bekaa();
        $this->lab(10, 'Bekaa Hospital Radiology', [
            'ready_signal' => 'none', 'caps_ranges' => false, 'caps_flags' => false,
            'radiology_enabled' => true, 'orders_enabled' => false, 'api_priority' => 20,
            'api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://pdf10.test/',
        ]);
        $token = $this->signedInAtBekaa();
        LabAccount::create(['user_id' => LabAccount::first()->user_id, 'lab_id' => 10, 'external_token' => 'rad']);
        Http::fake([
            'lab9.test/api/mobile/request*' => Http::response(self::REQUESTS),
            'lab9.test/api/mobile/results*' => Http::response(['results' => []]),
            'lab10.test/api/mobile/radiology*' => Http::response(self::STUDIES),
            'lab10.test/*' => Http::response(['requests' => [['request_nb' => '99L000001', 'status' => 'V']]]),
        ]);

        $response = $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 3)->assertJsonPath('errors', []);

        $rows = collect($response->json('data'))->keyBy('request_nb');
        $this->assertSame(9, $rows['26L000001']['lab_id']);
        $this->assertSame(10, $rows['26X000002']['lab_id']);
        $this->assertSame('Radiology', $rows['26X000002']['cat_name']);
        $this->assertStringContainsString('lab_id=10', $rows['26X000002']['pdf_url']);
        $this->assertArrayNotHasKey('99L000001', $rows->all());

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'lab10.test/api/mobile/request'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'lab9.test/api/mobile/radiology'));

        // Nothing to expand at a radiology-only post.
        $this->getJson(self::API.'/lab-results/26X000002?lab_id=10', $this->bearer($token))->assertNotFound();
    }

    /** One Bekaa post, two servers: lab orders on one, studies on the other. */
    private function bekaaWithRadiologyHost(): void
    {
        $this->bekaa(['radiology_enabled' => true, 'radiology_base' => 'https://rad9.test/api/mobile/', 'radiology_pdf_base' => 'https://radpdf9.test/']);
    }

    private function fakeTwoHosts(array $radiologyLogin = ['success' => true, 'token' => 'radtok', 'name' => 'NOUR AL HARIRI']): void
    {
        Http::fake([
            'lab9.test/api/mobile/request*' => Http::response(self::REQUESTS),
            'lab9.test/api/mobile/results*' => Http::response(['results' => []]),
            'rad9.test/api/mobile/login' => Http::response($radiologyLogin),
            'rad9.test/api/mobile/radiology*' => Http::response(self::STUDIES),
        ]);
    }

    public function test_a_separate_radiology_host_has_its_own_login_and_token(): void
    {
        $this->bekaaWithRadiologyHost();
        $token = $this->signedInAtBekaa();
        $this->fakeTwoHosts();

        $response = $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 3)->assertJsonPath('errors', []);

        $rows = collect($response->json('data'))->keyBy('request_nb');
        $this->assertSame(9, $rows['26X000002']['lab_id']);
        $this->assertSame(9, $rows['26L000001']['lab_id']);

        // The lab's token never goes to the radiology server, and the reverse.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://rad9.test/api/mobile/login');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'rad9.test/api/mobile/radiology?tcToken=radtok'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'lab9.test/api/mobile/radiology'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'rad9.test') && str_contains($r->url(), 'tcToken=good'));

        $account = LabAccount::sole();
        $this->assertSame('good', $account->external_token);
        $this->assertSame('radtok', $account->radiology_token);
        $this->assertSame('NOUR AL HARIRI', $account->radiology_holder_name);
        $this->assertNull($account->holder_name);
    }

    public function test_a_study_pdf_streams_from_the_radiology_hosts_folder_only(): void
    {
        $this->bekaaWithRadiologyHost();
        $token = $this->signedInAtBekaa();
        $this->fakeTwoHosts();

        $url = collect($this->getJson(self::API.'/lab-results', $this->bearer($token))->json('data'))
            ->firstWhere('request_nb', '26X000002')['pdf_url'];
        $this->assertStringContainsString('src=radiology', $url);
        $path = substr($url, strpos($url, '/wp-json'));

        Http::fake(['radpdf9.test/*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf'])]);
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://radpdf9.test/RadPDF/26X000002_100.PDF');

        // The source is signed: dropping it, to fetch the path from the lab's
        // folder instead, breaks the signature.
        $this->get(str_replace('&src=radiology', '', $path))->assertUnauthorized();
    }

    public function test_being_unknown_to_radiology_never_touches_the_lab_account(): void
    {
        $this->bekaaWithRadiologyHost();
        $token = $this->signedInAtBekaa();
        $this->fakeTwoHosts(['success' => false, 'message' => 'Invalid credentials']);

        // Not news, as at a lab that does not know the patient.
        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('errors', [])
            ->assertJsonPath('data.0.request_nb', '26L000001');

        $account = LabAccount::sole();
        $this->assertSame(1, $account->radiology_auth_failures);
        $this->assertSame(0, (int) $account->auth_failures);
        $this->assertSame('good', $account->external_token);
    }

    public function test_a_lab_that_gave_up_on_the_patient_still_shows_their_studies(): void
    {
        $this->bekaaWithRadiologyHost();
        $token = $this->signedInAtBekaa();
        LabAccount::query()->update(['auth_failures' => (int) config('medvault.labs.max_auth_failures')]);
        LabAccount::query()->each(fn ($a) => Cache::put(LabApi::retryKey($a->user_id, $a->lab_id), true, now()->addHour()));
        $this->fakeTwoHosts();

        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 2)->assertJsonPath('data.0.cat_name', 'Radiology')->assertJsonPath('data.1.cat_name', 'Radiology');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'lab9.test'));
    }
}
