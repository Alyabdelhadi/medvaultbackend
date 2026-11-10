<?php

namespace App\Http\Controllers;

use App\Http\WpError;
use App\Models\Banner;
use App\Models\Page;
use App\Models\ResultCategory;
use App\Models\Setting;
use App\Models\Slider;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\JsonResponse;

/** GET /sliders, /banners, /result-categories, /about, /faq, /phone. */
class ContentController extends Controller
{
    /** medvault_default_result_categories(), used while none are saved. */
    public const DEFAULT_CATEGORIES = [
        ['label' => 'Blood Test', 'cat_names' => ['Hematology', 'BioChemistry', 'Endocrinology', 'Serology', 'Immunology', 'Microbiology', 'Coagulation', 'Tumor Markers']],
        ['label' => 'Urine', 'cat_names' => ['Urinalysis', 'Urine Culture']],
        ['label' => 'X-Ray', 'cat_names' => ['Radiology', 'X-Ray', 'XRay']],
        ['label' => 'IV Drip', 'cat_names' => ['IVDrip', 'IV Drip', 'Infusion']],
    ];

    public function sliders(): JsonResponse
    {
        // Numbered 1..n by position, as the option array's index + 1 was.
        $data = Slider::orderBy('position')->orderBy('id')->get()->values()->map(fn (Slider $s, int $i) => [
            'id' => $i + 1,
            'image_url' => (string) $s->image_url,
            'title' => (string) ($s->title ?? ''),
            'link_url' => (string) ($s->link_url ?? ''),
        ])->all();

        return response()->json(['success' => true, 'count' => count($data), 'data' => $data]);
    }

    public function banners(): JsonResponse
    {
        $data = Banner::orderBy('position')->orderBy('id')->get()->values()->map(fn (Banner $b, int $i) => [
            'id' => $i + 1,
            'image_url' => (string) $b->image_url,
            'link_url' => (string) ($b->link_url ?? ''),
        ])->all();

        return response()->json(['success' => true, 'count' => count($data), 'data' => $data]);
    }

    public function resultCategories(): JsonResponse
    {
        $saved = ResultCategory::orderBy('position')->orderBy('id')->get()
            ->map(fn (ResultCategory $c) => ['label' => (string) $c->label, 'cat_names' => array_values((array) $c->cat_names)])
            ->all();

        $data = array_values(array_filter(
            $saved === [] ? self::DEFAULT_CATEGORIES : $saved,
            fn (array $c) => $c['label'] !== '' && $c['cat_names'] !== [],
        ));

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function about(): JsonResponse
    {
        return $this->page('about', 'About page not found.');
    }

    public function faq(): JsonResponse
    {
        return $this->page('faq', 'FAQ page not found.');
    }

    public function phone(): JsonResponse
    {
        // Set in Admin -> About & FAQ; .env is only the default.
        $phone = Setting::get('support_phone') ?: config('medvault.support_phone');

        return response()->json(['success' => true, 'data' => ['phone' => $phone]]);
    }

    private function page(string $key, string $notFound): JsonResponse
    {
        $page = Page::where('key', $key)->where('status', 'publish')->first();
        if ($page === null) {
            throw new WpError('page_not_found', $notFound, 404);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => (int) $page->id,
            'title' => (string) $page->title,
            'content' => (string) $page->content,
            'excerpt' => $page->excerpt ?? self::excerpt((string) $page->content),
        ]]);
    }

    /** wp_trim_excerpt() for a page with no excerpt of its own: 55 words. */
    public static function excerpt(string $html): string
    {
        $text = Sanitize::stripAllTags(str_replace(']]>', ']]&gt;', $html));
        $words = preg_split('/[\n\r\t ]+/', $text, 56, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 55) {
            array_pop($words);

            return implode(' ', $words).' [&hellip;]';
        }

        return implode(' ', $words);
    }
}
