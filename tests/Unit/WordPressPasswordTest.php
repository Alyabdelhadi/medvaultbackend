<?php

namespace Tests\Unit;

use App\Support\WordPress\WordPressPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vectors produced by WordPress's own code: PasswordHash(8, true) from
 * wp-includes/class-phpass.php for $P$, and wp_hash_password() 6.8+ for $wp$.
 */
class WordPressPasswordTest extends TestCase
{
    public static function vectors(): array
    {
        return [
            ['medvault2', '$P$Bcouc16czTkeDtRk/W0Eh2vJBUuXzw.', '$wp$2y$12$cGtK26iHeFQufASHDwz76O9Bf1bVebh0346cteh3Y9o4iCwLvaqkS', 'b99f6589475fa205cb9f3800b803e8ec'],
            ['Pa ss wörd!', '$P$ByoGeToJ4YWGqB970V3fArEcbUOpLl/', '$wp$2y$12$y.QRdQkDlGdWb.PSNX4/6eOmwxifXyt42WkOfUjvhC7sGdae7T/fe', '715f6db48cffa8e2f7256edc7bd9b099'],
            ['123456', '$P$Bn/ZykQ07hip7mWsL0/bN8yRKCVBj0/', '$wp$2y$12$9TYsrNGCF5KbMKYzl7RfduCTCFuYf6HkDVtK3hkXwt4DwLekRw9dG', 'e10adc3949ba59abbe56e057f20f883e'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_accepts_every_wordpress_format(string $password, string $phpass, string $wp, string $md5): void
    {
        $this->assertTrue(WordPressPassword::check($password, $phpass), 'phpass');
        $this->assertTrue(WordPressPassword::check($password, $wp), '$wp$ bcrypt');
        $this->assertTrue(WordPressPassword::check($password, $md5), 'md5');
    }

    #[DataProvider('vectors')]
    public function test_rejects_a_wrong_password(string $password, string $phpass, string $wp, string $md5): void
    {
        foreach ([$phpass, $wp, $md5] as $hash) {
            $this->assertFalse(WordPressPassword::check($password.'x', $hash));
            $this->assertFalse(WordPressPassword::check('', $hash));
        }
    }

    public function test_plain_bcrypt_is_checked_as_bcrypt(): void
    {
        $hash = password_hash('secret12', PASSWORD_BCRYPT);
        $this->assertTrue(WordPressPassword::check('secret12', $hash));
        $this->assertFalse(WordPressPassword::check('secret13', $hash));
        $this->assertFalse(WordPressPassword::isWordPressHash($hash));
    }

    /** wp_check_password() does not trim, so neither does this. */
    public function test_surrounding_whitespace_is_not_forgiven(): void
    {
        $this->assertFalse(WordPressPassword::check(' medvault2', '$P$Bcouc16czTkeDtRk/W0Eh2vJBUuXzw.'));
    }

    public function test_detects_formats_that_need_replacing(): void
    {
        $this->assertTrue(WordPressPassword::isWordPressHash('$P$Bcouc16czTkeDtRk/W0Eh2vJBUuXzw.'));
        $this->assertTrue(WordPressPassword::isWordPressHash('$wp$2y$12$cGtK26iHeFQufASHDwz76O9Bf1bVebh0346cteh3Y9o4iCwLvaqkS'));
        $this->assertTrue(WordPressPassword::isWordPressHash('e10adc3949ba59abbe56e057f20f883e'));
        $this->assertFalse(WordPressPassword::check('x', ''));
        $this->assertFalse(WordPressPassword::check('x', '$P$'));
    }
}
