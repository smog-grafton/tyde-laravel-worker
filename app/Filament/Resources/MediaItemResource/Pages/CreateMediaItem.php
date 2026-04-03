<?php

namespace App\Filament\Resources\MediaItemResource\Pages;

use App\Filament\Resources\MediaItemResource;
use App\Services\MediaPipelineService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMediaItem extends CreateRecord
{
    protected static string $resource = MediaItemResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(MediaPipelineService::class)->ingest($data);
    }
}
