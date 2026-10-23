<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The knowledgebase (owner 2026-10-23, docs/knowledgebase.md): categories and articles in the instance's language, with
 * optional translations; an article in one or more categories, with tags. Ids of WHMCS kept by the import (its URLs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->json('translations')->nullable();
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
        Schema::create('kb_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->longText('body');
            $table->json('tags')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->json('translations')->nullable();
            $table->unsignedInteger('whmcs_id')->nullable()->unique();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
        Schema::create('kb_article_category', function (Blueprint $table) {
            $table->foreignId('article_id')->constrained('kb_articles')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('kb_categories')->cascadeOnDelete();
            $table->primary(['article_id', 'category_id']);
        });
        // Imported and deleted here: a run of the import again never brings it back.
        Schema::create('kb_whmcs_tombstones', function (Blueprint $table) {
            $table->string('ref', 40)->primary();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_whmcs_tombstones');
        Schema::dropIfExists('kb_article_category');
        Schema::dropIfExists('kb_articles');
        Schema::dropIfExists('kb_categories');
    }
};
