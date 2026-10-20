<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiSummary;
use App\Models\AppNotification;
use App\Models\Device;
use App\Models\Lab;
use App\Models\PushMessage;
use App\Models\Setting;
use App\Models\User;
use App\Services\Admin\UserDirectory;
use App\Services\Notifications\LabMessenger;
use App\Services\Notifications\ResultPoller;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/** Send Notification, Firebase Settings, Result Alerts and AI Summary. */
class NotificationsController extends Controller
{
    public function push(Request $request, UserDirectory $directory): View
    {
        $me = $request->user();
        $scope = UserDirectory::scopeFor($me);

        // Who each choice reaches, shown before sending: patients, and how
        // many of them have a phone registered for push.
        $reach = function ($patients) {
            $ids = $patients->pluck('id');

            return [$ids->count(), Device::whereIn('user_id', $ids)->distinct()->count('user_id')];
        };
        $labIds = $scope > 0 ? [$scope] : Lab::published()->pluck('id')->all();
        $reachByLab = [];
        foreach ($labIds as $labId) {
            $reachByLab[$labId] = $reach(UserDirectory::inLab(UserDirectory::withoutStaff(
                User::where(fn ($q) => $q->where('function_type', 'P')->orWhereNull('function_type'))
            ), $labId));
        }

        return view('admin.push.send', [
            'reach' => [
                'all' => $scope > 0 ? null : Device::distinct()->count('user_id'),
                'labs' => $reachByLab,
            ],
            'scope' => $scope,
            'scopeLab' => $scope > 0 ? Lab::find($scope) : null,
            'labs' => Lab::published()->orderBy('title')->pluck('title', 'id'),
            'patients' => $directory->patients($me)->reorder('display_name')->get(['id', 'display_name', 'username', 'mobile_number']),
            'history' => PushMessage::with(['sender:id,display_name,username', 'lab:id,title'])
                ->when($scope > 0, fn ($q) => $q->where('lab_id', $scope))
                ->latest('id')->limit(15)->get(),
        ]);
    }

    /** To every install (the "medvault" topic), one lab's patients, or one patient. */
    public function send(Request $request, LabMessenger $messenger): RedirectResponse
    {
        $title = Sanitize::textField($request->input('title', ''));
        $body = trim(Sanitize::stripAllTags((string) $request->input('body', '')));
        if ($title === '' || $body === '') {
            return back()->withInput()->with(['status' => 'Title and message are required.', 'status_type' => 'error']);
        }

        $result = $messenger->send(
            $request->user(),
            (string) $request->input('audience', 'all'),
            (int) $request->input('lab_id', 0),
            (int) $request->input('user_id', 0),
            $title,
            $body,
        );

        return $result['success']
            ? redirect()->route('admin.push.index')->with('status', $result['message'])
            : back()->withInput()->with(['status' => $result['message'], 'status_type' => 'error']);
    }

    public function firebase(): View
    {
        try {
            $credentials = json_decode((string) Setting::secret('firebase_credentials'), true);
        } catch (Throwable) {
            $credentials = null;
        }

        // Which account is configured, never the private key itself.
        return view('admin.push.firebase', [
            'projectId' => is_array($credentials) ? ($credentials['project_id'] ?? null) : null,
            'clientEmail' => is_array($credentials) ? ($credentials['client_email'] ?? null) : null,
        ]);
    }

    public function saveFirebase(Request $request): RedirectResponse
    {
        $json = trim((string) $request->input('credentials', ''));
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || empty($decoded['project_id']) || empty($decoded['client_email']) || empty($decoded['private_key'])
            || openssl_pkey_get_private((string) $decoded['private_key']) === false) {
            return back()->with(['status' => 'That is not a Firebase service-account key. Paste the whole JSON file (Project Settings → Service Accounts → Generate new private key).', 'status_type' => 'error']);
        }

        Setting::putSecret('firebase_credentials', $json);

        return redirect()->route('admin.push.firebase')->with('status', "Firebase credentials saved for project {$decoded['project_id']}.");
    }

    public function alerts(): View
    {
        $seen = Setting::get('scheduler_seen_at');

        return view('admin.push.alerts', [
            'enabled' => (bool) Setting::get('poll_enabled', true),
            'minutes' => (int) Setting::get('poll_minutes', 10),
            'batch' => (int) Setting::get('poll_batch_size', ResultPoller::DEFAULT_BATCH),
            'cursor' => (int) Setting::get('poll_cursor', 0),
            'lastRun' => Setting::get('poll_last_run'),
            'schedulerAlive' => is_string($seen) && strtotime($seen) >= now()->subMinutes(5)->getTimestamp(),
            'sentTotal' => AppNotification::where('type', 'result_ready')->count(),
            'recent' => AppNotification::with('user:id,display_name,username')->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function saveAlerts(Request $request): RedirectResponse
    {
        Setting::put('poll_enabled', $request->boolean('poll_enabled'));
        Setting::put('poll_minutes', max(5, (int) $request->input('poll_minutes', 10)));
        Setting::put('poll_batch_size', max(1, (int) $request->input('poll_batch_size', ResultPoller::DEFAULT_BATCH)));

        return redirect()->route('admin.alerts.show')->with('status', 'Settings saved.');
    }

    public function runPoll(ResultPoller $poller): RedirectResponse
    {
        $run = $poller->run(true);

        // A finished run carries a "skipped" count too; only `true` means it did not run.
        if (($run['skipped'] ?? null) === true) {
            return redirect()->route('admin.alerts.show')->with(['status' => $run['message'], 'status_type' => 'warning']);
        }

        return redirect()->route('admin.alerts.show')->with('status', sprintf(
            'Polled %d of %d users (from offset %d): %d notification(s) sent, %d seeded, %d expired token(s), %d error(s).',
            $run['polled'], $run['total_users'], $run['offset'], $run['notified'], $run['seeded'], $run['unauthorized'], $run['errors'],
        ));
    }

    public function ai(): View
    {
        return view('admin.push.ai', [
            'enabled' => Setting::get('ai_summary_enabled', true) !== false,
            'cached' => AiSummary::where('expires_at', '>', now())->count(),
        ]);
    }

    public function saveAi(Request $request): RedirectResponse
    {
        Setting::put('ai_summary_enabled', $request->boolean('enabled'));

        if ($request->boolean('flush')) {
            AiSummary::query()->delete();

            return redirect()->route('admin.ai.show')->with('status', 'Settings saved and cached summaries cleared.');
        }

        return redirect()->route('admin.ai.show')->with('status', 'Settings saved.');
    }
}
