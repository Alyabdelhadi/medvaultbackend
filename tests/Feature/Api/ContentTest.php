<?php

namespace Tests\Feature\Api;

use App\Models\Banner;
use App\Models\Page;
use App\Models\ResultCategory;
use App\Models\Slider;

class ContentTest extends ApiTestCase
{
    private function live(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/Fixtures/wordpress-live/{$name}.json")), true);
    }

    public function test_sliders_match_the_live_output(): void
    {
        $live = $this->live('sliders');
        foreach ($live['data'] as $i => $s) {
            Slider::create(['position' => $i, 'image_url' => $s['image_url'], 'title' => $s['title'] ?: null, 'link_url' => $s['link_url'] ?: null]);
        }

        $this->assertSame($live, $this->getJson(self::API.'/sliders')->json());
    }

    public function test_banners_are_numbered_by_position(): void
    {
        Banner::create(['position' => 2, 'image_url' => 'https://x.test/2.jpg', 'link_url' => 'https://x.test/2']);
        Banner::create(['position' => 1, 'image_url' => 'https://x.test/1.jpg']);

        $this->getJson(self::API.'/banners')->assertExactJson(['success' => true, 'count' => 2, 'data' => [
            ['id' => 1, 'image_url' => 'https://x.test/1.jpg', 'link_url' => ''],
            ['id' => 2, 'image_url' => 'https://x.test/2.jpg', 'link_url' => 'https://x.test/2'],
        ]]);
    }

    public function test_result_categories_match_live_and_fall_back_to_defaults(): void
    {
        $this->getJson(self::API.'/result-categories')->assertJsonPath('data.0.label', 'Blood Test')->assertJsonCount(4, 'data');

        $live = $this->live('result-categories');
        foreach ($live['data'] as $i => $c) {
            ResultCategory::create(['position' => $i, 'label' => $c['label'], 'cat_names' => $c['cat_names']]);
        }
        ResultCategory::create(['position' => 99, 'label' => 'Empty', 'cat_names' => []]);

        $this->assertSame($live, $this->getJson(self::API.'/result-categories')->json());
    }

    public function test_about_matches_live_and_the_excerpt_is_generated_like_wordpress(): void
    {
        $live = $this->live('about');
        // Stored without an excerpt: the generated one must equal WordPress's.
        Page::forceCreate(['id' => 12, 'key' => 'about', 'title' => $live['data']['title'], 'content' => $live['data']['content'], 'status' => 'publish']);

        $this->assertSame($live, $this->getJson(self::API.'/about')->json());
    }

    public function test_missing_pages_and_the_phone(): void
    {
        Page::forceCreate(['id' => 10, 'key' => 'faq', 'title' => 'FAQ', 'content' => '<p>x</p>', 'status' => 'draft']);

        $this->getJson(self::API.'/faq')->assertStatus(404)
            ->assertExactJson(['code' => 'page_not_found', 'message' => 'FAQ page not found.', 'data' => ['status' => 404]]);
        $this->getJson(self::API.'/about')->assertStatus(404)->assertJsonPath('message', 'About page not found.');
        $this->getJson(self::API.'/phone')->assertExactJson(['success' => true, 'data' => ['phone' => '+96176618326']]);
    }
}
