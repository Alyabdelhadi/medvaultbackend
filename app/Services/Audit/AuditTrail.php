<?php

namespace App\Services\Audit;

use App\Models\AdminGroup;
use App\Models\AuditLog;
use App\Models\Banner;
use App\Models\Lab;
use App\Models\LabPayment;
use App\Models\LabPlan;
use App\Models\Page;
use App\Models\PushMessage;
use App\Models\ResultCategory;
use App\Models\Setting;
use App\Models\Slider;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The audit log: who did what, from where, and what it changed.
 *
 * Admin requests are covered wholesale by RecordAdminChanges: it calls
 * watch(), every save of a watched model is collected here through the
 * Eloquent events, and the request ends as one row. App and console actions
 * call record() themselves.
 *
 * Bound per request (scoped). Writing a row never throws: a full disk or a
 * missing table must cost the log entry, never the action being logged.
 */
final class AuditTrail
{
    /** Models whose saves are collected, => what the log calls them. */
    public const WATCHED = [
        User::class => 'user',
        Lab::class => 'lab',
        AdminGroup::class => 'group',
        LabPlan::class => 'plan',
        LabPayment::class => 'payment',
        Slider::class => 'slider',
        Banner::class => 'banner',
        Page::class => 'page',
        ResultCategory::class => 'category',
        Setting::class => 'setting',
        PushMessage::class => 'message',
    ];

    /** Never stored, not even as "changed": tokens rotate on every sign-in. */
    private const IGNORED = ['api_token_hash', 'remember_token', 'updated_at', 'created_at'];

    /** Stored as "changed", never as a value. */
    private const SECRET = ['password', 'external_token', 'api_headers'];

    /** Settings the poller rewrites on every run: housekeeping, not decisions. */
    private const NOISY_SETTINGS = ['poll_cursor', 'poll_last_run', 'scheduler_seen_at'];

    private const MAX_CHANGES = 50;

    private const MAX_VALUE = 300;

    private bool $watching = false;

    private bool $recorded = false;

    /** @var list<array{model: string, id: mixed, label: string, event: string, fields: array}> */
    private array $changes = [];

    /** @var list<Model> */
    private array $models = [];

    /**
     * Starts collecting, from a clean slate: the binding is per request, but
     * a long-lived process (a test, a queue worker) can see several.
     */
    public function watch(): void
    {
        $this->watching = true;
        $this->recorded = false;
        $this->changes = [];
        $this->models = [];
    }

    public function stop(): void
    {
        $this->watching = false;
    }

    /** Called from the Eloquent events (AppServiceProvider). */
    public function collect(string $event, Model $model): void
    {
        $kind = self::WATCHED[$model::class] ?? null;
        if (! $this->watching || $kind === null || count($this->changes) >= self::MAX_CHANGES) {
            return;
        }
        if ($model instanceof Setting && in_array($model->getKey(), self::NOISY_SETTINGS, true)) {
            return;
        }

        $fields = $event === 'deleted' ? [] : $this->fields($model, $event);
        if ($event === 'updated' && $fields === []) {
            return;
        }

        // Several saves of one record (an account, then its password) are one
        // change: what it was before the request and what it is after.
        foreach ($this->changes as $i => $change) {
            if ($change['model'] === $kind && $change['id'] === $model->getKey()) {
                if ($event === 'deleted') {
                    $this->changes[$i]['event'] = 'deleted';
                }
                foreach ($fields as $name => [$before, $after]) {
                    $first = $change['fields'][$name][0] ?? ($change['event'] === 'created' ? null : $before);
                    $this->changes[$i]['fields'][$name] = $after === '(changed)' ? [$before, $after] : [$first, $after];
                }
                $this->changes[$i]['label'] = self::label($model);

                return;
            }
        }

        $this->changes[] = ['model' => $kind, 'id' => $model->getKey(), 'label' => self::label($model), 'event' => $event, 'fields' => $fields];
        $this->models[] = $model;
    }

    /** @return list<array> what has been collected since watch() */
    public function changes(): array
    {
        return $this->changes;
    }

