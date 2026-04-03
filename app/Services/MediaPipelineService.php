<?php

namespace App\Services;

use App\Enums\MediaItemStatus;
use App\Enums\MediaOutputStatus;
use App\Enums\OutputType;
use App\Jobs\FetchRemoteMediaJob;
use App\Jobs\ProbeMediaJob;
use App\Jobs\ProcessMediaOutputJob;
use App\Models\MediaItem;
use App\Models\MediaOutput;
use App\Models\TranscodePreset;
use App\Support\TelegramFilenameParser;
use FFMpeg\Format\Video\X264;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ProtoneMedia\LaravelFFMpeg\Format\CopyFormat;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use RuntimeException;

class MediaPipelineService
{
    public function __construct(
        private readonly TelegramFilenameParser $filenameParser,
        private readonly MediaProbeService $probeService,
        private readonly TranscodeDecisionEngine $decisionEngine,
    ) {
    }

    public function ingest(array $payload, ?UploadedFile $file = null): MediaItem
    {
        $isRemoteFetch = !$file && blank($payload['source_path'] ?? null) && filled($payload['source_url'] ?? null);
        $sourceDisk = $isRemoteFetch ? null : (string) ($payload['source_disk'] ?? config('ffmpeg-worker.intake_disk'));
        $sourcePath = $isRemoteFetch ? null : $this->resolveSourcePath((string) $sourceDisk, $payload, $file);
        $sourceUrl = $isRemoteFetch ? trim((string) ($payload['source_url'] ?? '')) : null;
        $originalFilename = (string) ($payload['original_filename'] ?? ($sourcePath ? basename($sourcePath) : $this->resolveRemoteFilename($sourceUrl, null)));
        $parsed = $this->filenameParser->parse($originalFilename);
        $metadata = $this->normalizeMetadata($payload['metadata'] ?? null);
        $title = trim((string) ($payload['title'] ?? $parsed['title_guess'] ?? ''));
        $slug = Str::slug($title !== '' ? $title : pathinfo($originalFilename, PATHINFO_FILENAME));

        $media = DB::transaction(function () use ($payload, $sourceDisk, $sourcePath, $sourceUrl, $isRemoteFetch, $parsed, $metadata, $title, $slug, $originalFilename): MediaItem {
            $media = MediaItem::query()->create([
                'source_type' => (string) ($payload['source_type'] ?? ($isRemoteFetch ? 'remote_fetch' : 'telegram')),
                'status' => $isRemoteFetch ? MediaItemStatus::Queued : MediaItemStatus::PendingProbe,
                'title' => $title !== '' ? $title : null,
                'slug' => $slug !== '' ? $slug : null,
                'original_filename' => $originalFilename !== '' ? $originalFilename : $parsed['original_filename'],
                'source_disk' => $sourceDisk,
                'source_path' => $sourcePath,
                'source_url' => $sourceUrl,
                'source_host' => $sourceUrl ? (parse_url($sourceUrl, PHP_URL_HOST) ?: null) : null,
                'source_extension' => !empty($parsed['extension']) ? '.'.$parsed['extension'] : null,
                'source_mime_type' => $sourcePath ? (Storage::disk((string) $sourceDisk)->mimeType($sourcePath) ?: null) : null,
                'source_size_bytes' => $sourcePath ? (Storage::disk((string) $sourceDisk)->size($sourcePath) ?: null) : null,
                'fetch_status' => $isRemoteFetch ? 'queued' : 'not_applicable',
                'fetch_progress' => 0,
                'episode_number' => $payload['episode_number'] ?? $payload['episode'] ?? $parsed['episode_guess'],
                'vj_name' => $payload['vj_name'] ?? $payload['vj'] ?? $parsed['vj_guess'],
                'category' => $payload['category'] ?? null,
                'language' => $payload['language'] ?? null,
                'telegram_chat_id' => $payload['telegram_chat_id'] ?? null,
                'telegram_message_id' => $payload['telegram_message_id'] ?? null,
                'telegram_channel' => $payload['telegram_channel'] ?? null,
                'metadata' => $metadata,
                'imported_at' => now(),
                'queued_at' => now(),
            ]);

            $presets = $this->resolvePresets($payload['presets'] ?? null);

            foreach ($presets as $preset) {
                $media->outputs()->create([
                    'transcode_preset_id' => $preset->id,
                    'type' => $preset->output_type,
                    'label' => $preset->name,
                    'status' => MediaOutputStatus::Queued,
                    'progress' => 0,
                    'disk' => $this->diskForType($preset->output_type),
                    'queued_at' => now(),
                ]);
            }

            return $media;
        });

        if (($payload['queue_outputs'] ?? true) && $media->outputs()->exists()) {
            $this->queueMedia($media);
        }

        return $media->load('outputs.preset');
    }

