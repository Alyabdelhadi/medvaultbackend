<?php

namespace App\Http\Controllers;

use App\Services\Labs\LabApi;
use App\Services\Labs\LabFailure;
use App\Services\Labs\LabRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /dg/requests: a doctor's or guarantor's orders, grouped by patient. */
class DgController extends Controller
{
    private const FIELDS = [
        'request_nb', 'order_datetime', 'status', 'is_ready', 'ready_known', 'pdf_path', 'pdf_url',
        'pdf_paths', 'pdf_urls', 'cat_name', 'birth_date', 'lab_id', 'lab_name',
    ];

    public function requests(Request $request, LabRegistry $labs, LabApi $api): JsonResponse
    {
        $user = $request->user();
        $requests = [];
        $errors = [];

        foreach ($labs->active() as $cfg) {
            if ($api->hasGivenUp($user, $cfg)) {
                continue;
            }

            $rows = $api->requests($user, $cfg);
            if ($rows instanceof LabFailure) {
                if (! $rows->isUnknownAccount()) {
                    $errors[] = ['lab_id' => $cfg->labId, 'lab_name' => $cfg->labName, 'message' => $rows->message];
                }

                continue;
            }

            array_push($requests, ...$rows);
        }

        if ($requests === []) {
            return response()->json(['success' => true, 'count' => 0, 'errors' => $errors, 'patients' => []]);
        }

        // Grouped on the driver-resolved name. Reading a raw key here would
        // drop every row from a lab that spells it differently.
        $grouped = [];
        foreach ($requests as $req) {
            if ($req['name'] === '') {
                continue;
            }
            $grouped[$req['name']][] = array_intersect_key($req, array_flip(self::FIELDS));
        }

        $patients = [];
        foreach ($grouped as $name => $reqs) {
            usort($reqs, fn ($a, $b) => strcmp($b['order_datetime'], $a['order_datetime']));
            $patients[] = [
                'patient_name' => (string) $name,
                'request_count' => count($reqs),
                'latest_datetime' => $reqs[0]['order_datetime'] ?? '',
                // Field order as the plugin emitted it.
                'requests' => array_map(fn ($r) => array_merge(array_flip(self::FIELDS), $r), $reqs),
            ];
        }

        usort($patients, fn ($a, $b) => strcmp($a['patient_name'], $b['patient_name']));

        return response()->json(['success' => true, 'count' => count($patients), 'errors' => $errors, 'patients' => $patients]);
    }
}
