<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lab post that serves only imaging studies. Bekaa's radiology runs on its
 * own server, with its own database and logins, apart from its lab API, so it
 * is a second lab post with /request switched off: fetching lab orders from
 * the radiology server would list a second, different copy of them.
 *
 * On for every existing lab, which is what each of them did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labs', function (Blueprint $table) {
            $table->boolean('orders_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('labs', function (Blueprint $table) {
            $table->dropColumn('orders_enabled');
        });
    }
};
