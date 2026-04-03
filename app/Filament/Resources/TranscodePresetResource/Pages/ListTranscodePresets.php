<?php

namespace App\Filament\Resources\TranscodePresetResource\Pages;

use App\Filament\Resources\TranscodePresetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTranscodePresets extends ListRecords
{
    protected static string $resource = TranscodePresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
