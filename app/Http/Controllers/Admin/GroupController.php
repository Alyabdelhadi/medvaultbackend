<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminGroup;
use App\Support\AdminModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Groups & Permissions. A group is a named set of ticks, page by action.
 * A lab group's accounts belong to one lab each, and site-wide pages are
 * dropped from it on save, whatever was ticked.
 *
 * Nobody can grant what they do not have: a non-administrator editing a
 * group keeps whatever they lack exactly as it was.
 */
class GroupController extends Controller
{
    public function index(): View
    {
        return view('admin.groups.index', ['groups' => AdminGroup::withCount('users')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.groups.form', ['group' => new AdminGroup(['permissions' => [], 'for_labs' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $group = new AdminGroup;
        $this->fill($request, $group);

        return redirect()->route('admin.groups.index')->with('status', "Group {$group->name} created.");
    }

    public function edit(AdminGroup $group): View
    {
        return view('admin.groups.form', ['group' => $group]);
    }

    public function update(Request $request, AdminGroup $group): RedirectResponse
    {
        $this->fill($request, $group);

        return redirect()->route('admin.groups.index')->with('status', "Group {$group->name} saved.");
    }

    public function destroy(AdminGroup $group): RedirectResponse
    {
        if ($group->users()->exists()) {
            return back()->with(['status' => "Move this group's accounts to another group first.", 'status_type' => 'error']);
        }
        $group->delete();

        return redirect()->route('admin.groups.index')->with('status', "Group {$group->name} deleted.");
    }

    private function fill(Request $request, AdminGroup $group): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('admin_groups', 'name')->ignore($group->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
        ]);
        $forLabs = $request->boolean('for_labs');
        $wanted = AdminModules::sanitize((array) ($data['permissions'] ?? []), $forLabs);

        $me = $request->user();
        $before = (array) ($group->permissions ?? []);
        $permissions = [];
        foreach (AdminModules::all() as $key => $module) {
            foreach ($module['actions'] as $action) {
                $grantable = $me->canAdmin($key, $action);
                $on = $grantable ? in_array($action, $wanted[$key] ?? [], true) : in_array($action, $before[$key] ?? [], true);
                if ($on) {
                    $permissions[$key][] = $action;
                }
            }
        }

        $group->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'for_labs' => $forLabs,
            'permissions' => AdminModules::sanitize($permissions, $forLabs),
        ])->save();
    }
}
