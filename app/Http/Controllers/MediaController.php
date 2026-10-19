<?php

namespace App\Http\Controllers;

use App\Http\WpError;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * POST /wp-json/wp/v2/media, replacing the WordPress media library for the
 * one thing the app uses it for: uploading a profile, ID or insurance photo
 * and getting back its source_url (201).
 *
 * It is anonymous because the app uploads during sign-up, before there is
 * an account or a token, and installed builds cannot change that. What
 * WordPress did not do, this does: images only, judged by their content
 * rather than their name; a size cap; a per-IP rate limit (routes/wp.php);
 * and an unguessable file name, because these are photos of national IDs
 * and the URL is the only thing protecting them.
 */
class MediaController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw new WpError('rest_upload_no_data', 'No data supplied.', 400);
        }
        if (! $file->isValid()) {
            throw new WpError('rest_upload_unknown_error', 'Sorry, the file could not be uploaded.', 500);
        }

        $maxBytes = (int) config('medvault.uploads.max_kb') * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new WpError('rest_upload_file_too_big', 'This file is too big. Files must be less than '.intdiv($maxBytes, 1048576).' MB in size.', 400);
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $extension = config('medvault.uploads.mimes')[$mime] ?? null;
        if ($extension === null) {
            throw new WpError('rest_upload_sideload_error', 'Sorry, you are not allowed to upload this file type.', 500);
        }

        $disk = (string) config('medvault.uploads.disk');
        $path = 'uploads/'.now()->format('Y/m').'/'.Str::random(40).'.'.$extension;
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        $media = Media::create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
            'mime_type' => $mime,
            'size' => (int) $file->getSize(),
            'ip' => $request->ip(),
        ]);

        $url = $media->url();

        // The fields of WordPress's attachment response that mean anything
        // here. The app reads source_url and nothing else.
        return response()->json([
            'id' => (int) $media->id,
            'date' => $media->created_at->format('Y-m-d\TH:i:s'),
            'type' => 'attachment',
            'link' => $url,
            'media_type' => 'image',
            'mime_type' => $mime,
            'source_url' => $url,
            'media_details' => ['filesize' => (int) $media->size],
        ], 201);
    }
}
