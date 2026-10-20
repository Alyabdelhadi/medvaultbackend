<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Device;
use App\Models\Lab;
use App\Models\LabPayment;
use App\Models\LabPlan;
use App\Models\Setting;
use App\Services\Admin\AdminStats;
use App\Services\Admin\UserDirectory;
use App\Services\Notifications\LabMessenger;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The whole app for site-wide accounts; one lab's patients and messages for
 * a lab's own account. Every number on a lab's dashboard goes through
 * UserDirectory, so it counts that lab's people only.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, UserDirectory $directory): View
    {
        $me = $request->user();
        $scope = UserDirectory::scopeFor($me);
        $since = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(11);

        $patients = $directory->query('P', $me);
        $patientIds = (clone $patients)->pluck('id');
        $notifications = AppNotification::where('created_at', '>=', $since)
            ->when($scope > 0, fn ($q) => $q->where('lab_id', $scope))
            ->get(['type', 'created_at']);

        // label => [value, icon, tone]
        $stats = [
            'Patients' => [$patientIds->count(), 'patients', 'accent'],
            'Doctors' => [$directory->query('D', $me)->count(), 'doctors', 'sky'],
            'Guarantors' => [$directory->query('G', $me)->count(), 'guarantors', 'navy'],
            'Patients with the app' => [Device::whereIn('user_id', $patientIds)->distinct()->count('user_id'), 'phone', 'accent'],
            'Result alerts this month' => [AppNotification::where('type', 'result_ready')->where('created_at', '>=', now()->startOfMonth())
                ->when($scope > 0, fn ($q) => $q->where('lab_id', $scope))->count(), 'bell', 'sky'],
        ];
        if ($scope === 0) {
            $stats['Published labs'] = [Lab::published()->count(), 'lab', 'navy'];
        }

        $charts = [
            ['title' => 'New patients, last 12 months', 'tone' => 'accent', 'empty' => 'No new patients in the last 12 months.',
                'data' => AdminStats::lastMonths((clone $patients)->where('created_at', '>=', $since)->get(['created_at']), 'created_at')],
            ['title' => 'Result alerts sent', 'tone' => 'sky', 'empty' => 'No result alerts in the last 12 months.',
                'data' => AdminStats::lastMonths($notifications->where('type', 'result_ready'), 'created_at')],
            ['title' => $scope > 0 ? 'Messages from the lab' : 'Messages from the admin', 'tone' => 'navy', 'empty' => 'No messages sent yet.',
                'emptyLink' => $me->canAdmin('push', 'create') ? ['Send a notification', route('admin.push.index')] : null,
                'data' => AdminStats::lastMonths($notifications->where('type', LabMessenger::TYPE), 'created_at')],
        ];

        $billing = null;
        if ($me->canAdmin('accounting')) {
            $year = (int) now()->year;
            $billing = [
                'due' => LabPlan::needingAttention(),
                'revenue' => AdminStats::monthsOfYear(LabPayment::whereYear('paid_on', $year)->get(['paid_on', 'amount']), 'paid_on', 'amount', $year),
                'year' => $year,
            ];
        }

        $seen = Setting::get('scheduler_seen_at');

        return view('admin.dashboard', [
            'lab' => $scope > 0 ? Lab::find($scope) : null,
            'stats' => $stats,
            'charts' => $charts,
            'billing' => $billing,
            'showPoller' => $me->canAdmin('alerts'),
            'lastRun' => Setting::get('poll_last_run'),
            'schedulerAlive' => is_string($seen) && strtotime($seen) >= now()->subMinutes(5)->getTimestamp(),
        ]);
    }
}
