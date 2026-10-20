<?php

namespace App\Services\Notifications;

use App\Models\AppNotification;
use App\Models\Lab;
use App\Models\PushMessage;
use App\Models\User;
use App\Services\Admin\UserDirectory;

/**
 * Notifications written in the admin: to every install (the topic), to one
 * lab's patients, or to one patient.
 *
 * A lab's own account can only reach its own patients. The audience is
 * decided here from the sender, not from what the form posted: the lab and
 * patient fields are plain numbers in the markup.
 */
final class LabMessenger
{
    public const TYPE = 'lab_message';

    public function __construct(
        private readonly UserDirectory $directory,
        private readonly Notifier $notifier,
        private readonly Fcm $fcm,
    ) {}

    /**
     * @param  'all'|'lab'|'user'  $audience
     * @return array{success: bool, message: string}
     */
    public function send(User $sender, string $audience, int $labId, int $userId, string $title, string $body): array
    {
        $scope = UserDirectory::scopeFor($sender);
        if ($scope > 0) {
            // A lab's account: its own lab, never the whole app.
            $audience = $audience === 'user' ? 'user' : 'lab';
            $labId = $scope;
        }

        return match ($audience) {
            'all' => $this->toEveryone($sender, $title, $body),
            'lab' => $this->toLab($sender, $labId, $title, $body),
            'user' => $this->toPatient($sender, $userId, $scope > 0 ? $scope : $labId, $title, $body),
            default => ['success' => false, 'message' => 'Choose who to send to.'],
        };
    }

    private function toEveryone(User $sender, string $title, string $body): array
    {
        $result = $this->fcm->sendToTopic($title, $body);
        if ($result['success']) {
            $this->record($sender, 'all', null, $title, $body, 0);
        }

        return $result;
    }

    private function toLab(User $sender, int $labId, string $title, string $body): array
    {
        $lab = Lab::find($labId);
        if ($lab === null) {
            return ['success' => false, 'message' => 'Choose a lab.'];
        }

        $patients = UserDirectory::inLab(UserDirectory::withoutStaff(
            User::where(fn ($q) => $q->where('function_type', 'P')->orWhereNull('function_type'))
        ), $labId)->get(['id']);
        if ($patients->isEmpty()) {
            return ['success' => false, 'message' => "{$lab->title} has no patients yet."];
        }

        // Every patient gets the in-app row now, in one insert; the pushes,
        // one HTTP call per device, go out after the page has been sent.
        $now = now();
        foreach ($patients->chunk(500) as $chunk) {
            AppNotification::insert($chunk->map(fn (User $u) => [
                'user_id' => $u->id, 'title' => $title, 'body' => $body, 'type' => self::TYPE,
                'lab_id' => $labId, 'request_nb' => '', 'is_read' => false, 'created_at' => $now,
            ])->all());
        }
        $this->record($sender, 'lab', $labId, $title, $body, $patients->count());

        $ids = $patients->pluck('id')->all();
        dispatch(function () use ($ids, $title, $body, $labId) {
            @set_time_limit(600);
            foreach (User::whereIn('id', $ids)->get() as $user) {
                app(Notifier::class)->push($user, $title, $body, ['type' => self::TYPE, 'lab_id' => $labId]);
            }
        })->afterResponse();

        return ['success' => true, 'message' => "Sending to {$patients->count()} patient(s) of {$lab->title}. Each sees it in the app's notifications; those with the app installed also get a push."];
    }

    private function toPatient(User $sender, int $userId, int $labId, string $title, string $body): array
    {
        $patient = User::find($userId);
        if ($patient === null || $patient->functionType() !== 'P' || ! $this->directory->canSee($sender, $patient)) {
            return ['success' => false, 'message' => 'Choose one of your patients.'];
        }

        $result = $this->notifier->notify($patient, $title, $body, self::TYPE, ['type' => self::TYPE, 'lab_id' => $labId]);
        $this->record($sender, 'user', $labId ?: null, $title, $body, 1);

        $name = $patient->display_name ?: $patient->username;

        return [
            'success' => true,
            'message' => $result['success']
                ? "Sent to {$name}."
                : "Saved to {$name}'s notifications in the app. No push went out: {$result['message']}",
        ];
    }

    private function record(User $sender, string $audience, ?int $labId, string $title, string $body, int $recipients): void
    {
        PushMessage::create([
            'sender_id' => $sender->id, 'audience' => $audience, 'lab_id' => $labId,
            'title' => $title, 'body' => $body, 'recipients' => $recipients,
        ]);
    }
}