    public function queueMedia(MediaItem $media): void
    {
        DB::transaction(function () use ($media): void {
            $media->outputs()
                ->where('status', '!=', MediaOutputStatus::Completed->value)
                ->update([
                    'status' => MediaOutputStatus::Queued->value,
                    'progress' => 0,
                    'last_error' => null,
                    'queued_at' => now(),
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                ]);

            $media->forceFill([
                'status' => $media->source_url && blank($media->source_path) ? MediaItemStatus::Queued : MediaItemStatus::PendingProbe,
                'last_error' => null,
                'queued_at' => now(),
                'processing_started_at' => null,
                'processed_at' => null,
                'fetch_status' => $media->source_url && blank($media->source_path) ? 'queued' : $media->fetch_status,
                'fetch_progress' => $media->source_url && blank($media->source_path) ? 0 : $media->fetch_progress,
                'fetch_started_at' => $media->source_url && blank($media->source_path) ? null : $media->fetch_started_at,
                'fetch_completed_at' => $media->source_url && blank($media->source_path) ? null : $media->fetch_completed_at,
            ])->save();
        });

        if ($media->source_url && blank($media->source_path)) {
            FetchRemoteMediaJob::dispatch($media->id)->onQueue((string) config('ffmpeg-worker.queue'));

            return;
        }

        ProbeMediaJob::dispatch($media->id)->onQueue((string) config('ffmpeg-worker.queue'));
    }

    public function queueOutput(MediaOutput $output): void
    {
        $output->forceFill([
            'status' => MediaOutputStatus::Queued,
            'progress' => 0,
            'last_error' => null,
            'queued_at' => now(),
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ])->save();

        $output->mediaItem->forceFill([
            'status' => MediaItemStatus::Queued,
            'last_error' => null,
            'queued_at' => now(),
        ])->save();

        ProcessMediaOutputJob::dispatch($output->id)->onQueue((string) config('ffmpeg-worker.queue'));
    }

    public function probeMedia(MediaItem $media): void
    {
        $media->refresh();

        if (!Storage::disk($media->source_disk)->exists($media->source_path)) {
            throw new RuntimeException("Source file is missing on disk [{$media->source_disk}]: {$media->source_path}");
        }

        $probe = $this->probeService->probe($media->source_disk, $media->source_path);
        $decision = $this->decisionEngine->analyze($probe);

        $media->forceFill([
            'status' => MediaItemStatus::Queued,
            'last_error' => null,
            'source_size_bytes' => $probe['size_bytes'] ?? $media->source_size_bytes,
            'source_extension' => $probe['extension'] ?? $media->source_extension,
            'duration_seconds' => $probe['duration_seconds'] ?? null,
            'width' => $probe['video']['width'] ?? null,
            'height' => $probe['video']['height'] ?? null,
            'video_codec' => $probe['video']['codec'] ?? null,
            'audio_codec' => $probe['audio']['codec'] ?? null,
            'probe_data' => $probe,
            'decision_data' => $decision,
            'fetch_status' => $media->source_url ? 'ready' : $media->fetch_status,
            'fetch_progress' => $media->source_url ? 100 : $media->fetch_progress,
        ])->save();

        $this->generatePoster($media, $probe);

        $media->outputs()
            ->where('status', MediaOutputStatus::Queued->value)
            ->pluck('id')
            ->each(function (int $outputId): void {
                ProcessMediaOutputJob::dispatch($outputId)->onQueue((string) config('ffmpeg-worker.queue'));
            });
    }

