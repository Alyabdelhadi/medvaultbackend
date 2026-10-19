<?php

namespace App\Services\Otp;

use App\Http\WpError;
use App\Support\Mobile;
use Illuminate\Support\Facades\Cache;

/**
 * Six-digit codes keyed by the canonical number, so send, verify and reset
 * agree whichever spelling each app screen submits.
 */
final class OtpService
{
    public function __construct(private readonly SmsGateway $sms) {}

    public function send(string $mobile): void
    {
        $e164 = Mobile::normalize($mobile);
        $ttl = (int) config('medvault.otp.ttl_seconds');

        $rateKey = self::rateKey($e164);
        $count = (int) Cache::get($rateKey, 0);
        if ($count >= (int) config('medvault.otp.max_sends')) {
            throw new WpError('rate_limited', 'Too many verification requests. Please wait a few minutes and try again.', 429);
        }

        $code = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::codeKey($e164), $code, $ttl);
        // Counted before sending, and each send restarts the window, as before.
        Cache::put($rateKey, $count + 1, $ttl);

        $sent = $this->sms->sendOtp(Mobile::forSms($mobile), $code);
        if ($sent !== true) {
            // So a retry starts clean.
            Cache::forget(self::codeKey($e164));
            $detail = is_string($sent) ? $sent : 'Unknown SMS gateway error.';
            throw new WpError('sms_failed', "Failed to send verification SMS: {$detail}", 502);
        }
    }

    /** Checks and consumes the code. Throws the plugin's errors otherwise. */
    public function consume(string $mobile, string $code): void
    {
        $key = self::codeKey(Mobile::normalize($mobile));
        $stored = Cache::get($key);

        if ($stored === null) {
            throw new WpError('otp_expired', 'Verification code has expired. Please request a new one.', 400);
        }
        if (! hash_equals((string) $stored, $code)) {
            throw new WpError('otp_invalid', 'Invalid verification code. Please try again.', 400);
        }

        Cache::forget($key);
    }

    public static function codeKey(string $e164): string
    {
        return 'otp_code_'.md5($e164);
    }

    public static function rateKey(string $e164): string
    {
        return 'otp_rate_'.md5($e164);
    }
}
