<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * app_sessions: one row per signed-in device. WordPress kept a single token
 * per account and replaced it on every login, so signing in on a second
 * phone signed the first one out. users.api_token_hash stays, read once more
 * for a token issued before this table, which becomes a row on first use.
 *
 * user_labs: every lab a doctor or guarantor works with. dg_lab_id stays the
 * first of them, for everything that reads one lab.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sha256 of the bearer token, as users.api_token_hash.
            $table->string('token_hash', 64)->unique();
            $table->string('device_name', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_labs', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // No foreign key: a lab's id is its WordPress id, and lab_id 0 is
            // the legacy account elsewhere; trashing a lab must not cascade.
            $table->unsignedBigInteger('lab_id');
            $table->primary(['user_id', 'lab_id']);
            $table->index('lab_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_labs');
        Schema::dropIfExists('app_sessions');
    }
};
