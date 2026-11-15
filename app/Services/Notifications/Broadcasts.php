<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\PushMessage;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Topic pushes (the admin's "everyone" and Health Days) in each user's history.
 *
 * Firebase delivers a topic push to every subscribed phone, so the server
 * never knows who got it and writes no per-user row at send time. Instead,
 * each user's copy is written the first time they open their history: one row
 * per message, keyed `pm:{id}` in request_nb, so it can be read and deleted
 * like any other. A deleted copy still exists (is_deleted), so it never comes
 * back.
 */
final class Broadcasts
{
    /** push_messages.audience => notifications.type */
    public const TYPES = ['all' => 'broadcast', 'health' => 'health_tip'];

    /** The history keeps 90 days (Notifier::prune). */
    private const LOOKBACK_DAYS = 90;

    /** @return int rows added */
    public function deliver(User $user, string $lang): int
    {
        $lang = $lang === 'ar' ? 'ar' : 'en';
        $since = CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS);
        $joined = $user->created_at ? CarbonImmutable::parse($user->created_at) : null;
        if ($joined !== null && $joined->greaterThan($since)) {
            $since = $joined;
        }

        $messages = PushMessage::whereIn('audience', array_keys(self::TYPES))
            ->where(fn ($q) => $q->whereNull('lang')->orWhere('lang', $lang))
            ->where('created_at', '>=', $since)
            ->get(['id', 'audience', 'title', 'body', 'created_at']);
        if ($messages->isEmpty()) {
            return 0;
        }

        $have = AppNotification::where('user_id', $user->id)
            ->whereIn('type', array_values(self::TYPES))
            ->where('request_nb', 'like', 'pm:%')
            ->pluck('request_nb')->flip();

        $rows = $messages->reject(fn (PushMessage $m) => $have->has('pm:'.$m->id))
            ->map(fn (PushMessage $m) => [
                'user_id' => $user->id,
                'title' => (string) $m->title,
                'body' => (string) $m->body,
                'type' => self::TYPES[$m->audience],
                'lab_id' => 0,
                'request_nb' => 'pm:'.$m->id,
                'is_read' => false,
                'created_at' => $m->getRawOriginal('created_at'),
            ])->values()->all();

        if ($rows !== []) {
            AppNotification::insert($rows);
        }

        return count($rows);
    }
}
