<?php

namespace App\Support;

/**
 * Mobile number parsing, ported unchanged from the WordPress plugin
 * (medvault_split_mobile() and friends).
 *
 * Canonical form is E.164 digits with no "+" ("96170536332", "97470536332").
 * Every lookup, duplicate check and OTP key matches on it, so an account
 * resolves whichever spelling the caller used.
 *
 * Two third parties want something else, and neither gets the canonical form:
 *
 *   * Verdun compares lab usernames as literal strings: "70536332" signs in,
 *     "070536332", "96170536332" and "+96170536332" are "Invalid
 *     credentials". It stores what the patient wrote at the counter, which
 *     for Lebanese 3-prefix numbers includes the trunk zero. See forLab().
 *   * The SMS gateway wants full international form, with the same 3 → 03
 *     rule scoped to 961. See forSms().
 */
final class Mobile
{
    public const DEFAULT_CC = '961';

    /**
     * Dial codes, longest first so "1809" is matched before "1".
     * Keep in step with lib/data/country_codes.dart in the app: a code the
     * picker offers but that is missing here parses as a Lebanese number.
     */
    private const DIAL_CODES = [
        '1242', '1246', '1268', '1787', '1809', '1868', '1876', '211',
        '212', '213', '216', '218', '220', '221', '222', '223',
        '224', '226', '227', '228', '229', '230', '231', '232',
        '233', '234', '235', '236', '237', '238', '239', '241',
        '242', '243', '244', '245', '249', '250', '251', '252',
        '253', '254', '255', '256', '257', '258', '260', '261',
        '263', '264', '265', '266', '267', '268', '291', '351',
        '352', '353', '354', '355', '356', '357', '358', '359',
        '370', '371', '372', '373', '374', '375', '376', '377',
        '378', '380', '381', '382', '383', '385', '386', '387',
        '389', '420', '421', '423', '501', '502', '503', '504',
        '505', '506', '507', '509', '591', '593', '595', '598',
        '670', '673', '675', '850', '852', '853', '855', '856',
        '880', '886', '960', '961', '962', '963', '964', '965',
        '966', '967', '968', '970', '971', '972', '973', '974',
        '975', '976', '977', '992', '993', '994', '995', '996',
        '998', '20', '27', '30', '31', '32', '33', '34',
        '36', '39', '40', '41', '43', '44', '45', '46',
        '47', '48', '49', '51', '52', '53', '54', '55',
        '56', '57', '58', '60', '61', '62', '63', '64',
        '65', '66', '77', '81', '82', '84', '86', '90',
        '91', '92', '93', '94', '95', '98', '1', '7',
    ];

    /** National-number lengths, used only to disambiguate bare input. */
    private const NATIONAL_LENGTHS = [
        '961' => [7, 8], // 3xxxxxx (7) and 70/71/76/78/79/81 xxxxxx (8)
    ];

    /** Countries whose national number keeps its leading zero in E.164. */
    private const KEEPS_TRUNK_ZERO = ['39']; // Italy

    /**
     * Splits any spelling into country code and national number.
     *
     * Bare digits with no "+" or "00" are only country-code-detected when
     * their length rules out a national number of $defaultCc. Otherwise a
     * Lebanese "71234567" would parse as Russia's +7.
     *
     *   "+97470536332" / "0097470536332" → 974 + 70536332
     *   "97470536332"                    → 974 + 70536332 (11 digits)
     *   "70536332" / "070536332"         → 961 + 70536332
     *   "03123456" / "3123456"           → 961 + 3123456
     *
     * @return array{cc: string, national: string, e164: string} empty strings when $raw holds no digits
     */
    public static function split(string $raw, string $defaultCc = self::DEFAULT_CC): array
    {
        $empty = ['cc' => '', 'national' => '', 'e164' => ''];
        $trimmed = trim($raw);
        $digits = preg_replace('/\D/', '', $trimmed);
        if ($digits === '') {
            return $empty;
        }

        $explicit = str_starts_with($trimmed, '+') || str_starts_with($digits, '00');
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits === '') {
            return $empty;
        }

