<?php

namespace App\Services\HealthDays;

use App\Models\HealthDay;
use App\Models\PushMessage;
use App\Services\Notifications\Fcm;
use Carbon\CarbonImmutable;

/**
 * Sends the awareness days that are due. Run every few minutes by the
 * scheduler; each day goes out once per year per language.
 *
 * Only to health_tips_en / health_tips_ar: the app subscribes to the one for
 * its language unless the member turned Health tips off (on by default).
 * Never to `medvault`, so a member can drop tips and keep everything else.
 * Being on by default is why the text must stay information, not offers:
 * Apple allows promotional pushes only to people who opted in.
 *
 * Nothing goes out before its time or after LATEST, so a server that was
 * down all morning does not send a tip at night; it waits for next year.
 */
final class HealthDaySender
{
    public const TZ = 'Asia/Beirut';

    public const LANGUAGES = ['en', 'ar'];

    /** No tips after this, Beirut time. */
    public const LATEST = '20:00';

    public const TYPE = 'health_tip';

    public function __construct(private readonly Fcm $fcm) {}

    public static function topic(string $lang): string
    {
        return 'health_tips_'.$lang;
    }

    /** @return list<string> what happened, one line per send */
    public function sendDue(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(self::TZ);
        $clock = $now->format('H:i');
        if ($clock >= self::LATEST) {
            return [];
        }

        $report = [];
        $due = HealthDay::where('is_active', true)->where('month', $now->month)->where('day', $now->day)
            ->where('send_time', '<=', $clock)->get();

        foreach ($due as $day) {
            foreach (self::LANGUAGES as $lang) {
                if ($day->{"last_sent_{$lang}"}?->toDateString() === $now->toDateString()) {
                    continue;
                }
                $result = $this->send($day, $lang);
                if ($result['success']) {
                    $day->{"last_sent_{$lang}"} = $now->toDateString();
                    $day->save();
                    PushMessage::create([
                        'sender_id' => null, 'audience' => 'health', 'lab_id' => $day->sponsor_lab_id,
                        'title' => $day->{"title_{$lang}"}, 'body' => $day->{"body_{$lang}"}, 'recipients' => 0,
                    ]);
                }
                $report[] = "{$day->name} ({$lang}): ".($result['success'] ? 'sent' : $result['message']);
            }
        }

        return $report;
    }

    /** @return array{success: bool, message: string, code: int, error_status: string} */
    public function send(HealthDay $day, string $lang): array
    {
        $data = ['type' => self::TYPE, 'health_day_id' => (string) $day->id];
        if ($day->link_url) {
            $data['link_url'] = (string) $day->link_url;
        }

        return $this->fcm->sendRaw([
            'topic' => self::topic($lang),
            'notification' => ['title' => $day->{"title_{$lang}"}, 'body' => $day->{"body_{$lang}"}],
            'data' => $data,
            'android' => ['priority' => 'normal', 'notification' => ['channel_id' => 'medvault_notifications']],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ]);
    }
}
