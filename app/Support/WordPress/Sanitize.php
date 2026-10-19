<?php

namespace App\Support\WordPress;

use Illuminate\Support\Str;

/**
 * The WordPress sanitizers and validators the plugin's endpoints relied on,
 * ported so a value accepted, rejected or reshaped by WordPress is accepted,
 * rejected or reshaped the same way here.
 */
final class Sanitize
{
    private const ALLOWED_PROTOCOLS = [
        'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'irc6', 'ircs', 'gopher',
        'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'sms', 'svn', 'tel', 'fax', 'xmpp', 'webcal', 'urn',
    ];

    /** sanitize_text_field(): no tags, no line breaks, no octets, single spaces. */
    public static function textField(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $str = (string) $value;
        if ($str === '' || ! mb_check_encoding($str, 'UTF-8')) {
            return '';
        }

        if (str_contains($str, '<')) {
            // wp_pre_kses_less_than(): a "<" that opens no tag is text.
            $str = preg_replace_callback('%<[^>]*?((?=<)|>|$)%', static function ($m) {
                return str_contains($m[0], '>') ? $m[0] : htmlspecialchars($m[0], ENT_QUOTES, 'UTF-8');
            }, $str);
            $str = self::stripAllTags($str);
            $str = str_replace("<\n", "&lt;\n", $str);
        }

        $str = trim(preg_replace('/[\r\n\t ]+/', ' ', $str));

        $found = false;
        while (preg_match('/%[a-f0-9]{2}/i', $str, $match)) {
            $str = str_replace($match[0], '', $str);
            $found = true;
        }

        return $found ? trim(preg_replace('/ +/', ' ', $str)) : $str;
    }

    /** wp_strip_all_tags() without line-break removal: no tags, no script or style bodies, trimmed. */
    public static function stripAllTags(string $text): string
    {
        return trim(strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text)));
    }

    /**
     * sanitize_user(). The register route passed it as a REST sanitize
     * callback, which WordPress calls with the request as the second
     * argument, so the app's usernames were always sanitized strictly.
     */
    public static function user(mixed $value, bool $strict = true): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $username = self::stripAllTags((string) $value);
        $username = Str::ascii($username);
        $username = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '', $username);
        $username = preg_replace('/&.+?;/', '', $username);
        if ($strict) {
            $username = preg_replace('|[^a-z0-9 _.\-@]|i', '', $username);
        }

        return preg_replace('|\s+|', ' ', trim($username));
    }

    /** validate_username(). */
    public static function validUsername(string $username): bool
    {
        $sanitized = self::user($username, true);

        return $sanitized === $username && $sanitized !== '';
    }

    /** is_email(): the shape check, not a deliverability check. */
    public static function isEmail(string $email): bool
    {
        if (strlen($email) < 6 || strpos($email, '@', 1) === false) {
            return false;
        }
        [$local, $domain] = explode('@', $email, 2);

        if (! preg_match('/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local)) {
            return false;
        }
        if (preg_match('/\.{2,}/', $domain) || trim($domain, " \t\n\r\0\x0B.") !== $domain) {
            return false;
        }

        $subs = explode('.', $domain);
        if (count($subs) < 2) {
            return false;
        }
        foreach ($subs as $sub) {
            if (trim($sub, " \t\n\r\0\x0B-") !== $sub || ! preg_match('/^[a-z0-9-]+$/i', $sub)) {
                return false;
            }
        }

        return true;
    }

    /** sanitize_email(): repairs what it can, '' for what it cannot. */
    public static function email(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $email = trim((string) $value);
        if (strlen($email) < 6 || strpos($email, '@', 1) === false) {
            return '';
        }
        [$local, $domain] = explode('@', $email, 2);

        $local = preg_replace('/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]/', '', $local);
        if ($local === '') {
            return '';
        }

        $domain = trim(preg_replace('/\.{2,}/', '', $domain), " \t\n\r\0\x0B.");
        if ($domain === '') {
            return '';
        }

        $subs = [];
        foreach (explode('.', $domain) as $sub) {
            $sub = preg_replace('/[^a-z0-9-]+/i', '', trim($sub, " \t\n\r\0\x0B-"));
            if ($sub !== '') {
                $subs[] = $sub;
            }
        }

        return count($subs) < 2 ? '' : $local.'@'.implode('.', $subs);
    }

    /** esc_url_raw(): a URL safe to store, or '' for a disallowed scheme. */
    public static function urlRaw(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $url = str_replace(' ', '%20', ltrim((string) $value));
        $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url);
        if ($url === '') {
            return '';
        }

        if (stripos($url, 'mailto:') !== 0) {
            do {
                $before = $url;
                $url = str_ireplace(['%0d', '%0a'], '', $url);
            } while ($url !== $before);
        }
        $url = str_replace(';//', '://', $url);

        if (! str_contains($url, ':') && ! in_array($url[0], ['/', '#', '?'], true) && ! preg_match('/^[a-z0-9-]+?\.php/i', $url)) {
            $url = 'http://'.$url;
        }

        if ($url[0] === '/') {
            return $url;
        }

        $scheme = strtolower(strstr($url, ':', true) ?: '');

        return in_array($scheme, self::ALLOWED_PROTOCOLS, true) ? $url : '';
    }
}
