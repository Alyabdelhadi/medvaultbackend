<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home-screen images, result categories, CMS pages, settings and the AI
 * summary cache: everything WordPress kept in wp_options and wp_posts.
 */
return new class extends Migration
{
    public function up(): void
    {
        // custom_slider_items. The API numbers them 1..n by position, so
        // position is what matters, not id.
        Schema::create('sliders', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0)->index();
            $table->text('image_url');
            $table->string('title')->nullable();
            $table->text('link_url')->nullable();
            $table->timestamps();
        });

        // custom_banner_items.
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0)->index();
            $table->text('image_url');
            $table->text('link_url')->nullable();
            $table->timestamps();
        });

        // medvault_result_categories: friendly filter labels for raw lab
        // cat_name values. Empty table means the built-in defaults.
        Schema::create('result_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('position')->default(0)->index();
            $table->string('label');
            $table->json('cat_names');
            $table->timestamps();
        });

        // The About (post 12) and FAQ (post 10) pages. content is the
        // rendered HTML the old /about and /faq endpoints returned.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('title');
            $table->longText('content');
            $table->text('excerpt')->nullable();
            $table->string('status', 20)->default('publish');
            $table->timestamps();
        });

        // Former wp_options: feature switches, poller settings and state,
        // Firebase credentials (encrypted by Setting::putSecret).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        // Former _transient_ai_summary_* options. Results never change, so a
        // summary is cached per (lab, order, patient) for 30 days; every
        // entry lost here is re-billed on its next open.
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key', 64)->unique();
            $table->json('data');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('result_categories');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('sliders');
    }
};
