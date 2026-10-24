<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Audit Log: who did what, newest first. Read-only; rows are never edited. */
class AuditController extends Controller
{
    public const PER_PAGE = 50;

    /** Filter => label and the action prefixes it covers. */
    public const AREAS = [
        'people' => ['Patients, doctors & guarantors', ['users.']],
        'app' => ['From the app', ['account.']],
        'signin' => ['Admin sign-ins', ['auth.']],
        'access' => ['Admin accounts & groups', ['staff.', 'groups.']],
        'labs' => ['Labs & accounting', ['labs.', 'accounting.']],
        'content' => ['Images, categories & pages', ['images.', 'categories.', 'pages.']],
        'settings' => ['Notifications & settings', ['push.', 'alerts.', 'ai.']],
        'system' => ['Commands & error log', ['console.', 'errors.']],
    ];

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'area' => (string) $request->query('area', ''),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'subject' => (string) $request->query('subject', ''),
        ];

        $query = AuditLog::query()->orderByDesc('id');

        if (isset(self::AREAS[$filters['area']])) {
            $query->where(function ($q) use ($filters) {
                foreach (self::AREAS[$filters['area']][1] as $prefix) {
                    $q->orWhere('action', 'like', $prefix.'%');
                }
            });
        }
        if ($filters['q'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('summary', 'like', $like)->orWhere('actor_name', 'like', $like)->orWhere('subject_label', 'like', $like)->orWhere('ip', 'like', $like));
        }
        // "user:42", from a person's page: everything done to that account.
        if (preg_match('/^([a-z]+):(\d+)$/', $filters['subject'], $m)) {
            $query->where('subject_type', $m[1])->where('subject_id', (int) $m[2]);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if ($filters[$key] !== '' && ($day = $this->day($filters[$key])) !== null) {
                $query->where('created_at', $op, $key === 'from' ? $day->startOfDay() : $day->endOfDay());
            }
        }

        return view('admin.audit.index', [
            'logs' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'filters' => $filters,
            'areas' => array_map(fn ($a) => $a[0], self::AREAS),
        ]);
    }

    private function day(string $value): ?Carbon
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
