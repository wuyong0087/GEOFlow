<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_video_watches', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('platform', 32);
            $table->string('collector', 32)->default('manual');
            $table->json('keywords');
            $table->json('entity_types');
            $table->json('lexicon')->nullable();
            $table->string('brand_name', 120);
            $table->string('brand_routes', 500)->nullable();
            $table->string('template_path', 500)->nullable();
            $table->string('status', 32)->default('active');
            $table->date('daily_imported_on')->nullable();
            $table->unsignedInteger('daily_imported_count')->default(0);
            $table->timestamp('last_imported_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['platform', 'status']);
        });

        Schema::create('competitor_videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competitor_video_watch_id')->nullable()->constrained('competitor_video_watches')->nullOnDelete();
            $table->string('platform', 32);
            $table->string('external_id', 120);
            $table->string('source_url', 2000);
            $table->string('author', 200)->nullable();
            $table->string('title', 500)->nullable();
            $table->text('caption')->nullable();
            $table->string('collector', 32)->default('manual');
            $table->string('status', 32);
            $table->string('storage_path', 500)->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('file_bytes')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->longText('vision_text')->nullable();
            $table->text('error_message')->nullable();
            $table->date('imported_on');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
            $table->index(['platform', 'imported_on']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('competitor_video_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competitor_video_id')->constrained('competitor_videos')->cascadeOnDelete();
            $table->string('platform_comment_id', 120);
            $table->string('parent_platform_comment_id', 120)->nullable();
            $table->string('author', 200)->nullable();
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->boolean('matched')->default(false);
            $table->timestamps();

            $table->unique(['competitor_video_id', 'platform_comment_id'], 'competitor_video_comment_unique');
        });

        Schema::create('competitor_video_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competitor_video_id')->constrained('competitor_videos')->cascadeOnDelete();
            $table->string('source', 16);
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('end_ms')->nullable();
            $table->text('text');
            $table->timestamps();

            $table->index(['competitor_video_id', 'source']);
        });

        Schema::create('competitor_video_extractions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competitor_video_id')->constrained('competitor_videos')->cascadeOnDelete();
            $table->foreignId('competitor_video_comment_id')->nullable()->constrained('competitor_video_comments')->nullOnDelete();
            $table->string('field', 32);
            $table->string('value', 500);
            $table->text('evidence');
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->string('review_status', 16)->default('pending');
            $table->timestamps();

            $table->index(['competitor_video_id', 'field', 'review_status'], 'competitor_video_extraction_review_index');
        });

        Schema::create('competitor_video_scripts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competitor_video_id')->constrained('competitor_videos')->cascadeOnDelete();
            $table->longText('voiceover');
            $table->json('shots');
            $table->text('on_screen_text');
            $table->string('cover_title', 200);
            $table->text('publish_caption');
            $table->boolean('too_similar')->default(false);
            $table->string('status', 32);
            $table->timestamps();
        });

        Schema::create('competitor_video_compositions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('competitor_video_script_id')->constrained('competitor_video_scripts')->cascadeOnDelete();
            $table->string('status', 32);
            $table->string('storage_path', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_video_compositions');
        Schema::dropIfExists('competitor_video_scripts');
        Schema::dropIfExists('competitor_video_extractions');
        Schema::dropIfExists('competitor_video_segments');
        Schema::dropIfExists('competitor_video_comments');
        Schema::dropIfExists('competitor_videos');
        Schema::dropIfExists('competitor_video_watches');
    }
};
