<?php

namespace App\Services\Labs;

use App\Http\WpError;

/**
 * Why a call to a lab did not produce what was asked for.
 *
 * The code is what callers decide on, and "refused" and "unreachable" lead
 * to opposite decisions: a refusal may end in a sign-out, an outage never
 * may. Keep them apart.
 */
final class LabFailure
{
    /** The lab answered and does not know this account. */
    public const REFUSED = 'lab_login_failed';

    /** We had no number to send it. Treated like a refusal. */
    public const NO_MOBILE = 'no_mobile';

    /** Throttled, by status code or by message. Never counted against the account. */
    public const RATE_LIMITED = 'lab_rate_limited';

    /** Timeout, connection error, 5xx. */
    public const UNREACHABLE = 'external_api_error';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status = 502,
    ) {}

    /** "This lab does not know this patient", as opposed to "this lab is down". */
    public function isUnknownAccount(): bool
    {
        return in_array($this->code, [self::REFUSED, self::NO_MOBILE], true);
    }

    public function toWpError(): WpError
    {
        return new WpError($this->code, $this->message, $this->status);
    }
}
