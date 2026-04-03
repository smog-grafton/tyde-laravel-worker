# Narabox FFmpeg Worker

A Laravel 12 + Filament media worker for Narabox. It accepts Telegram or remote-source handoffs, probes source media with `ffprobe`, generates delivery MP4/HLS/poster outputs with FFmpeg, and exposes direct playback URLs for your portal.

## What it does

- Accepts intake from telebot through `source_path` or `source_url`
- Downloads remote sources into a controlled intake pipeline
- Queues probe/transcode work instead of holding long HTTP requests open
- Generates direct MP4, HLS, and thumbnail outputs
- Exposes operators to the workflow through Filament admin at `/admin`
- Auto-detects FFmpeg/FFprobe from env overrides, bundled binaries, or system paths

## Runtime roles

The Docker image supports three roles through `APP_RUNTIME_ROLE`:

- `web`: Nginx + PHP-FPM, serves Filament and API
- `queue`: runs `php artisan queue:work`
- `scheduler`: runs `php artisan schedule:work`

That makes it easy to deploy one image multiple times in Coolify.

## Important environment variables

These are the worker-specific ones you will use most often:

- `APP_URL`
- `APP_KEY`
- `APP_RUNTIME_ROLE`
- `APP_PORT`
- `APP_RUN_MIGRATIONS`
- `APP_RUN_DB_SEED`
- `FFMPEG_WORKER_PUBLIC_BASE_URL`
- `TELEGRAM_INGEST_TOKEN`
- `FFMPEG_WORKER_QUEUE`
- `FFMPEG_WORKER_INTAKE_ROOT`
- `FFMPEG_WORKER_DELIVERY_ROOT`
- `FFMPEG_WORKER_STREAMING_ROOT`
- `FFMPEG_WORKER_THUMBNAILS_ROOT`
- `FFMPEG_BINARIES`
- `FFPROBE_BINARIES`

You will also need the normal Laravel database/session/cache settings for your chosen deployment strategy.

## Deployment guides

- [Same server with shared intake path](docs/deployment/01-same-server-path-copy.md)
- [Cross-server pull handoff with signed source URLs](docs/deployment/02-cross-server-source-url.md)
- [Coolify and Docker deployment](docs/deployment/03-coolify-docker.md)

## Local queue worker

```bash
/usr/local/bin/php artisan queue:work --queue=media-processing --timeout=14400
```

## Filament

After login, go to `/admin` and create or inspect media jobs there. Finished records expose direct HLS playlist, MP4, poster, and generated-output URLs.
