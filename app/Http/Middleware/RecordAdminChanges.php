<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditTrail;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every change made in the admin becomes one audit row, without each
 * controller having to remember to write it: a page added later is logged
 * the day it ships.
 *
 * Only requests that went through are logged. A form sent back with errors
 * or a failed request changed nothing (or was rolled back), and a row for
 * it would describe something that did not happen. Reads are not logged,
 * except the CSV exports, which take patient data out of the system.
 */
class RecordAdminChanges
{
    /** Route name (without "admin.") => what the row says. {type}: patient, doctor or guarantor. */
    public const SUMMARIES = [
        'users.store' => 'Added {type}',
        'users.update' => 'Edited {type}',
        'users.password' => 'Set a new password for {type}',
        'users.destroy' => 'Deleted {type}',
        'users.export' => 'Exported {types} to Excel',
        'staff.store' => 'Created admin account',
        'staff.update' => 'Edited admin account',
        'staff.destroy' => 'Deleted admin account',
        'groups.store' => 'Created group',
        'groups.update' => 'Edited group',
        'groups.destroy' => 'Deleted group',
        'labs.store' => 'Created lab',
        'labs.update' => 'Edited lab',
        'labs.trash' => 'Moved lab to trash',
        'labs.restore' => 'Restored lab',
        'images.store' => 'Added {kind} image',
        'images.update' => 'Edited {kind} image',
        'images.destroy' => 'Deleted {kind} image',
        'images.move' => 'Reordered {kind} images',
        'categories.update' => 'Edited result categories',
        'pages.update' => 'Edited page',
        'push.send' => 'Sent a notification',
        'push.firebase.save' => 'Changed Firebase settings',
        'alerts.save' => 'Changed result alert settings',
        'alerts.run' => 'Ran the result poll now',
        'ai.save' => 'Changed AI summary settings',
        'accounting.plan.save' => 'Saved billing plan',
        'accounting.payment.store' => 'Recorded payment',
        'accounting.payment.destroy' => 'Deleted payment',
        'errors.resolve' => 'Marked an error resolved',
        'errors.destroy' => 'Deleted an error',
        'errors.clear' => 'Cleared resolved errors',
    ];

    /** Reads worth a row. */
    private const LOGGED_READS = ['admin.users.export'];

    public function __construct(private readonly AuditTrail $trail) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->trail->watch();

        try {
            $response = $next($request);
        } finally {
            $this->trail->stop();
        }

        $route = (string) $request->route()?->getName();
        $isWrite = ! $request->isMethodSafe();
        if (($isWrite || in_array($route, self::LOGGED_READS, true)) && ! $this->trail->hasRecorded() && $this->succeeded($request, $response)) {
            $this->write($request, $route);
        }

        return $response;
    }

    private function succeeded(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        // Errors flashed by this request mean the form came back for correction.
        return ! $request->hasSession() || ! in_array('errors', (array) $request->session()->get('_flash.new', []), true);
    }

    private function write(Request $request, string $route): void
    {
        $key = str_starts_with($route, 'admin.') ? substr($route, 6) : $route;
        $slug = (string) $request->route('slug');
        $summary = strtr(self::SUMMARIES[$key] ?? ucfirst(str_replace(['.', '_'], ' ', $key ?: $request->path())), [
            '{type}' => ['patients' => 'patient', 'doctors' => 'doctor', 'guarantors' => 'guarantor'][$slug] ?? 'user',
            '{types}' => $slug ?: 'users',
            '{kind}' => rtrim((string) $request->route('kind'), 's'),
        ]);

        // The model the page is about: bound in the route ({user}, {lab},
        // {group}, {payment}), else the first thing this request created.
        $subject = collect($request->route()?->parameters() ?? [])->first(fn ($p) => $p instanceof Model);
        if ($subject === null) {
            $created = collect($this->trail->changes())->firstWhere('event', 'created');
            $subject = $created ? $this->trail->firstModel(array_search($created['model'], AuditTrail::WATCHED, true)) : null;
        }
        if ($subject === null && $key === 'pages.update') {
            $summary .= ': '.$request->route('key');
        }
        if ($subject !== null && ! ($subject instanceof User && $key === 'users.export')) {
            $summary .= ': '.AuditTrail::label($subject);
        }

        $this->trail->record($key ?: 'admin.request', $summary, $subject);
    }
}
