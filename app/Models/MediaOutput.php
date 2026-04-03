<?php

namespace App\Models;

use App\Enums\MediaOutputStatus;
use App\Enums\OutputType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MediaOutput extends Model
{
    use HasFactory;

    protected $fillable = [
        'media_item_id',
        'transcode_preset_id',
        'type',
        'label',
        'status',
        'progress',
        'disk',
        'path',
        'size_bytes',
        'duration_seconds',
        'width',
        'height',
        'video_codec',
        'audio_codec',
        'metadata',
        'last_error',
        'queued_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => OutputType::class,
            'status' => MediaOutputStatus::class,
            'progress' => 'integer',
            'size_bytes' => 'integer',
            'duration_seconds' => 'float',
            'width' => 'integer',
            'height' => 'integer',
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(TranscodePreset::class, 'transcode_preset_id');
    }

    public function publicUrl(): ?string
    {
        if (!$this->disk || !$this->path) {
            return null;
        }

        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }
}
