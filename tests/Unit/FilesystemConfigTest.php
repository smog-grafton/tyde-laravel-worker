<?php

namespace Tests\Unit;

use Tests\TestCase;

class FilesystemConfigTest extends TestCase
{
    public function test_it_falls_back_to_default_local_roots_when_env_vars_are_blank(): void
    {
        putenv('FFMPEG_WORKER_INTAKE_ROOT=');
        putenv('FFMPEG_WORKER_DELIVERY_ROOT=');
        putenv('FFMPEG_WORKER_STREAMING_ROOT=');
        putenv('FFMPEG_WORKER_THUMBNAILS_ROOT=');

        $config = require base_path('config/filesystems.php');

        $this->assertSame(storage_path('app/telegram-intake'), $config['disks']['telegram-intake']['root']);
        $this->assertSame(storage_path('app/public/media'), $config['disks']['media']['root']);
        $this->assertSame(storage_path('app/public/streaming'), $config['disks']['streaming']['root']);
        $this->assertSame(storage_path('app/public/thumbnails'), $config['disks']['thumbnails']['root']);
    }
}
