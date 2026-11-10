<?php

namespace App\Services\Notifications;

use App\Models\LabAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Labs\LabApi;
use App\Services\Labs\LabConfig;
use App\Services\Labs\LabFailure;
use App\Services\Labs\LabRegistry;
use App\Services\Labs\LabRows;
use App\Services\Labs\LabTokenService;
use Illuminate\Support\Facades\Cache;

/**
 * The result-ready engine, from section 13 of the plugin.
 *
 * No lab has a webhook, so readiness is polled: a rotating batch of patients
 * per run, one request-list call per user per lab, diffed against the
 * readiness markers seen last time. A transition into ready pushes and
 * writes an in-app row.
 *
 * Load-bearing, so do not "simplify":
 *   * The first poll for a user at a lab only seeds. Otherwise every finished
 *     result in their history alerts the moment the engine goes live, a lab is
 *     added, or a map is lost.
 *   * The stored marker is whatever readiness is judged on for that lab: for
 *     a report_datetime lab the status never changes.
 *   * Labs are polled independently; a user's outcome is the best any lab gave.
 *   * Patients only. Doctors and guarantors read other people's orders.
 *   * Duplicates are stopped three ways: the seen map, a lock against
 *     overlapping runs, and a check against the notification log keyed on
 *     the lab as well as the request number.
 */
final class ResultPoller
{
    public const LOCK = 'medvault_poll_running';

    public const DEFAULT_BATCH = 50;

    public function __construct(
        private readonly LabRegistry $labs,
        private readonly LabApi $api,
        private readonly Notifier $notifier,
    ) {}

    public function run(bool $manual = false): array
    {
        if (! $manual && ! Setting::get('poll_enabled', true)) {
            return ['skipped' => true, 'message' => 'Polling is disabled.'];
        }

        // Cron can overlap on a slow host; a second run would double-notify.
        $lock = Cache::lock(self::LOCK, 600);
        if (! $lock->get()) {
            return ['skipped' => true, 'message' => 'A poll is already running.'];
        }

        try {
            return $this->runBatch();
        } finally {
            $lock->release();
        }
    }

    /** Whether the configured interval has passed since the last run started. */
    public static function isDue(): bool
    {
        $minutes = max(5, (int) Setting::get('poll_minutes', 10));
        $last = Setting::get('poll_last_run');
        $startedAt = is_array($last) ? strtotime((string) ($last['started_at'] ?? '')) : false;

        return $startedAt === false || now()->getTimestamp() - $startedAt >= $minutes * 60 - 30;
    }

    private function runBatch(): array
    {
        $batch = max(1, (int) Setting::get('poll_batch_size', self::DEFAULT_BATCH));
        $offset = max(0, (int) Setting::get('poll_cursor', 0));
        $labIds = array_map(fn (LabConfig $cfg) => $cfg->labId, $this->labs->active());

        // Pollable: a patient (P or unset) holding a token at any active lab.
        $query = User::query()
            ->where(fn ($q) => $q->where('function_type', 'P')->orWhereNull('function_type'))
            ->whereIn('id', LabAccount::select('user_id')->whereIn('lab_id', $labIds)
                ->where(fn ($q) => $q->whereNotNull('external_token')->orWhereNotNull('radiology_token')));

        $total = (clone $query)->count();
        $users = $query->orderBy('id')->offset($offset)->limit($batch)->get();

        $next = $offset + $batch;
        Setting::put('poll_cursor', $users->isEmpty() || $next >= $total ? 0 : $next);

        $stats = [
            'started_at' => now()->format('Y-m-d H:i:s'),
            'total_users' => $total,
            'offset' => $offset,
            'polled' => 0,
            'seeded' => 0,
            'notified' => 0,
            'errors' => 0,
            'unauthorized' => 0,
            'skipped' => 0,
        ];

        foreach ($users as $user) {
            $result = $this->pollUser($user);
            $stats['polled']++;
            match ($result['status']) {
                'seeded' => $stats['seeded']++,
                'ok' => $stats['notified'] += $result['notified'],
                'unauthorized' => $stats['unauthorized']++,
                'error' => $stats['errors']++,
                default => $stats['skipped']++,
            };
        }

        if ($offset === 0) {
            Notifier::prune();
        }

        $stats['finished_at'] = now()->format('Y-m-d H:i:s');
        Setting::put('poll_last_run', $stats);

        return $stats;
    }

