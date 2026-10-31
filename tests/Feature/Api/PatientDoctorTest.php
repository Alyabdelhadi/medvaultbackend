<?php

namespace Tests\Feature\Api;

use App\Models\LabAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * One account that is a patient and a doctor. The lab answers the same
 * number as type P and as type D with different orders, so each side has
 * its own login and token, and each endpoint uses the side it is for.
 */
class PatientDoctorTest extends ApiTestCase
{
    private function lab9(): void
    {
        $this->lab(9, 'Bekaa Hospital', ['api_pdf_mode' => 'endpoint']);
    }

    /** Logins answer per type; /request answers per token. */
    private function fakeLab(): void
    {
        Http::fake(function (Request $r) {
            if (str_ends_with($r->url(), '/login')) {
                $type = json_decode($r->body(), true)['type'] ?? '';

                return Http::response(['success' => true, 'token' => $type === 'D' ? 'doctok' : 'pattok', 'name' => 'NOUR']);
            }
            if (str_contains($r->url(), '/request')) {
                return str_contains($r->url(), 'tcToken=doctok')
                    ? Http::response(['requests' => [['request_nb' => '77', 'order_datetime' => '2026-10-01 09:00:00', 'status' => 'V', 'name' => 'A PATIENT OF HERS', 'pdf_path' => 'R/77.pdf']]])
                    : Http::response(['requests' => [['request_nb' => '11', 'order_datetime' => '2026-09-01 09:00:00', 'status' => 'V', 'name' => 'NOUR']]]);
            }
            if (str_contains($r->url(), '/results')) {
                return Http::response(['results' => [['test_name' => 'HB', 'result' => str_contains($r->url(), 'doctok') ? '9' : '14']]]);
            }

            return Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']);
        });
    }

    public function test_the_profile_lists_both_roles_and_keeps_patient_as_the_type(): void
    {
        [, $token] = $this->signedIn(['also_doctor' => true]);

        // An app build that knows one role still sees a patient.
        $this->getJson(self::API.'/profile', $this->bearer($token))->assertOk()
            ->assertJsonPath('data.function_type', 'P')
            ->assertJsonPath('data.roles', ['P', 'D']);
    }

    public function test_a_plain_patient_has_one_role(): void
    {
        [, $token] = $this->signedIn();
        $this->getJson(self::API.'/profile', $this->bearer($token))->assertJsonPath('data.roles', ['P']);
    }

    public function test_each_side_reads_its_own_orders_with_its_own_login(): void
    {
        $this->lab9();
        [, $token] = $this->signedIn(['also_doctor' => true]);
        $this->fakeLab();

        $this->getJson(self::API.'/lab-results', $this->bearer($token))->assertOk()
            ->assertJsonPath('count', 1)->assertJsonPath('data.0.request_nb', '11');
        $this->getJson(self::API.'/dg/requests', $this->bearer($token))->assertOk()
            ->assertJsonPath('patients.0.patient_name', 'A PATIENT OF HERS');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/login') && json_decode($r->body(), true)['type'] === 'P');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/login') && json_decode($r->body(), true)['type'] === 'D');

        $account = LabAccount::sole();
        $this->assertSame(['pattok', 'doctok'], [$account->external_token, $account->doctor_token]);
    }

    public function test_an_order_and_its_pdf_opened_as_the_doctor_use_the_doctor_login(): void
    {
        $this->lab9();
        [, $token] = $this->signedIn(['also_doctor' => true]);
        $this->fakeLab();

        $this->getJson(self::API.'/lab-results/77?lab_id=9&as=D', $this->bearer($token))->assertOk()
            ->assertJsonPath('data.0.result', '9');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'requestnb=77') && str_contains($r->url(), 'tcToken=doctok'));

        $url = $this->getJson(self::API.'/dg/requests', $this->bearer($token))->json('patients.0.requests.0.pdf_url');
        $this->assertStringContainsString('src=doctor', $url);
        $this->get(substr($url, strpos($url, '/wp-json')))->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/file') && str_contains($r->url(), 'tcToken=doctok'));
    }

    public function test_as_d_means_nothing_to_an_account_that_is_not_both(): void
    {
        $this->lab9();
        [$user, $token] = $this->signedIn();
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'pattok']);
        $this->fakeLab();

        $this->getJson(self::API.'/lab-results/11?lab_id=9&as=D', $this->bearer($token))->assertOk()
            ->assertJsonPath('data.0.result', '14');
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/login'));
    }

    public function test_being_refused_as_a_doctor_never_touches_the_patient_side(): void
    {
        $this->lab9();
        [$user, $token] = $this->signedIn(['also_doctor' => true]);
        LabAccount::create(['user_id' => $user->id, 'lab_id' => 9, 'external_token' => 'pattok']);
        Http::fake(['*/login' => Http::response(['success' => false, 'message' => 'Invalid credentials'])]);

        $this->getJson(self::API.'/dg/requests', $this->bearer($token))->assertOk()->assertJsonPath('count', 0);

        $account = LabAccount::sole();
        $this->assertSame([1, 0, 'pattok'], [$account->doctor_auth_failures, (int) $account->auth_failures, $account->external_token]);
    }
}
