<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTelegramIntakeRequest;
use App\Services\MediaPipelineService;
use Illuminate\Http\JsonResponse;

class TelegramIntakeController extends Controller
{
    public function __invoke(StoreTelegramIntakeRequest $request, MediaPipelineService $pipeline): JsonResponse
    {
        $media = $pipeline->ingest($request->validated(), $request->file('file'));

        return response()->json([
            'ok' => true,
            'media_item_id' => $media->id,
            'uuid' => $media->uuid,
            'status' => $media->status->value,
            'title' => $media->title,
            'source_disk' => $media->source_disk,
            'source_path' => $media->source_path,
            'outputs_queued' => $media->outputs()->count(),
        ], 201);
    }
}
