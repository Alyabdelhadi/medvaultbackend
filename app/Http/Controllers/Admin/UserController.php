<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\LabAccount;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Services\Accounts\Passwords;
use App\Services\Admin\AdminUserCreator;
use App\Services\Admin\UserDirectory;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Patients, Doctors and Guarantors. Lab managers reach these pages only,
 * limited to their own lab by UserDirectory, which every query here goes
 * through, the CSV export included.
 */
class UserController extends Controller
{
    private const SLUGS = ['patients' => 'P', 'doctors' => 'D', 'guarantors' => 'G'];

    public const PER_PAGE = 20;

    public function __construct(private readonly UserDirectory $directory) {}

    public function index(Request $request, string $slug): View
    {
        $type = $this->type($slug);
        $search = trim((string) $request->query('s', ''));
        $query = $this->directory->query($type, $request->user());
        $total = (clone $query)->count();
        $matches = $this->directory->search($query, $search);

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $matches->forPage($page, self::PER_PAGE)->values(),
            $matches->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => array_filter(['s' => $search])],
        );

        return view('admin.users.index', [
            'slug' => $slug,
            'type' => $type,
            'title' => UserDirectory::PLURALS[$type],
            'users' => $paginator,
            'total' => $total,
            'search' => $search,
            'created' => $request->query('created') ? User::find($request->query('created')) : null,
        ]);
    }

    public function create(Request $request, string $slug): View
    {
        $type = $this->type($slug);
        $formType = strtoupper((string) $request->query('type', $type));

        return view('admin.users.create', [
            'slug' => $slug,
            'type' => $type,
            'formType' => isset(AdminUserCreator::FORM_TYPES[$formType]) ? $formType : $type,
            'suggestedPassword' => self::newPassword(),
            'labs' => Lab::where('status', '!=', 'trash')->orderBy('title')->pluck('title', 'id'),
            'managerLab' => UserDirectory::scopeFor($request->user()),
        ]);
    }

    public function store(Request $request, string $slug, AdminUserCreator $creator): RedirectResponse|View
    {
        $this->type($slug);
        $input = $request->only(['function_type', 'first_name', 'last_name', 'mobile_number', 'password', 'username', 'email', 'dob', 'gender', 'blood_type', 'dg_lab_id', 'owner_lab_id']);
        $nationalMobile = trim((string) ($input['mobile_number'] ?? ''));
        $cc = (string) $request->input('mobile_cc', '');
        $input['mobile_number'] = self::withCountry($cc, $nationalMobile);
        $input['dob'] ??= self::dobFromParts($request->input('dob_d'), $request->input('dob_m'), $request->input('dob_y'));

        // One Lab field on Add: the patient's lab, or the lab a doctor or
        // guarantor is attached to.
        $labId = (int) $request->input('lab_id', 0);
        if ($labId > 0) {
            $input['owner_lab_id'] ??= $labId;
            $input['dg_lab_id'] ??= $labId;
        }

        // A lab manager's accounts belong to their lab, whatever the form says:
        // the lab field is a plain number in the markup.
        $managerLab = UserDirectory::scopeFor($request->user());
        if ($managerLab > 0) {
            $input['dg_lab_id'] = $managerLab;
            $input['owner_lab_id'] = $managerLab;
        }

        [$user, $errors] = $creator->create($input, $request->user());
        if ($user === null) {
            // Not the password: the form suggests a new one, and the preview
            // shows whichever is in the field.
            return back()->withInput($request->except('password'))->withErrors($errors)
                ->with(['status' => 'Please correct the highlighted fields.', 'status_type' => 'error']);
        }

        // Save: back to the list, no slip. The password was on the form (and
        // its copy button); it is not carried any further.
        if ($request->input('after') === 'save') {
            return redirect()->route('admin.users.index', [array_search($user->functionType(), self::SLUGS, true) ?: $slug, 'created' => $user->id]);
        }

        // The slip, rendered here rather than after a redirect: the password
        // exists only in this request and must not be parked in the session.
        $labs = Lab::pluck('title', 'id');
        $slipLab = $labs[$user->dg_lab_id ?: $user->owner_lab_id] ?? null;

        return view('admin.users.created', [
            'slug' => array_search($user->functionType(), self::SLUGS, true),
            'user' => $user,
            'slipName' => trim("{$user->first_name} {$user->last_name}"),
            'slipLab' => $slipLab,
            'slipMobile' => self::slipMobile($cc, $nationalMobile, (string) $user->mobile_number),
            'slipPassword' => (string) $input['password'],
            'slipDoctor' => in_array($user->functionType(), ['D', 'G'], true),
        ]);
    }

    /** An 8-character password readable from paper: no 0/O or 1/l/I. */
    public static function newPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /**
     * Day, month and year boxes as Y-m-d, or null when all are empty. A
     * two-digit year after this one is last century: "85" is 1985. An
     * impossible date is passed on as typed, for the creator to reject.
     */
    public static function dobFromParts(mixed $day, mixed $month, mixed $year): ?string
    {
        [$d, $m, $y] = array_map(fn ($v) => preg_replace('/\D/', '', (string) $v), [$day, $month, $year]);
        if ($d === '' && $m === '' && $y === '') {
            return null;
        }
        if (strlen($y) === 2) {
            $y = (string) (((int) $y > (int) date('y') ? 1900 : 2000) + (int) $y);
        }

        return sprintf('%s-%s-%s', $y, str_pad($m, 2, '0', STR_PAD_LEFT), str_pad($d, 2, '0', STR_PAD_LEFT));
    }

    /** The number as the slip shows it; mirrors mvSlipMobile() in the slip. */
    public static function slipMobile(string $cc, string $national, string $stored): string
    {
        $digits = preg_replace('/\D/', '', $national);
        // Typed with its own "+" or "00": print what was stored.
        if ($digits === '' || str_starts_with(trim($national), '+') || str_starts_with($digits, '00')) {
            return $stored;
        }
        $grouped = match (strlen($digits)) {
            8 => preg_replace('/^(\d{2})(\d{3})(\d{3})$/', '$1 $2 $3', $digits),
            7 => preg_replace('/^(\d)(\d{3})(\d{3})$/', '$1 $2 $3', $digits),
            default => trim(chunk_split($digits, 3, ' ')),
        };

        return $cc !== '' && $cc !== '961' ? "+{$cc} {$grouped}" : $grouped;
    }

    public function show(Request $request, string $slug, User $user): View
    {
        $this->type($slug);
        abort_unless($this->directory->canSee($request->user(), $user), 404);

        return view('admin.users.show', [
            'slug' => $slug,
            'user' => $user,
            'accounts' => LabAccount::where('user_id', $user->id)->orderBy('lab_id')->get(),
            'labNames' => Lab::pluck('title', 'id'),
            'devices' => $user->devices()->orderByDesc('updated_at')->get(),
            'labs' => Lab::where('status', '!=', 'trash')->orderBy('title')->pluck('title', 'id'),
            // The audit log is site-wide, so only those who may read it see this account's part of it.
            'history' => $request->user()->canAdmin('audit')
                ? AuditLog::where('subject_type', 'user')->where('subject_id', $user->id)->orderByDesc('id')->limit(10)->get()
                : null,
        ]);
    }

    public function edit(Request $request, string $slug, User $user): View
    {
        $this->type($slug);
        abort_unless($this->directory->canSee($request->user(), $user), 404);

        return view('admin.users.edit', [
            'slug' => $slug,
            'user' => $user,
            'labs' => Lab::where('status', '!=', 'trash')->orderBy('title')->pluck('title', 'id'),
            'managerLab' => UserDirectory::scopeFor($request->user()),
        ]);
    }

    public function update(Request $request, string $slug, User $user, AdminUserCreator $creator): RedirectResponse
    {
        $this->type($slug);
        abort_unless($this->directory->canSee($request->user(), $user), 404);

        $input = $request->only(['function_type', 'first_name', 'last_name', 'mobile_number', 'password', 'email', 'dob', 'gender', 'blood_type', 'dg_lab_id']);
        $input['mobile_number'] = self::withCountry((string) $request->input('mobile_cc', ''), (string) ($input['mobile_number'] ?? ''));

        // A lab's own account cannot move anyone to another lab.
        [$saved, $errors, $numberChanged] = $creator->update($user, $input, UserDirectory::scopeFor($request->user()) === 0);
        if (! $saved) {
            return back()->withInput($request->except('password'))->withErrors($errors);
        }

        $listSlug = array_search($user->functionType(), self::SLUGS, true);
        $message = 'Saved.'.($numberChanged ? ' The mobile number changed, so the labs will be asked again with the new number at the next sign-in or poll.' : '');

        return redirect()->route('admin.users.show', [$listSlug, $user])->with('status', $message);
    }

    public function export(Request $request, string $slug): StreamedResponse
    {
        $type = $this->type($slug);
        $search = trim((string) $request->query('s', ''));
        $users = $this->directory->search($this->directory->query($type, $request->user()), $search);
        $filename = sprintf('medvault-%s-%s.csv', $slug, now('UTC')->format('Y-m-d'));

        return response()->streamDownload(function () use ($users) {
            $out = fopen('php://output', 'w');
            // A BOM, so Excel shows non-ASCII names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Name', 'Username', 'Email', 'Mobile', 'Registration Date', 'Type', 'How the account was made'], ',', '"', '');
            foreach ($users as $u) {
                fputcsv($out, [
                    $u->display_name,
                    $u->username,
                    (string) $u->email,
                    // A leading apostrophe stops Excel turning "+961..." into a formula.
                    "'".($u->mobile_number ?? ''),
                    $u->created_at?->format('Y-m-d H:i:s'),
                    $u->functionType(),
                    $u->createdViaLabel(),
                ], ',', '"', '');
            }
            fputcsv($out, [], ',', '"', '');
            fputcsv($out, ['Total', $users->count()], ',', '"', '');
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** Sets a new password and ends the account's app sessions. */
    public function password(Request $request, string $slug, User $user, Passwords $passwords, ApiTokens $tokens): RedirectResponse
    {
        abort_unless($this->directory->canSee($request->user(), $user), 404);
        $password = (string) $request->input('password', '');
        if (strlen($password) < 6) {
            return back()->withErrors(['password' => 'Password must be at least 6 characters.']);
        }

        $passwords->set($user, $password);
        $tokens->revoke($user);

        return redirect()->route('admin.users.show', [$slug, $user])->with('status', 'Password changed. The app will ask this user to sign in again.');
    }

    public function destroy(Request $request, string $slug, User $user): RedirectResponse
    {
        abort_unless($this->directory->canSee($request->user(), $user), 404);
        abort_if($user->id === $request->user()->id, 403, 'You cannot delete your own account.');

        $name = $user->display_name ?: $user->username;
        $user->delete();

        return redirect()->route('admin.users.index', $slug)->with('status', "{$name} was deleted, with their devices, lab accounts and notifications.");
    }

    /**
     * The form's country picker plus the number typed beside it, as one
     * international number. A number typed with its own "+" or "00" keeps
     * it: whoever typed it meant that country.
     */
    public static function withCountry(string $cc, string $number): string
    {
        $number = trim($number);
        $digits = preg_replace('/\D/', '', $number);
        if ($digits === '' || ! in_array($cc, Countries::codes(), true)
            || str_starts_with($number, '+') || str_starts_with($digits, '00')) {
            return $number;
        }

        return '+'.$cc.$digits;
    }

    private function type(string $slug): string
    {
        return self::SLUGS[$slug] ?? abort(404);
    }
}