        $detect = $explicit;
        if (! $detect) {
            $bare = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;
            $lengths = self::NATIONAL_LENGTHS[$defaultCc] ?? [];
            $detect = ! empty($lengths) && ! in_array(strlen($bare), $lengths, true);
        }

        $cc = '';
        if ($detect) {
            foreach (self::DIAL_CODES as $code) {
                if (str_starts_with($digits, $code) && strlen($digits) > strlen($code)) {
                    $cc = $code;
                    break;
                }
            }
        }

        if ($cc === '') {
            $cc = $defaultCc;
            $national = $digits;
        } else {
            $national = substr($digits, strlen($cc));
        }

        // The trunk "0" is dropped in E.164 everywhere except a short list.
        if (str_starts_with($national, '0') && ! in_array($cc, self::KEEPS_TRUNK_ZERO, true)) {
            $national = ltrim($national, '0');
        }
        if ($national === '') {
            return $empty;
        }

        return ['cc' => $cc, 'national' => $national, 'e164' => $cc.$national];
    }

    /** Canonical E.164 digits (no "+"), or "" when $raw holds no usable number. */
    public static function normalize(string $raw, string $defaultCc = self::DEFAULT_CC): string
    {
        return self::split($raw, $defaultCc)['e164'];
    }

    /**
     * The spellings a number may have been stored under before the canonical
     * form existed. Bare national spellings only for the default country.
     *
     * @return list<string>
     */
    public static function variants(string $cc, string $national): array
    {
        $variants = [
            $cc.$national,
            '+'.$cc.$national,
            '00'.$cc.$national,
            $cc.'0'.$national,
            '+'.$cc.'0'.$national,
            '00'.$cc.'0'.$national,
        ];
        if ($cc === self::DEFAULT_CC) {
            array_unshift($variants, $national, '0'.$national);
        }

        return array_values(array_unique($variants));
    }

    /**
     * The lb_trunk_zero lab username: Lebanese numbers as bare local digits,
     * except a single-digit "3" prefix keeps its trunk zero ("03123456").
     * Any other country goes out as national digits and will usually be
     * refused, which callers expect.
     */
    public static function forLab(string $raw): string
    {
        $parts = self::split($raw);
        if ($parts['e164'] === '') {
            return '';
        }
        if ($parts['cc'] !== self::DEFAULT_CC) {
            return $parts['national'];
        }

        return self::lebaneseForExternal($parts['national']);
    }

    /**
     * Full international form for the SMS gateway. Lebanese 3-prefix numbers
     * keep the trunk zero ("+96103123456"); without it the code is never
     * delivered to any 03 subscriber.
     */
    public static function forSms(string $raw): string
    {
        $parts = self::split($raw);
        if ($parts['e164'] === '') {
            return '';
        }
        $national = $parts['cc'] === self::DEFAULT_CC
            ? self::lebaneseForExternal($parts['national'])
            : $parts['national'];

        return '+'.$parts['cc'].$national;
    }

    /** Restores the trunk zero a single-digit "3" prefix is written with. */
    public static function lebaneseForExternal(string $local): string
    {
        return str_starts_with($local, '3') ? '0'.$local : $local;
    }

    /**
     * The derived columns written alongside the raw number.
     *
     * @return array{mobile_e164: ?string, mobile_cc: ?string, mobile_local: ?string}
     */
    public static function indexColumns(string $raw): array
    {
        $parts = self::split($raw);

        return [
            'mobile_e164' => $parts['e164'] !== '' ? $parts['e164'] : null,
            'mobile_cc' => $parts['cc'] !== '' ? $parts['cc'] : null,
            // Lebanese national digits only; null anywhere else.
            'mobile_local' => $parts['cc'] === self::DEFAULT_CC ? $parts['national'] : null,
        ];
    }
}
