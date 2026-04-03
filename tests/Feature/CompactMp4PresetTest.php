<?php

namespace Tests\Feature;

use App\Models\TranscodePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompactMp4PresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_compact_mp4_500mb_preset(): void
    {
        $preset = TranscodePreset::query()
            ->where('slug', 'compact-mp4-500mb')
            ->first();

        $this->assertNotNull($preset);
        $this->assertSame('Compact MP4 500MB', $preset->name);
        $this->assertSame('mp4', $preset->output_type->value);
        $this->assertSame(500, $preset->max_size_mb);
        $this->assertSame(96, $preset->audio_bitrate_kbps);
        $this->assertSame(720, $preset->target_height);
        $this->assertTrue($preset->is_active);
    }
}
