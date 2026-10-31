<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lab;
use App\Services\Admin\AdminUploads;
use App\Services\Labs\LabDrivers;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Labs: the directory entry and the results-API settings, one form, saved
 * with the plugin's rules (save_lab_meta() and medvault_save_lab_api_meta()).
 *
 * Adding a facility must never need an app release: everything about how
 * the server talks to it is here.
 */
class LabController extends Controller
{
    public const PDF_MODES = [
        'static_base' => 'Static folder, fetched by the app (PDF base URL + path)',
        'static_proxy' => 'Static folder, proxied through MedVault',
        'endpoint' => 'Authenticated endpoint (/file)',
        'none' => 'No PDFs',
    ];

    public const MOBILE_FORMATS = [
        'lb_trunk_zero' => 'Lebanese local, 3-prefix keeps its 0 (Verdun)',
        'national' => 'National digits, no country code',
        'e164' => 'Full international digits',
    ];

    public const READY_SIGNALS = [
        'status' => 'status becomes V / F / C',
        'report_datetime' => 'report_datetime stops being null',
        'none' => 'None — never send result alerts',
    ];

    private const URL_FIELDS = ['image_url', 'google_map_url', 'refer_link', 'website', 'facebook', 'instagram', 'twitter', 'linkedin', 'youtube', 'tiktok'];

    private const TEXT_FIELDS = ['location', 'phone', 'appointment_phone', 'mobile_phone', 'latitude', 'longitude'];

    public function index(Request $request): View
    {
        $status = $request->query('status', 'all');
        $labs = Lab::query()
            ->when($status === 'trash', fn ($q) => $q->where('status', 'trash'), fn ($q) => $q->where('status', '!=', 'trash'))
            ->orderByRaw('LOWER(title)')
            ->get();

        return view('admin.labs.index', ['labs' => $labs, 'status' => $status, 'trashed' => Lab::where('status', 'trash')->count()]);
    }

    public function create(): View
    {
        return view('admin.labs.form', ['lab' => new Lab(['status' => 'publish', 'api_enabled' => false]), 'options' => $this->options()]);
    }

    public function edit(Lab $lab): View
    {
        return view('admin.labs.form', ['lab' => $lab, 'options' => $this->options()]);
    }

    public function store(Request $request, AdminUploads $uploads): RedirectResponse
    {
        $lab = new Lab;
        $this->fill($lab, $request, $uploads);
        $lab->slug = $this->uniqueSlug($lab->title);
        $lab->save();

        return redirect()->route('admin.labs.edit', $lab)->with('status', 'Lab created.');
    }

    public function update(Request $request, Lab $lab, AdminUploads $uploads): RedirectResponse
    {
        $this->fill($lab, $request, $uploads);
        $lab->save();

        return redirect()->route('admin.labs.edit', $lab)->with('status', 'Lab updated.');
    }

    /** Trash, not delete: lab accounts, notifications and signed links refer to its id. */
    public function trash(Lab $lab): RedirectResponse
    {
        $lab->forceFill(['status' => 'trash'])->save();

        return redirect()->route('admin.labs.index')->with('status', "“{$lab->title}” moved to the trash. It no longer appears in the app or receives result polls.");
    }

    public function restore(Lab $lab): RedirectResponse
    {
        $lab->forceFill(['status' => 'draft'])->save();

        return redirect()->route('admin.labs.edit', $lab)->with('status', 'Lab restored as a draft.');
    }

    private function fill(Lab $lab, Request $request, AdminUploads $uploads): void
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'status' => 'required|in:publish,draft,private,pending',
            'email' => 'nullable|string|max:255',
            'image_file' => 'nullable|file',
            'slide_files.*' => 'nullable|file',
        ], [], ['title' => 'name']);

        $lab->title = Sanitize::textField($request->input('title'));
        $lab->content = (string) $request->input('content', '');
        $lab->status = $request->input('status');

        foreach (self::URL_FIELDS as $field) {
            $lab->{$field} = Sanitize::urlRaw((string) $request->input($field, '')) ?: null;
        }
        foreach (self::TEXT_FIELDS as $field) {
            $lab->{$field} = Sanitize::textField($request->input($field, '')) ?: null;
        }
        $lab->email = Sanitize::email($request->input('email', '')) ?: null;
        $lab->refer_text = trim(Sanitize::stripAllTags((string) $request->input('refer_text', ''))) ?: null;

        if ($url = $uploads->store($request->file('image_file'), $request->ip())) {
            $lab->image_url = $url;
        }

        // One URL per line, in order; uploads are appended.
        $slides = array_values(array_filter(array_map(
            fn ($line) => Sanitize::urlRaw(trim($line)),
            preg_split('/\R/', (string) $request->input('slides', '')),
        )));
        foreach ((array) $request->file('slide_files', []) as $file) {
            if ($url = $uploads->store($file, $request->ip())) {
                $slides[] = $url;
            }
        }
        $lab->slides = $slides;

        // Checkboxes: absent means off, so they are always written.
        foreach (['api_enabled', 'caps_ranges', 'caps_flags', 'caps_pdf', 'ai_summary', 'radiology_enabled', 'orders_enabled'] as $flag) {
            $lab->{$flag} = $request->boolean($flag);
        }

        $lab->api_base = Sanitize::urlRaw((string) $request->input('api_base', '')) ?: null;
        $lab->api_pdf_base = Sanitize::urlRaw((string) $request->input('api_pdf_base', '')) ?: null;
        $lab->api_headers = trim((string) $request->input('api_headers', '')) ?: null;

        // Selects only take a known value; anything else leaves the old one.
        $selects = [
            'api_driver' => array_keys(LabDrivers::all()),
            'api_pdf_mode' => array_keys(self::PDF_MODES),
            'mobile_format' => array_keys(self::MOBILE_FORMATS),
            'ready_signal' => array_keys(self::READY_SIGNALS),
        ];
        foreach ($selects as $field => $allowed) {
            $value = (string) $request->input($field, '');
            if (in_array($value, $allowed, true)) {
                $lab->{$field} = $value;
            }
        }

        foreach (['api_timeout' => [1, 60], 'api_priority' => [0, 999]] as $field => [$min, $max]) {
            if ($request->filled($field)) {
                $lab->{$field} = max($min, min($max, abs((int) $request->input($field))));
            }
        }
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'lab';
        $slug = $base;
        $n = 2;
        while (Lab::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function options(): array
    {
        return [
            'drivers' => array_map(fn ($d) => $d['label'], LabDrivers::all()),
            'pdfModes' => self::PDF_MODES,
            'mobileFormats' => self::MOBILE_FORMATS,
            'readySignals' => self::READY_SIGNALS,
        ];
    }
}