    /** Every active lab, independently; the outcome is the best any lab produced. */
    public function pollUser(User $user): array
    {
        $statuses = [];
        $notified = 0;

        foreach ($this->labs->active() as $cfg) {
            if ($cfg->orders) {
                $result = $this->pollUserLab($user, $cfg);
                $statuses[] = $result['status'];
                $notified += $result['notified'];
            }

            if ($cfg->radiology) {
                $result = $this->pollUserRadiology($user, $cfg);
                $statuses[] = $result['status'];
                $notified += $result['notified'];
            }
        }

        foreach (['ok', 'seeded', 'skipped', 'error', 'unauthorized'] as $rank) {
            if (in_array($rank, $statuses, true)) {
                return ['status' => $rank, 'notified' => $notified];
            }
        }

        return ['status' => 'skipped', 'notified' => 0];
    }

    public function pollUserLab(User $user, LabConfig $cfg): array
    {
        // A lab with a constant status has no transition to detect; polling
        // it would announce every order the moment it is created.
        if ($cfg->readySignal === 'none') {
            return ['status' => 'skipped', 'notified' => 0];
        }

        // An account the lab keeps refusing is retried every 20 minutes, not every
        // cycle: a full lab login per cycle would spend the lab's rate limit.
        if ($this->api->hasGivenUp($user, $cfg)) {
            return ['status' => 'unauthorized', 'notified' => 0];
        }

        // Re-issues an expired token itself, so a long-idle account keeps
        // getting alerts without signing in again.
        $response = $this->api->get($user, $cfg, 'requests_path');

        if ($response instanceof LabFailure) {
            $this->recordError($user, $cfg, $response->message);

            return ['status' => $response->isUnknownAccount() ? 'unauthorized' : 'error', 'notified' => 0];
        }

        $code = $response->status();
        if ($code === 401 || $code === 403) {
            $this->recordError($user, $cfg, "Lab rejected a freshly issued token (HTTP {$code}).");

            return ['status' => 'unauthorized', 'notified' => 0];
        }
        if ($code !== 200) {
            $this->recordError($user, $cfg, "Lab API returned HTTP {$code}.");

            return ['status' => 'error', 'notified' => 0];
        }

        $requests = LabTokenService::decode($response)[$cfg->driver['requests_key']] ?? null;
        if (! is_array($requests)) {
            $this->recordError($user, $cfg, 'Unexpected response shape from the lab API.');

            return ['status' => 'error', 'notified' => 0];
        }

        // Re-read: the call above may have written a fresh token or counter.
        $account = LabAccount::firstOrNew(['user_id' => $user->id, 'lab_id' => $cfg->labId]);
        $seen = $account->seen_status;
        $seeding = ! is_array($seen);
        $seen = $seen ?? [];

        $current = [];
        $newlyReady = [];

        foreach ($requests as $labRequest) {
            if (! is_array($labRequest)) {
                continue;
            }

            $row = LabRows::request($labRequest, $cfg);
            if ($row['request_nb'] === '') {
                continue;
            }

            $marker = $cfg->readySignal === 'report_datetime' ? $row['report_datetime'] : $row['status'];
            $current[$row['request_nb']] = $marker;

            if ($seeding || ! $row['is_ready']) {
                continue;
            }

            // Already announced? An unseen order that is already ready finished
            // between two polls and still deserves an alert.
            $previous = $seen[$row['request_nb']] ?? null;
            if ($previous !== null) {
                $wasReady = $cfg->readySignal === 'report_datetime'
                    ? trim((string) $previous) !== ''
                    : LabRows::statusIsReady((string) $previous);
                if ($wasReady) {
                    continue;
                }
            }

            $newlyReady[] = $row;
        }

        // Rebuilt from this response, so orders that age out of the lab's
        // list do not grow the map forever.
        $account->forceFill(['seen_status' => $current, 'polled_at' => now(), 'poll_error' => null])->save();

        if ($seeding) {
            return ['status' => 'seeded', 'notified' => 0];
        }

        $notified = 0;
        foreach ($newlyReady as $row) {
            if ($this->notifyReady($user, $row, $cfg)) {
                $notified++;
            }
        }

        return ['status' => 'ok', 'notified' => $notified];
    }

