<?php

namespace App\Filament\Resources;

use App\Enums\OutputType;
use App\Filament\Resources\TranscodePresetResource\Pages\CreateTranscodePreset;
use App\Filament\Resources\TranscodePresetResource\Pages\EditTranscodePreset;
use App\Filament\Resources\TranscodePresetResource\Pages\ListTranscodePresets;
use App\Models\TranscodePreset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TranscodePresetResource extends Resource
{
    protected static ?string $model = TranscodePreset::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Preset')
                ->description('Presets define which public playback URL the worker creates. MP4 presets create direct file URLs; HLS presets create adaptive playlist URLs.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, ?string $state): mixed => $set('slug', Str::slug((string) $state))),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('output_type')
                        ->options(OutputType::options())
                        ->required(),
                    TextInput::make('sort_order')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(4),

            Section::make('Encoding Strategy')
                ->description('Use MP4 presets for downloadable/fallback files and HLS presets for adaptive streaming in the portal. A compact MP4 preset can target smaller download sizes such as 500 MB.')
                ->schema([
                    TextInput::make('target_height')->numeric()->helperText('Target output height. HLS still uses the configured ladder and never upscales above the source.'),
                    TextInput::make('video_bitrate_kbps')->numeric()->helperText('Used mainly for HLS ladder variants or capped MP4 strategies.'),
                    TextInput::make('audio_bitrate_kbps')->numeric()->default(128)->helperText('AAC audio bitrate in kilobits per second.'),
                    TextInput::make('crf')->numeric()->default(24)->helperText('Lower values increase quality and file size.'),
                    TextInput::make('ffmpeg_preset')->default('medium')->required()->helperText('FFmpeg speed/efficiency trade-off, for example medium or slow.'),
                    TextInput::make('max_size_mb')->numeric()->helperText('Optional hard cap for MP4 compression attempts, for example 500 for a lightweight download variant.'),
                    Toggle::make('is_active')->default(true),
                    Toggle::make('is_default')->default(false),
                ])
                ->columns(4),

            Textarea::make('notes')
                ->helperText('Use notes to tell operators when this preset should be enabled or pasted into the portal. Example: use Compact MP4 500MB for lightweight mirrors or mobile downloads.')
                ->rows(4)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (TranscodePreset $record): string => $record->slug),
                Tables\Columns\TextColumn::make('output_type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => is_object($state) && method_exists($state, 'label') ? $state->label() : strtoupper((string) $state)),
                Tables\Columns\TextColumn::make('target_height')
                    ->suffix('p'),
                Tables\Columns\TextColumn::make('max_size_mb')
                    ->label('Max Size')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state} MB" : 'None'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_default')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since(),
            ])
            ->actions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranscodePresets::route('/'),
            'create' => CreateTranscodePreset::route('/create'),
            'edit' => EditTranscodePreset::route('/{record}/edit'),
        ];
    }
}
