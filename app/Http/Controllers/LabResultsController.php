<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsWordPressParams;
use App\Http\Middleware\AuthenticateAppToken;
use App\Http\WpError;
use App\Models\LabAccount;
use App\Models\User;
use App\Services\Accounts\ApiTokens;
use App\Services\Labs\LabApi;
use App\Services\Labs\LabFailure;
use App\Services\Labs\LabRegistry;
use App\Services\Labs\LabRows;
use App\Support\WordPress\Sanitize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /lab-results, /lab-results/{request_nb}, /lab-file.
 *
 * The app never calls a lab. Everything here is the server doing it on the
 * user's behalf, with the token and any facility credentials staying here.
 */
class LabResultsController extends Controller
{
    use ReadsWordPressParams;

    public function __construct(
        private readonly LabRegistry $labs,
        private readonly LabApi $api,
    ) {}

    /**
     * Every order at every lab, newest first.
     *
     * Result rows are inlined only within a time budget: each order is one
     * chained call, and a long history run past PHP's limit is an HTML 502
     * the app cannot parse. Orders past the budget come back listed but
     * empty with results_loaded false, and the app fetches them on expand.
     * with_results=0 skips inlining entirely.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $wantResults = (string) ($request->input('with_results') ?? '1') !== '0';
        $budget = (float) config('medvault.labs.results_time_budget');
        $started = microtime(true);
        $truncated = false;
        $all = [];
        $errors = [];

        foreach ($this->labs->active() as $cfg) {
            // A separate radiology server is asked even when the lab itself
            // is down or does not know this patient: it is another system.
            $separateRadiology = $cfg->hasSeparateRadiology();

            // Already established that this lab does not know this patient.
            $givenUp = $this->api->hasGivenUp($user, $cfg);
            if ($givenUp && ! $separateRadiology) {
                continue;
            }

            $requests = $givenUp ? [] : $this->api->requests($user, $cfg);
            if ($requests instanceof LabFailure) {
                // One lab down must not empty another's results, but a lab
                // that has simply never heard of this patient is not news.
                if (! $requests->isUnknownAccount()) {
                    $errors[] = ['lab_id' => $cfg->labId, 'lab_name' => $cfg->labName, 'message' => $requests->message];
                }
                if (! $separateRadiology) {
                    continue;
                }
                $requests = [];
            }

            foreach ($requests as $row) {
                if (! $wantResults || (microtime(true) - $started) >= $budget) {
                    $truncated = $truncated || $wantResults;
                    $all[] = [...$row, 'capabilities' => $cfg->caps, 'results' => [], 'results_loaded' => false, 'error' => null];

                    continue;
                }

                $results = $this->api->results($user, $cfg, $row['request_nb']);
                $failed = $results instanceof LabFailure;
                $all[] = [
                    ...$row,
                    'capabilities' => $cfg->caps,
                    'results' => $failed ? [] : $results,
                    // A failed fetch is loaded: retrying on expand would only
                    // fail again and hide the reason.
                    'results_loaded' => true,
                    'error' => $failed ? $results->message : null,
                ];
            }

            // Studies are returned exactly like orders, so the app needs no
            // change. They have no rows to fetch, so they are always loaded and
            // never lazily asked for at /results. A radiology outage must not
            // hide the lab orders already listed.
            $studies = $this->api->radiology($user, $cfg);
            if ($studies instanceof LabFailure) {
                if (! $studies->isUnknownAccount()) {
                    $errors[] = ['lab_id' => $cfg->labId, 'lab_name' => $cfg->labName, 'message' => $studies->message];
                }

                continue;
            }
            // Whoever the server that sent the studies says this account is.
            $holder = $studies === [] ? null
                : LabAccount::where('user_id', $user->id)->where('lab_id', $cfg->labId)
                    ->value($cfg->radiologyEndpoint()->accountColumn('holder_name'));
            foreach ($studies as $study) {
                $study['birth_date'] = LabRows::studyBirthDate($study, $requests, $holder, (string) $user->dob);
                [$order, $results] = LabRows::radiologyAsOrder($study);
                $all[] = [...$order, 'capabilities' => LabRows::radiologyCaps($cfg), 'results' => $results, 'results_loaded' => true, 'error' => null];
            }
        }

        usort($all, fn ($a, $b) => strcmp($b['order_datetime'], $a['order_datetime']));

        return response()->json([
            'success' => true,
            'count' => count($all),
            'truncated' => $truncated,
            'errors' => $errors,
            'data' => $all,
        ]);
    }

    /** One order's rows, for lazy loading. lab_id 0 (absent) is the legacy account. */
    public function show(Request $request, string $requestNb): JsonResponse
    {
        $requestNb = Sanitize::textField($requestNb);
        $cfg = $this->labs->find(abs((int) $request->input('lab_id', 0)));
        if ($cfg === null) {
            throw new WpError('lab_not_found', 'Unknown lab.', 404);
        }
        // as=D: a patient-and-doctor reading an order from their doctor view.
        if (strtoupper((string) $request->input('as', '')) === 'D' && $request->user()->isPatientAndDoctor()) {
            $cfg = $cfg->forDoctorRole();
        }

        $results = $this->api->results($request->user(), $cfg, $requestNb);
        if ($results instanceof LabFailure) {
            throw $results->toWpError();
        }

        return response()->json([
            'success' => true,
            'lab_id' => $cfg->labId,
            'lab_name' => $cfg->labName,
            'request_nb' => $requestNb,
            'capabilities' => $cfg->caps,
            'results_loaded' => true,
            'count' => count($results),
            'data' => $results,
        ]);
    }

