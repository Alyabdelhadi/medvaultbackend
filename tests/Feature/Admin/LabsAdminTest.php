<?php

namespace Tests\Feature\Admin;

use App\Models\Lab;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LabsAdminTest extends AdminTestCase
{
    private function form(array $overrides = []): array
    {
        return [
            'title' => 'Zahle Lab', 'status' => 'publish', 'content' => '<p>Open daily</p>', 'location' => 'Zahle',
            'phone' => '08 123 456', 'email' => 'lab@zahle.test', 'latitude' => '33.85', 'longitude' => '35.9',
            'facebook' => 'https://facebook.com/zahle', 'slides' => "https://x.test/1.jpg\n\n javascript:alert(1)\nhttps://x.test/2.jpg",
            'api_enabled' => '1', 'api_driver' => 'vmc_v1', 'api_base' => 'https://zahle.test/api/mobile/',
            'api_pdf_mode' => 'static_proxy', 'api_pdf_base' => 'https://zahle.test/reports/', 'api_headers' => "X-Api-Key: plaintext-facility-secret\nngrok-skip-browser-warning: 1",
            'mobile_format' => 'national', 'ready_signal' => 'report_datetime', 'api_timeout' => '99', 'api_priority' => '5',
            'caps_ranges' => '1', 'caps_pdf' => '1',
            ...$overrides,
        ];
    }

    public function test_creating_a_lab_saves_with_the_plugins_rules_and_reaches_the_app(): void
    {
        $this->actingAs($this->admin());

        $this->post('/admin/labs', $this->form())->assertRedirect();
        $lab = Lab::sole();

        $this->assertSame('zahle-lab', $lab->slug);
        $this->assertSame(['https://x.test/1.jpg', 'https://x.test/2.jpg'], $lab->slides);
        $this->assertSame(60, $lab->api_timeout);
        $this->assertTrue($lab->caps_ranges && $lab->caps_pdf);
        $this->assertFalse($lab->caps_flags || $lab->ai_summary);
        $this->assertStringNotContainsString('plaintext-facility-secret', DB::table('labs')->value('api_headers'));
        $this->assertSame("X-Api-Key: plaintext-facility-secret\nngrok-skip-browser-warning: 1", $lab->api_headers);

        $this->getJson("/wp-json/custom/v1/labs/{$lab->id}")->assertOk()
            ->assertJsonPath('data.name', 'Zahle Lab')
            ->assertJsonPath('data.description', 'Open daily')
            ->assertJsonPath('data.latitude', 33.85)
            ->assertJsonPath('data.social_media', ['facebook' => 'https://facebook.com/zahle'])
            ->assertJsonPath('data.results_api', ['enabled' => true, 'capabilities' => ['ranges' => true, 'flags' => false, 'pdf' => true, 'ai' => false]])
            ->assertJsonMissingPath('data.api_headers');
    }

    public function test_unticked_boxes_turn_off_and_unknown_choices_are_ignored(): void
    {
        $this->actingAs($this->admin());
        $this->post('/admin/labs', $this->form(['caps_flags' => '1', 'ai_summary' => '1']));
        $lab = Lab::sole();

        $this->put("/admin/labs/{$lab->id}", $this->form(['api_enabled' => null, 'caps_ranges' => null, 'caps_pdf' => null, 'ready_signal' => 'whenever', 'api_priority' => '0']))->assertRedirect();
        $lab->refresh();

        $this->assertFalse($lab->api_enabled || $lab->caps_ranges || $lab->caps_flags || $lab->caps_pdf || $lab->ai_summary);
        $this->assertSame('report_datetime', $lab->ready_signal);
        $this->assertSame(0, $lab->api_priority);
    }

    public function test_uploads_replace_the_image_and_append_slides(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin());

        $this->post('/admin/labs', $this->form([
            'image_file' => UploadedFile::fake()->image('front.jpg'),
            'slide_files' => [UploadedFile::fake()->image('a.png'), UploadedFile::fake()->createWithContent('x.jpg', 'not an image')],
        ]));
        $lab = Lab::sole();

        $this->assertStringContainsString('/storage/uploads/', $lab->image_url);
        $this->assertCount(3, $lab->slides);
    }

    public function test_trash_hides_a_lab_from_the_app_and_restore_brings_it_back_as_a_draft(): void
    {
        $lab = $this->lab(5, 'Verdun Medical Center');
        $this->actingAs($this->admin());

        $this->post("/admin/labs/{$lab->id}/trash")->assertRedirect('/admin/labs');
        $this->getJson('/wp-json/custom/v1/labs/5')->assertNotFound();
        $this->get('/admin/labs')->assertSee('moved to the trash');
        $this->get('/admin/labs')->assertDontSee('Verdun Medical Center');
        $this->get('/admin/labs?status=trash')->assertSee('Verdun Medical Center');

        $this->post("/admin/labs/{$lab->id}/restore");
        $this->assertSame('draft', $lab->refresh()->status);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->admin());
        $this->from('/admin/labs/create')->post('/admin/labs', $this->form(['title' => '']))->assertRedirect('/admin/labs/create')->assertSessionHasErrors('title');
        $this->assertSame(0, Lab::count());
    }
}
