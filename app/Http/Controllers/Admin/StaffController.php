<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminGroup;
use App\Models\Lab;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Services\Accounts\Passwords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\View\View;

/**
 * Admin Accounts: who can sign in to this admin. An account is either an
 * Administrator (everything) or a member of one group; a lab group's
 * account also names its lab.
 *
 * Escalation is closed off here: only an administrator can create, change
 * or delete an administrator, and anyone else can only put an account in a
 * group whose every permission they hold themselves.
 */
class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.staff.index', [
            'accounts' => self::staff(User::query())->with('adminGroup')->orderBy('display_name')->get(),
            'labNames' => Lab::pluck('title', 'id'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.staff.form', $this->formData($request->user(), new User(['role' => User::ROLE_SUBSCRIBER])));
    }

    public function store(Request $request, Passwords $passwords): RedirectResponse
    {
        $data = $this->validated($request, null);

        $user = new User;
        $user->forceFill([
            'username' => $data['username'],
            'email' => ($data['email'] ?? null) ?: null,
            'display_name' => $data['display_name'],
            'password' => '',
            ...$this->access($data),
        ])->save();
        $passwords->set($user, $data['password']);

        return redirect()->route('admin.staff.index')->with('status', "{$user->display_name} can now sign in to the admin as {$user->username}.");
    }

    public function edit(Request $request, User $user): View
    {
        $this->guard($request->user(), $user);

        return view('admin.staff.form', $this->formData($request->user(), $user));
    }

    public function update(Request $request, User $user, Passwords $passwords, ApiTokens $tokens): RedirectResponse
    {
        $this->guard($request->user(), $user);
        $data = $this->validated($request, $user);

        $user->forceFill([
            'username' => $data['username'],
            'email' => ($data['email'] ?? null) ?: null,
            'display_name' => $data['display_name'],
        ]);
        // Nobody changes their own access: one click could lock the last
        // administrator out.
        if ($user->id !== $request->user()->id) {
            $user->forceFill($this->access($data));
        }
        $user->save();

        if (filled($data['password'] ?? null)) {
            $passwords->set($user, $data['password']);
            $tokens->revoke($user);
        }

        return redirect()->route('admin.staff.index')->with('status', "{$user->display_name} saved.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->guard($request->user(), $user);
        abort_if($user->id === $request->user()->id, 403, 'You cannot delete your own account.');

        $user->delete();

        return redirect()->route('admin.staff.index')->with('status', "{$user->display_name} can no longer sign in.");
    }

    /** Administrators, imported lab managers and every account in a group. */
    public static function staff(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereIn('role', [User::ROLE_ADMIN, User::ROLE_LAB_MANAGER])->orWhereNotNull('admin_group_id'));
    }

    /** Groups $me may put an account in: those whose every tick $me holds too. */
    public static function grantableGroups(User $me)
    {
        return AdminGroup::orderBy('name')->get()->filter(function (AdminGroup $group) use ($me) {
            foreach ((array) $group->permissions as $module => $actions) {
                foreach ((array) $actions as $action) {
                    if (! $me->canAdmin($module, $action)) {
                        return false;
                    }
                }
            }

            return true;
        })->values();
    }

    private function guard(User $me, User $user): void
    {
        abort_unless(self::staff(User::whereKey($user->id))->exists(), 404);
        abort_if($user->isAdministrator() && ! $me->isAdministrator(), 403, 'Only an administrator can change an administrator.');
    }

    private function formData(User $me, User $user): array
    {
        return [
            'account' => $user,
            'groups' => self::grantableGroups($me),
            'labs' => Lab::where('status', '!=', 'trash')->orderBy('title')->pluck('title', 'id'),
            'canMakeAdmins' => $me->isAdministrator(),
            'isSelf' => $user->exists && $user->id === $me->id,
        ];
    }

    private function validated(Request $request, ?User $user): array
    {
        $me = $request->user();
        $groupIds = self::grantableGroups($me)->pluck('id')->all();
        $rules = [
            'display_name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._@-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
        ];
        // Their own access is not changed (see update()), so not validated either.
        if ($user === null || $user->id !== $me->id) {
            $rules += [
                'access' => ['required', Rule::in($me->isAdministrator() ? ['admin', 'group'] : ['group'])],
                'admin_group_id' => ['nullable', 'required_if:access,group', Rule::in($groupIds)],
                'managed_lab_id' => ['nullable', 'integer', Rule::exists('labs', 'id')],
            ];
        }

        $validator = validator($request->all(), $rules, [
            'username.regex' => 'Use letters, numbers, dots, dashes, @ or underscores.',
            'admin_group_id.required_if' => 'Choose a group.',
            'admin_group_id.in' => 'Choose a group you are allowed to give.',
        ]);

        $validator->after(function (Validator $v) use ($request) {
            if ($request->input('access') !== 'group') {
                return;
            }
            $group = AdminGroup::find($request->input('admin_group_id'));
            if ($group?->for_labs && (int) $request->input('managed_lab_id') <= 0) {
                $v->errors()->add('managed_lab_id', "Accounts in {$group->name} belong to a lab. Choose which one.");
            }
        });

        return $validator->validate();
    }

    private function access(array $data): array
    {
        if ($data['access'] === 'admin') {
            return ['role' => User::ROLE_ADMIN, 'admin_group_id' => null, 'managed_lab_id' => null];
        }

        $group = AdminGroup::findOrFail($data['admin_group_id']);

        return [
            // Not an administrator: the group decides everything.
            'role' => User::ROLE_SUBSCRIBER,
            'admin_group_id' => $group->id,
            'managed_lab_id' => $group->for_labs ? (int) $data['managed_lab_id'] : null,
        ];
    }
}
