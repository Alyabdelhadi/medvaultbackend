<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WordPress\WordPressImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('medvault:import-wordpress
    {--prefix= : WordPress table prefix (default WP_DB_PREFIX, or wp_)}
    {--site-url= : The WordPress site, e.g. https://medvaultapp.com/app, to copy the rendered About/FAQ HTML from}
    {--replace : Clear every imported table first. Before cutover only.}
    {--if-empty : Do nothing (successfully) when users already exist; for re-runnable install scripts}')]
#[Description('Copy users, labs, lab accounts, devices, notifications and settings from the WordPress database')]
class ImportWordPress extends Command
{
    public function handle(): int
    {
        if ($this->option('if-empty') && User::query()->exists()) {
            $this->components->info('Already imported. To refresh before cutover: php artisan medvault:import-wordpress --replace');

            return self::SUCCESS;
        }

        $prefix = (string) ($this->option('prefix') ?: env('WP_DB_PREFIX', 'wp_'));
        $siteUrl = $this->option('site-url') ?: env('WP_SITE_URL');

        $this->components->info("Reading WordPress tables {$prefix}* from the 'wordpress' connection.");

        $importer = new WordPressImporter(
            DB::connection('wordpress'),
            $prefix,
            $siteUrl,
            fn (string $message) => $this->components->warn($message),
        );

        try {
            $result = $importer->run((bool) $this->option('replace'));
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Imported', 'Rows'], collect($result['counts'])->map(fn ($n, $what) => [$what, $n])->values());

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->components->warn(count($result['warnings']).' warning(s) above. Read them before cutover.');
        }

        return self::SUCCESS;
    }
}
