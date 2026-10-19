<?php

namespace App\Services\Admin;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image uploads from the admin forms (lab images, slides, sliders, banners),
 * replacing "Select from Media Library". Same rules as the app's upload:
 * images only, judged by content.
 */
final class AdminUploads
{
    /** The stored file's public URL, or null when it is not an acceptable image. */
    public function store(?UploadedFile $file, ?string $ip = null): ?string
    {
        if ($file === null || ! $file->isValid() || $file->getSize() > (int) config('medvault.uploads.max_kb') * 1024) {
            return null;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = config('medvault.uploads.mimes')[$mime] ?? null;
        if ($extension === null) {
            return null;
        }

        $disk = (string) config('medvault.uploads.disk');
        $path = 'uploads/'.now()->format('Y/m').'/'.Str::random(40).'.'.$extension;
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return Media::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'ip' => $ip,
        ])->url();
    }
}
