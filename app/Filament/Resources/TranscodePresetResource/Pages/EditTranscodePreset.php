<?php

namespace App\Filament\Resources\TranscodePresetResource\Pages;

use App\Filament\Resources\TranscodePresetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTranscodePreset extends EditRecord
{
    protected static string $resource = TranscodePresetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
