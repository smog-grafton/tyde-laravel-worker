# Strategy 1: Same Server With Shared Intake Path

Use this when `telebot` and `ffmpeg-worker` run on the same machine or on containers that can share one writable volume. This is the best option for large Telegram files because telebot copies the file into the worker intake path and only sends metadata over HTTP.

## Flow

1. Telebot downloads the original Telegram file.
2. Telebot copies it into the worker intake path.
3. Telebot posts `source_path` to `/api/v1/media/telegram-intake`.
4. The Laravel queue probes, transcodes, and publishes MP4/HLS/poster outputs.

## Worker environment variables

Set these in the worker environment:

```env
APP_NAME=Narabox FFmpeg Worker
APP_ENV=production
APP_DEBUG=false
APP_URL=https://worker.example.com
APP_KEY=base64:replace_me

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ffmpeg-worker
DB_USERNAME=ffmpeg_worker
DB_PASSWORD=replace_me

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

FFMPEG_WORKER_PUBLIC_BASE_URL=https://worker.example.com
TELEGRAM_INGEST_TOKEN=replace_me
FFMPEG_WORKER_QUEUE=media-processing

FFMPEG_WORKER_INTAKE_DISK=telegram-intake
FFMPEG_WORKER_INTAKE_ROOT=/srv/narabox/ffmpeg-worker/intake
FFMPEG_WORKER_DELIVERY_DISK=media
FFMPEG_WORKER_DELIVERY_ROOT=/srv/narabox/ffmpeg-worker/storage/app/public/media
FFMPEG_WORKER_STREAMING_DISK=streaming
FFMPEG_WORKER_STREAMING_ROOT=/srv/narabox/ffmpeg-worker/storage/app/public/streaming
FFMPEG_WORKER_THUMBNAILS_DISK=thumbnails
FFMPEG_WORKER_THUMBNAILS_ROOT=/srv/narabox/ffmpeg-worker/storage/app/public/thumbnails

FFMPEG_BINARIES=
FFPROBE_BINARIES=
FFMPEG_TIMEOUT=14400
FFMPEG_THREADS=4
FFMPEG_TEMPORARY_FILES_ROOT=/srv/narabox/ffmpeg-worker/temp
FFMPEG_LOG_CHANNEL=stack

REMOTE_FETCH_CONNECT_TIMEOUT_SECONDS=60
REMOTE_FETCH_TIMEOUT_SECONDS=21600
REMOTE_FETCH_RETRY_TIMES=2
REMOTE_FETCH_RETRY_SLEEP_MS=1200
REMOTE_FETCH_MAX_REDIRECTS=5
REMOTE_FETCH_FORCE_IPV4_FALLBACK=true

VIDEO_PREP_MIN_SIZE_MB_FOR_TRANSCODE=50
VIDEO_PREP_TARGET_MAX_MB=1500
VIDEO_PREP_MAX_HEIGHT=720
VIDEO_PREP_CRF=24
VIDEO_PREP_PRESET=medium
VIDEO_PREP_TIMEOUT_SECONDS=14400
VIDEO_PREP_CAP_HEIGHT_LADDER=720,480,360
VIDEO_PREP_CAP_ATTEMPTS=3
VIDEO_PREP_MIN_VIDEO_BITRATE_KBPS=150
VIDEO_PREP_CAP_OVERHEAD_RATIO=0.97
FFPROBE_TIMEOUT_SECONDS=120
HLS_SEGMENT_LENGTH=6
HLS_KEY_FRAME_INTERVAL=48

ADMIN_NAME=Narabox Admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=replace_me
```

## Important notes

- `FFMPEG_WORKER_INTAKE_ROOT` must be the same physical path as telebot `CDN_SHARED_INTAKE_ROOT`.
- `FFMPEG_WORKER_PUBLIC_BASE_URL` should be the real public hostname or CDN hostname you want copied out of Filament and the API.
- Leave `FFMPEG_BINARIES` and `FFPROBE_BINARIES` blank unless you want to force a specific binary path. The worker auto-detects system or bundled binaries.
- Run the queue worker continuously. The web app alone is not enough.

## Minimum runtime checklist

- Run database migrations.
- Create the storage symlink with `php artisan storage:link`.
- Keep `php artisan queue:work --queue=media-processing --timeout=14400` running.
- Point telebot `CDN_UPLOAD_URL` to `https://worker.example.com/api/v1/media/telegram-intake`.
- Use the HLS playlist URL from Filament or `/api/v1/media/{uuid}` in your main portal.
