<?php

namespace App\Models;

use App\Enums\MediaItemStatus;
use App\Enums\MediaOutputStatus;
use App\Enums\OutputType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'source_type',
        'status',
        'title',
        'slug',
        'original_filename',
        'source_disk',
        'source_path',
        'source_url',
        'source_host',
        'source_extension',
        'source_mime_type',
        'source_size_bytes',
        'fetch_status',
        'fetch_progress',
        'bytes_downloaded',
        'bytes_total',
        'fetch_started_at',
        'fetch_completed_at',
        'poster_disk',
        'poster_path',
        'episode_number',
        'vj_name',
        'category',
        'language',
        'telegram_chat_id',
        'telegram_message_id',
        'telegram_channel',
        'metadata',
        'probe_data',
        'decision_data',
        'duration_seconds',
        'width',
        'height',
        'video_codec',
        'audio_codec',
        'last_error',
        'imported_at',
        'queued_at',
        'processing_started_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MediaItemStatus::class,
            'metadata' => 'array',
            'probe_data' => 'array',
            'decision_data' => 'array',
            'source_size_bytes' => 'integer',
            'fetch_progress' => 'integer',
            'bytes_downloaded' => 'integer',
            'bytes_total' => 'integer',
            'duration_seconds' => 'float',
            'width' => 'integer',
            'height' => 'integer',
            'episode_number' => 'integer',
            'fetch_started_at' => 'datetime',
            'fetch_completed_at' => 'datetime',
            'imported_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $mediaItem): void {
            $mediaItem->uuid ??= (string) Str::uuid();
            $mediaItem->slug ??= Str::slug((string) ($mediaItem->title ?: pathinfo($mediaItem->original_filename, PATHINFO_FILENAME)));
        });
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(MediaOutput::class);
    }

    public function posterUrl(): ?string
    {
        if (!$this->poster_disk || !$this->poster_path) {
            return null;
        }

        try {
            return Storage::disk($this->poster_disk)->url($this->poster_path);
        } catch (\Throwable) {
            return null;
        }
    }

    public function remoteFetchMetadata(): ?array
    {
        $remoteFetch = $this->metadata['remote_fetch'] ?? null;

        return is_array($remoteFetch) ? $remoteFetch : null;
    }

    public function firstOutputForType(OutputType $type): ?MediaOutput
    {
        if ($this->relationLoaded('outputs')) {
            return $this->outputs
                ->first(static fn (MediaOutput $output): bool => ($output->type instanceof OutputType ? $output->type : OutputType::from((string) $output->type)) === $type);
        }

        return $this->outputs()
            ->where('type', $type->value)
            ->orderBy('id')
            ->first();
    }

    public function primaryOutputUrl(OutputType $type): ?string
    {
        return $this->firstOutputForType($type)?->publicUrl();
    }

    public function outputUrlMap(): array
    {
        $outputs = $this->relationLoaded('outputs')
            ? $this->outputs->sortBy('id')->values()
            : $this->outputs()->orderBy('id')->get();

        return $outputs
            ->map(static function (MediaOutput $output): array {
                return [
                    'label' => $output->label,
                    'type' => $output->type instanceof OutputType ? $output->type->value : (string) $output->type,
                    'status' => $output->status instanceof MediaOutputStatus ? $output->status->value : (string) $output->status,
                    'path' => $output->path,
                    'public_url' => $output->publicUrl(),
                    'size_bytes' => $output->size_bytes,
                    'width' => $output->width,
                    'height' => $output->height,
                    'video_codec' => $output->video_codec,
                    'audio_codec' => $output->audio_codec,
                ];
            })
            ->all();
    }

    public function syncAggregateStatus(): void
    {
        $statuses = $this->outputs()
            ->pluck('status')
            ->map(static fn (MediaOutputStatus|string $status): MediaOutputStatus => $status instanceof MediaOutputStatus ? $status : MediaOutputStatus::from($status));

        if ($statuses->isEmpty()) {
            return;
        }

        $nextStatus = match (true) {
            $statuses->contains(MediaOutputStatus::Processing) => MediaItemStatus::Processing,
            $statuses->contains(MediaOutputStatus::Queued) => MediaItemStatus::Queued,
            $statuses->every(static fn (MediaOutputStatus $status): bool => in_array($status, [MediaOutputStatus::Completed, MediaOutputStatus::Skipped], true)) => MediaItemStatus::Completed,
            $statuses->contains(MediaOutputStatus::Failed) => MediaItemStatus::Failed,
            default => MediaItemStatus::Queued,
        };

        $this->forceFill([
            'status' => $nextStatus,
            'processing_started_at' => $nextStatus === MediaItemStatus::Processing
                ? ($this->processing_started_at ?? now())
                : $this->processing_started_at,
            'processed_at' => $nextStatus === MediaItemStatus::Completed ? now() : $this->processed_at,
        ])->save();
    }
}
