<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\Device;
use App\Models\User;

/**
 * The in-app log plus push, from medvault_notify_user() and friends.
 */
final class Notifier
{
    public function __construct(
        private readonly Fcm $fcm,
        private readonly DeviceRegistry $devices,
    ) {}

    /** Writes the in-app row, then pushes, in that order. */
    public function notify(User $user, string $title, string $body, string $type = 'general', array $data = []): array
    {
        AppNotification::create([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'lab_id' => (int) ($data['lab_id'] ?? 0),
            'request_nb' => (string) ($data['request_nb'] ?? ''),
            'is_read' => false,
            'created_at' => now(),
        ]);

        return $this->push($user, $title, $body, $data);
    }

    /**
     * Pushes to every device on the account.
     *
     * UNREGISTERED and NOT_FOUND mean the install is gone and the token is
     * removed. INVALID_ARGUMENT is deliberately not: it also fires for a
     * malformed payload, and pruning on it would wipe every device on the
     * account because of a bug on our side.
     */
    public function push(User $user, string $title, string $body, array $data = []): array
    {
        $tokens = $this->devices->tokens($user);
        if ($tokens === []) {
            return ['success' => false, 'sent' => 0, 'message' => 'No registered devices for this user.'];
        }

        $sent = 0;
        $errors = [];
        foreach ($tokens as $token) {
            $result = $this->fcm->sendRaw($this->fcm->deviceMessage($token, $title, $body, $data));
            if ($result['success']) {
                $sent++;

                continue;
            }

            $errors[] = $result['message'];
            if (in_array($result['error_status'], ['UNREGISTERED', 'NOT_FOUND'], true)) {
                Device::where('user_id', $user->id)->where('token', $token)->delete();
            }
        }

        return [
            'success' => $sent > 0,
            'sent' => $sent,
            'message' => $sent > 0 ? "Sent to {$sent} device(s)." : ($errors !== [] ? implode(' | ', $errors) : 'No devices reachable.'),
        ];
    }

    /** Whether an alert of this type for this order at this lab has already gone out. */
    public static function alreadySent(User $user, string $type, string $requestNb, int $labId): bool
    {
        return $requestNb !== '' && AppNotification::where('user_id', $user->id)
            ->where('type', $type)->where('request_nb', $requestNb)->where('lab_id', $labId)->exists();
    }

    /** Keeps the log from growing without bound. */
    public static function prune(int $days = 90): int
    {
        return AppNotification::where('created_at', '<', now()->subDays($days))->delete();
    }
}
