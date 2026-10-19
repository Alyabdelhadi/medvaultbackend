<?php

namespace Tests\Feature\Api;

use App\Models\LabAccount;
use App\Services\Labs\LabRows;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * GET /lab-file: opened by the OS browser, so the signed link is the
 * credential and doubles as the ownership check.
 */
class LabFileTest extends ApiTestCase
{
    private const PDF = "%PDF-1.4\n%fake report\n";

    private function link(int $labId, string $path, int $userId = 217, ?int $expires = null, ?string $sig = null): string
    {
        $expires ??= time() + 3600;
        $sig ??= LabRows::signature($userId, $labId, $path, $expires);

        return self::API.'/lab-file?'.http_build_query(['lab_id' => $labId, 'path' => $path, 'uid' => $userId, 'exp' => $expires, 'sig' => $sig]);
    }

    public function test_static_proxy_streams_the_pdf_with_the_labs_headers(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://tunnel.test/', 'api_headers' => 'ngrok-skip-browser-warning: 1']);
        $this->patient();
        Http::fake(['tunnel.test/*' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf'])]);

        $response = $this->get($this->link(9, 'ResultPDF/26E007790_101.PDF'))->assertOk();

        $this->assertSame(self::PDF, $response->getContent());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename="26E007790_101.PDF"', $response->headers->get('Content-Disposition'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://tunnel.test/ResultPDF/26E007790_101.PDF' && $r->hasHeader('ngrok-skip-browser-warning', '1'));
    }

    public function test_a_tunnel_interstitial_is_an_outage_not_a_blank_pdf(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://tunnel.test/']);
        $this->patient();
        Http::fake(['*' => Http::response('<html>ngrok warning</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->get($this->link(9, 'ResultPDF/x.pdf'))->assertStatus(502)->assertJsonPath('code', 'lab_file_unavailable');
    }

    public function test_the_signature_binds_user_lab_path_and_expiry(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://tunnel.test/']);
        $this->patient();
        Http::fake(['*' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf'])]);

        $expires = time() + 3600;
        $sig = LabRows::signature(217, 9, 'ResultPDF/mine.pdf', $expires);

        // Another patient's path under my signature.
        $this->get(self::API.'/lab-file?'.http_build_query(['lab_id' => 9, 'path' => 'ResultPDF/theirs.pdf', 'uid' => 217, 'exp' => $expires, 'sig' => $sig]))
            ->assertStatus(401)->assertExactJson(['code' => 'unauthorized', 'message' => 'Invalid file link.', 'data' => ['status' => 401]]);
        // A longer life.
        $this->get(self::API.'/lab-file?'.http_build_query(['lab_id' => 9, 'path' => 'ResultPDF/mine.pdf', 'uid' => 217, 'exp' => $expires + 1, 'sig' => $sig]))->assertStatus(401);
        // Expired.
        $this->get($this->link(9, 'ResultPDF/mine.pdf', 217, time() - 1))
            ->assertStatus(410)->assertJsonPath('message', 'This link has expired. Open the result again.');
        Http::assertNothingSent();
    }

    public function test_traversal_is_refused_even_when_signed(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://tunnel.test/reports/']);
        $this->patient();
        Http::fake();

        foreach (['../../.env', 'ResultPDF/../../x.pdf', 'https://evil.test/x.pdf'] as $path) {
            $this->get($this->link(9, $path))->assertStatus(404)->assertJsonPath('code', 'lab_file_unavailable');
        }
        Http::assertNothingSent();
    }

    public function test_endpoint_labs_are_fetched_with_the_lab_token_and_bearer_works(): void
    {
        $this->lab(44, 'Endpoint Lab', ['api_pdf_mode' => 'endpoint']);
        [$user, $token] = $this->signedIn();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 44, 'external_token' => 'lt']);
        Http::fake(['lab44.test/api/mobile/file*' => Http::response(self::PDF, 200)]);

        $this->get(self::API.'/lab-file?lab_id=44&path=ResultPDF/1.pdf', ['Authorization' => 'Bearer '.$token])->assertOk();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://lab44.test/api/mobile/file?path=ResultPDF%2F1.pdf&tcToken=lt');

        $this->getJson(self::API.'/lab-file?lab_id=44&path=x.pdf')->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    }

    public function test_labs_whose_files_the_app_fetches_itself_are_not_proxied(): void
    {
        $this->lab(5, 'Verdun Medical Center', ['api_pdf_mode' => 'static_base', 'api_pdf_base' => 'https://lab5.test/']);
        $this->patient();

        $this->get($this->link(5, 'a.pdf'))->assertStatus(404)
            ->assertJsonPath('message', 'This lab does not serve files through MedVault.');
        $this->getJson(self::API.'/lab-file?lab_id=5')->assertStatus(400)->assertJsonPath('code', 'rest_missing_callback_param');
    }

    public function test_a_lab_404_is_a_404(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://tunnel.test/']);
        $this->patient();
        Http::fake(['*' => Http::response('nope', 404)]);

        $this->get($this->link(9, 'ResultPDF/gone.pdf'))->assertStatus(404)->assertJsonPath('message', 'This file is not available.');
    }
}
