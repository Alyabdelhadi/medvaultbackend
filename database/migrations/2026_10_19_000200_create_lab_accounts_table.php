<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (user, lab): what WordPress kept in the suffixed user metas
 * _lab_external_token_{id}, _lab_auth_failures_{id}, _lab_seen_status_{id},
 * _lab_poll_error_{id} and _lab_polled_at_{id}.
 *
 * lab_id 0 is the legacy single-lab account (the un-suffixed metas), used by
 * the Verdun fallback when no lab is configured. It is not a row in labs, so
 * there is deliberately no foreign key on lab_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lab_id');

            // Encrypted. Null when this lab has never issued one.
            $table->text('external_token')->nullable();

            // Refusals since the last successful lab login. At
            // MEDVAULT_MAX_LAB_AUTH_FAILURES the account stops being polled.
            $table->unsignedInteger('auth_failures')->default(0);

            // request_nb => readiness marker from the last poll. NULL means
            // "not yet seeded", and the next poll will only record statuses.
            // An empty map is a seeded account with no orders, which is not
            // the same thing: losing the difference re-seeds the account and
            // silently swallows every result that turned ready meanwhile.
            $table->json('seen_status')->nullable();
            $table->text('poll_error')->nullable();
            $table->timestamp('polled_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'lab_id']);
            $table->index('lab_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_accounts');
    }
};
