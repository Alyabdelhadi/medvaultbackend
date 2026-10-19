<?php

namespace Tests\Feature\Api;

use App\Models\Lab;
use App\Models\Setting;

class DirectoryTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['medvault.site_url' => 'https://medvaultapp.com/app']);
    }

    /** Recreates the live labs from what WordPress served for them. */
    private function seedFromLive(): array
    {
        $live = json_decode(file_get_contents(base_path('tests/Fixtures/wordpress-live/labs.json')), true);

        foreach ($live['data'] as $l) {
            $this->lab($l['id'], $l['name'], [
                'slug' => $l['slug'],
                'content' => $l['description'],
                'image_url' => $l['image_url'],
                'location' => $l['location'],
                'google_map_url' => $l['google_map_url'],
                'appointment_phone' => $l['appointment_phone'],
                'mobile_phone' => $l['mobile_phone'],
                'phone' => $l['phone'],
                'email' => $l['email'],
                'website' => $l['website'],
                'refer_link' => $l['refer_link'],
                'refer_text' => $l['refer_text'],
                'latitude' => (string) $l['latitude'],
                'longitude' => (string) $l['longitude'],
                'slides' => array_column($l['slides'], 'image_url'),
                ...$l['social_media'],
                'api_enabled' => $l['results_api']['enabled'],
                'caps_ranges' => $l['results_api']['capabilities']['ranges'],
                'caps_flags' => $l['results_api']['capabilities']['flags'],
                'caps_pdf' => $l['results_api']['capabilities']['pdf'],
                'ai_summary' => $l['results_api']['capabilities']['ai'],
            ]);
        }

        return $live;
    }

    /** The whole /labs response, byte-for-byte what WordPress serves today. */
    public function test_labs_matches_the_live_wordpress_output(): void
    {
        $live = $this->seedFromLive();

        $this->assertSame($live, $this->getJson(self::API.'/labs?per_page=100')->assertOk()->json());
    }

    public function test_single_lab_matches_and_unknown_or_unpublished_is_404(): void
    {
        $live = $this->seedFromLive();
        Lab::whereKey(59)->update(['status' => 'draft']);

        $this->assertSame(['success' => true, 'data' => $live['data'][1]], $this->getJson(self::API.'/labs/15')->json());

        foreach (['/labs/59', '/labs/999999'] as $path) {
            $this->getJson(self::API.$path)
                ->assertStatus(404)
                ->assertExactJson(['code' => 'lab_not_found', 'message' => 'Lab not found.', 'data' => ['status' => 404]]);
        }
        $this->getJson(self::API.'/labs/abc')->assertStatus(404)->assertJsonPath('code', 'rest_no_route');
    }

    public function test_pagination_follows_wp_query(): void
    {
        foreach (['Zahle Lab', 'alpha clinic', 'Beta Lab'] as $i => $name) {
            $this->lab(100 + $i, $name);
        }
        $this->lab(200, 'Hidden', ['status' => 'draft']);

        $page = $this->getJson(self::API.'/labs?per_page=2&page=2')->assertOk();
        $page->assertJsonPath('total', 3)->assertJsonPath('total_pages', 2)->assertJsonPath('page', 2)
            ->assertJsonPath('per_page', 2)->assertJsonPath('data.0.name', 'Zahle Lab');

        // Title order ignores case, as MySQL's collation did.
        $this->assertSame(['alpha clinic', 'Beta Lab', 'Zahle Lab'], array_column($this->getJson(self::API.'/labs')->json('data'), 'name'));
        // per_page=0 is a page of one; the response still says 0.
        $this->getJson(self::API.'/labs?per_page=0')->assertJsonPath('per_page', 0)->assertJsonCount(1, 'data')->assertJsonPath('total_pages', 3);
    }

    public function test_fallbacks_and_capabilities(): void
    {
        $this->lab(7, 'Directory Only', [
            'api_enabled' => false, 'api_base' => null, 'thumbnail_url' => 'https://x.test/thumb.png',
            'latitude' => 'n/a', 'content' => '<!-- wp:paragraph --><p>Open <b>daily</b></p><!-- /wp:paragraph -->',
        ]);
        $this->lab(8, 'Paid', ['ai_summary' => true]);

        $directory = $this->getJson(self::API.'/labs/7')->json('data');
        $this->assertSame('https://x.test/thumb.png', $directory['image_url']);
        $this->assertSame('https://example.com/refer', $directory['refer_link']);
        $this->assertNull($directory['latitude']);
        $this->assertSame('Open daily', $directory['description']);
        $this->assertSame([], $directory['social_media']);
        $this->assertSame([], $directory['slides']);
        $this->assertSame(['enabled' => false, 'capabilities' => ['ranges' => false, 'flags' => false, 'pdf' => false, 'ai' => false]], $directory['results_api']);

        $this->getJson(self::API.'/labs/8')->assertJsonPath('data.results_api.capabilities.ai', true);
        // The global switch overrides every lab's opt-in.
        Setting::put('ai_summary_enabled', false);
        $this->getJson(self::API.'/labs/8')->assertJsonPath('data.results_api.capabilities.ai', false);
    }
}
