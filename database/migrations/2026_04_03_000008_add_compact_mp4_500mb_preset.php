<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transcode_presets')->updateOrInsert(
            ['slug' => 'compact-mp4-500mb'],
            [
                'name' => 'Compact MP4 500MB',
                'output_type' => 'mp4',
                'target_height' => 720,
                'video_bitrate_kbps' => null,
                'audio_bitrate_kbps' => 96,
                'crf' => 25,
                'ffmpeg_preset' => 'medium',
                'max_size_mb' => 500,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 30,
                'notes' => 'Lightweight MP4 download preset. Good for mobile downloads and compact mirrors. The worker will step down to 480p or 360p if that is what it takes to stay near 500 MB.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('transcode_presets')
            ->where('slug', 'compact-mp4-500mb')
            ->delete();
    }
};
