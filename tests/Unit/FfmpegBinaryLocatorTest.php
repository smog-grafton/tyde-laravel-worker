<?php

namespace Tests\Unit;

use App\Support\FfmpegBinaryLocator;
use PHPUnit\Framework\TestCase;

class FfmpegBinaryLocatorTest extends TestCase
{
    public function test_it_prefers_an_explicit_override(): void
    {
        $resolved = FfmpegBinaryLocator::resolve('ffmpeg', '/custom/ffmpeg');

        $this->assertSame('/custom/ffmpeg', $resolved);
    }

    public function test_it_uses_a_bundled_binary_when_present(): void
    {
        $root = sys_get_temp_dir().'/ffmpeg-worker-test-'.uniqid();
        mkdir($root.'/ffmpeg', 0777, true);
        file_put_contents($root.'/ffmpeg/ffmpeg', "#!/bin/sh\nexit 0\n");
        chmod($root.'/ffmpeg/ffmpeg', 0755);

        $resolved = FfmpegBinaryLocator::resolve('ffmpeg', null, $root);

        $this->assertSame($root.'/ffmpeg/ffmpeg', $resolved);

        @unlink($root.'/ffmpeg/ffmpeg');
        @rmdir($root.'/ffmpeg');
        @rmdir($root);
    }

    public function test_it_falls_back_to_the_binary_name_when_no_candidate_exists(): void
    {
        $root = sys_get_temp_dir().'/ffmpeg-worker-test-missing-'.uniqid();
        mkdir($root, 0777, true);
        $binary = 'ffprobe-nonexistent-test-binary';

        $resolved = FfmpegBinaryLocator::resolve($binary, null, $root);

        $this->assertSame($binary, $resolved);

        @rmdir($root);
    }
}
