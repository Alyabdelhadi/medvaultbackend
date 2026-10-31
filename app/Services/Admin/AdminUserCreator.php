<?php

namespace App\Services\Admin;

use App\Models\LabAccount;
use App\Models\User;
use App\Services\Accounts\Passwords;
use App\Services\Accounts\UserLookup;
use App\Support\Mobile;
use App\Support\WordPress\Sanitize;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds or edits a patient, doctor or guarantor from the admin, from
 * medvault_admin_create_user(). The rules mirror the app's registration so
 * an account made here is indistinguishable from one made in the app, and
 * an edit is held to the same rules as a new account.
 *
 * It stops at the account: no lab login and no SMS. The lab token is issued
 * at the user's first sign-in, and a welcome SMS would cost a message each.
 */
final class AdminUserCreator
{
    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * What the form offers. PD is stored as a patient with also_doctor, so it
     * is not a function type of its own.
     */
    public const FORM_TYPES = ['P' => 'Patient', 'PD' => 'Patient + Doctor', 'D' => 'Doctor', 'G' => 'Guarantor'];

    /** The form's type code for an existing account. */
    public static function formType(User $user): string
    {
        return $user->isPatientAndDoctor() ? 'PD' : $user->functionType();
    }

    public function __construct(
        private readonly UserLookup $users,
        private readonly Passwords $passwords,
    ) {}

    /**
     * $creator is the admin account adding it, kept on the row as "added by".
     *
     * @return array{0: ?User, 1: array<string, string>} the account, or field => message
     */
    public function create(array $input, ?User $creator = null): array
    {
        [$f, $errors] = $this->check($input, null);
        if ($errors !== []) {
            return [null, $errors];
        }

        $username = $f['username'] !== '' ? $f['username'] : $this->users->generateUsername($f['first'], $f['last'], 'user');

        $user = new User;
        $user->forceFill([
            'username' => $username,
            'password' => '',
            'role' => User::ROLE_SUBSCRIBER,
            ...$this->columns($f),
            // Set for patients too, so a facility's own list is not limited
            // to its doctors and guarantors.
            'owner_lab_id' => $f['owner_lab_id'] > 0 ? $f['owner_lab_id'] : null,
            ...User::createdBy(User::VIA_ADMIN, $creator),
        ]);

        DB::transaction(function () use ($user, $f) {
            $user->save();
            $this->passwords->set($user, $f['password']);
        });

        return [$user, []];
    }

