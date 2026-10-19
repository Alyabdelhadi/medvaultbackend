<?php

namespace Tests\Unit;

use App\Support\WordPress\Sanitize;
use PHPUnit\Framework\TestCase;

class SanitizeTest extends TestCase
{
    public function test_text_field(): void
    {
        $this->assertSame('Rana Haddad', Sanitize::textField("  Rana\n\t Haddad "));
        $this->assertSame('alert(1)Rana', Sanitize::textField('<script>x</script>alert(1)<b>Rana</b>'));
        $this->assertSame('a &lt; b', Sanitize::textField('a < b'));
        $this->assertSame('70918679', Sanitize::textField(70918679));
        $this->assertSame('ab', Sanitize::textField('a%20b'));
        $this->assertSame('', Sanitize::textField(['x']));
        $this->assertSame('', Sanitize::textField("\xff\xfe"));
    }

    public function test_username(): void
    {
        $this->assertSame('ranahaddad', Sanitize::user('ranahaddad'));
        $this->assertSame('Jose', Sanitize::user('José'));
        $this->assertSame('', Sanitize::user('!!!'));
        $this->assertTrue(Sanitize::validUsername('rana.h_99@x'));
        $this->assertFalse(Sanitize::validUsername('rana!'));
    }

    public function test_email(): void
    {
        $this->assertTrue(Sanitize::isEmail('sami@clinic.test'));
        $this->assertFalse(Sanitize::isEmail('sami@clinic'));
        $this->assertFalse(Sanitize::isEmail('a@b.c'));
        $this->assertFalse(Sanitize::isEmail('sami@-clinic.test'));
        $this->assertSame('sami@clinic.test', Sanitize::email(' sami@clinic.test '));
        $this->assertSame('', Sanitize::email('nope'));
    }

    public function test_url(): void
    {
        $this->assertSame('https://x.test/a%20b.jpg', Sanitize::urlRaw('https://x.test/a b.jpg'));
        $this->assertSame('http://x.test/a.jpg', Sanitize::urlRaw('x.test/a.jpg'));
        $this->assertSame('', Sanitize::urlRaw('javascript:alert(1)'));
        $this->assertSame('/wp-content/a.jpg', Sanitize::urlRaw('/wp-content/a.jpg'));
    }
}
