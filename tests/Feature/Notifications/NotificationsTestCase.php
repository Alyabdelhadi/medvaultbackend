<?php

namespace Tests\Feature\Notifications;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiTestCase;

abstract class NotificationsTestCase extends ApiTestCase
{
    protected string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        Setting::putSecret('firebase_credentials', json_encode([
            'type' => 'service_account',
            'project_id' => 'medvault-test',
            'client_email' => 'push@medvault-test.iam.gserviceaccount.com',
            'private_key' => $this->privateKey,
        ]));
    }

    /** FCM and Google OAuth fakes; $fcm decides per message. */
    protected function fcmFake(?callable $fcm = null): array
    {
        return [
            'oauth2.googleapis.com/*' => fn () => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
            'fcm.googleapis.com/*' => $fcm ?? fn () => Http::response(['name' => 'projects/medvault-test/messages/1']),
        ];
    }
}