    /**
     * Diffs one user's imaging studies at one lab.
     *
     * Independent of ready_signal: a study is ready when it has a report, so
     * a lab with no usable order status still alerts for studies. Its own
     * seen map, seeded on its own first poll, so switching radiology on for a
     * lab whose order map is long seeded does not announce every past study.
     */
    public function pollUserRadiology(User $user, LabConfig $cfg): array
    {
        // Counted on the radiology server's own account when it has one.
        if ($this->api->hasGivenUp($user, $cfg->radiologyEndpoint())) {
            return ['status' => 'unauthorized', 'notified' => 0];
        }

        $studies = $this->api->radiology($user, $cfg);
        if ($studies instanceof LabFailure) {
            $this->recordError($user, $cfg, 'Radiology: '.$studies->message);

            return ['status' => $studies->isUnknownAccount() ? 'unauthorized' : 'error', 'notified' => 0];
        }

        $account = LabAccount::firstOrNew(['user_id' => $user->id, 'lab_id' => $cfg->labId]);
        $seen = $account->radiology_seen_status;
        $seeding = ! is_array($seen);
        $seen = $seen ?? [];

        $current = [];
        $newlyReady = [];

        foreach ($studies as $row) {
            $current[$row['request_nb']] = LabRows::radiologyMarker($row);

            if ($seeding || ! $row['is_ready']) {
                continue;
            }
            if (trim((string) ($seen[$row['request_nb']] ?? '')) !== '') {
                continue;
            }

            $newlyReady[] = $row;
        }

        $account->forceFill(['radiology_seen_status' => $current])->save();

        if ($seeding) {
            return ['status' => 'seeded', 'notified' => 0];
        }

        $notified = 0;
        foreach ($newlyReady as $row) {
            if ($this->notifyReady($user, $row, $cfg)) {
                $notified++;
            }
        }

        return ['status' => 'ok', 'notified' => $notified];
    }

    private function notifyReady(User $user, array $row, LabConfig $cfg): bool
    {
        // The second line of defence, scoped to the lab: facilities number
        // their requests independently and most start at 1.
        if (Notifier::alreadySent($user, 'result_ready', $row['request_nb'], $cfg->labId)) {
            return false;
        }

        $category = $row['cat_name'] !== '' ? $row['cat_name'] : 'Lab';
        $body = sprintf('%s results for request %s are now available in MedVault.', $category, $row['request_nb']);

        // Naming the lab is noise on a single-lab install.
        if (count($this->labs->active()) > 1 && $row['lab_name'] !== '') {
            $body = sprintf('%s results for request %s at %s are now available in MedVault.', $category, $row['request_nb'], $row['lab_name']);
        }

        $this->notifier->notify($user, 'Your results are ready', $body, 'result_ready', [
            'type' => 'result_ready',
            'request_nb' => $row['request_nb'],
            'lab_id' => $cfg->labId,
        ]);

        return true;
    }

    private function recordError(User $user, LabConfig $cfg, string $message): void
    {
        LabAccount::firstOrNew(['user_id' => $user->id, 'lab_id' => $cfg->labId])->forceFill(['poll_error' => $message])->save();
    }
}
