<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Was the _fcm_tokens user meta. A token is unique across all users:
        // a handset that signs into a second account must stop receiving the
        // first account's alerts.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512)->unique();
            $table->string('platform', 20)->default('unknown');
            $table->timestamps();
        });

        // Was {prefix}medvault_notifications, same columns. Ids are kept so a
        // notification the app has already shown keeps its id.
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->default('');
            $table->text('body');
            $table->string('type', 40)->default('general');
            // Part of the duplicate check: facilities number their requests
            // independently and most start at 1.
            $table->unsignedBigInteger('lab_id')->default(0);
            $table->string('request_nb', 64)->default('');
            $table->boolean('is_read')->default(false);
            // Site-local wall-clock time, as WordPress wrote it with
            // current_time('mysql'). app.timezone must match the old site.
            $table->dateTime('created_at');

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'lab_id', 'request_nb']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('devices');
    }
};
