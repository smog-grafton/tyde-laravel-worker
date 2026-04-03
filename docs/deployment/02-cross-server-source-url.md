# Strategy 2: Cross-Server Pull Handoff With Signed Source URLs

Use this when `telebot` and `ffmpeg-worker` live on different servers and cannot share a filesystem. Telebot exposes a signed temporary file URL, and the worker fetches it through `source_url`.

## Flow

1. Telebot downloads the Telegram file locally.
2. Telebot creates a signed fetch URL with the real file extension.
3. Telebot posts that URL to the worker as `source_url`.
4. The worker downloads the file into intake storage, then probes and transcodes it.

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

- The worker must be able to reach telebot `TEMP_PUBLIC_URL` over the network.
- Keep `REMOTE_FETCH_TIMEOUT_SECONDS` high enough for slow Telegram-origin downloads.
- `TELEGRAM_INGEST_TOKEN` on the worker must match telebot `CDN_API_TOKEN`.
- `FFMPEG_WORKER_PUBLIC_BASE_URL` should be the hostname your portal and operators will really use.

## Recommended operations

- Use HTTPS on both apps.
- Keep queue workers running all the time.
- If you later put Bunny or another CDN in front of the worker, change `FFMPEG_WORKER_PUBLIC_BASE_URL` to the CDN hostname so copied HLS URLs are already CDN-ready.
- Watch Filament `Fetch Status`, `Status`, and `Last Error` to spot bad remote URLs early.
