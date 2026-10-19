<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facilities: the directory entry and, for those with one, the results API
 * configuration that used to live in the "Lab Results API" meta box.
 *
 * Ids are the WordPress post ids. The app sends lab_id back on lazy loads,
 * AI summaries and PDF links, and every per-lab user row is keyed on it.
 *
 * API fields are nullable where WordPress distinguished "never saved" from a
 * value: LabConfig applies the same defaults the plugin did, including its
 * quirks (a saved priority of 0 still means 10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->index();
            $table->longText('content')->nullable();
            // publish | draft | pending | private | trash. Only publish is
            // served to the app, as before.
            $table->string('status', 20)->default('publish')->index();
            $table->integer('menu_order')->default(0);

            // Directory fields, served by /labs.
            $table->text('image_url')->nullable();
            // The featured image, used when image_url is empty.
            $table->text('thumbnail_url')->nullable();
            $table->string('location')->nullable();
            $table->text('google_map_url')->nullable();
            // Strings, as stored: the API only emits them when numeric.
            $table->string('latitude', 32)->nullable();
            $table->string('longitude', 32)->nullable();
            $table->string('appointment_phone', 64)->nullable();
            $table->string('mobile_phone', 64)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('email')->nullable();
            $table->text('website')->nullable();
            $table->text('refer_link')->nullable();
            $table->text('refer_text')->nullable();
            $table->text('facebook')->nullable();
            $table->text('instagram')->nullable();
            $table->text('twitter')->nullable();
            $table->text('linkedin')->nullable();
            $table->text('youtube')->nullable();
            $table->text('tiktok')->nullable();
            // Ordered list of image URLs.
            $table->json('slides')->nullable();

            // Results API. Off means a directory-only lab.
            $table->boolean('api_enabled')->default(false)->index();
            $table->string('api_driver', 40)->nullable();
            $table->text('api_base')->nullable();
            $table->text('api_pdf_base')->nullable();
            // static_base | static_proxy | endpoint | none
            $table->string('api_pdf_mode', 20)->nullable();
            // "Key: Value" per line. Encrypted: it holds facility credentials
            // and must never reach the app.
            $table->text('api_headers')->nullable();
            $table->unsignedSmallInteger('api_timeout')->nullable();
            $table->unsignedSmallInteger('api_priority')->nullable();
            // lb_trunk_zero | national | e164
            $table->string('mobile_format', 20)->nullable();
            // status | report_datetime | none
            $table->string('ready_signal', 20)->nullable();

            // What the facility actually records. Absent was off in WordPress,
            // and it stays off: a result gauge drawn on data the lab never
            // sent tells a patient their result is normal.
            $table->boolean('caps_ranges')->default(false);
            $table->boolean('caps_flags')->default(false);
            $table->boolean('caps_pdf')->default(false);
            // Billed per facility. Off unless the lab opted in.
            $table->boolean('ai_summary')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labs');
    }
};
