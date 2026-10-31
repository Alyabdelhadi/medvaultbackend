<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notification the patient deleted, kept so Notifier::alreadySent() still
 * sees it: the log is one of the duplicate-alert guards, and a removed row
 * would let a dismissed result alert fire again once its seen map is lost.
 * Prune still removes them after 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->boolean('is_deleted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('is_deleted');
        });
    }
};
