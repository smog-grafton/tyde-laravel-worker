<?php

namespace App\Filament\Resources\MediaItemResource\RelationManagers;

use App\Enums\MediaOutputStatus;
use App\Enums\OutputType;
use App\Models\MediaOutput;
use App\Services\MediaPipelineService;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class OutputsRelationManager extends RelationManager
{
    protected static string $relationship = 'outputs';

    protected static ?string $title = 'Outputs & URLs';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->description(function (MediaOutput $record): string {
                        $type = $record->type instanceof OutputType ? $record->type : OutputType::from((string) $record->type);

                        return $type === OutputType::Hls
                            ? 'Use this playlist URL in the portal for adaptive streaming.'
                            : 'Use this MP4 as a direct playback or fallback URL.';
                    }),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) && method_exists($state, 'label') ? $state->label() : strtoupper((string) $state)),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (MediaOutputStatus|string $state): string => self::statusLabel($state))
                    ->color(fn (MediaOutputStatus|string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('progress')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('path')
                    ->copyable()
                    ->limit(40)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('public_url')
                    ->label('Direct URL')
                    ->state(fn (MediaOutput $record): string => $record->publicUrl() ?: 'Pending')
                    ->copyable()
                    ->limit(42)
                    ->tooltip(fn (MediaOutput $record): ?string => $record->publicUrl())
                    ->url(fn (MediaOutput $record): ?string => $record->publicUrl(), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : 'Pending'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since(),
            ])
            ->actions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(fn (MediaOutput $record): mixed => app(MediaPipelineService::class)->queueOutput($record)),
                Action::make('open')
                    ->label('Open')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MediaOutput $record): ?string => $record->publicUrl(), shouldOpenInNewTab: true)
                    ->visible(fn (MediaOutput $record): bool => filled($record->publicUrl())),
            ]);
    }

    private static function statusLabel(MediaOutputStatus|string $state): string
    {
        $status = $state instanceof MediaOutputStatus ? $state : MediaOutputStatus::from($state);

        return $status->label();
    }

    private static function statusColor(MediaOutputStatus|string $state): string
    {
        $status = $state instanceof MediaOutputStatus ? $state : MediaOutputStatus::from($state);

        return $status->color();
    }
}
