<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient name the lab returned at login: who this account is, as the
 * lab spells it. Bekaa's /radiology sends no birth_date, so the app's
 * mine/family split falls back to comparing names with the MedVault profile,
 * which rarely spells a name the way the lab does. Matching a study against
 * this name instead lets the server give the holder's studies the holder's
 * own birth date. Null until the next lab login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->string('holder_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->dropColumn('holder_name');
        });
    }
};
