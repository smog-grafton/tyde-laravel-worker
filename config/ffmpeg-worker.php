<?php

return [
    'queue' => env('FFMPEG_WORKER_QUEUE', 'media-processing'),

    'ingest_token' => env('TELEGRAM_INGEST_TOKEN'),
    'public_base_url' => rtrim((string) env('FFMPEG_WORKER_PUBLIC_BASE_URL', env('APP_URL', 'http://localhost')), '/'),

    'intake_disk' => env('FFMPEG_WORKER_INTAKE_DISK', 'telegram-intake'),
    'delivery_disk' => env('FFMPEG_WORKER_DELIVERY_DISK', 'media'),
    'streaming_disk' => env('FFMPEG_WORKER_STREAMING_DISK', 'streaming'),
    'thumbnails_disk' => env('FFMPEG_WORKER_THUMBNAILS_DISK', 'thumbnails'),
    'remote_fetch' => [
        'connect_timeout_seconds' => (int) env('REMOTE_FETCH_CONNECT_TIMEOUT_SECONDS', 60),
        'timeout_seconds' => (int) env('REMOTE_FETCH_TIMEOUT_SECONDS', 21600),
        'retry_times' => (int) env('REMOTE_FETCH_RETRY_TIMES', 2),
        'retry_sleep_ms' => (int) env('REMOTE_FETCH_RETRY_SLEEP_MS', 1200),
        'max_redirects' => (int) env('REMOTE_FETCH_MAX_REDIRECTS', 5),
        'force_ipv4_fallback' => (bool) env('REMOTE_FETCH_FORCE_IPV4_FALLBACK', true),
    ],

    'video_prep_min_size_mb_for_transcode' => (int) env('VIDEO_PREP_MIN_SIZE_MB_FOR_TRANSCODE', 50),
    'video_prep_target_max_mb' => (int) env('VIDEO_PREP_TARGET_MAX_MB', 1500),
    'video_prep_max_height' => (int) env('VIDEO_PREP_MAX_HEIGHT', 720),
    'video_prep_crf' => (int) env('VIDEO_PREP_CRF', 24),
    'video_prep_timeout_seconds' => (int) env('VIDEO_PREP_TIMEOUT_SECONDS', 14400),
    'video_prep_preset' => env('VIDEO_PREP_PRESET', 'medium'),
    'video_prep_cap_height_ladder' => array_values(array_filter(array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('VIDEO_PREP_CAP_HEIGHT_LADDER', '720,480,360'))
    ))),
    'video_prep_cap_attempts' => (int) env('VIDEO_PREP_CAP_ATTEMPTS', 3),
    'video_prep_min_video_bitrate_kbps' => (int) env('VIDEO_PREP_MIN_VIDEO_BITRATE_KBPS', 150),
    'video_prep_cap_overhead_ratio' => (float) env('VIDEO_PREP_CAP_OVERHEAD_RATIO', 0.97),

    'probe_timeout_seconds' => (int) env('FFPROBE_TIMEOUT_SECONDS', 120),

    'hls_segment_length' => (int) env('HLS_SEGMENT_LENGTH', 6),
    'hls_key_frame_interval' => (int) env('HLS_KEY_FRAME_INTERVAL', 48),
    'hls_ladder' => [
        ['height' => 240, 'video_bitrate_kbps' => 600, 'audio_bitrate_kbps' => 96],
        ['height' => 360, 'video_bitrate_kbps' => 800, 'audio_bitrate_kbps' => 128],
        ['height' => 480, 'video_bitrate_kbps' => 1400, 'audio_bitrate_kbps' => 192],
        ['height' => 720, 'video_bitrate_kbps' => 2800, 'audio_bitrate_kbps' => 256],
        ['height' => 1080, 'video_bitrate_kbps' => 5000, 'audio_bitrate_kbps' => 256],
    ],
];
