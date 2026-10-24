<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\UserDirectory;
use App\Services\Audit\AuditTrail;
use App\Support\Mobile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off clean-up of patient, doctor and guarantor rows: first names start
 * with a capital, and every mobile number is stored as "+<cc><national>".
 *
 * Only the raw mobile_number changes. Lookups, OTP keys and duplicate checks
 * match on mobile_e164, which is left as it is, and lab usernames are shaped
 * from the parsed number (Mobile::forLab), so "70123456" and "+96170123456"
 * sign in to every lab alike. A row whose stored mobile_e164 disagrees with
 * its raw number is reported and left alone.
 *
 * Previews by default; --apply writes.
 */
#[Signature('medvault:tidy-users {--apply : Write the changes; without it nothing is saved}')]
#[Description('Capitalize first names and add the country code to mobile numbers of app users')]
class TidyUsers extends Command
{
    public function handle(AuditTrail $audit): int
    {
        $apply = (bool) $this->option('apply');
        $names = $mobiles = 0;
        $skipped = [];
        $sample = [];

        $query = UserDirectory::withoutStaff(User::query())
            ->where(fn ($q) => $q->whereNull('function_type')->orWhereIn('function_type', ['P', 'D', 'G']));

        $query->chunkById(500, function ($users) use ($apply, &$names, &$mobiles, &$skipped, &$sample) {
            DB::transaction(function () use ($users, $apply, &$names, &$mobiles, &$skipped, &$sample) {
                foreach ($users as $user) {
                    $changes = [];

                    $first = self::capitalize((string) $user->first_name);
                    if ($first !== (string) $user->first_name) {
                        $changes['first_name'] = $first;
                        // Only a display name the app built from the two
                        // names is rebuilt; one set by hand is kept.
                        if ($user->display_name === $user->first_name.' '.$user->last_name) {
                            $changes['display_name'] = $first.' '.$user->last_name;
                        }
                        $names++;
                    }

                    $raw = (string) $user->mobile_number;
                    $e164 = Mobile::normalize($raw);
                    if ($e164 !== '' && $raw !== '+'.$e164) {
                        if ($user->mobile_e164 !== null && $user->mobile_e164 !== $e164) {
                            $skipped[] = [$user->id, $raw, $user->mobile_e164];
                        } else {
                            $changes += ['mobile_number' => '+'.$e164, ...Mobile::indexColumns($raw)];
                            $mobiles++;
                        }
                    }

                    if ($changes === []) {
                        continue;
                    }
                    if (count($sample) < 20) {
                        $sample[] = [
                            $user->id,
                            $user->first_name.' → '.($changes['first_name'] ?? $user->first_name),
                            $raw.' → '.($changes['mobile_number'] ?? $raw),
                        ];
                    }
                    if ($apply) {
                        // No updated_at: this is a format change, not an edit by the user.
                        $user->timestamps = false;
                        $user->forceFill($changes)->save();
                    }
                }
            });
        });

        if ($sample !== []) {
            $this->table(['Id', 'First name', 'Mobile'], $sample);
        }
        if ($skipped !== []) {
            $this->components->warn(count($skipped).' numbers left alone: the stored mobile_e164 does not match the raw number.');
            $this->table(['Id', 'Raw number', 'Stored e164'], $skipped);
        }

        if ($apply && $names + $mobiles > 0) {
            $audit->record('console.tidy_users', "Capitalized {$names} first names and added the country code to {$mobiles} mobile numbers (medvault:tidy-users)", null, [], false);
        }

        $verb = $apply ? 'Updated' : 'Would update';
        $this->components->info("{$verb} {$names} first names and {$mobiles} mobile numbers.");
        if (! $apply && $names + $mobiles > 0) {
            $this->line('  Run again with --apply to save. Back up the database first.');
        }

        return self::SUCCESS;
    }

    /**
     * Capitalizes the first letter of each word ("jean-paul el" → "Jean-Paul El")
     * and leaves the rest alone, so "McKenzie" stays as written. Arabic
     * script has no case and passes through unchanged.
     */
    public static function capitalize(string $name): string
    {
        return preg_replace_callback('/(^|[\s\-])(\p{Ll})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $name);
    }
}
