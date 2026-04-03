<?php

namespace App\Jobs;

use App\Enums\MediaItemStatus;
use App\Enums\MediaOutputStatus;
use App\Models\MediaOutput;
use App\Services\MediaPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMediaOutputJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public function __construct(public readonly int $mediaOutputId)
    {
        $this->timeout = (int) config('ffmpeg-worker.video_prep_timeout_seconds', 14400);
    }

    public function handle(MediaPipelineService $pipeline): void
    {
        $output = MediaOutput::query()->findOrFail($this->mediaOutputId);

        $pipeline->processOutput($output);
    }

    public function failed(Throwable $exception): void
    {
        $output = MediaOutput::query()->with('mediaItem')->find($this->mediaOutputId);

        if (!$output) {
            return;
        }

        $output->forceFill([
            'status' => MediaOutputStatus::Failed,
            'last_error' => $exception->getMessage(),
            'failed_at' => now(),
        ])->save();

        $output->mediaItem?->forceFill([
            'status' => MediaItemStatus::Failed,
            'last_error' => $exception->getMessage(),
        ])->save();
    }
}
