<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsWordPressParams;
use App\Http\WpError;
use App\Models\Lab;
use App\Models\LabAccount;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Services\Accounts\Passwords;
use App\Services\Accounts\Profile;
use App\Services\Accounts\UserLookup;
use App\Services\Audit\AuditTrail;
use App\Services\Labs\LabFailure;
use App\Services\Labs\LabRegistry;
use App\Services\Labs\LabTokenService;
use App\Services\Otp\OtpService;
use App\Support\Mobile;
use App\Support\WordPress\Sanitize;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /register, /login, /dg/register, /password/reset, /lab-token/refresh.
 *
 * Validation order and messages follow the plugin: the app shows `message`
 * verbatim, so a reordered check is a visible change.
 */
class AuthController extends Controller
{
    use ReadsWordPressParams;

    private const GENDERS = ['male', 'female', 'other'];

    private const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public function __construct(
        private readonly UserLookup $users,
        private readonly Passwords $passwords,
        private readonly ApiTokens $tokens,
        private readonly LabTokenService $labTokens,
        private readonly LabRegistry $labs,
        private readonly AuditTrail $audit,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $this->requireParams($request, ['first_name', 'last_name', 'dob', 'gender', 'username', 'password', 'mobile_number']);

        $first = $this->text($request, 'first_name');
        $last = $this->text($request, 'last_name');
        $dob = $this->text($request, 'dob');
        $gender = strtolower($this->text($request, 'gender'));
        $username = trim(Sanitize::user($this->param($request, 'username') ?? ''));
        $password = $this->raw($request, 'password');
        $email = trim(Sanitize::email($this->param($request, 'email') ?? ''));
        $mobile = $this->text($request, 'mobile_number');
        $profilePic = Sanitize::urlRaw(trim(Sanitize::urlRaw($this->param($request, 'profile_pic') ?? '')));
        $idImage = Sanitize::urlRaw(trim(Sanitize::urlRaw($this->param($request, 'id_image') ?? '')));
        $insuranceImage = Sanitize::urlRaw(trim(Sanitize::urlRaw($this->param($request, 'insurance_image') ?? '')));
        $bloodType = $this->text($request, 'blood_type');
        // "I'm also a doctor": the same account gets a doctor side.
        $alsoDoctor = filter_var($this->param($request, 'also_doctor'), FILTER_VALIDATE_BOOLEAN);

        if ($first === '') {
            throw new WpError('missing_first_name', 'First name is required.');
        }
        if ($last === '') {
            throw new WpError('missing_last_name', 'Last name is required.');
        }
        if ($dob === '') {
            throw new WpError('missing_dob', 'Date of birth is required.');
        }
        $this->assertDob($dob, 'Date of birth must be in YYYY-MM-DD format (e.g. 1990-07-25).');
        if ($gender === '') {
            throw new WpError('missing_gender', 'Gender is required.');
        }
        if (! in_array($gender, self::GENDERS, true)) {
            throw new WpError('invalid_gender', 'Gender must be one of: male, female, other.');
        }
        if ($bloodType !== '' && ! in_array($bloodType, self::BLOOD_TYPES, true)) {
            throw new WpError('invalid_blood_type', 'Blood type must be one of: A+, A-, B+, B-, AB+, AB-, O+, O-.');
        }
        if ($username === '') {
            throw new WpError('missing_username', 'Username is required.');
        }
        if (! Sanitize::validUsername($username)) {
            throw new WpError('invalid_username', 'Username contains invalid characters.');
        }
        if (strlen($password) < 6) {
            throw new WpError('invalid_password', 'Password must be at least 6 characters.');
        }
        // Optional: accounts are identified by mobile number, and resets go
        // through SMS. When one is given it still has to be valid and unused.
        if ($email !== '' && ! Sanitize::isEmail($email)) {
            throw new WpError('invalid_email', 'Please enter a valid email address.');
        }
        if ($mobile === '') {
            throw new WpError('missing_mobile', 'Mobile number is required.');
        }
        if ($this->users->usernameExists($username)) {
            throw new WpError('username_exists', 'This username is already taken.', 409);
        }
        if ($email !== '' && $this->users->emailOwner($email) !== null) {
            throw new WpError('email_exists', 'This email address is already registered.', 409);
        }
        // Matched on the canonical number, so a second spelling cannot register twice.
        if ($this->users->byMobile($mobile) !== null) {
            throw new WpError('mobile_exists', 'This mobile number is already registered.', 409);
        }

        $user = $this->createUser($username, $password, $email, $first, $last, $mobile, [
            'dob' => $dob,
            'gender' => $gender,
            'profile_pic' => $profilePic ?: null,
            'id_image' => $idImage ?: null,
            'insurance_image' => $insuranceImage ?: null,
            'blood_type' => $bloodType ?: null,
            'also_doctor' => $alsoDoctor,
        ]);
        $this->audit->record('account.signup', 'Signed up in the app'.($alsoDoctor ? ' as a patient and doctor' : ''), $user, [], $user);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'data' => Profile::of($user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $this->requireParams($request, ['mobile_number', 'password']);

        $mobile = $this->text($request, 'mobile_number');
        $password = $this->raw($request, 'password');

        if ($mobile === '' || $password === '') {
            throw new WpError('missing_fields', 'Mobile number and password are both required.');
        }

        $user = $this->users->byMobile($mobile);
        if ($user === null || ! $this->passwords->check($user, $password)) {
            throw new WpError('invalid_credentials', 'Invalid mobile number or password.', 401);
        }

        // A lab outage must not block signing in, so failures here are only
        // recorded. The just-authenticated number goes to every lab, each
        // shaping it for itself; most patients are known to exactly one.
        $issued = $this->labTokens->refreshAll($user, $mobile);
        $primary = $issued[0] ?? null;

        $token = $this->tokens->issue($user, $request);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                ...Profile::of($user),
                'token' => $token,
                // The flat fields describe the primary lab, for app builds
                // from before multi-lab.
                'lab_token' => $primary['token'] ?? null,
                'lab_name' => $primary['lab_name'] ?? null,
                'lab_id' => $primary['lab_id'] ?? null,
                'labs' => $issued,
            ],
        ]);
    }

    public function dgRegister(Request $request): JsonResponse
    {
        $this->requireParams($request, ['first_name', 'last_name', 'phone_number', 'email', 'password', 'function_type']);

        $first = $this->text($request, 'first_name');
        $last = $this->text($request, 'last_name');
        $phone = $this->text($request, 'phone_number');
        $email = trim(Sanitize::email($this->param($request, 'email')));
        $password = $this->raw($request, 'password');
        $type = strtoupper($this->text($request, 'function_type'));
        // Every lab they work with: lab_ids (a list), or the single lab_id an
        // older build sends. Only labs that exist are kept; the first is the
        // primary, dg_lab_id.
        $wanted = $this->param($request, 'lab_ids');
        $wanted = is_array($wanted) ? $wanted : [$this->param($request, 'lab_id')];
        $wanted = array_values(array_unique(array_filter(array_map(fn ($id) => abs((int) $id), $wanted))));
        $known = $wanted === [] ? [] : Lab::whereIn('id', $wanted)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $labIds = array_values(array_intersect($wanted, $known));
        $labId = $labIds[0] ?? 0;

        if ($first === '') {
            throw new WpError('missing_first_name', 'First name is required.');
        }
        if ($last === '') {
            throw new WpError('missing_last_name', 'Last name is required.');
        }
        if ($phone === '') {
            throw new WpError('missing_phone', 'Phone number is required.');
        }
        if (! Sanitize::isEmail($email)) {
            throw new WpError('invalid_email', 'A valid email address is required.');
        }
        if (strlen($password) < 6) {
            throw new WpError('invalid_password', 'Password must be at least 6 characters.');
        }
        // PD: a doctor who is also a patient, stored as a patient with a
        // doctor side, exactly as the admin's Patient + Doctor.
        if (! in_array($type, ['D', 'G', 'PD'], true)) {
            throw new WpError('invalid_function_type', "function_type must be 'D' (Doctor), 'G' (Guarantor) or 'PD' (Patient and Doctor).");
        }
        if ($this->users->emailOwner($email) !== null) {
            throw new WpError('email_exists', 'This email address is already registered.', 409);
        }
        if ($this->users->byMobile($phone) !== null) {
            throw new WpError('mobile_exists', 'This phone number is already registered.', 409);
        }

        $username = $this->users->generateUsername($first, $last);
        $user = $this->createUser($username, $password, $email, $first, $last, $phone, [
            'function_type' => $type === 'PD' ? 'P' : $type,
            'also_doctor' => $type === 'PD',
            'dg_lab_id' => $labId > 0 ? $labId : null,
        ]);
        DB::table('user_labs')->insert(array_map(fn ($id) => ['user_id' => $user->id, 'lab_id' => $id], $labIds));
        $role = ['D' => 'doctor', 'G' => 'guarantor', 'PD' => 'patient and doctor'][$type];
        $this->audit->record('account.signup', "Signed up in the app as a {$role}", $user, [], $user);

        // Log in at every lab now, so the tokens are there on first use.
        $issued = $this->labTokens->refreshAll($user, $phone);
        $primary = $issued[0] ?? null;

        $token = $this->tokens->issue($user, $request);

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully.',
            'data' => [
                ...Profile::of($user),
                'token' => $token,
                'lab_token' => $primary['token'] ?? null,
                'lab_name' => $primary['lab_name'] ?? null,
                'lab_id' => $labId ?: ($primary['lab_id'] ?? null),
                'labs' => $issued,
            ],
        ]);
    }

    public function passwordReset(Request $request, OtpService $otp): JsonResponse
    {
        $this->requireParams($request, ['mobile_number', 'otp', 'new_password']);

        $mobile = $this->text($request, 'mobile_number');
        $code = $this->text($request, 'otp');
        $newPassword = $this->raw($request, 'new_password');

        if ($mobile === '' || $code === '' || $newPassword === '') {
            throw new WpError('missing_fields', 'Mobile number, verification code, and new password are all required.');
        }
        if (strlen($newPassword) < 6) {
            throw new WpError('invalid_password', 'New password must be at least 6 characters.');
        }

        $otp->consume($mobile, $code);

        $user = $this->users->byMobile($mobile);
        if ($user === null) {
            throw new WpError('user_not_found', 'No account found with this mobile number.', 404);
        }

        DB::transaction(function () use ($user, $newPassword) {
            $this->passwords->set($user, $newPassword);
            // Every session ends, and so does every device's push: a result
            // alert carries the request number, so a handset the owner just
            // locked out must stop receiving them.
            $this->tokens->revoke($user);
            $user->devices()->delete();
            LabAccount::where('user_id', $user->id)
                ->whereIn('lab_id', array_map(fn ($cfg) => $cfg->labId, $this->labs->active()))
                ->update(['auth_failures' => 0, 'radiology_auth_failures' => 0, 'doctor_auth_failures' => 0]);
        });
        $this->audit->record('account.password_reset', 'Reset their password with an SMS code', $user, [], $user);

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.',
        ]);
    }

    /**
     * POST /lab-token/refresh. No current screen calls it, but old builds do.
     *
     * "Every lab refused" signs the user out; "a lab was unreachable" must
     * not. Judged on this call's errors only, never on stored counters: a
     * refusal from weeks ago must not turn today's outage into a sign-out.
     */
    public function refreshLabTokens(Request $request): JsonResponse
    {
        $errors = [];
        $issued = $this->labTokens->refreshAll($request->user(), '', $errors);

        if ($issued === []) {
            $codes = array_column($errors, 'code');
            $unknown = [LabFailure::REFUSED, LabFailure::NO_MOBILE];
            $refused = array_intersect($codes, $unknown);
            // A throttle is deliberately not a refusal.
            $transient = array_diff($codes, $unknown);

            if ($refused !== [] && $transient === []) {
                throw new WpError('lab_account_invalid', 'This account is no longer registered with the lab. Please sign in again.', 401);
            }

            throw new WpError('external_api_error', 'Could not connect to the lab system. Please try again later.', 502);
        }

        $primary = $issued[0];

        return response()->json([
            'success' => true,
            'message' => 'Lab token refreshed.',
            'data' => [
                'lab_token' => $primary['token'],
                'lab_name' => $primary['lab_name'],
                'lab_id' => $primary['lab_id'],
                'labs' => $issued,
            ],
        ]);
    }

    /** wp_insert_user() with the plugin's metas. */
    private function createUser(string $username, string $password, string $email, string $first, string $last, string $mobile, array $extra): User
    {
        if ($username === '') {
            throw new WpError('registration_failed', 'Cannot create a user with an empty login name.', 500);
        }
        if (mb_strlen($username) > 60) {
            throw new WpError('registration_failed', 'Username may not be longer than 60 characters.', 500);
        }

        $user = new User;
        $user->forceFill([
            'username' => $username,
            'email' => $email !== '' ? $email : null,
            'password' => '',
            'display_name' => $first.' '.$last,
            'first_name' => $first,
            'last_name' => $last,
            'mobile_number' => $mobile,
            ...Mobile::indexColumns($mobile),
            'role' => User::ROLE_SUBSCRIBER,
            'created_via' => User::VIA_APP,
            ...$extra,
        ]);

        DB::transaction(function () use ($user, $password) {
            $user->save();
            $this->passwords->set($user, $password);
        });

        return $user->refresh();
    }

    private function assertDob(string $dob, string $formatMessage): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        if (! $parsed || $parsed->format('Y-m-d') !== $dob) {
            throw new WpError('invalid_dob', $formatMessage);
        }
        if ($parsed >= new DateTimeImmutable('today')) {
            throw new WpError('invalid_dob', 'Date of birth must be in the past.');
        }
    }
}
