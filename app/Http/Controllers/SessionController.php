<?php

namespace App\Http\Controllers;

use App\Http\WpError;
use App\Models\AppSession;
use App\Services\Notifications\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /sessions, POST /sessions/revoke, /sessions/revoke-others, /logout:
 * the devices signed in to this account, and removing them. A removed
 * device is signed out and stops receiving the account's result alerts.
 */
class SessionController extends Controller
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    public function index(Request $request): JsonResponse
    {
        $current = $this->current($request);

        $data = AppSession::where('user_id', $request->user()->id)
            ->orderByDesc('last_used_at')->orderByDesc('id')->get()
            ->map(fn (AppSession $s) => [
                'id' => (int) $s->id,
                'device_name' => (string) ($s->device_name ?? ''),
                'platform' => (string) ($s->platform ?? ''),
                'app_version' => (string) ($s->app_version ?? ''),
                'last_used_at' => $s->last_used_at?->format('Y-m-d H:i:s'),
                'created_at' => $s->created_at?->format('Y-m-d H:i:s'),
                'current' => $s->id === $current->id,
            ])
            // This device first, then the rest by when they were last used.
            ->sortByDesc('current')->values()->all();

        return response()->json(['success' => true, 'count' => count($data), 'data' => $data]);
    }

    /** Signs one of this account's other devices out. */
    public function revoke(Request $request): JsonResponse
    {
        $id = abs((int) $request->input('id', 0));
        if ($id === 0) {
            throw new WpError('missing_id', 'A session id is required.');
        }
        if ($id === $this->current($request)->id) {
            throw new WpError('current_session', 'Use Log out to sign this device out.');
        }

        $deleted = $this->remove($request, AppSession::where('user_id', $request->user()->id)->where('id', $id));

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    public function revokeOthers(Request $request): JsonResponse
    {
        $deleted = $this->remove($request, AppSession::where('user_id', $request->user()->id)
            ->where('id', '!=', $this->current($request)->id));

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /** Ends this device's session, so its token stops working at once. */
    public function logout(Request $request): JsonResponse
    {
        $this->remove($request, AppSession::whereKey($this->current($request)->id));

        return response()->json(['success' => true]);
    }

    /** Deletes the sessions and the push registrations they made. */
    private function remove(Request $request, $query): int
    {
        $ids = (clone $query)->pluck('id')->all();
        $this->devices->forgetSessions($request->user(), $ids);

        return $ids === [] ? 0 : AppSession::whereIn('id', $ids)->delete();
    }

    private function current(Request $request): AppSession
    {
        return $request->attributes->get('app_session');
    }
}
