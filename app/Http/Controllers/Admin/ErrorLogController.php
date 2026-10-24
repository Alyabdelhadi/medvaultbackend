<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Error Log: unexpected exceptions, one row per place they are thrown from
 * (see ErrorRecorder). Resolving one hides it until it happens again.
 */
class ErrorLogController extends Controller
{
    public const PER_PAGE = 30;

    public function index(Request $request): View
    {
        $resolved = $request->query('show') === 'resolved';

        return view('admin.errors.index', [
            'entries' => ErrorLog::query()
                ->when($resolved, fn ($q) => $q->whereNotNull('resolved_at'), fn ($q) => $q->whereNull('resolved_at'))
                ->orderByDesc('last_seen_at')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'resolved' => $resolved,
            'openCount' => ErrorLog::open()->count(),
            'resolvedCount' => ErrorLog::whereNotNull('resolved_at')->count(),
        ]);
    }

    public function show(ErrorLog $error): View
    {
        return view('admin.errors.show', ['error' => $error]);
    }

    public function resolve(ErrorLog $error): RedirectResponse
    {
        $error->forceFill(['resolved_at' => now()])->save();

        return redirect()->route('admin.errors.index')->with('status', 'Marked resolved. It comes back here if it happens again.');
    }

    public function destroy(ErrorLog $error): RedirectResponse
    {
        $error->delete();

        return redirect()->route('admin.errors.index')->with('status', 'Deleted.');
    }

    public function clear(): RedirectResponse
    {
        $count = ErrorLog::whereNotNull('resolved_at')->delete();

        return redirect()->route('admin.errors.index', ['show' => 'resolved'])->with('status', "{$count} resolved error(s) deleted.");
    }
}
