<?php

namespace App\Services\Audit;

use App\Models\ErrorLog;
use App\Support\HttpErrors;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps unexpected exceptions where an administrator can read them: the
 * host is cPanel, and storage/logs is not somewhere anyone looks.
 *
 * Laravel's own log still gets every exception; this is a second copy,
 * grouped by where it was thrown, so the poller failing every ten minutes is
 * one row with a count, not a thousand. Only what reaches report() lands
 * here, so WpError, validation and 404s never do.
 *
 * What is kept is deliberately thin. No request body (passwords, patient
 * data), no query string (signed links, tokens) and no function arguments
 * in the trace. Lab URLs are cut from HTTP client messages the same way
 * error responses do.
 */
final class ErrorRecorder
{
    private const MAX_TRACE_LINES = 40;

    private static bool $busy = false;

    public function capture(Throwable $e): void
    {
        // An exception thrown while recording one (the database is down,
        // say) must not be recorded in turn.
        if (self::$busy) {
            return;
        }
        self::$busy = true;

        try {
            $file = $this->relative($e->getFile());
            $fingerprint = sha1($e::class.'|'.$file.'|'.$e->getLine());
            $context = $this->context();
            $now = now();
            $values = [
                'class' => Str::limit($e::class, 250),
                'message' => Str::limit(HttpErrors::safeMessage($e) ?: '(no message)', 2000),
                'file' => Str::limit($file, 250),
                'line' => $e->getLine(),
                'trace' => $this->trace($e),
                ...$context,
                'last_seen_at' => $now,
                // A resolved error that comes back is open again.
                'resolved_at' => null,
            ];

            $updated = ErrorLog::where('fingerprint', $fingerprint)->increment('count', 1, $values);
            if ($updated === 0) {
                ErrorLog::create(['fingerprint' => $fingerprint, 'count' => 1, 'first_seen_at' => $now, ...$values]);
            }
        } catch (Throwable) {
            // Laravel's own log has it already.
        } finally {
            self::$busy = false;
        }
    }

    private function context(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            $argv = (array) ($_SERVER['argv'] ?? []);

            return ['method' => 'CLI', 'url' => Str::limit(implode(' ', array_slice($argv, 1, 2)), 250), 'user_id' => null];
        }

        $request = request();
        $user = null;
        try {
            $user = $request->user();
        } catch (Throwable) {
        }

        return ['method' => $request->method(), 'url' => Str::limit('/'.ltrim($request->path(), '/'), 250), 'user_id' => $user?->id];
    }

    /** File:line and function per frame; never the arguments. */
    private function trace(Throwable $e): string
    {
        $lines = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current !== $e) {
                $lines[] = 'Caused by '.$current::class.': '.Str::limit(HttpErrors::safeMessage($current), 300);
            }
            foreach (array_slice($current->getTrace(), 0, self::MAX_TRACE_LINES) as $i => $frame) {
                $where = isset($frame['file']) ? $this->relative($frame['file']).':'.($frame['line'] ?? '?') : '[internal]';
                $lines[] = "#{$i} {$where} ".($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').'()';
            }
        }

        return Str::limit(implode("\n", $lines), 20000);
    }

    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
