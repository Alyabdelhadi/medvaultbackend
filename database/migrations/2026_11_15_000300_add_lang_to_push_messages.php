<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Health Day goes out once per language, each to its own topic. The
 * language is kept so each user's history shows only the one they received.
 * Null means every language (a broadcast to everyone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_messages', function (Blueprint $table) {
            $table->string('lang', 2)->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('push_messages', function (Blueprint $table) {
            $table->dropColumn('lang');
        });
    }
};
