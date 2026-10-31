<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radiology on its own server. Bekaa serves imaging from a different host
 * (its own database and logins) than its lab API, and a token from one is a
 * 401 on the other. So the lab keeps one post with a second base URL, and
 * each patient holds a second token for that host, with its own refusal
 * count and holder name: being unknown to radiology must not park the lab
 * account, nor the reverse.
 *
 * Empty radiology_base keeps /radiology on api_base with the lab's token,
 * which is what every lab did before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labs', function (Blueprint $table) {
            $table->string('radiology_base')->nullable();
            $table->string('radiology_pdf_base')->nullable();
        });

        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->text('radiology_token')->nullable();
            $table->unsignedInteger('radiology_auth_failures')->default(0);
            $table->string('radiology_holder_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->dropColumn(['radiology_token', 'radiology_auth_failures', 'radiology_holder_name']);
        });

        Schema::table('labs', function (Blueprint $table) {
            $table->dropColumn(['radiology_base', 'radiology_pdf_base']);
        });
    }
};
