<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * lab_id 0 was the WordPress plugin's single-lab account, copied over by the
 * import next to the real Verdun lab. No app build in use reads it and the
 * poller never checks it, so it only showed up as a stale second Verdun.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lab_accounts')->where('lab_id', 0)->delete();
    }

    public function down(): void
    {
        // Deleted data; nothing to restore.
    }
};
