<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsWordPressParams;
use App\Http\WpError;
use App\Models\AppNotification;
use App\Services\Notifications\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /device/register, /device/unregister; GET /notifications; POST /notifications/read. */
class NotificationController extends Controller
{
    use ReadsWordPressParams;

    public function register(Request $request, DeviceRegistry $devices): JsonResponse
    {
        $this->requireParams($request, ['device_token']);
        $token = $this->text($request, 'device_token');
        $platform = strtolower($this->text($request, 'platform'));

        if ($token === '') {
            throw new WpError('missing_token', 'device_token is required.');
        }

        $devices->register($request->user(), $token, $platform ?: 'unknown');

        return response()->json([
            'success' => true,
            'message' => 'Device registered for notifications.',
            'devices' => $devices->count($request->user()),
        ]);
    }

    public function unregister(Request $request, DeviceRegistry $devices): JsonResponse
    {
        $this->requireParams($request, ['device_token']);
        $token = $this->text($request, 'device_token');

        if ($token === '') {
            throw new WpError('missing_token', 'device_token is required.');
        }

        $devices->remove($request->user(), $token);

        return response()->json(['success' => true, 'message' => 'Device unregistered.']);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(100, max(1, abs((int) $request->input('per_page', 30))));
        $page = max(1, abs((int) $request->input('page', 1)));

        $rows = AppNotification::where('user_id', $user->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $unread = AppNotification::where('user_id', $user->id)->where('is_read', false)->count();

        $data = $rows->map(function (AppNotification $n) {
            $created = (string) $n->getRawOriginal('created_at');
            $timestamp = strtotime($created);

            return [
                'id' => (int) $n->id,
                'title' => (string) $n->title,
                'body' => (string) $n->body,
                'type' => (string) $n->type,
                'lab_id' => (int) $n->lab_id,
                'request_nb' => (string) $n->request_nb,
                'is_read' => (bool) $n->is_read,
                'created_at' => substr($created, 0, 19),
                'date' => $timestamp ? date('M j, Y • g:i A', $timestamp) : '',
            ];
        })->all();

        return response()->json(['success' => true, 'count' => count($data), 'unread_count' => $unread, 'data' => $data]);
    }

    public function read(Request $request): JsonResponse
    {
        $id = abs((int) $request->input('id', 0));

        // Only rows that change are counted, as MySQL reported affected rows.
        $updated = AppNotification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->when($id > 0, fn ($q) => $q->where('id', $id))
            ->update(['is_read' => true]);

        return response()->json(['success' => true, 'updated' => $updated]);
    }
}
