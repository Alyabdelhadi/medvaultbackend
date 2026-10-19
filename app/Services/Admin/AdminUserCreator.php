<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Accounts\Passwords;
use App\Services\Accounts\UserLookup;
use App\Support\Mobile;
use App\Support\WordPress\Sanitize;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Adds a patient, doctor or guarantor from the admin, from
 * medvault_admin_create_user(). The rules mirror the app's registration so
 * an account made here is indistinguishable from one made in the app.
 *
 * It stops at the account: no lab login and no SMS. The lab token is issued
 * at the user's first sign-in, and a welcome SMS would cost a message each.
 */
final class AdminUserCreator
{
    public function __construct(
        private readonly UserLookup $users,
        private readonly Passwords $passwords,
    ) {}

    /**
     * @return array{0: ?User, 1: array<string, string>} the account, or field => message
     */
    public function create(array $input): array
    {
        $errors = [];

        $type = strtoupper(trim((string) ($input['function_type'] ?? '')));
        if (! isset(UserDirectory::TYPES[$type])) {
            $errors['function_type'] = 'Choose Patient, Doctor or Guarantor.';
            $type = 'P';
        }

        $first = trim(Sanitize::textField($input['first_name'] ?? ''));
        $last = trim(Sanitize::textField($input['last_name'] ?? ''));
        $mobile = trim(Sanitize::textField($input['mobile_number'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $username = Sanitize::user(trim((string) ($input['username'] ?? '')), true);
        $email = Sanitize::email(trim((string) ($input['email'] ?? '')));
        $dob = trim(Sanitize::textField($input['dob'] ?? ''));
        $gender = strtolower(trim(Sanitize::textField($input['gender'] ?? '')));
        $blood = trim(Sanitize::textField($input['blood_type'] ?? ''));
        $dgLabId = (int) ($input['dg_lab_id'] ?? 0);
        $ownerLabId = (int) ($input['owner_lab_id'] ?? 0);

        if ($first === '') {
            $errors['first_name'] = 'First name is required.';
        }
        if ($last === '') {
            $errors['last_name'] = 'Last name is required.';
        }

        if ($mobile === '') {
            $errors['mobile_number'] = 'Mobile number is required.';
        } elseif (Mobile::normalize($mobile) === '') {
            $errors['mobile_number'] = 'That does not look like a phone number.';
        } elseif ($this->users->byMobile($mobile) !== null) {
            $errors['mobile_number'] = 'This mobile number is already registered.';
        }

        if (strlen($password) < 6) {
            $errors['password'] = 'Password must be at least 6 characters.';
        }

        if ($username !== '') {
            if (! Sanitize::validUsername($username)) {
                $errors['username'] = 'Username contains invalid characters.';
            } elseif ($this->users->usernameExists($username)) {
                $errors['username'] = 'This username is already taken.';
            }
        }

        if ($email !== '') {
            if (! Sanitize::isEmail($email)) {
                $errors['email'] = 'Please enter a valid email address.';
            } elseif ($this->users->emailOwner($email) !== null) {
                $errors['email'] = 'This email address is already registered.';
            }
        }

        if ($dob !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
            if (! $parsed || $parsed->format('Y-m-d') !== $dob) {
                $errors['dob'] = 'Date of birth must be in YYYY-MM-DD format.';
            } elseif ($parsed >= new DateTimeImmutable('today')) {
                $errors['dob'] = 'Date of birth must be in the past.';
            }
        }

        if ($gender !== '' && ! in_array($gender, ['male', 'female', 'other'], true)) {
            $errors['gender'] = 'Gender must be male, female or other.';
        }

        $bloodTypes = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
        if ($blood !== '' && ! in_array($blood, $bloodTypes, true)) {
            $errors['blood_type'] = 'Blood type must be one of: '.implode(', ', $bloodTypes).'.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        if ($username === '') {
            $username = $this->users->generateUsername($first, $last, 'user');
        }

        $user = new User;
        $user->forceFill([
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password' => '',
            'display_name' => "$first $last",
            'first_name' => $first,
            'last_name' => $last,
            'function_type' => $type,
            'mobile_number' => $mobile,
            ...Mobile::indexColumns($mobile),
            'dob' => $dob ?: null,
            'gender' => $gender ?: null,
            'blood_type' => $blood ?: null,
            'role' => User::ROLE_SUBSCRIBER,
            'dg_lab_id' => $type !== 'P' && $dgLabId > 0 ? $dgLabId : null,
            // Set for patients too, so a facility's own list is not limited
            // to its doctors and guarantors.
            'owner_lab_id' => $ownerLabId > 0 ? $ownerLabId : null,
        ]);

        DB::transaction(function () use ($user, $password) {
            $user->save();
            $this->passwords->set($user, $password);
        });

        return [$user, []];
    }
}
