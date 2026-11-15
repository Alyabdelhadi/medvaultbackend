<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HealthDay;
use App\Models\Lab;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Health Days: the yearly calendar of awareness notifications that
 * HealthDaySender sends to members who turned on Health tips in the app.
 */
class HealthDaysController extends Controller
{
    public function index(Request $request): View
    {
        $days = HealthDay::all()->sortBy(fn (HealthDay $d) => $d->nextDate()->timestamp)->values();
        $editing = $request->query('edit') ? HealthDay::find($request->query('edit')) : null;

        return view('admin.health-days.index', [
            'days' => $days,
            'editing' => $editing,
            'adding' => $request->boolean('new'),
            'labs' => Lab::where('status', '!=', 'trash')->orderBy('title')->pluck('title', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $day = new HealthDay;
        $day->fill($this->validated($request))->save();

        return redirect()->route('admin.healthdays.index')->with('status', "{$day->name} added. It goes out only once it is active.");
    }

    public function update(Request $request, HealthDay $day): RedirectResponse
    {
        $day->fill($this->validated($request))->save();

        return redirect()->route('admin.healthdays.index')->with('status', "{$day->name} saved.");
    }

    public function toggle(HealthDay $day): RedirectResponse
    {
        $day->is_active = ! $day->is_active;
        $day->save();

        return redirect()->route('admin.healthdays.index')->with('status', $day->is_active
            ? "{$day->name} is active: it goes out on ".$day->nextDate()->format('j F').' at '.$day->send_time.' (Beirut).'
            : "{$day->name} is off.");
    }

    public function destroy(HealthDay $day): RedirectResponse
    {
        $day->delete();

        return redirect()->route('admin.healthdays.index')->with('status', "{$day->name} deleted.");
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            'send_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'title_en' => ['required', 'string', 'max:120'],
            'body_en' => ['required', 'string', 'max:300'],
            'title_ar' => ['required', 'string', 'max:120'],
            'body_ar' => ['required', 'string', 'max:300'],
            'link_url' => ['nullable', 'url:https', 'max:255'],
            'sponsor_lab_id' => ['nullable', 'integer', Rule::exists('labs', 'id')],
        ], [
            'send_time.regex' => 'Use a 24-hour time, for example 10:00.',
        ]);

        if ($v['send_time'] >= \App\Services\HealthDays\HealthDaySender::LATEST) {
            throw \Illuminate\Validation\ValidationException::withMessages(['send_time' => 'Health tips are not sent after 20:00. Choose an earlier time.']);
        }

        // 2024 is a leap year, so 29 February is allowed; 31 April is not.
        if (! checkdate((int) $v['month'], (int) $v['day'], 2024)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['day' => 'That date does not exist.']);
        }

        foreach (['name', 'title_en', 'body_en', 'title_ar', 'body_ar'] as $k) {
            $v[$k] = trim(Sanitize::textField($v[$k]));
        }
        $v['sponsor_lab_id'] = ($v['sponsor_lab_id'] ?? null) ?: null;
        $v['link_url'] = ($v['link_url'] ?? null) ?: null;

        return $v;
    }
}
