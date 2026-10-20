<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who may do what in the admin. Administrators bypass groups; every
        // other admin account gets its permissions from exactly one group.
        Schema::create('admin_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('description')->nullable();
            // {module: [view, create, edit, delete]}, see App\Support\AdminModules.
            $table->json('permissions');
            // A lab's own accounts: each must be assigned a lab, sees only that
            // lab's people and can never be granted a site-wide page.
            $table->boolean('for_labs')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('admin_group_id')->nullable()->after('managed_lab_id')->constrained('admin_groups')->nullOnDelete();
        });

        // One billing plan per lab: what we charge it, how often, and when the
        // next payment is due.
        Schema::create('lab_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cycle', 10)->default('monthly'); // monthly | yearly
            $table->decimal('amount', 12, 2);
            $table->date('starts_on');
            $table->date('next_due_on');
            $table->unsignedSmallInteger('remind_days')->default(7);
            $table->boolean('active')->default(true);
            $table->string('billing_contact')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('lab_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('method', 40)->nullable();
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Lab-wide notifications sent from the admin, for the history and the
        // graphs. The per-patient rows still go to `notifications`.
        Schema::create('push_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('lab_id')->nullable()->index();
            $table->string('audience', 10); // all | lab | user
            $table->string('title');
            $table->text('body');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_messages');
        Schema::dropIfExists('lab_payments');
        Schema::dropIfExists('lab_plans');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_group_id');
        });
        Schema::dropIfExists('admin_groups');
    }
};
