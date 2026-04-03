<?php

namespace App\Models;

use App\Enums\OutputType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TranscodePreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'output_type',
        'target_height',
        'video_bitrate_kbps',
        'audio_bitrate_kbps',
        'crf',
        'ffmpeg_preset',
        'max_size_mb',
        'is_active',
        'is_default',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'output_type' => OutputType::class,
            'target_height' => 'integer',
            'video_bitrate_kbps' => 'integer',
            'audio_bitrate_kbps' => 'integer',
            'crf' => 'integer',
            'max_size_mb' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(MediaOutput::class);
    }
}
