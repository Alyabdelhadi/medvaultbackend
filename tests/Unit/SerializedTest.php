<?php

namespace Tests\Unit;

use App\Support\WordPress\Serialized;
use PHPUnit\Framework\TestCase;

class SerializedTest extends TestCase
{
    public function test_decodes_arrays_and_scalars(): void
    {
        $this->assertSame(['1001' => 'V', '7' => 'P'], Serialized::decode(serialize(['1001' => 'V', '7' => 'P'])));
        $this->assertSame([], Serialized::decode('a:0:{}'));
        $this->assertFalse(Serialized::decode('b:0;'));
        $this->assertSame(5, Serialized::decode('i:5;'));
        $this->assertNull(Serialized::decode('N;'));
    }

    public function test_leaves_plain_strings_alone(): void
    {
        $this->assertSame('70536332', Serialized::decode('70536332'));
        $this->assertSame('', Serialized::decode(''));
        $this->assertSame('a:broken', Serialized::decode('a:broken'));
    }

    public function test_refuses_objects(): void
    {
        $decoded = Serialized::decode('O:8:"stdClass":1:{s:1:"a";i:1;}');
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $decoded);
    }
}
