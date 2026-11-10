<?php

namespace Tests\Feature\Admin;

use App\Models\AiSummary;
use App\Models\Setting;
use App\Models\Slider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

class ContentAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->admin());
    }

    public function test_sliders_add_edit_reorder_delete_as_the_app_sees_them(): void
    {
        $this->post('/admin/sliders', ['image_url' => 'https://x.test/a.jpg', 'title' => 'A']);
        $this->post('/admin/sliders', ['image_url' => 'https://x.test/b.jpg']);
        $this->post('/admin/sliders', ['image_url' => 'https://x.test/c.jpg', 'link_url' => 'https://x.test/c']);
        [$a, $b, $c] = Slider::orderBy('position')->get();

        $this->post("/admin/sliders/{$c->id}/move/up");
        $this->put("/admin/sliders/{$a->id}", ['image_url' => 'https://x.test/a2.jpg', 'title' => '']);
        $this->delete("/admin/sliders/{$b->id}");

        $this->getJson('/wp-json/custom/v1/sliders')->assertExactJson(['success' => true, 'count' => 2, 'data' => [
            ['id' => 1, 'image_url' => 'https://x.test/a2.jpg', 'title' => '', 'link_url' => ''],
            ['id' => 2, 'image_url' => 'https://x.test/c.jpg', 'title' => '', 'link_url' => 'https://x.test/c'],
        ]]);
    }

    public function test_banners_need_an_image_and_a_link(): void
    {
        $this->from('/admin/banners')->post('/admin/banners', ['image_url' => 'https://x.test/b.jpg'])->assertSessionHasErrors('link_url');
        $this->from('/admin/banners')->post('/admin/banners', ['link_url' => 'https://x.test'])->assertSessionHasErrors('image_url');
        $this->post('/admin/banners', ['image_url' => 'https://x.test/b.jpg', 'link_url' => 'https://x.test'])->assertRedirect();
        $this->getJson('/wp-json/custom/v1/banners')->assertJsonPath('count', 1);
    }

    public function test_result_categories_replace_the_defaults(): void
    {
        $this->get('/admin/result-categories')->assertSee('built-in defaults')->assertSee('Hematology');

        $this->post('/admin/result-categories', [
            'label' => ['Blood Test', '', 'Clinical '],
            'cat_names' => ['Hematology,  BioChemistry ,', 'Ignored', 'Clinics'],
        ]);

        $this->getJson('/wp-json/custom/v1/result-categories')->assertExactJson(['success' => true, 'data' => [
            ['label' => 'Blood Test', 'cat_names' => ['Hematology', 'BioChemistry']],
            ['label' => 'Clinical', 'cat_names' => ['Clinics']],
        ]]);
    }

    public function test_pages_are_served_as_written_and_drafts_disappear(): void
    {
        $this->put('/admin/pages/about', ['title' => 'About App', 'content' => '<p>Hello <strong>patients</strong></p>', 'status' => 'publish'])->assertRedirect();
        $this->getJson('/wp-json/custom/v1/about')->assertJsonPath('data', ['id' => 12, 'title' => 'About App', 'content' => '<p>Hello <strong>patients</strong></p>', 'excerpt' => 'Hello patients']);

        $this->put('/admin/pages/about', ['title' => 'About App', 'content' => '<p>x</p>', 'status' => 'draft']);
        $this->getJson('/wp-json/custom/v1/about')->assertNotFound();
    }

    public function test_the_contact_number_is_set_from_the_dashboard(): void
    {
        $this->get('/admin/pages/about')->assertSee('+96176618326');
        $this->put('/admin/contact-phone', ['phone' => 'call me'])->assertSessionHasErrors('phone');
        $this->getJson('/wp-json/custom/v1/phone')->assertJsonPath('data.phone', '+96176618326');

        $this->put('/admin/contact-phone', ['phone' => '+961 70 123-456'])->assertSessionHas('status', 'Contact number saved.');
        $this->getJson('/wp-json/custom/v1/phone')->assertExactJson(['success' => true, 'data' => ['phone' => '+96170123456']]);
    }

    public function test_broadcast_goes_to_the_topic(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        $this->post('/admin/push/firebase', ['credentials' => json_encode(['project_id' => 'mv', 'client_email' => 'a@mv.iam.gserviceaccount.com', 'private_key' => $pem])])
            ->assertSessionHas('status', 'Firebase credentials saved for project mv.');
        $this->get('/admin/push/firebase')->assertSee('a@mv.iam.gserviceaccount.com')->assertDontSee('PRIVATE KEY');

        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 't']), 'fcm.googleapis.com/*' => Http::response(['name' => 'x'])]);
        $this->post('/admin/push', ['title' => 'Eid hours', 'body' => 'Labs close early.'])->assertSessionHas('status', 'Notification sent to all subscribed users.');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm') && $r['message']['topic'] === 'medvault' && $r['message']['notification'] === ['title' => 'Eid hours', 'body' => 'Labs close early.']);

        $this->post('/admin/push', ['title' => '', 'body' => 'x'])->assertSessionHas('status', 'Title and message are required.');
    }

    public function test_firebase_rejects_anything_but_a_service_account_key(): void
    {
        $this->post('/admin/push/firebase', ['credentials' => '{"project_id":"mv"}'])->assertSessionHas('status_type', 'error');
        $this->assertNull(Setting::get('firebase_credentials'));
    }

    public function test_result_alert_settings_and_run_poll_now_reports_the_run(): void
    {
        $this->post('/admin/result-alerts', ['poll_minutes' => '2', 'poll_batch_size' => '0'])->assertRedirect();
        $this->assertSame([false, 5, 1], [Setting::get('poll_enabled'), Setting::get('poll_minutes'), Setting::get('poll_batch_size')]);

        // Runs even while disabled, and reports the numbers rather than an empty warning.
        $this->post('/admin/result-alerts/run')->assertSessionHas('status', 'Polled 0 of 0 users (from offset 0): 0 notification(s) sent, 0 seeded, 0 expired token(s), 0 error(s).');
        $this->get('/admin/result-alerts')->assertSee('scheduler has not checked in');
    }

    public function test_ai_switch_and_cache_flush(): void
    {
        AiSummary::create(['cache_key' => 'a', 'data' => ['intro' => 'x'], 'expires_at' => now()->addDay()]);
        $this->get('/admin/ai-summary')->assertSee('1 cached');

        $this->post('/admin/ai-summary', ['enabled' => '0']);
        $this->assertFalse(Setting::get('ai_summary_enabled'));
        $this->assertSame(1, AiSummary::count());

        $this->post('/admin/ai-summary', ['enabled' => '1', 'flush' => '1'])->assertSessionHas('status', 'Settings saved and cached summaries cleared.');
        $this->assertSame([true, 0], [Setting::get('ai_summary_enabled'), AiSummary::count()]);
    }
}
