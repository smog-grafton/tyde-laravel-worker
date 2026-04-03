<?php

namespace Tests\Feature;

use App\Enums\MediaItemStatus;
use App\Enums\MediaOutputStatus;
use App\Enums\OutputType;
use App\Models\MediaItem;
use App\Models\TranscodePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_direct_output_urls_and_remote_fetch_metadata(): void
    {
        config()->set('ffmpeg-worker.ingest_token', 'secret-token');
        config()->set('filesystems.disks.media.url', 'https://worker.example/storage/media');
        config()->set('filesystems.disks.streaming.url', 'https://worker.example/storage/streaming');
        config()->set('filesystems.disks.thumbnails.url', 'https://worker.example/storage/thumbnails');

        $media = MediaItem::query()->create([
            'status' => MediaItemStatus::Completed,
            'title' => 'Demo Movie',
            'original_filename' => 'demo-movie.mkv',
            'source_type' => 'remote_fetch',
            'source_url' => 'https://downloads.example.com/movies/demo-movie.mkv',
            'source_host' => 'downloads.example.com',
            'source_disk' => 'telegram-intake',
            'source_path' => 'remote/2026/04/03/1-demo-movie.mkv',
            'source_mime_type' => 'video/x-matroska',
            'source_size_bytes' => 1_500_000_000,
            'fetch_status' => 'ready',
            'fetch_progress' => 100,
            'poster_disk' => 'thumbnails',
            'poster_path' => 'demo-movie-1/poster.jpg',
            'metadata' => [
                'remote_fetch' => [
                    'requested_url' => 'https://downloads.example.com/movies/demo-movie.mkv',
                    'effective_url' => 'https://cdn-origin.example.com/files/demo-movie.mkv',
                    'content_type' => 'video/x-matroska',
                    'content_length' => 1_500_000_000,
                    'supports_range_requests' => true,
                    'etag' => '"demo-etag"',
                ],
            ],
        ]);

        $mp4Preset = TranscodePreset::query()->where('slug', 'delivery-mp4')->firstOrFail();
        $hlsPreset = TranscodePreset::query()->where('slug', 'adaptive-hls')->firstOrFail();

        $media->outputs()->create([
            'transcode_preset_id' => $hlsPreset->id,
            'type' => OutputType::Hls,
            'label' => 'Adaptive HLS',
            'status' => MediaOutputStatus::Completed,
            'progress' => 100,
            'disk' => 'streaming',
            'path' => 'demo-movie-1/adaptive-hls/master.m3u8',
        ]);

        $media->outputs()->create([
            'transcode_preset_id' => $mp4Preset->id,
            'type' => OutputType::Mp4,
            'label' => 'Delivery MP4',
            'status' => MediaOutputStatus::Completed,
            'progress' => 100,
            'disk' => 'media',
            'path' => 'demo-movie-1/delivery-mp4.mp4',
        ]);

        $response = $this->withToken('secret-token')->getJson("/api/v1/media/{$media->uuid}");

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('recommended_urls.hls_playlist', 'https://worker.example/storage/streaming/demo-movie-1/adaptive-hls/master.m3u8')
            ->assertJsonPath('recommended_urls.mp4', 'https://worker.example/storage/media/demo-movie-1/delivery-mp4.mp4')
            ->assertJsonPath('poster_url', 'https://worker.example/storage/thumbnails/demo-movie-1/poster.jpg')
            ->assertJsonPath('source.remote_fetch.effective_url', 'https://cdn-origin.example.com/files/demo-movie.mkv')
            ->assertJsonPath('source.remote_fetch.supports_range_requests', true);
    }
}