    /** The first collected model of $class: the account a create just made, say. */
    public function firstModel(string $class): ?Model
    {
        foreach ($this->models as $model) {
            if ($model instanceof $class) {
                return $model;
            }
        }

        return null;
    }

    /** Whether something has already written this request's row. */
    public function hasRecorded(): bool
    {
        return $this->recorded;
    }

    /**
     * Writes one row. $actor defaults to whoever the request is signed in
     * as; pass false for "nobody" (a failed sign-in, a console command).
     *
     * @param  list<array>|null  $changes  null takes whatever was collected
     */
    public function record(string $action, string $summary, ?Model $subject = null, ?array $changes = null, User|false|null $actor = null, ?int $labId = null, ?string $actorName = null): void
    {
        $this->recorded = true;

        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $actor = $actor === false ? null : ($actor ?? $request?->user());
            $kind = match (true) {
                $request instanceof Request && $request->is('admin', 'admin/*') => 'admin',
                $request instanceof Request && $request->is('wp-json/*') => 'app',
                default => 'system',
            };
            $changes ??= $this->changes;

            AuditLog::create([
                'actor_id' => $actor?->id,
                'actor_name' => Str::limit($actorName ?? ($actor ? ($actor->display_name ?: $actor->username) : (app()->runningInConsole() ? 'Server (command line)' : 'Unknown')), 117),
                'actor_kind' => $kind,
                'action' => $action,
                'summary' => Str::limit($summary, 250),
                'subject_type' => $subject ? (self::WATCHED[$subject::class] ?? class_basename($subject)) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? Str::limit(self::label($subject), 157) : null,
                'lab_id' => $labId ?? ($actor?->managed_lab_id ?: ($subject ? self::labOf($subject) : null)),
                'changes' => $changes === [] ? null : $changes,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Audit log entry not written: '.$e->getMessage(), ['action' => $action]);
        }
    }

    /** How a row names a model, kept on the row so it outlives the model. */
    public static function label(Model $model): string
    {
        $label = match (true) {
            $model instanceof User => $model->display_name ?: $model->username,
            $model instanceof Lab => $model->title,
            $model instanceof AdminGroup => $model->name,
            $model instanceof LabPayment => '$'.number_format((float) $model->amount, 2).' on '.substr((string) $model->paid_on, 0, 10),
            $model instanceof Page, $model instanceof PushMessage, $model instanceof Slider => $model->title,
            $model instanceof ResultCategory => $model->label,
            $model instanceof Setting => (string) $model->getKey(),
            default => null,
        };

        return (string) ($label ?: (self::WATCHED[$model::class] ?? class_basename($model)).' #'.$model->getKey());
    }

    private static function labOf(Model $model): ?int
    {
        $id = match (true) {
            $model instanceof Lab => $model->id,
            $model instanceof User => $model->owner_lab_id ?: $model->dg_lab_id,
            $model instanceof LabPlan, $model instanceof LabPayment, $model instanceof PushMessage => $model->lab_id,
            default => null,
        };

        return $id ? (int) $id : null;
    }

    /** name => [before, after], secrets and housekeeping columns left out. */
    private function fields(Model $model, string $event): array
    {
        $encrypted = array_keys(array_filter($model->getCasts(), fn ($cast) => str_starts_with((string) $cast, 'encrypted')));
        $fields = [];

        foreach ($event === 'created' ? $model->getAttributes() : $model->getChanges() as $name => $after) {
            if (in_array($name, self::IGNORED, true)) {
                continue;
            }
            $before = $event === 'created' ? null : $model->getRawOriginal($name);
            if ($event === 'created' && ($after === null || $after === '')) {
                continue;
            }
            $secret = in_array($name, self::SECRET, true) || in_array($name, $encrypted, true)
                || ($model instanceof Setting && $name === 'value' && self::looksEncrypted($after));
            $fields[$name] = $secret ? ['(hidden)', '(changed)'] : [self::short($before), self::short($after)];
        }

        return $fields;
    }

    private static function looksEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'eyJpdiI6');
    }

    private static function short(mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE) {
            return mb_substr($value, 0, self::MAX_VALUE).'… ('.mb_strlen($value).' characters)';
        }

        return is_scalar($value) || $value === null ? $value : json_encode($value);
    }
}
