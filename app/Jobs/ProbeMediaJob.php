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

class ProbeMediaJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public function __construct(public readonly int $mediaItemId)
    {
        $this->timeout = (int) config('ffmpeg-worker.video_prep_timeout_seconds', 14400);
    }

    public function handle(MediaPipelineService $pipeline): void
    {
        $media = MediaItem::query()->findOrFail($this->mediaItemId);

        $pipeline->probeMedia($media);
    }

    public function failed(Throwable $exception): void
    {
        MediaItem::query()
            ->whereKey($this->mediaItemId)
            ->update([
                'status' => MediaItemStatus::Failed->value,
                'last_error' => $exception->getMessage(),
            ]);
    }
}
