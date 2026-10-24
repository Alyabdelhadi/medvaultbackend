<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsWordPressParams;
use App\Http\WpError;
use App\Services\Accounts\ApiTokens;
use App\Services\Accounts\Passwords;
use App\Services\Accounts\Profile;
use App\Services\Accounts\UserLookup;
use App\Services\Audit\AuditTrail;
use App\Support\Mobile;
use App\Support\WordPress\Sanitize;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /profile, POST /profile/update, DELETE /account/delete. */
class ProfileController extends Controller
{
    use ReadsWordPressParams;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => Profile::of($request->user())]);
    }

    /**
     * Partial update: an absent or empty field is left alone, except that
     * id_image, insurance_image and blood_type distinguish "not sent" from
     * "sent empty", which clears them.
     */
    public function update(Request $request, UserLookup $users, Passwords $passwords, ApiTokens $tokens, AuditTrail $audit): JsonResponse
    {
        $user = $request->user();

        $first = $this->text($request, 'first_name');
        $last = $this->text($request, 'last_name');
        $dob = $this->text($request, 'dob');
        $gender = strtolower($this->text($request, 'gender'));
        $email = Sanitize::email(trim($this->raw($request, 'email')));
        $mobile = $this->text($request, 'mobile_number');
        $password = $this->raw($request, 'password');
        $profilePic = Sanitize::urlRaw(trim($this->raw($request, 'profile_pic')));
        $bloodType = $this->text($request, 'blood_type');

        $hasIdImage = $request->input('id_image') !== null;
        $hasInsuranceImage = $request->input('insurance_image') !== null;
        $hasBloodType = $request->input('blood_type') !== null;
        $idImage = $hasIdImage ? Sanitize::urlRaw(trim($this->raw($request, 'id_image'))) : null;
        $insuranceImage = $hasInsuranceImage ? Sanitize::urlRaw(trim($this->raw($request, 'insurance_image'))) : null;

        if ($hasBloodType && $bloodType !== '' && ! in_array($bloodType, ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], true)) {
            throw new WpError('invalid_blood_type', 'Blood type must be one of: A+, A-, B+, B-, AB+, AB-, O+, O-.');
        }

        if ($first === '' && $last === '' && $dob === '' && $gender === '' && $email === '' && $mobile === ''
            && $password === '' && $profilePic === '' && ! $hasIdImage && ! $hasInsuranceImage && ! $hasBloodType) {
            throw new WpError('nothing_to_update', 'Please provide at least one field to update.');
        }

        $changes = [];
        if ($first !== '' || $last !== '') {
            $newFirst = $first !== '' ? $first : (string) $user->first_name;
            $newLast = $last !== '' ? $last : (string) $user->last_name;
            $changes += ['first_name' => $newFirst, 'last_name' => $newLast, 'display_name' => $newFirst.' '.$newLast];
        }

        if ($dob !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
            if (! $parsed || $parsed->format('Y-m-d') !== $dob) {
                throw new WpError('invalid_dob', 'Date of birth must be in YYYY-MM-DD format.');
            }
            if ($parsed >= new DateTimeImmutable('today')) {
                throw new WpError('invalid_dob', 'Date of birth must be in the past.');
            }
            $changes['dob'] = $dob;
        }

        if ($gender !== '' && ! in_array($gender, ['male', 'female', 'other'], true)) {
            throw new WpError('invalid_gender', 'Gender must be one of: male, female, other.');
        }

        if ($email !== '') {
            if (! Sanitize::isEmail($email)) {
                throw new WpError('invalid_email', 'Please provide a valid email address.');
            }
            $owner = $users->emailOwner($email);
            if ($owner !== null && $owner !== (int) $user->id) {
                throw new WpError('email_exists', 'This email address is already in use.', 409);
            }
            $changes['email'] = $email;
        }

        if ($mobile !== '') {
            // Normalized, so "03…" cannot be claimed by someone stored as "+96103…".
            $owner = $users->byMobile($mobile);
            if ($owner !== null && (int) $owner->id !== (int) $user->id) {
                throw new WpError('mobile_exists', 'This mobile number is already in use.', 409);
            }
            $changes += ['mobile_number' => $mobile, ...Mobile::indexColumns($mobile)];
        }

        if ($password !== '' && strlen($password) < 6) {
            throw new WpError('invalid_password', 'Password must be at least 6 characters.');
        }

        if ($gender !== '') {
            $changes['gender'] = $gender;
        }
        if ($profilePic !== '') {
            $changes['profile_pic'] = $profilePic;
        }
        if ($hasIdImage) {
            $changes['id_image'] = $idImage ?: null;
        }
        if ($hasInsuranceImage) {
            $changes['insurance_image'] = $insuranceImage ?: null;
        }
        if ($hasBloodType) {
            $changes['blood_type'] = $bloodType ?: null;
        }

        // Collected from the saves below, with the password shown only as changed.
        $audit->watch();
        $newToken = DB::transaction(function () use ($user, $changes, $password, $passwords, $tokens) {
            $user->forceFill($changes)->save();
            if ($password === '') {
                return null;
            }
            $passwords->set($user, $password);

            // Changing the password ends the other sessions; this one gets a new token.
            return $tokens->issue($user);
        });

        $audit->stop();
        if ($audit->changes() !== []) {
            $audit->record('account.profile', 'Changed their profile in the app', $user);
        }

        $data = Profile::of($user->refresh());
        if ($newToken !== null) {
            $data['new_token'] = $newToken;
        }

        return response()->json(['success' => true, 'message' => 'Profile updated successfully.', 'data' => $data]);
    }

    public function destroy(Request $request, AuditTrail $audit): JsonResponse
    {
        $audit->record('account.deleted', 'Deleted their account in the app', $request->user(), []);
        // Lab accounts, devices and notifications go with it.
        $request->user()->delete();

        return response()->json(['success' => true, 'message' => 'Account has been permanently deleted.']);
    }
}
