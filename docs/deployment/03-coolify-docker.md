# Strategy 3: Coolify And Docker Deployment

Use this when you want `ffmpeg-worker` deployed as a proper Docker image in Coolify. The repo now includes a production `Dockerfile` that installs FFmpeg in the container and can run as `web`, `queue`, or `scheduler` from the same image.

If telebot already runs in its own Coolify container, treat that as a separate filesystem by default. Use telebot `CDN_HANDOFF_MODE=source_url` unless you intentionally mount one shared intake volume into both services.

## What the image does

- Builds frontend assets with Vite
- Installs PHP 8.4, Nginx, FFmpeg, and required extensions
- Auto-detects system FFmpeg or bundled fallback binaries
- Serves static HLS/media files with CORS headers so another domain can play them
- Supports role-based startup through `APP_RUNTIME_ROLE`

## Create three Coolify services from the same repo

### 1. Web service

Use the repo `Dockerfile` with these environment variables:

```env
APP_NAME=Narabox FFmpeg Worker
APP_ENV=production
APP_DEBUG=false
APP_URL=https://worker.example.com
APP_KEY=base64:replace_me
APP_PORT=8080
APP_RUNTIME_ROLE=web
APP_CREATE_STORAGE_LINK=true
APP_RUN_MIGRATIONS=true
APP_RUN_DB_SEED=true

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=mysql
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
FFMPEG_WORKER_INTAKE_ROOT=/var/www/html/storage/app/telegram-intake
FFMPEG_WORKER_DELIVERY_DISK=media
FFMPEG_WORKER_DELIVERY_ROOT=/var/www/html/storage/app/public/media
FFMPEG_WORKER_STREAMING_DISK=streaming
FFMPEG_WORKER_STREAMING_ROOT=/var/www/html/storage/app/public/streaming
FFMPEG_WORKER_THUMBNAILS_DISK=thumbnails
FFMPEG_WORKER_THUMBNAILS_ROOT=/var/www/html/storage/app/public/thumbnails

FFMPEG_BINARIES=
FFPROBE_BINARIES=
FFMPEG_TIMEOUT=14400
FFMPEG_THREADS=4
FFMPEG_TEMPORARY_FILES_ROOT=/var/www/html/storage/app/ffmpeg-temp

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

Mount a persistent volume to `/var/www/html/storage`.

After the first successful deploy, turn `APP_RUN_MIGRATIONS=false` and `APP_RUN_DB_SEED=false` so every restart does not try to reseed.

### 2. Queue service

Create a second Coolify service from the same image and use the same env block, but change only these values:

```env
APP_RUNTIME_ROLE=queue
APP_RUN_MIGRATIONS=false
APP_RUN_DB_SEED=false
QUEUE_WORKER_SLEEP=3
QUEUE_WORKER_TRIES=1
QUEUE_WORKER_TIMEOUT=14400
```

Mount the same persistent volume to `/var/www/html/storage`.

### 3. Scheduler service

This is optional today, but it gives you a clean place for future scheduled cleanup or reporting tasks:

```env
APP_RUNTIME_ROLE=scheduler
APP_RUN_MIGRATIONS=false
APP_RUN_DB_SEED=false
```

Mount the same persistent volume to `/var/www/html/storage`.

## Important notes

- The container already installs FFmpeg, so you usually leave `FFMPEG_BINARIES` and `FFPROBE_BINARIES` blank.
- If you ever want bundled fallback binaries, mount them into `/var/www/html/ffmpeg/ffmpeg` and `/var/www/html/ffmpeg/ffprobe`. The worker auto-detects them.
- The web service health endpoint is `/up`.
- Static files under `/storage/` are served with CORS headers so HLS can be played from `portal.naraboxtv.com` or another origin.
