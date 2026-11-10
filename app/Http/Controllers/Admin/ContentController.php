<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\ContentController as ApiContent;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\ResultCategory;
use App\Models\Setting;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Result Categories and the About / FAQ pages. */
class ContentController extends Controller
{
    private const PAGES = ['about' => ['id' => 12, 'title' => 'About'], 'faq' => ['id' => 10, 'title' => 'FAQ']];

    public function categories(): View
    {
        $saved = ResultCategory::orderBy('position')->orderBy('id')->get()
            ->map(fn ($c) => ['label' => $c->label, 'cat_names' => (array) $c->cat_names])->all();

        return view('admin.content.categories', [
            'rows' => $saved === [] ? ApiContent::DEFAULT_CATEGORIES : $saved,
            'usingDefaults' => $saved === [],
        ]);
    }

    /** Replaces the whole list, as the plugin's option did. A blank label drops its row. */
    public function saveCategories(Request $request): RedirectResponse
    {
        $labels = (array) $request->input('label', []);
        $names = (array) $request->input('cat_names', []);

        DB::transaction(function () use ($labels, $names) {
            ResultCategory::query()->delete();
            $position = 0;
            foreach ($labels as $i => $label) {
                $label = Sanitize::textField($label);
                if ($label === '') {
                    continue;
                }
                $catNames = array_values(array_filter(array_map('trim', explode(',', Sanitize::textField($names[$i] ?? '')))));
                ResultCategory::create(['position' => $position++, 'label' => $label, 'cat_names' => $catNames]);
            }
        });

        return redirect()->route('admin.categories.edit')->with('status', 'Categories saved.');
    }

    public function page(string $key): View
    {
        $meta = self::PAGES[$key] ?? abort(404);

        return view('admin.content.page', [
            'key' => $key,
            'meta' => $meta,
            'page' => Page::where('key', $key)->first() ?? new Page(['title' => $meta['title'], 'status' => 'publish']),
            'phone' => Setting::get('support_phone') ?: config('medvault.support_phone'),
        ]);
    }

    /** The app's "Contact us" number (GET /phone). */
    public function savePhone(Request $request): RedirectResponse
    {
        $request->validate(['phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9 ()-]{5,24}$/']],
            ['phone.regex' => 'Enter a phone number, for example +96176618326.']);
        // Stored without spaces or dashes, the way the app always received it.
        Setting::put('support_phone', preg_replace('/[^0-9+]/', '', (string) $request->input('phone')));

        return back()->with('status', 'Contact number saved.');
    }

    public function savePage(string $key, Request $request): RedirectResponse
    {
        $meta = self::PAGES[$key] ?? abort(404);
        $request->validate(['title' => 'required|string|max:255', 'content' => 'required|string', 'status' => 'required|in:publish,draft']);

        $page = Page::where('key', $key)->first() ?? (new Page)->forceFill(['id' => $meta['id'], 'key' => $key]);
        $page->forceFill([
            'title' => Sanitize::textField($request->input('title')),
            // The HTML the app renders, as an administrator wrote it.
            'content' => (string) $request->input('content'),
            'excerpt' => trim((string) $request->input('excerpt', '')) ?: null,
            'status' => $request->input('status'),
        ])->save();

        return redirect()->route('admin.pages.edit', $key)->with('status', "{$meta['title']} page saved.");
    }
}
