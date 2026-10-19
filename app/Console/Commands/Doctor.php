<?php

namespace App\Console\Commands;

use App\Models\Lab;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pre-flight for a server: everything that would otherwise surface as a
 * patient-facing failure after cutover. Exits non-zero on any failure, so it
 * can gate a deploy script.
 */
#[Signature('medvault:doctor {--cutover : Also require the things only needed once WordPress is switched off}')]
#[Description('Check that this server is ready to serve the MedVault app')]
class Doctor extends Command
{
    private int $failures = 0;

    public function handle(): int
    {
        $this->components->info('PHP and extensions');
        $this->check(version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP '.PHP_VERSION.' (command line)', 'Laravel 13 needs PHP 8.3 or newer.');
        $this->line('    <fg=gray>The website may run a different PHP. Check https://medvaultapp.com/app/medvault.php/up answers 200.</>');
        foreach (['pdo', 'mbstring', 'openssl', 'fileinfo', 'curl', 'tokenizer', 'xml', 'ctype', 'json'] as $ext) {
            $this->check(extension_loaded($ext), "ext-{$ext}", "Enable the {$ext} extension (cPanel → Select PHP Version).");
        }
        $this->check(extension_loaded('pdo_'.config('database.connections.'.config('database.default').'.driver')), 'database driver for '.config('database.default'));

        $this->components->info('Application');
        $this->check((string) config('app.key') !== '', 'APP_KEY is set', 'Run php artisan key:generate once, then never change it: it encrypts lab tokens and credentials.');
        $this->check(! config('app.debug'), 'APP_DEBUG is off', 'Debug pages expose configuration. Set APP_DEBUG=false.');
        $this->check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL is https ('.config('app.url').')', 'Photo and report links are built from APP_URL; it must be the public https base, e.g. https://medvaultapp.com/app.');
        $this->check(config('app.timezone') === 'Asia/Beirut', 'timezone '.config('app.timezone'), 'Notification times were stored in Asia/Beirut by WordPress.', warn: true);

        $this->components->info('Database');
        try {
            DB::connection()->getPdo();
            $this->check(true, 'connected to '.config('database.default').' / '.DB::connection()->getDatabaseName());
            $this->check(DB::table('migrations')->count() > 0, 'migrations have run');
            $users = User::count();
            $this->check($users > 0, "{$users} users", 'Run medvault:import-wordpress.', warn: true);
            $admins = User::where('role', User::ROLE_ADMIN)->count();
            $this->check($admins > 0, "{$admins} administrator(s)", 'Create one with medvault:make-admin.');
            $labs = Lab::published()->where('api_enabled', true)->count();
            $this->check($labs > 0, "{$labs} lab(s) with a results API", 'With none enabled, results fall back to the legacy Verdun settings.', warn: true);
        } catch (Throwable $e) {
            $this->check(false, 'database connection', $e->getMessage());
        }

        $this->components->info('Files');
        foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache', 'storage/app/public'] as $dir) {
            $this->check(is_writable(base_path($dir)), "{$dir} writable");
        }
        $disk = (string) config('medvault.uploads.disk');
        $this->check(Storage::disk($disk)->put('.doctor', 'ok') && Storage::disk($disk)->delete('.doctor'), "uploads can be written to the {$disk} disk");
        $this->check(is_link(public_path('storage')) || is_dir(public_path('storage')), 'public/storage link exists', 'Run php artisan storage:link, and link the public storage folder under public_html/app (see docs/DEPLOY.md).');

        $this->components->info('Services');
        $this->check($this->firebaseConfigured(), 'Firebase credentials', 'Push notifications fail until a service-account key is saved in Admin → Firebase Settings.', warn: ! $this->option('cutover'));
        $this->check(filled(config('medvault.openai.api_key')), 'OPENAI_API_KEY', 'AI summaries fail without it. Use a new, rotated key.', warn: ! $this->option('cutover'));
        $this->check(filled(config('medvault.sms.api_key')) && filled(config('medvault.sms.api_secret')), 'BSB_API_KEY / BSB_API_SECRET', 'OTP codes for sign-up and password reset cannot be sent.', warn: ! $this->option('cutover'));

        $this->components->info('Scheduler');
        $seen = Setting::get('scheduler_seen_at');
        $alive = is_string($seen) && strtotime($seen) >= now()->subMinutes(5)->getTimestamp();
        $this->check($alive, 'scheduler heartbeat '.($seen ?: 'never'), 'Add in cPanel → Cron Jobs (Once Per Minute): cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1', warn: ! $this->option('cutover'));
        $polling = Setting::get('poll_enabled', true) !== false;
        $this->line('  Result polling is '.($polling ? '<fg=yellow>ON</>' : 'OFF').'. Only one server may poll: turn it off in WordPress when it is on here.');

        $this->newLine();
        if ($this->failures > 0) {
            $this->components->error("{$this->failures} problem(s) to fix.");

            return self::FAILURE;
        }
        $this->components->info('Ready.');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $fix = '', bool $warn = false): void
    {
        if ($ok) {
            $this->components->twoColumnDetail($label, '<fg=green>OK</>');

            return;
        }
        $this->components->twoColumnDetail($label, $warn ? '<fg=yellow>WARN</>' : '<fg=red>FAIL</>');
        if ($fix !== '') {
            $this->line("    <fg=gray>{$fix}</>");
        }
        if (! $warn) {
            $this->failures++;
        }
    }

    private function firebaseConfigured(): bool
    {
        try {
            $credentials = json_decode((string) Setting::secret('firebase_credentials'), true);
        } catch (Throwable) {
            return false;
        }

        return is_array($credentials) && filled($credentials['project_id'] ?? null) && filled($credentials['private_key'] ?? null);
    }
}