    /**
     * Streams a report the app must not fetch for itself: from an `endpoint`
     * lab (the lab token stays here, and the lab checks the path belongs to
     * this patient) or a `static_proxy` folder (needs headers the app never
     * has; the path is confined to the folder here, as nobody else checks it).
     *
     * Authorised by signature: the app opens the link in the OS browser,
     * which sends no headers. A bearer token is still accepted.
     */
    public function file(Request $request, ApiTokens $tokens): Response|JsonResponse
    {
        $path = Sanitize::textField($request->input('path') ?? '');
        $labId = abs((int) $request->input('lab_id', 0));
        $userId = abs((int) $request->input('uid', 0));
        $expires = abs((int) $request->input('exp', 0));
        $sig = Sanitize::textField($request->input('sig') ?? '');
        $src = Sanitize::textField($request->input('src') ?? '');

        if ($request->input('path') === null) {
            throw WpError::missingParams(['path']);
        }
        if ($path === '') {
            throw new WpError('missing_path', 'A file path is required.');
        }

        if ($sig !== '') {
            // The signature covers the path: a valid one proves the path came
            // out of this user's own order list.
            if ($expires < time()) {
                throw new WpError('link_expired', 'This link has expired. Open the result again.', 410);
            }
            if (! hash_equals(LabRows::signature($userId, $labId, $path, $expires, $src), $sig)) {
                throw new WpError('unauthorized', 'Invalid file link.', 401);
            }
            $user = User::find($userId);
        } else {
            $user = AuthenticateAppToken::userFrom($request, $tokens) ?? throw WpError::unauthorized();
        }

        $cfg = $this->labs->find($labId);
        $cfg = match ($src) {
            '' => $cfg,
            // A study's report, from the lab's separate radiology server.
            'radiology' => $cfg?->hasSeparateRadiology() ? $cfg->radiologyEndpoint() : null,
            // A report a patient-and-doctor opened as the doctor.
            'doctor' => $user?->isPatientAndDoctor() ? $cfg?->forDoctorRole() : null,
            default => null,
        };
        if ($cfg === null || ! in_array($cfg->pdfMode, ['endpoint', 'static_proxy'], true)) {
            throw new WpError('lab_file_unavailable', 'This lab does not serve files through MedVault.', 404);
        }

        // A browser opening the link gets a viewer page with a Download button;
        // the page asks this same signed URL for the bytes. Anything that is not
        // a browser (the app, a test, curl) gets the PDF as before.
        $mode = (string) $request->query('view', '');
        if ($mode === '' && str_contains((string) $request->header('Accept'), 'text/html')) {
            $query = $request->query();

            return response()->view('lab-file', [
                'name' => self::fileName(basename(str_replace('\\', '/', $path))),
                // Relative, so it resolves under whatever path the site is mounted at.
                'rawUrl' => '?'.http_build_query([...$query, 'view' => 'raw']),
                'downloadUrl' => '?'.http_build_query([...$query, 'view' => 'download']),
            ]);
        }

        if ($cfg->pdfMode === 'static_proxy') {
            $url = LabRows::staticFileUrl($cfg, $path) ?? throw new WpError('lab_file_unavailable', 'This file is not available.', 404);
            $response = $this->api->fetch($cfg, $url);
        } else {
            $response = $user === null
                ? new LabFailure(LabFailure::NO_MOBILE, 'No mobile number on file for this account.', 400)
                : $this->api->get($user, $cfg, 'file_path', ['path' => $path]);
        }

        if ($response instanceof LabFailure) {
            throw $response->toWpError();
        }
        if ($response->status() !== 200) {
            throw new WpError('lab_file_unavailable', 'This file is not available.', $response->status() === 404 ? 404 : 502);
        }

        $type = $response->header('Content-Type') ?: 'application/pdf';

        // A tunnel interstitial or a login wall answers 200 with HTML. Passed
        // through as a PDF it is a blank sheet; it is an outage.
        if ($cfg->pdfMode === 'static_proxy' && ! str_contains(strtolower($type), 'pdf')) {
            throw new WpError('lab_file_unavailable', 'This file is not available.', 502);
        }

        $body = $response->body();

        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => ($mode === 'download' ? 'attachment' : 'inline').'; filename="'.self::fileName(basename(str_replace('\\', '/', $path))).'"',
        ]);
    }

    /** sanitize_file_name(), the parts that matter for a header value. */
    private static function fileName(string $name): string
    {
        $name = str_replace(['?', '[', ']', '/', '\\', '=', '<', '>', ':', ';', ',', "'", '"', '&', '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+', "\0"], '', $name);
        $name = preg_replace('/[\r\n\t -]+/', '-', $name);

        return trim($name, '.-_') ?: 'report.pdf';
    }
}
