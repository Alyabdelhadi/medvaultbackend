<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsWordPressParams;
use App\Http\WpError;
use App\Services\Accounts\UserLookup;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /otp/send, POST /otp/verify. */
class OtpController extends Controller
{
    use ReadsWordPressParams;

    public function __construct(private readonly OtpService $otp) {}

    public function send(Request $request, UserLookup $users): JsonResponse
    {
        $this->requireParams($request, ['mobile_number']);

        $mobile = $this->text($request, 'mobile_number');
        if ($mobile === '') {
            throw new WpError('missing_mobile', 'Mobile number is required.');
        }

        // For a reset, the number must belong to an account before a message
        // is paid for; otherwise a typo walks the user through the whole flow
        // only to fail after they have chosen a new password.
        if (strtolower($this->text($request, 'purpose')) === 'reset' && $users->byMobile($mobile) === null) {
            throw new WpError('user_not_found', 'No account found with this mobile number.', 404);
        }

        $this->otp->send($mobile);

        return response()->json(['success' => true, 'message' => 'Verification code sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->requireParams($request, ['mobile_number', 'otp']);

        $mobile = $this->text($request, 'mobile_number');
        $code = $this->text($request, 'otp');
        if ($mobile === '' || $code === '') {
            throw new WpError('missing_fields', 'Mobile number and code are required.');
        }

        $this->otp->consume($mobile, $code);

        return response()->json(['success' => true, 'message' => 'Phone number verified.']);
    }
}
