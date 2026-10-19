<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Device;
use App\Models\Lab;
use App\Models\Setting;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $seen = Setting::get('scheduler_seen_at');

        return view('admin.dashboard', [
            'stats' => [
                'Patients' => User::where(fn ($q) => $q->where('function_type', 'P')->orWhereNull('function_type'))->count(),
                'Doctors' => User::where('function_type', 'D')->count(),
                'Guarantors' => User::where('function_type', 'G')->count(),
                'Published labs' => Lab::published()->count(),
                'Labs with a results API' => Lab::published()->where('api_enabled', true)->count(),
                'Registered devices' => Device::count(),
                'Result alerts sent' => AppNotification::where('type', 'result_ready')->count(),
            ],
            'lastRun' => Setting::get('poll_last_run'),
            'schedulerAlive' => is_string($seen) && strtotime($seen) >= now()->subMinutes(5)->getTimestamp(),
        ]);
    }
}
