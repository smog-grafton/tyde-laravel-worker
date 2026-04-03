<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcode_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('output_type', 20)->default('mp4');
            $table->unsignedSmallInteger('target_height')->nullable();
            $table->unsignedInteger('video_bitrate_kbps')->nullable();
            $table->unsignedSmallInteger('audio_bitrate_kbps')->default(128);
            $table->unsignedTinyInteger('crf')->default(24);
            $table->string('ffmpeg_preset', 40)->default('medium');
            $table->unsignedInteger('max_size_mb')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::table('transcode_presets')->insert([
            [
                'name' => 'Delivery MP4',
                'slug' => 'delivery-mp4',
                'output_type' => 'mp4',
                'target_height' => 720,
                'video_bitrate_kbps' => null,
                'audio_bitrate_kbps' => 128,
                'crf' => 24,
                'ffmpeg_preset' => 'medium',
                'max_size_mb' => 1500,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 10,
                'notes' => 'Primary compressed MP4 output for Narabox delivery.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Adaptive HLS',
                'slug' => 'adaptive-hls',
                'output_type' => 'hls',
                'target_height' => 720,
                'video_bitrate_kbps' => 2200,
                'audio_bitrate_kbps' => 128,
                'crf' => 24,
                'ffmpeg_preset' => 'medium',
                'max_size_mb' => null,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 20,
                'notes' => 'Multi-bitrate HLS stream for browser playback.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('transcode_presets');
    }
};
