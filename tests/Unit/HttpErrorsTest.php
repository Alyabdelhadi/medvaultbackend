<?php

namespace Tests\Unit;

use App\Support\HttpErrors;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HttpErrorsTest extends TestCase
{
    /** The exact text seen from production against Verdun on 2026-10-20. */
    public function test_keeps_the_curl_error_and_drops_the_lab_address(): void
    {
        $e = new RuntimeException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received (see https://curl.se/libcurl/c/libcurl-errors.html) for https://verdunmedicalcenter.net/public/index.php/api/mobile/request?tcToken=secret');

        $this->assertSame('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received', HttpErrors::safeMessage($e));
    }

    public function test_drops_a_bare_url_suffix_and_leaves_plain_messages_alone(): void
    {
        $this->assertSame('cURL error 6: Could not resolve host: tunnel.test', HttpErrors::safeMessage(new RuntimeException('cURL error 6: Could not resolve host: tunnel.test for https://tunnel.test/api/mobile/login')));
        $this->assertSame('Connection refused', HttpErrors::safeMessage(new RuntimeException('Connection refused')));
    }
}
