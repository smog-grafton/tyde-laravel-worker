<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_outputs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transcode_preset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('label');
            $table->string('status', 40)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('disk', 100);
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->decimal('duration_seconds', 10, 2)->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('video_codec', 50)->nullable();
            $table->string('audio_codec', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'queued_at']);
            $table->unique(['media_item_id', 'transcode_preset_id', 'type'], 'media_outputs_unique_job');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_outputs');
    }
};
