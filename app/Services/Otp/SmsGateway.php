<?php

namespace App\Services\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BestSmsBulk's WhatsApp template endpoint. The template ("otp_1") holds the
 * message text; we send only the code as its variable.
 */
class SmsGateway
{
    /** True on success, otherwise a description of what went wrong. */
    public function sendOtp(string $destination, string $code): bool|string
    {
        $cfg = config('medvault.sms');
        if (empty($cfg['api_key']) || empty($cfg['api_secret'])) {
            return 'SMS gateway is not configured.';
        }

        try {
            $response = Http::asForm()->timeout($cfg['timeout'])->post($cfg['url'], [
                'api_key' => $cfg['api_key'],
                'api_secret' => $cfg['api_secret'],
                'destination' => $destination,
                'senderid' => $cfg['sender_id'],
                'route' => $cfg['route'],
                'template' => $cfg['template'],
                'variables' => $code,
            ]);
        } catch (ConnectionException $e) {
            return $e->getMessage();
        }

        $status = $response->status();
        $body = trim($response->body());

        // Success is "confirmationID;recipientNumber;numParts".
        Log::info("[MedVault OTP] BSB response HTTP {$status} to {$destination}: {$body}");

        return $status === 200 && str_contains($body, ';') ? true : "HTTP {$status}: {$body}";
    }
}
