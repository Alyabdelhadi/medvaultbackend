<?php

namespace Tests\Unit;

use App\Support\Mobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MobileTest extends TestCase
{
    /** The examples documented on medvault_split_mobile(), plus the edges. */
    public static function spellings(): array
    {
        return [
            'explicit plus' => ['+97470536332', '974', '70536332'],
            'explicit 00' => ['0097470536332', '974', '70536332'],
            'bare international, not a Lebanese length' => ['97470536332', '974', '70536332'],
            'Lebanese 8 digits' => ['70536332', '961', '70536332'],
            'Lebanese with trunk zero' => ['070536332', '961', '70536332'],
            'Lebanese 03' => ['03123456', '961', '3123456'],
            'Lebanese 3 without zero' => ['3123456', '961', '3123456'],
            // A Lebanese 71 number must not be read as Russia's +7.
            'Lebanese 71 is not Russia' => ['71234567', '961', '71234567'],
            'Lebanese full international' => ['+96170536332', '961', '70536332'],
            'Lebanese +961 with trunk zero' => ['+961070536332', '961', '70536332'],
            'formatting ignored' => ['+961 70 536-332', '961', '70536332'],
            'Italy keeps its zero' => ['+390612345678', '39', '0612345678'],
            'Iraq' => ['+9647701234567', '964', '7701234567'],
        ];
    }

    #[DataProvider('spellings')]
    public function test_split(string $raw, string $cc, string $national): void
    {
        $this->assertSame(['cc' => $cc, 'national' => $national, 'e164' => $cc.$national], Mobile::split($raw));
    }

    public function test_no_digits_is_empty(): void
    {
        $this->assertSame(['cc' => '', 'national' => '', 'e164' => ''], Mobile::split('  '));
        $this->assertSame('', Mobile::normalize('+'));
        $this->assertSame('', Mobile::normalize('00'));
    }

    /** Verdun matches the username literally; these are the spellings that sign in. */
    public function test_lab_username_shape(): void
    {
        $this->assertSame('70536332', Mobile::forLab('+96170536332'));
        $this->assertSame('70918679', Mobile::forLab('+96170918679'));
        $this->assertSame('03123456', Mobile::forLab('3123456'));
        $this->assertSame('03123456', Mobile::forLab('+9613123456'));
        // Foreign numbers go out as national digits and are expected to be refused.
        $this->assertSame('70536332', Mobile::forLab('+97470536332'));
        $this->assertSame('', Mobile::forLab(''));
    }

    public function test_sms_destination_keeps_03_zero_for_lebanon_only(): void
    {
        $this->assertSame('+96103123456', Mobile::forSms('03123456'));
        $this->assertSame('+96170536332', Mobile::forSms('70536332'));
        $this->assertSame('+97433123456', Mobile::forSms('+97433123456'));
    }

    public function test_variants_include_bare_spellings_only_for_lebanon(): void
    {
        $this->assertSame(
            ['70536332', '070536332', '96170536332', '+96170536332', '0096170536332', '961070536332', '+961070536332', '00961070536332'],
            Mobile::variants('961', '70536332'),
        );
        $this->assertNotContains('70536332', Mobile::variants('974', '70536332'));
    }

    public function test_index_columns(): void
    {
        $this->assertSame(
            ['mobile_e164' => '96170918679', 'mobile_cc' => '961', 'mobile_local' => '70918679'],
            Mobile::indexColumns('+96170918679'),
        );
        $this->assertSame(
            ['mobile_e164' => '97470536332', 'mobile_cc' => '974', 'mobile_local' => null],
            Mobile::indexColumns('+97470536332'),
        );
    }
}
