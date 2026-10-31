<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One account that is both a patient and a doctor. Labs accept the same
 * number as type P and as type D and answer each with different data, so
 * the doctor side has its own lab login: a second token per lab, with its
 * own refusal count, beside the patient's.
 *
 * function_type stays P for such an account, so everything that means
 * "patient" (result alerts, an app build that knows one role) is unchanged;
 * also_doctor adds the doctor side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('also_doctor')->default(false);
        });

        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->text('doctor_token')->nullable();
            $table->unsignedInteger('doctor_auth_failures')->default(0);
            $table->string('doctor_holder_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lab_accounts', function (Blueprint $table) {
            $table->dropColumn(['doctor_token', 'doctor_auth_failures', 'doctor_holder_name']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('also_doctor');
        });
    }
};
