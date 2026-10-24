<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ErrorLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Drops audit rows past config('medvault.logs.audit_days') and errors
 * resolved more than resolved_error_days ago. Open errors stay, however
 * old: nobody has looked at them yet. Scheduled daily.
 */
#[Signature('medvault:prune-logs')]
#[Description('Delete old audit log rows and long-resolved errors')]
class PruneLogs extends Command
{
    public function handle(): int
    {
        $audit = AuditLog::where('created_at', '<', now()->subDays(max(1, (int) config('medvault.logs.audit_days'))))->delete();
        $errors = ErrorLog::where('resolved_at', '<', now()->subDays(max(1, (int) config('medvault.logs.resolved_error_days'))))->delete();

        $this->components->info("Deleted {$audit} audit rows and {$errors} resolved errors.");

        return self::SUCCESS;
    }
}
