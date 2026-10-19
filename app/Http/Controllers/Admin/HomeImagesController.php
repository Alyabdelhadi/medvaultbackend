<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Slider;
use App\Services\Admin\AdminUploads;
use App\Support\WordPress\Sanitize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Slider Images and Banner Images. The app numbers them 1..n by position,
 * so order is what matters; sliders carry an optional title, banners a
 * required link.
 */
class HomeImagesController extends Controller
{
    private const KINDS = [
        'sliders' => ['model' => Slider::class, 'title' => 'Slider Images', 'singular' => 'Slide'],
        'banners' => ['model' => Banner::class, 'title' => 'Banner Images', 'singular' => 'Banner'],
    ];

    public function index(string $kind, Request $request): View
    {
        $meta = $this->meta($kind);
        $items = $meta['model']::orderBy('position')->orderBy('id')->get();
        $editing = $request->query('edit') ? $meta['model']::find($request->query('edit')) : null;

        return view('admin.content.images', compact('kind', 'meta', 'items', 'editing'));
    }

    public function store(string $kind, Request $request, AdminUploads $uploads): RedirectResponse
    {
        $meta = $this->meta($kind);
        $item = new $meta['model'];
        $this->fill($kind, $item, $request, $uploads);
        $item->position = (int) $meta['model']::max('position') + 1;
        $item->save();

        return redirect()->route('admin.images.index', $kind)->with('status', "{$meta['singular']} added successfully.");
    }

    public function update(string $kind, int $id, Request $request, AdminUploads $uploads): RedirectResponse
    {
        $meta = $this->meta($kind);
        $item = $meta['model']::findOrFail($id);
        $this->fill($kind, $item, $request, $uploads);
        $item->save();

        return redirect()->route('admin.images.index', $kind)->with('status', "{$meta['singular']} updated successfully.");
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        $meta = $this->meta($kind);
        $meta['model']::findOrFail($id)->delete();

        return redirect()->route('admin.images.index', $kind)->with('status', "{$meta['singular']} deleted.");
    }

    /** Swaps an item with its neighbour, then renumbers 0..n. */
    public function move(string $kind, int $id, string $direction): RedirectResponse
    {
        $meta = $this->meta($kind);
        $ids = $meta['model']::orderBy('position')->orderBy('id')->pluck('id')->all();
        $index = array_search($id, $ids, true);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if ($index !== false && isset($ids[$target])) {
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            DB::transaction(function () use ($ids, $meta) {
                foreach ($ids as $position => $itemId) {
                    $meta['model']::whereKey($itemId)->update(['position' => $position]);
                }
            });
        }

        return redirect()->route('admin.images.index', $kind);
    }

    private function fill(string $kind, Model $item, Request $request, AdminUploads $uploads): void
    {
        $image = $uploads->store($request->file('image_file'), $request->ip())
            ?? Sanitize::urlRaw((string) $request->input('image_url', ''));
        $link = Sanitize::urlRaw((string) $request->input('link_url', ''));

        $errors = [];
        if ($image === '') {
            $errors['image_url'] = 'An image URL or an uploaded image is required.';
        }
        if ($kind === 'banners' && $link === '') {
            $errors['link_url'] = 'Banners need a link URL.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $item->image_url = $image;
        $item->link_url = $link ?: null;
        if ($kind === 'sliders') {
            $item->title = Sanitize::textField($request->input('title', '')) ?: null;
        }
    }

    private function meta(string $kind): array
    {
        abort_unless(isset(self::KINDS[$kind]), 404);

        return self::KINDS[$kind];
    }
}
