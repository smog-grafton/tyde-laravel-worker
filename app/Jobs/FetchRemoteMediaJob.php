<?php

namespace App\Jobs;

use App\Enums\MediaItemStatus;
use App\Models\MediaItem;
use App\Services\MediaPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class FetchRemoteMediaJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 7200;

    public function __construct(public readonly int $mediaItemId)
    {
    }

    public function handle(MediaPipelineService $pipeline): void
    {
        $media = MediaItem::query()->findOrFail($this->mediaItemId);

        $pipeline->fetchRemoteSource($media);
    }

    public function failed(Throwable $exception): void
    {
        MediaItem::query()
            ->whereKey($this->mediaItemId)
            ->update([
                'status' => MediaItemStatus::Failed->value,
                'fetch_status' => 'failed',
                'last_error' => $exception->getMessage(),
                'fetch_completed_at' => now(),
            ]);
    }
}
