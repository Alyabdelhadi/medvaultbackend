<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts: patients, doctors, guarantors, lab managers and administrators,
 * one table as in WordPress.
 *
 * Ids are carried over from wp_users unchanged. They appear in signed PDF
 * links, in the notification log and in every lab account row, so a new
 * numbering would break all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 60)->unique();
            // Optional, as in WordPress: accounts are identified by mobile
            // number. Uniqueness of a non-empty address is checked in code,
            // the way email_exists() did, because many rows have none.
            $table->string('email')->nullable()->index();
            // A WordPress hash ($wp$2y$, $P$, md5) until the account first
            // signs in here, then plain bcrypt. See WordPressPassword.
            $table->string('password');
            $table->string('display_name')->default('');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            // Kept as the string the app sent (YYYY-MM-DD); the API returns it
            // verbatim, and a date column would reformat legacy values.
            $table->string('dob', 32)->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('blood_type', 4)->nullable();
            $table->text('profile_pic')->nullable();
            $table->text('id_image')->nullable();
            $table->text('insurance_image')->nullable();

            // The raw number exactly as submitted, which is what the profile
            // API returns, plus the derived forms every lookup matches on.
            $table->string('mobile_number', 32)->nullable();
            $table->string('mobile_e164', 20)->nullable()->index();
            $table->string('mobile_cc', 6)->nullable();
            $table->string('mobile_local', 16)->nullable()->index();

            // P (patient), D (doctor), G (guarantor). Null is a patient: the
            // WordPress code treated an unset _function_type as P everywhere.
            $table->char('function_type', 1)->nullable()->index();

            // subscriber | administrator | lab_manager, or any other WordPress
            // role name carried over as-is.
            $table->string('role', 40)->default('subscriber')->index();
            $table->unsignedBigInteger('managed_lab_id')->nullable()->index();
            $table->unsignedBigInteger('owner_lab_id')->nullable()->index();
            $table->unsignedBigInteger('dg_lab_id')->nullable()->index();

            // sha256 of the bearer token. WordPress stored it in the clear;
            // hashing it on import keeps every signed-in app signed in.
            $table->string('api_token_hash', 64)->nullable()->unique();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
