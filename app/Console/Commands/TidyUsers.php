<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\UserDirectory;
use App\Services\Audit\AuditTrail;
use App\Support\Mobile;
use App\Support\PersonName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clean-up of patient, doctor and guarantor rows: first and last names start
 * with a capital (PersonName, as every new account is saved), and every mobile number is stored as "+<cc><national>".
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
#[Description('Capitalize first and last names and add the country code to mobile numbers of app users')]
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

                    $oldFirst = (string) $user->first_name;
                    $oldLast = (string) $user->last_name;
                    $first = PersonName::capitalize($oldFirst);
                    $last = PersonName::capitalize($oldLast);
                    if ($first !== $oldFirst || $last !== $oldLast) {
                        if ($first !== $oldFirst) {
                            $changes['first_name'] = $first;
                        }
                        if ($last !== $oldLast) {
                            $changes['last_name'] = $last;
                        }
                        // Only a display name built from the two names is
                        // rebuilt; one set by hand ("Dr Rami") is kept.
                        if (mb_strtolower(trim((string) $user->display_name)) === mb_strtolower(trim($oldFirst.' '.$oldLast))) {
                            $changes['display_name'] = trim($first.' '.$last);
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
                            trim($user->first_name.' '.$user->last_name).' → '.trim(($changes['first_name'] ?? $user->first_name).' '.($changes['last_name'] ?? $user->last_name)),
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
            $this->table(['Id', 'Name', 'Mobile'], $sample);
        }
        if ($skipped !== []) {
            $this->components->warn(count($skipped).' numbers left alone: the stored mobile_e164 does not match the raw number.');
            $this->table(['Id', 'Raw number', 'Stored e164'], $skipped);
        }

        if ($apply && $names + $mobiles > 0) {
            $audit->record('console.tidy_users', "Capitalized the names of {$names} users and added the country code to {$mobiles} mobile numbers (medvault:tidy-users)", null, [], false);
        }

        $verb = $apply ? 'Updated' : 'Would update';
        $this->components->info("{$verb} {$names} names and {$mobiles} mobile numbers.");
        if (! $apply && $names + $mobiles > 0) {
            $this->line('  Run again with --apply to save. Back up the database first.');
        }

        return self::SUCCESS;
    }
}
