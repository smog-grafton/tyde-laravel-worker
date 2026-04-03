<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type', 40)->default('telegram');
            $table->string('status', 40)->default('pending_probe');
            $table->string('title')->nullable();
            $table->string('slug')->nullable()->index();
            $table->string('original_filename');
            $table->string('source_disk', 100);
            $table->string('source_path');
            $table->string('source_extension', 20)->nullable();
            $table->string('source_mime_type', 100)->nullable();
            $table->unsignedBigInteger('source_size_bytes')->nullable();
            $table->string('poster_disk', 100)->nullable();
            $table->string('poster_path')->nullable();
            $table->unsignedSmallInteger('episode_number')->nullable();
            $table->string('vj_name')->nullable();
            $table->string('category')->nullable();
            $table->string('language')->nullable();
            $table->string('telegram_chat_id', 100)->nullable();
            $table->string('telegram_message_id', 100)->nullable();
            $table->string('telegram_channel')->nullable();
            $table->json('metadata')->nullable();
            $table->json('probe_data')->nullable();
            $table->json('decision_data')->nullable();
            $table->decimal('duration_seconds', 10, 2)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('video_codec', 50)->nullable();
            $table->string('audio_codec', 50)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['telegram_chat_id', 'telegram_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