    /**
     * Saves an edit. The username stays: imported accounts and the app's
     * sign-in may still use it. An empty password keeps the current one.
     *
     * A new mobile number is a different person to every lab, which issued
     * its tokens for the old number: those lab accounts are dropped, so the
     * next sign-in or poll logs in with the new number and seeds afresh,
     * rather than showing the old number's results.
     *
     * @return array{0: bool, 1: array<string, string>, 2: bool} saved, field => message, whether the number changed
     */
    public function update(User $user, array $input, bool $mayChangeLab): array
    {
        [$f, $errors] = $this->check($input, $user);
        if ($errors !== []) {
            return [false, $errors, false];
        }

        $columns = $this->columns($f);
        if (! $mayChangeLab) {
            unset($columns['dg_lab_id']);
        }
        $numberChanged = $columns['mobile_e164'] !== $user->mobile_e164;
        if (! $numberChanged) {
            // Same number, perhaps typed another way: keep the stored spelling.
            // Some labs match it letter for letter (Verdun: 03123456).
            unset($columns['mobile_number'], $columns['mobile_e164'], $columns['mobile_cc'], $columns['mobile_local']);
        }

        DB::transaction(function () use ($user, $columns, $f, $numberChanged) {
            $user->forceFill($columns)->save();
            if ($f['password'] !== '') {
                $this->passwords->set($user, $f['password']);
            }
            if ($numberChanged) {
                LabAccount::where('user_id', $user->id)->delete();
            }
        });

        return [true, [], $numberChanged];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>} cleaned fields, field => message
     */
    private function check(array $input, ?User $existing): array
    {
        $errors = [];

        $type = strtoupper(trim((string) ($input['function_type'] ?? '')));
        if (! isset(self::FORM_TYPES[$type])) {
            $errors['function_type'] = 'Choose Patient, Patient + Doctor, Doctor or Guarantor.';
            $type = 'P';
        }

        $f = [
            'type' => $type,
            'first' => trim(Sanitize::textField($input['first_name'] ?? '')),
            'last' => trim(Sanitize::textField($input['last_name'] ?? '')),
            'mobile' => trim(Sanitize::textField($input['mobile_number'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
            'username' => Sanitize::user(trim((string) ($input['username'] ?? '')), true),
            'email' => Sanitize::email(trim((string) ($input['email'] ?? ''))),
            'dob' => trim(Sanitize::textField($input['dob'] ?? '')),
            'gender' => strtolower(trim(Sanitize::textField($input['gender'] ?? ''))),
            'blood' => trim(Sanitize::textField($input['blood_type'] ?? '')),
            'dg_lab_id' => (int) ($input['dg_lab_id'] ?? 0),
            'owner_lab_id' => (int) ($input['owner_lab_id'] ?? 0),
        ];

        if ($f['first'] === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($f['last'] === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        if ($f['mobile'] === '') {
            $errors['mobile_number'] = 'Mobile number is required.';
        } elseif (Mobile::normalize($f['mobile']) === '') {
            $errors['mobile_number'] = 'That does not look like a phone number.';
        } elseif (($owner = $this->users->byMobile($f['mobile'])) !== null && $owner->id !== $existing?->id) {
            $errors['mobile_number'] = 'This mobile number is already registered.';
        }

        // Required for a new account; on an edit, empty keeps the current one.
        if (($existing === null || $f['password'] !== '') && strlen($f['password']) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }

        if ($existing === null && $f['username'] !== '') {
            if (! Sanitize::validUsername($f['username'])) {
                $errors['username'] = 'Username contains invalid characters.';
            } elseif ($this->users->usernameExists($f['username'])) {
                $errors['username'] = 'This username is already taken.';
            }
        }

        if ($f['email'] !== '') {
            if (! Sanitize::isEmail($f['email'])) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif (($ownerId = $this->users->emailOwner($f['email'])) !== null && $ownerId !== $existing?->id) {
                $errors['email'] = 'This email address is already registered.';
            }
        }

        if ($f['dob'] !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $f['dob']);
            if (! $parsed || $parsed->format('Y-m-d') !== $f['dob']) {
                $errors['dob'] = 'Date of birth must be in YYYY-MM-DD format.';
            } elseif ($parsed >= new DateTimeImmutable('today')) {
                $errors['dob'] = 'Date of birth must be in the past.';
            }
        }

        if ($f['gender'] !== '' && ! in_array($f['gender'], ['male', 'female', 'other'], true)) {
            $errors['gender'] = 'Gender must be male, female or other.';
        }

        if ($f['blood'] !== '' && ! in_array($f['blood'], self::BLOOD_TYPES, true)) {
            $errors['blood_type'] = 'Blood type must be one of: '.implode(', ', self::BLOOD_TYPES).'.';
        }

        return [$f, $errors];
    }

    /** The columns both a new account and an edit write. */
    private function columns(array $f): array
    {
        return [
            'email' => $f['email'] !== '' ? $f['email'] : null,
            'display_name' => "{$f['first']} {$f['last']}",
            'first_name' => $f['first'],
            'last_name' => $f['last'],
            'function_type' => $f['type'] === 'PD' ? 'P' : $f['type'],
            'also_doctor' => $f['type'] === 'PD',
            'mobile_number' => $f['mobile'],
            ...Mobile::indexColumns($f['mobile']),
            'dob' => $f['dob'] ?: null,
            'gender' => $f['gender'] ?: null,
            'blood_type' => $f['blood'] ?: null,
            'dg_lab_id' => $f['type'] !== 'P' && $f['dg_lab_id'] > 0 ? $f['dg_lab_id'] : null,
        ];
    }
}
