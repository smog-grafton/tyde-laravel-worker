<?php

namespace App\Http\Controllers\Api;

use App\Enums\OutputType;
use App\Http\Controllers\Controller;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;

class MediaStatusController extends Controller
{
    public function __invoke(string $uuid): JsonResponse
    {
        $media = MediaItem::query()
            ->with('outputs.preset')
            ->where('uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'ok' => true,
            'media_item_id' => $media->id,
            'uuid' => $media->uuid,
            'status' => $media->status->value,
            'fetch_status' => $media->fetch_status,
            'fetch_progress' => $media->fetch_progress,
            'title' => $media->title,
            'original_filename' => $media->original_filename,
            'poster_url' => $media->posterUrl(),
            'source' => [
                'type' => $media->source_type,
                'disk' => $media->source_disk,
                'path' => $media->source_path,
                'url' => $media->source_url,
                'host' => $media->source_host,
                'mime_type' => $media->source_mime_type,
                'size_bytes' => $media->source_size_bytes,
                'remote_fetch' => $media->remoteFetchMetadata(),
            ],
            'recommended_urls' => [
                'hls_playlist' => $media->primaryOutputUrl(OutputType::Hls),
                'mp4' => $media->primaryOutputUrl(OutputType::Mp4),
                'poster' => $media->posterUrl(),
            ],
            'outputs' => $media->outputUrlMap(),
        ]);
    }
}
