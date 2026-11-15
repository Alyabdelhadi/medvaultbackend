<?php

namespace App\Services\Admin;

use App\Models\LabAccount;
use App\Models\User;
use App\Support\Mobile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Patients / Doctors / Guarantors lists, from medvault_get_users_by_type().
 *
 * Lab scoping lives here, in the query, and not in a template: every caller
 * is then scoped, the CSV export included. A filter that only hides rows on
 * screen is not a boundary.
 */
final class UserDirectory
{
    public const TYPES = ['P' => 'Patient', 'D' => 'Doctor', 'G' => 'Guarantor'];

    public const PLURALS = ['P' => 'Patients', 'D' => 'Doctors', 'G' => 'Guarantors'];

    /** The lab a signed-in admin user is limited to, or 0 for the whole site. */
    public static function scopeFor(User $viewer): int
    {
        // Administrators are never scoped, even with a lab assigned.
        return $viewer->role === User::ROLE_ADMIN ? 0 : (int) $viewer->managed_lab_id;
    }

    /**
     * A user belongs to a lab if it created them, if they are a doctor or
     * guarantor attached to it, or if its API has issued them a token. The
     * last makes the list useful on day one, before anyone is re-entered.
     */
    public static function inLab(Builder $query, int $labId): Builder
    {
        return $query->where(function (Builder $q) use ($labId) {
            $q->where('owner_lab_id', $labId)
                ->orWhere('dg_lab_id', $labId)
                // Every lab a doctor or guarantor signed up with, not only the first.
                ->orWhereIn('id', DB::table('user_labs')->select('user_id')->where('lab_id', $labId))
                ->orWhereIn('id', LabAccount::select('user_id')->where('lab_id', $labId)->whereNotNull('external_token'));
        });
    }

    public static function withoutStaff(Builder $query): Builder
    {
        return $query->whereNotIn('role', [User::ROLE_ADMIN, User::ROLE_LAB_MANAGER])->whereNull('admin_group_id');
    }

    /** Patients (function type P or unset) visible to $viewer. */
    public function patients(User $viewer): Builder
    {
        return $this->query('P', $viewer);
    }

    public function query(string $type, User $viewer): Builder
    {
        $query = User::query();

        // An unset type has always been a patient. A patient who is also a
        // doctor is listed under both.
        match ($type) {
            'P' => $query->where(fn ($q) => $q->where('function_type', 'P')->orWhereNull('function_type')),
            'D' => $query->where(fn ($q) => $q->where('function_type', 'D')->orWhere(
                fn ($q) => $q->where('also_doctor', true)->where(fn ($q) => $q->where('function_type', 'P')->orWhereNull('function_type'))
            )),
            default => $query->where('function_type', $type),
        };

        // Admin accounts are listed under Admin Accounts, not with the app's users.
        self::withoutStaff($query);

        $labId = self::scopeFor($viewer);
        if ($labId > 0) {
            self::inLab($query, $labId);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Whether $viewer may open, change or delete $user from these pages.
     * Admin accounts never: they are managed under Admin Accounts, so a
     * Patients permission cannot reset an administrator's password.
     */
    public function canSee(User $viewer, User $user): bool
    {
        $query = self::withoutStaff(User::whereKey($user->id));
        $labId = self::scopeFor($viewer);

        return ($labId === 0 ? $query : self::inLab($query, $labId))->exists();
    }

    /**
     * Whether $viewer may change $user's details or delete the account.
     *
     * Seeing is not owning. A patient another lab created, and this lab only
     * linked (Add → "already registered" → Add to my lab), is shared: this
     * lab may open them and set a new password at the counter, but the name,
     * number and the account itself stay with the lab that made it. Without
     * that, two labs overwrite each other's corrections, and one lab could
     * delete a patient the other still serves.
     */
    public function canEdit(User $viewer, User $user): bool
    {
        $labId = self::scopeFor($viewer);
        if ($labId === 0) {
            return $this->canSee($viewer, $user);
        }

        $ownedElsewhere = $user->owner_lab_id !== null && (int) $user->owner_lab_id !== $labId
            && (int) $user->dg_lab_id !== $labId;

        return ! $ownedElsewhere && $this->canSee($viewer, $user);
    }

    /** Whether $user was linked to $labId from the admin (and can be unlinked). */
    public static function isLinked(User $user, int $labId): bool
    {
        return $labId > 0 && DB::table('user_labs')->where('user_id', $user->id)->where('lab_id', $labId)->exists();
    }

    /**
     * "Y****a M****d": enough for a lab to recognise the person in front of
     * it, too little to learn who another lab's patient is by typing numbers.
     */
    public static function maskedName(User $user): string
    {
        $name = trim((string) ($user->display_name ?: trim("{$user->first_name} {$user->last_name}")));

        return collect(preg_split('/\s+/u', $name))->filter()->map(function (string $part) {
            $len = mb_strlen($part);

            return $len <= 2 ? mb_substr($part, 0, 1).str_repeat('*', $len - 1)
                : mb_substr($part, 0, 1).str_repeat('*', $len - 2).mb_substr($part, -1);
        })->implode(' ') ?: '(no name)';
    }

    /**
     * medvault_user_matches_search(): name, username or email contain the
     * term, or the stored number contains the term's digits. The number is
     * compared normalised, so "76 618 326" and "+96176618326" both match.
     */
    public function search(Builder $query, string $term): Collection
    {
        $users = $query->get();
        $term = trim($term);
        if ($term === '') {
            return $users;
        }

        $needle = mb_strtolower($term);
        $digits = Mobile::normalize($term);

        return $users->filter(function (User $u) use ($needle, $digits) {
            $haystack = mb_strtolower(implode(' ', [$u->display_name, $u->username, $u->email, $u->first_name, $u->last_name]));
            if (str_contains($haystack, $needle)) {
                return true;
            }
            if ($digits === '') {
                return false;
            }
            $mobile = Mobile::normalize((string) $u->mobile_number);

            return $mobile !== '' && str_contains($mobile, $digits);
        })->values();
    }
}
