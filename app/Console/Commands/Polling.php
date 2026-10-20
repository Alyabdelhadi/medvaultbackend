<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Result polling here and in WordPress. Only one of them may poll, or every
 * alert is sent twice; deploy/switch.sh reads WordPress's setting through
 * this before it turns polling on here.
 */
#[Signature('medvault:polling
    {state? : on or off, for this server}
    {--wordpress : Print only WordPress\'s setting, 1 or 0, and fail if it cannot be read}')]
#[Description('Show or set result polling, and show whether WordPress still polls')]
class Polling extends Command
{
    public function handle(): int
    {
        $state = $this->argument('state');
        if ($state !== null) {
            if (! in_array($state, ['on', 'off'], true)) {
                $this->components->error('Use on or off.');

                return self::FAILURE;
            }
            Setting::put('poll_enabled', $state === 'on');
        }

        try {
            $wordpress = $this->wordpressPolls();
        } catch (Throwable $e) {
            if ($this->option('wordpress')) {
                $this->components->error('Cannot read WordPress\'s polling setting: '.$e->getMessage());

                return self::FAILURE;
            }
            $wordpress = null;
        }

        if ($this->option('wordpress')) {
            $this->line($wordpress ? '1' : '0');

            return self::SUCCESS;
        }

        $this->line('Here:      '.(Setting::get('poll_enabled', true) !== false ? 'on' : 'off'));
        $this->line('WordPress: '.($wordpress === null ? 'unknown' : ($wordpress ? 'on' : 'off')));

        return self::SUCCESS;
    }

    /** WordPress polls unless its option says 0; absent means its default, on. */
    private function wordpressPolls(): bool
    {
        $value = DB::connection('wordpress')
            ->table(config('medvault.wordpress.prefix', 'wp_').'options')
            ->where('option_name', 'medvault_poll_enabled')
            ->value('option_value');

        return (bool) (int) ($value ?? 1);
    }
}
