<?php

namespace Tests\Feature;

use App\Jobs\FetchRemoteMediaJob;
use App\Jobs\ProbeMediaJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TelegramIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ingests_a_server_side_file_and_queues_probe_work(): void
    {
        Storage::fake('telegram-intake');
        Storage::disk('telegram-intake')->put('incoming/demo.mkv', 'video');

        config()->set('ffmpeg-worker.ingest_token', 'secret-token');

        Queue::fake();

        $response = $this->withToken('secret-token')->postJson('/api/v1/media/telegram-intake', [
            'source_disk' => 'telegram-intake',
            'source_path' => 'incoming/demo.mkv',
            'original_filename' => '28 YEARS LATER=1 VJ JOZZ UG.mkv',
            'telegram_chat_id' => '123',
            'telegram_message_id' => '456',
            'telegram_channel' => '@vjjozzchannel',
            'queue_outputs' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pending_probe');

        $this->assertDatabaseHas('media_items', [
            'telegram_chat_id' => '123',
            'telegram_message_id' => '456',
            'telegram_channel' => '@vjjozzchannel',
            'source_path' => 'incoming/demo.mkv',
        ]);

        Queue::assertPushed(ProbeMediaJob::class);
    }

    public function test_it_ingests_a_remote_url_and_queues_fetch_work(): void
    {
        config()->set('ffmpeg-worker.ingest_token', 'secret-token');

        Queue::fake();

        $response = $this->withToken('secret-token')->postJson('/api/v1/media/telegram-intake', [
            'source_url' => 'https://downloads.example.com/movies/demo.mkv',
            'original_filename' => 'DEMO MOVIE VJ JOZZ.mkv',
            'queue_outputs' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'queued');

        $this->assertDatabaseHas('media_items', [
            'source_type' => 'remote_fetch',
            'source_url' => 'https://downloads.example.com/movies/demo.mkv',
            'fetch_status' => 'queued',
        ]);

        Queue::assertPushed(FetchRemoteMediaJob::class);
    }

    public function test_it_extracts_the_real_filename_from_query_style_remote_urls(): void
    {
        config()->set('ffmpeg-worker.ingest_token', 'secret-token');

        Queue::fake();

        $response = $this->withToken('secret-token')->postJson('/api/v1/media/telegram-intake', [
            'source_url' => 'https://mobifliks.info/downloadmp4.php?file=luganda/Top%20Gun-%20Maverick%20by%20Vj%20Junior%20-%20Mobifliks.com.mp4',
            'queue_outputs' => true,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('media_items', [
            'source_type' => 'remote_fetch',
            'original_filename' => 'Top Gun- Maverick by Vj Junior - Mobifliks.com.mp4',
        ]);
    }
}
