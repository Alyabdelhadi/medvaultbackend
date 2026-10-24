<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imaging studies, served by a lab's /radiology endpoint alongside its lab
 * orders. New here: the WordPress plugin never had it, so nothing imports.
 *
 * radiology_seen_status is its own map because studies have their own
 * readiness rule (a report exists) and their own seeding: sharing seen_status
 * would mean switching radiology on alerts every past study at once, since the
 * lab-order map is already seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labs', function (Blueprint $table) {
            // Off unless ticked.
            $table->boolean('radiology_enabled')->default(false);
        });

        Schema::table('lab_accounts', function (Blueprint $table) {
            // study_nb => report marker. NULL means not yet seeded, exactly as
            // seen_status.
            $table->json('radiology_seen_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->dropColumn('radiology_seen_status');
        });

        Schema::table('labs', function (Blueprint $table) {
            $table->dropColumn('radiology_enabled');
        });
    }
};
