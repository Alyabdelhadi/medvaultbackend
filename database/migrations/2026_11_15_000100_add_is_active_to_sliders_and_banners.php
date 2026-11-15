<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A slide or banner can be switched off without being deleted. Off means the
 * API leaves it out, so the app's home screen hides it; with every one off,
 * the app hides the whole section. Existing rows default to on, so a deploy
 * changes nothing on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sliders', 'banners'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('is_active')->default(true);
            });
        }
    }

    public function down(): void
    {
        foreach (['sliders', 'banners'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('is_active');
            });
        }
    }
};
