<?php

use App\Support\FfmpegBinaryLocator;

$ffmpegBinary = FfmpegBinaryLocator::resolve('ffmpeg', env('FFMPEG_BINARIES'));
$ffprobeBinary = FfmpegBinaryLocator::resolve('ffprobe', env('FFPROBE_BINARIES'));

return [
    'ffmpeg' => [
        'binaries' => $ffmpegBinary,
        'threads' => (int) env('FFMPEG_THREADS', 4),
    ],

    'ffprobe' => [
        'binaries' => $ffprobeBinary,
    ],

    'timeout' => (int) env('FFMPEG_TIMEOUT', 14400),

    'log_channel' => env('FFMPEG_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),

    'temporary_files_root' => env('FFMPEG_TEMPORARY_FILES_ROOT', storage_path('app/ffmpeg-temp')),

    'temporary_files_encrypted_hls' => env(
        'FFMPEG_TEMPORARY_ENCRYPTED_HLS',
        env('FFMPEG_TEMPORARY_FILES_ROOT', storage_path('app/ffmpeg-temp'))
    ),
];
