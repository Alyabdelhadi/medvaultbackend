<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an account came from: app | admin | console | wordpress. Null
        // is an account created before this was recorded; nothing on the row
        // tells an app sign-up from an admin one, so none is guessed.
        // created_by_name keeps "by whom" readable after that account is gone.
        Schema::table('users', function (Blueprint $table) {
            $table->string('created_via', 12)->nullable()->index()->after('dg_lab_id');
            $table->unsignedBigInteger('created_by_id')->nullable()->index()->after('created_via');
            $table->string('created_by_name', 120)->nullable()->after('created_by_id');
        });

        // Who did what. Actor and subject are snapshots as well as ids, with
        // no foreign keys: the history of a deleted account must survive it.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('actor_name', 120)->nullable();
            $table->string('actor_kind', 10); // admin | app | system
            $table->string('action', 60)->index();
            $table->string('summary');
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 160)->nullable();
            $table->unsignedBigInteger('lab_id')->nullable()->index();
            // [{model, id, label, event, fields: {name: [before, after]}}], secrets redacted.
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // Unexpected exceptions, one row per place they are thrown from, so a
        // failure that repeats every poll is one row with a count.
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('class');
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('url')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['created_via']);
            $table->dropIndex(['created_by_id']);
            $table->dropColumn(['created_via', 'created_by_id', 'created_by_name']);
        });
    }
};
