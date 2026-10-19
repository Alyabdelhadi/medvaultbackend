<?php

namespace App\Http\Controllers;

use App\Http\WpError;
use App\Models\Lab;
use App\Models\Setting;
use App\Services\Labs\LabPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /labs, GET /labs/{id}: the public labs directory. */
class DirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // absint() on both, defaulting to 10 and 1. WP_Query reads a page size
        // of 0 as the site's posts_per_page (10 on the live site, confirmed
        // against it) and a page of 0 as the first; the response echoes what
        // was asked for.
        $perPage = abs((int) $request->query('per_page', 10));
        $page = abs((int) $request->query('page', 1));
        $size = $perPage > 0 ? $perPage : max(1, (int) Setting::get('posts_per_page', 10));

        $query = Lab::published();
        $total = (clone $query)->count();

        $labs = $query
            ->orderByRaw('LOWER(title) ASC')
            ->orderBy('id')
            ->forPage(max(1, $page), $size)
            ->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'total_pages' => (int) ceil($total / $size),
            'page' => $page,
            'per_page' => $perPage,
            'data' => $labs->map(fn (Lab $lab) => LabPresenter::present($lab))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $lab = Lab::published()->find($id);
        if ($lab === null) {
            throw new WpError('lab_not_found', 'Lab not found.', 404);
        }

        return response()->json(['success' => true, 'data' => LabPresenter::present($lab)]);
    }
}