    public function fetchRemoteSource(MediaItem $media): void
    {
        $media->refresh();

        $sourceUrl = trim((string) $media->source_url);

        if ($sourceUrl === '') {
            throw new RuntimeException("Media item {$media->id} has no source_url.");
        }

        $sourceDisk = (string) config('ffmpeg-worker.intake_disk');
        $relativeDirectory = sprintf('remote/%s', now()->format('Y/m/d'));
        $partialRelativePath = "{$relativeDirectory}/{$media->id}.download.part";
        $partialAbsolutePath = Storage::disk($sourceDisk)->path($partialRelativePath);

        Storage::disk($sourceDisk)->makeDirectory($relativeDirectory);

        $media->forceFill([
            'status' => MediaItemStatus::Processing,
            'fetch_status' => 'downloading',
            'fetch_progress' => 0,
            'bytes_downloaded' => null,
            'bytes_total' => null,
            'fetch_started_at' => now(),
            'fetch_completed_at' => null,
            'last_error' => null,
        ])->save();

        $headDetails = [
            'content_length' => null,
            'content_type' => null,
            'content_disposition' => null,
            'accept_ranges' => null,
            'etag' => null,
            'last_modified' => null,
        ];

        try {
            $headResponse = $this->remoteRequest()->head($sourceUrl);

            if ($headResponse->successful()) {
                $headDetails = [
                    'content_length' => $this->numericHeader($headResponse, 'Content-Length'),
                    'content_type' => $this->normalizedHeader($headResponse, 'Content-Type'),
                    'content_disposition' => $this->normalizedHeader($headResponse, 'Content-Disposition'),
                    'accept_ranges' => $this->normalizedHeader($headResponse, 'Accept-Ranges'),
                    'etag' => $this->normalizedHeader($headResponse, 'ETag'),
                    'last_modified' => $this->normalizedHeader($headResponse, 'Last-Modified'),
                ];
            }
        } catch (\Throwable) {
            $headDetails = [
                'content_length' => null,
                'content_type' => null,
                'content_disposition' => null,
                'accept_ranges' => null,
                'etag' => null,
                'last_modified' => null,
            ];
        }

        $headContentLength = $headDetails['content_length'];

        $lastPersistedBytes = 0;
        $lastPersistedPercent = 0;

        try {
            $attempts = $this->remoteDownloadAttempts();
            $response = null;

            foreach ($attempts as $attemptIndex => $attempt) {
                try {
                    $response = $this->remoteRequest()
                        ->withOptions(array_merge([
                            'sink' => $partialAbsolutePath,
                            'progress' => function (int $downloadTotal, int $downloadedBytes) use (&$headContentLength, &$lastPersistedBytes, &$lastPersistedPercent, $media): void {
                                $total = $downloadTotal > 0 ? $downloadTotal : $headContentLength;
                                $downloaded = max(0, $downloadedBytes);

                                if ($downloaded < $lastPersistedBytes) {
                                    $lastPersistedBytes = 0;
                                    $lastPersistedPercent = 0;
                                }

                                $percent = $total && $total > 0
                                    ? (int) floor(($downloaded / $total) * 100)
                                    : 0;
                                $percent = max(0, min(99, $percent));

                                if ($percent < $lastPersistedPercent) {
                                    $percent = $lastPersistedPercent;
                                }

                                $shouldPersist =
                                    ($downloaded - $lastPersistedBytes) >= (1024 * 1024) ||
                                    ($percent - $lastPersistedPercent) >= 2;

                                if (!$shouldPersist) {
                                    return;
                                }

                                $lastPersistedBytes = $downloaded;
                                $lastPersistedPercent = $percent;

                                $media->forceFill([
                                    'fetch_status' => 'downloading',
                                    'fetch_progress' => $percent,
                                    'bytes_downloaded' => $downloaded > 0 ? $downloaded : null,
                                    'bytes_total' => $total ?: null,
                                ])->save();
                            },
                        ], $attempt))
                        ->get($sourceUrl);

                    $response->throw();

                    break;
                } catch (\Throwable $exception) {
                    if (is_file($partialAbsolutePath)) {
                        @unlink($partialAbsolutePath);
                    }

                    if ($attemptIndex < count($attempts) - 1) {
                        continue;
                    }

                    throw $exception;
                }
            }

            if (!$response instanceof Response) {
                throw new RuntimeException('Remote download did not return a response.');
            }

            if (!is_file($partialAbsolutePath) || filesize($partialAbsolutePath) <= 0) {
                throw new RuntimeException('Downloaded file is empty.');
            }

            $effectiveUrl = $response->effectiveUri() ? (string) $response->effectiveUri() : $sourceUrl;
            $responseContentType = $this->normalizedHeader($response, 'Content-Type') ?: $headDetails['content_type'];
            $contentDisposition = $this->normalizedHeader($response, 'Content-Disposition') ?: $headDetails['content_disposition'];
            $resolvedFilename = $this->resolveRemoteFilename(
                $effectiveUrl,
                $media->id,
                $this->contentDispositionFilename($contentDisposition) ?: $media->original_filename,
                $responseContentType ?: $mimeType,
            );
            $relativePath = sprintf('%s/%d-%s', $relativeDirectory, $media->id, $resolvedFilename);
            $size = (int) filesize($partialAbsolutePath);
            $mimeType = @mime_content_type($partialAbsolutePath) ?: ($responseContentType ?: 'application/octet-stream');
            $preferredExtension = $this->extensionFromMimeType($mimeType);
            $currentExtension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
            $resolvedParse = $this->filenameParser->parse($resolvedFilename);
            $currentOriginalExtension = strtolower((string) pathinfo((string) $media->original_filename, PATHINFO_EXTENSION));
            $shouldRefreshIdentity = !$this->isVideoFilename((string) $media->original_filename)
                || $currentOriginalExtension === 'php';
            $resolvedTitle = trim((string) ($resolvedParse['title_guess'] ?? '')) ?: null;

            if ($preferredExtension && $currentExtension === '') {
                $relativePath .= '.'.$preferredExtension;
            }

            if (Storage::disk($sourceDisk)->exists($relativePath)) {
                Storage::disk($sourceDisk)->delete($relativePath);
            }

            Storage::disk($sourceDisk)->move($partialRelativePath, $relativePath);

            $remoteFetchMetadata = [
                'requested_url' => $sourceUrl,
                'effective_url' => $effectiveUrl,
                'requested_host' => parse_url($sourceUrl, PHP_URL_HOST) ?: null,
                'effective_host' => parse_url($effectiveUrl, PHP_URL_HOST) ?: null,
                'content_type' => $responseContentType ?: $mimeType,
                'content_length' => $this->numericHeader($response, 'Content-Length') ?: $headDetails['content_length'] ?: $size,
                'accept_ranges' => $this->normalizedHeader($response, 'Accept-Ranges') ?: $headDetails['accept_ranges'],
                'etag' => $this->normalizedHeader($response, 'ETag') ?: $headDetails['etag'],
                'last_modified' => $this->normalizedHeader($response, 'Last-Modified') ?: $headDetails['last_modified'],
                'content_disposition' => $contentDisposition,
                'redirect_history' => $this->redirectHistory($response),
                'supports_range_requests' => strtolower((string) ($this->normalizedHeader($response, 'Accept-Ranges') ?: $headDetails['accept_ranges'])) === 'bytes',
            ];

            $media->forceFill([
                'title' => $shouldRefreshIdentity ? $resolvedTitle : $media->title,
                'slug' => $shouldRefreshIdentity
                    ? Str::slug((string) (($resolvedTitle ?: pathinfo($resolvedFilename, PATHINFO_FILENAME)) ?: 'media'))
                    : $media->slug,
                'original_filename' => $shouldRefreshIdentity ? $resolvedFilename : $media->original_filename,
                'source_disk' => $sourceDisk,
                'source_path' => $relativePath,
                'source_host' => parse_url($effectiveUrl, PHP_URL_HOST) ?: (parse_url($sourceUrl, PHP_URL_HOST) ?: null),
                'source_mime_type' => $mimeType,
                'source_size_bytes' => $size > 0 ? $size : null,
                'source_extension' => '.'.strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION)),
                'fetch_status' => 'ready',
                'fetch_progress' => 100,
                'bytes_downloaded' => $size > 0 ? $size : null,
                'bytes_total' => $size > 0 ? $size : ($headContentLength ?: $size),
                'metadata' => array_merge($media->metadata ?? [], [
                    'remote_fetch' => $remoteFetchMetadata,
                ]),
                'fetch_completed_at' => now(),
                'status' => MediaItemStatus::PendingProbe,
                'last_error' => null,
            ])->save();

