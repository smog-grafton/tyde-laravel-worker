<?php

namespace App\Filament\Resources\MediaItemResource\Pages;

use App\Filament\Resources\MediaItemResource;
use App\Models\MediaItem;
use App\Services\MediaPipelineService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMediaItem extends EditRecord
{
    protected static string $resource = MediaItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('queueOutputs')
                ->label('Queue Outputs')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->action(function (): void {
                    /** @var MediaItem $record */
                    $record = $this->record;
                    app(MediaPipelineService::class)->queueMedia($record);
                }),
            DeleteAction::make(),
        ];
    }
}
