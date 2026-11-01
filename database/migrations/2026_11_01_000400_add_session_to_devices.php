<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which signed-in session registered a push token, so removing a device in
 * Connected devices also stops its result alerts. Without it the phone was
 * signed out but still received the account's notifications. Null for a
 * token registered before this, which is linked the next time it registers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedBigInteger('app_session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['app_session_id']);
            $table->dropColumn('app_session_id');
        });
    }
};