            ProbeMediaJob::dispatch($media->id)->onQueue((string) config('ffmpeg-worker.queue'));
        } catch (\Throwable $exception) {
            if (is_file($partialAbsolutePath)) {
                @unlink($partialAbsolutePath);
            }

            $media->forceFill([
                'status' => MediaItemStatus::Failed,
                'fetch_status' => 'failed',
                'last_error' => $exception->getMessage(),
                'fetch_completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function processOutput(MediaOutput $output): void
    {
        $output->loadMissing(['mediaItem', 'preset']);
        $media = $output->mediaItem;
        $preset = $output->preset;

        if (!$preset) {
            throw new RuntimeException("Output {$output->id} is missing its preset.");
        }

        $probe = $media->probe_data ?: $this->probeService->probe($media->source_disk, $media->source_path);
        $decision = $this->decisionEngine->analyze($probe, $preset);
        $baseDirectory = $this->baseDirectory($media);

        $output->forceFill([
            'status' => MediaOutputStatus::Processing,
            'progress' => 5,
            'last_error' => null,
            'started_at' => now(),
        ])->save();

        $media->forceFill([
            'status' => MediaItemStatus::Processing,
            'last_error' => null,
            'processing_started_at' => $media->processing_started_at ?? now(),
        ])->save();

        if ($preset->output_type === OutputType::Hls) {
            $path = "{$baseDirectory}/{$preset->slug}/master.m3u8";
            $this->exportHls($media, $output, $preset, $path);
            $directory = dirname($path);
            $output->forceFill([
                'path' => $path,
                'size_bytes' => $this->directorySize($output->disk, $directory),
                'duration_seconds' => $probe['duration_seconds'] ?? null,
                'width' => $probe['video']['width'] ?? null,
                'height' => min((int) ($probe['video']['height'] ?? 0), (int) ($preset->target_height ?: 0)) ?: ($probe['video']['height'] ?? null),
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
                'metadata' => [
                    'decision' => $decision,
                    'manifest_url' => Storage::disk($output->disk)->url($path),
                    'directory' => $directory,
                ],
            ])->save();
        } else {
            $path = "{$baseDirectory}/{$preset->slug}.mp4";
            $this->cleanupExistingOutput($output->disk, $path);

            if ($decision['should_copy'] ?? false) {
                $this->copyAsDelivery($media, $output, $path);
            } elseif (($decision['mode'] ?? null) === 'cap') {
                $this->transcodeWithCap($media, $output, $preset, $probe, $path);
            } else {
                $this->transcodeAsMp4(
                    $media,
                    $output,
                    $preset,
                    $path,
                    $decision['target_height'] ?? null,
                    null,
                    $preset->audio_bitrate_kbps,
                    true,
                );
            }

            $outputProbe = $this->probeService->probe($output->disk, $path);

            $output->forceFill([
                'path' => $path,
                'size_bytes' => $outputProbe['size_bytes'] ?? null,
                'duration_seconds' => $outputProbe['duration_seconds'] ?? null,
                'width' => $outputProbe['video']['width'] ?? null,
                'height' => $outputProbe['video']['height'] ?? null,
                'video_codec' => $outputProbe['video']['codec'] ?? null,
                'audio_codec' => $outputProbe['audio']['codec'] ?? null,
                'metadata' => [
                    'decision' => $decision,
                    'public_url' => Storage::disk($output->disk)->url($path),
                ],
            ])->save();
        }

        $output->forceFill([
            'status' => MediaOutputStatus::Completed,
            'progress' => 100,
            'last_error' => null,
            'completed_at' => now(),
        ])->save();

        $media->refresh()->syncAggregateStatus();
    }

    private function resolveSourcePath(string $disk, array $payload, ?UploadedFile $file): string
    {
        if ($file instanceof UploadedFile) {
            $name = $this->filenameParser->sanitize($file->getClientOriginalName());
            $fileName = Str::ulid()->toBase32().'-'.$name;

            return (string) $file->storeAs(now()->format('Y/m/d'), $fileName, $disk);
        }

        $path = ltrim((string) ($payload['source_path'] ?? ''), '/');

        if ($path === '') {
            throw ValidationException::withMessages([
                'source_path' => 'A source_path is required when no file upload is provided.',
            ]);
        }

        if (!Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages([
                'source_path' => "The file [{$path}] was not found on disk [{$disk}].",
            ]);
        }

        return $path;
    }

    private function resolvePresets(mixed $presetIds): \Illuminate\Support\Collection
    {
        $query = TranscodePreset::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if (is_array($presetIds) && $presetIds !== []) {
            $query->whereIn('id', array_map('intval', $presetIds));
        }

        return $query->get();
    }

    private function normalizeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && trim($metadata) !== '') {
            $decoded = json_decode($metadata, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function resolveRemoteFilename(string $sourceUrl, ?int $mediaId = null, ?string $preferred = null, ?string $contentType = null): string
    {
        $fallbackExtension = $this->extensionFromMimeType($contentType) ?? 'mp4';
        $preferredCandidate = $this->sanitizeFilenameCandidate($preferred);

        if ($this->isVideoFilename($preferredCandidate)) {
            return $preferredCandidate;
        }

        $urlPath = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $pathCandidate = $this->sanitizeFilenameCandidate(basename($urlPath));

        if ($this->isVideoFilename($pathCandidate)) {
            return $pathCandidate;
        }

        $queryCandidate = $this->queryFilenameCandidate($sourceUrl);

        if ($this->isVideoFilename($queryCandidate)) {
            return $queryCandidate;
        }

        if ($preferredCandidate !== null) {
            return $this->attachFallbackExtension($preferredCandidate, $fallbackExtension, $mediaId);
        }

        if ($pathCandidate !== null) {
            return $this->attachFallbackExtension($pathCandidate, $fallbackExtension, $mediaId);
        }

        return sprintf('remote-source-%s.%s', $mediaId ?: Str::ulid()->toBase32(), $fallbackExtension);
    }

    private function queryFilenameCandidate(string $sourceUrl): ?string
    {
        $query = (string) parse_url($sourceUrl, PHP_URL_QUERY);

        if ($query === '') {
            return null;
        }

        parse_str($query, $queryParams);

        foreach (['file', 'filename', 'name', 'title', 'download', 'url', 'path'] as $key) {
            $candidateValue = $queryParams[$key] ?? null;

            if (!is_string($candidateValue) || trim($candidateValue) === '') {
                continue;
            }

            $candidate = $this->sanitizeFilenameCandidate(basename($candidateValue));

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function sanitizeFilenameCandidate(?string $filename): ?string
    {
        if (!is_string($filename) || trim($filename) === '' || $filename === '/') {
            return null;
        }

        return $this->filenameParser->sanitize(urldecode($filename));
    }

    private function isVideoFilename(?string $filename): bool
    {
        if (!is_string($filename) || trim($filename) === '') {
            return false;
        }

        return in_array(
            strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)),
            ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'mpeg', 'mpg', 'ts'],
            true,
        );
    }

    private function attachFallbackExtension(string $candidate, string $fallbackExtension, ?int $mediaId): string
    {
        $base = trim((string) pathinfo($candidate, PATHINFO_FILENAME));

        if ($base === '') {
            $base = sprintf('remote-source-%s', $mediaId ?: Str::ulid()->toBase32());
        }

        return $this->filenameParser->sanitize($base.'.'.$fallbackExtension);
    }

    private function remoteRequest(): PendingRequest
    {
        $remoteFetch = config('ffmpeg-worker.remote_fetch', []);

        return Http::connectTimeout((int) ($remoteFetch['connect_timeout_seconds'] ?? 60))
            ->timeout((int) ($remoteFetch['timeout_seconds'] ?? 21600))
            ->retry(
                (int) ($remoteFetch['retry_times'] ?? 2),
                (int) ($remoteFetch['retry_sleep_ms'] ?? 1200)
            )
            ->withHeaders([
                'User-Agent' => 'NaraboxWorker/1.0',
                'Accept' => '*/*',
            ])
            ->withOptions([
                'allow_redirects' => [
                    'max' => (int) ($remoteFetch['max_redirects'] ?? 5),
                    'track_redirects' => true,
                    'referer' => true,
                ],
            ]);
    }

    private function remoteDownloadAttempts(): array
    {
        $attempts = [[]];

        if ((bool) data_get(config('ffmpeg-worker.remote_fetch', []), 'force_ipv4_fallback', true)) {
            $attempts[] = ['force_ip_resolve' => 'v4'];
        }

        return $attempts;
    }

    private function numericHeader(Response $response, string $header): ?int
    {
        $value = $response->header($header);

        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function normalizedHeader(Response $response, string $header): ?string
    {
        $value = $response->header($header);

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (strtolower($header) === 'content-type') {
            return trim(strtolower(explode(';', $value)[0]));
        }

        return trim($value);
    }

    private function redirectHistory(Response $response): array
    {
        $history = $response->header('X-Guzzle-Redirect-History');

        if (!is_string($history) || trim($history) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $url): string => trim($url),
            explode(',', $history)
        )));
    }

    private function contentDispositionFilename(?string $contentDisposition): ?string
    {
        if (!is_string($contentDisposition) || trim($contentDisposition) === '') {
            return null;
        }

        if (preg_match("/filename\\*=UTF-8''([^;]+)/i", $contentDisposition, $matches) === 1) {
            return $this->filenameParser->sanitize(urldecode($matches[1]));
        }

        if (preg_match('/filename="?([^";]+)"?/i', $contentDisposition, $matches) === 1) {
            return $this->filenameParser->sanitize($matches[1]);
        }

        return null;
    }

    private function extensionFromMimeType(?string $mimeType): ?string
    {
        if (!is_string($mimeType) || trim($mimeType) === '') {
            return null;
        }

        return match (trim(strtolower(explode(';', $mimeType)[0]))) {
            'video/mp4' => 'mp4',
            'video/x-m4v' => 'm4v',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-msvideo' => 'avi',
            'video/x-matroska' => 'mkv',
            'video/mpeg' => 'mpeg',
            'video/mp2t' => 'ts',
            default => null,
        };
    }

    private function generatePoster(MediaItem $media, array $probe): void
    {
        try {
            $seconds = max(1, (int) round(min(10, max(2.0, ((float) ($probe['duration_seconds'] ?? 10.0)) / 3))));
            $path = $this->baseDirectory($media).'/poster.jpg';
            $disk = (string) config('ffmpeg-worker.thumbnails_disk');

            FFMpeg::fromDisk($media->source_disk)
                ->open($media->source_path)
                ->getFrameFromSeconds($seconds)
                ->export()
                ->toDisk($disk)
                ->save($path);

            $media->forceFill([
                'poster_disk' => $disk,
                'poster_path' => $path,
            ])->save();
        } catch (\Throwable $exception) {
            Log::warning('Poster generation failed for media item '.$media->id, [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function copyAsDelivery(MediaItem $media, MediaOutput $output, string $path): void
    {
        $output->forceFill(['progress' => 25])->save();

        FFMpeg::fromDisk($media->source_disk)
            ->open($media->source_path)
            ->export()
            ->toDisk($output->disk)
            ->inFormat(new CopyFormat())
            ->save($path);

        $output->forceFill(['progress' => 95])->save();
    }

    private function transcodeWithCap(MediaItem $media, MediaOutput $output, TranscodePreset $preset, array $probe, string $path): void
    {
        $attempts = $this->decisionEngine->buildCapAttempts($probe, $preset);
        $targetMaxBytes = (int) (($preset->max_size_mb ?: config('ffmpeg-worker.video_prep_target_max_mb')) * 1024 * 1024);

        if ($attempts === []) {
            throw new RuntimeException('Unable to build cap attempts because duration or max size is missing.');
        }

        foreach ($attempts as $index => $attempt) {
            $this->cleanupExistingOutput($output->disk, $path);
            $this->transcodeAsMp4(
                $media,
                $output,
                $preset,
                $path,
                $attempt['target_height'] ?? null,
                $attempt['video_bitrate_kbps'] ?? null,
                $attempt['audio_bitrate_kbps'] ?? null,
                false,
            );

            $outputProbe = $this->probeService->probe($output->disk, $path);

            if ((int) ($outputProbe['size_bytes'] ?? 0) <= $targetMaxBytes) {
                return;
            }

            $output->forceFill([
                'progress' => min(95, 55 + (($index + 1) * 10)),
                'metadata' => array_merge($output->metadata ?? [], [
                    'cap_attempt' => $index + 1,
                    'cap_attempt_size_bytes' => $outputProbe['size_bytes'] ?? null,
                ]),
            ])->save();
        }

        throw new RuntimeException("Compressed output for {$media->title} is still above the configured size cap.");
    }

    private function transcodeAsMp4(
        MediaItem $media,
        MediaOutput $output,
        TranscodePreset $preset,
        string $path,
        ?int $targetHeight,
        ?int $videoBitrateKbps,
        ?int $audioBitrateKbps,
        bool $useCrf,
    ): void {
        $format = new X264('aac');
        $format->setAudioKiloBitrate((int) ($audioBitrateKbps ?: $preset->audio_bitrate_kbps ?: 128));

        if (!$useCrf && $videoBitrateKbps) {
            $format->setKiloBitrate($videoBitrateKbps);
        }

        $additionalParameters = ['-preset', $preset->ffmpeg_preset, '-movflags', '+faststart'];
        if ($useCrf) {
            $additionalParameters = [...$additionalParameters, '-crf', (string) $preset->crf];
        }
        $format->setAdditionalParameters($additionalParameters);

        $exporter = FFMpeg::fromDisk($media->source_disk)
            ->open($media->source_path)
            ->export()
            ->onProgress(function (int $percentage) use ($output): void {
                $output->forceFill([
                    'progress' => max(10, min(96, $percentage)),
                ])->save();
            })
            ->toDisk($output->disk)
            ->inFormat($format);

        if ($targetHeight) {
            $exporter->addFilter("scale=-2:{$targetHeight}");
        }

        $exporter->save($path);
    }

    private function exportHls(MediaItem $media, MediaOutput $output, TranscodePreset $preset, string $path): void
    {
        $ladder = collect(config('ffmpeg-worker.hls_ladder', []))
            ->filter(static function (array $variant) use ($media): bool {
                $sourceHeight = (int) ($media->height ?? 0);

                return $sourceHeight === 0 || (int) ($variant['height'] ?? 0) <= $sourceHeight;
            })
            ->values();

        if ($ladder->isEmpty()) {
            throw new RuntimeException('No HLS ladder variants are configured.');
        }

        $exporter = FFMpeg::fromDisk($media->source_disk)
            ->open($media->source_path)
            ->exportForHLS()
            ->setSegmentLength((int) config('ffmpeg-worker.hls_segment_length', 6))
            ->setKeyFrameInterval((int) config('ffmpeg-worker.hls_key_frame_interval', 48))
            ->onProgress(function (int $percentage) use ($output): void {
                $output->forceFill([
                    'progress' => max(10, min(96, $percentage)),
                ])->save();
            })
            ->toDisk($output->disk);

        $ladder->each(function (array $variant) use ($exporter, $preset): void {
            $height = (int) ($variant['height'] ?? 0);
            $format = new X264('aac');
            $format->setKiloBitrate((int) ($variant['video_bitrate_kbps'] ?? $preset->video_bitrate_kbps ?: 1200));
            $format->setAudioKiloBitrate((int) ($variant['audio_bitrate_kbps'] ?? $preset->audio_bitrate_kbps ?: 128));
            $format->setAdditionalParameters(['-preset', $preset->ffmpeg_preset]);

            $exporter->addFormat($format, function ($media) use ($height): void {
                if ($height > 0) {
                    $media->scale(-2, $height);
                }
            });
        });

        $exporter->save($path);
    }

    private function baseDirectory(MediaItem $media): string
    {
        $slug = Str::slug((string) ($media->title ?: pathinfo($media->original_filename, PATHINFO_FILENAME)));

        return trim(($slug !== '' ? $slug : 'media').'-'.$media->id, '/');
    }

    private function diskForType(OutputType $type): string
    {
        return match ($type) {
            OutputType::Mp4 => (string) config('ffmpeg-worker.delivery_disk'),
            OutputType::Hls => (string) config('ffmpeg-worker.streaming_disk'),
        };
    }

    private function cleanupExistingOutput(string $disk, string $path): void
    {
        $storage = Storage::disk($disk);

        if ($storage->exists($path)) {
            $storage->delete($path);
        }
    }

    private function directorySize(string $disk, string $directory): int
    {
        return collect(Storage::disk($disk)->allFiles($directory))
            ->sum(static fn (string $file): int => (int) Storage::disk($disk)->size($file));
    }
}
