<?php

namespace App\Filament\Resources;

use App\Enums\MediaItemStatus;
use App\Enums\OutputType;
use App\Filament\Resources\MediaItemResource\Pages\CreateMediaItem;
use App\Filament\Resources\MediaItemResource\Pages\EditMediaItem;
use App\Filament\Resources\MediaItemResource\Pages\ListMediaItems;
use App\Filament\Resources\MediaItemResource\RelationManagers\OutputsRelationManager;
use App\Models\MediaItem;
use App\Models\TranscodePreset;
use App\Services\MediaPipelineService;
use Filament\Forms;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

class MediaItemResource extends Resource
{
    protected static ?string $model = MediaItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-film';

    protected static ?string $navigationGroup = 'Media Library';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Workflow Guide')
                ->description('Use this screen to ingest a source, let the queue build playback outputs, then copy the playback URLs into the portal.')
                ->schema([
                    Placeholder::make('workflow_help')
                        ->label('How to use this page')
                        ->content(new HtmlString(
                            '<div style="line-height:1.65;">'
                            .'1. Choose <strong>Shared server path</strong> when telebot already copied a file into the worker intake disk, or choose <strong>Fetch from remote URL</strong> for a direct downloadable file.<br>'
                            .'2. Leave <strong>Queue outputs immediately</strong> enabled unless you want to stage the record first.<br>'
                            .'3. After processing completes, open the record and copy the <strong>HLS playlist URL</strong> for adaptive streaming in <code>portal.naraboxtv.com</code>.<br>'
                            .'4. Keep the <strong>MP4 URL</strong> as a fallback source and the <strong>poster URL</strong> for artwork or previews.'
                            .'</div>'
                        ))
                        ->columnSpanFull(),
                ])
                ->columns(1),

            Section::make('Source Intake')
                ->description('Choose a local intake path for telebot handoffs or a direct remote file URL that the worker should download first.')
                ->schema([
                    Radio::make('intake_mode')
                        ->label('Source type')
                        ->options([
                            'server_path' => 'Shared server path',
                            'remote_url' => 'Fetch from remote URL',
                        ])
                        ->default('server_path')
                        ->live()
                        ->visible(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->columnSpanFull(),
                    TextInput::make('title')
                        ->maxLength(255)
                        ->columnSpan(2),
                    TextInput::make('original_filename')
                        ->maxLength(255)
                        ->helperText('Leave blank to infer from the path or URL.')
                        ->columnSpan(2),
                    Select::make('source_disk')
                        ->options(self::sourceDiskOptions())
                        ->default(config('ffmpeg-worker.intake_disk'))
                        ->required(fn (string $operation, Forms\Get $get, ?MediaItem $record): bool => self::usesServerPath($operation, $get, $record))
                        ->visible(fn (string $operation, Forms\Get $get, ?MediaItem $record): bool => self::usesServerPath($operation, $get, $record)),
                    TextInput::make('source_path')
                        ->maxLength(500)
                        ->helperText('Relative path on the intake disk, for example: 2026/04/03/movie-final-cut.mkv')
                        ->required(fn (string $operation, Forms\Get $get, ?MediaItem $record): bool => self::usesServerPath($operation, $get, $record))
                        ->visible(fn (string $operation, Forms\Get $get, ?MediaItem $record): bool => self::usesServerPath($operation, $get, $record))
                        ->columnSpan(3),
                    TextInput::make('source_url')
                        ->url()
                        ->maxLength(2048)
                        ->helperText('Use a direct file URL. Redirects are followed, headers are captured, and the downloaded file is queued for probe, MP4, HLS, and poster generation.')
                        ->required(fn (string $operation, Forms\Get $get, ?MediaItem $record): bool => self::usesRemoteUrl($operation, $get, $record))
                        ->visible(fn (string $operation, Forms\Get $get, ?MediaItem $record): bool => self::usesRemoteUrl($operation, $get, $record))
                        ->columnSpan(4),
                    Checkbox::make('queue_outputs')
                        ->label('Queue outputs immediately')
                        ->default(true)
                        ->dehydrated(fn (string $operation): bool => $operation === 'create'),
                    Select::make('presets')
                        ->label('Output presets')
                        ->multiple()
                        ->options(fn (): array => TranscodePreset::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText('Leave empty to use every active preset. The compact 500MB MP4 preset is useful for smaller download mirrors.')
                        ->columnSpan(3),
                    Textarea::make('metadata')
                        ->helperText('Optional raw JSON metadata from your Telegram worker.')
                        ->rows(4)
                        ->visible(fn (string $operation): bool => $operation === 'create')
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Section::make('Classification')
                ->schema([
                    TextInput::make('episode_number')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(999),
                    TextInput::make('vj_name')
                        ->maxLength(255),
                    TextInput::make('category')
                        ->maxLength(255),
                    TextInput::make('language')
                        ->maxLength(255),
                ])
                ->columns(4),

            Section::make('Telegram Linkage')
                ->schema([
                    TextInput::make('telegram_chat_id')->maxLength(100),
                    TextInput::make('telegram_message_id')->maxLength(100),
                    TextInput::make('telegram_channel')->maxLength(255)->columnSpan(2),
                ])
                ->columns(4),

            Section::make('Processing Snapshot')
                ->visible(fn (?MediaItem $record): bool => filled($record))
                ->schema([
                    Placeholder::make('status_label')
                        ->label('Status')
                        ->content(fn (?MediaItem $record): string => $record?->status?->label() ?? 'Pending'),
                    Placeholder::make('source_flow')
                        ->label('Source Flow')
                        ->content(function (?MediaItem $record): string {
                            if (!$record) {
                                return 'Choose a shared server path or a remote URL.';
                            }

                            if ($record->source_url && !$record->source_path) {
                                return sprintf(
                                    'Remote fetch from %s (%s%%, %s)',
                                    $record->source_host ?: 'unknown host',
                                    (int) $record->fetch_progress,
                                    strtoupper((string) $record->fetch_status)
                                );
                            }

                            if ($record->source_url && $record->source_path) {
                                return sprintf(
                                    'Fetched from %s into %s',
                                    $record->source_host ?: 'remote source',
                                    $record->source_path
                                );
                            }

                            return sprintf('Server path on %s: %s', $record->source_disk ?: 'unknown disk', $record->source_path ?: 'pending');
                        }),
                    Placeholder::make('technical_summary')
                        ->label('Technical Summary')
                        ->content(function (?MediaItem $record): string {
                            if (!$record) {
                                return 'Probe information will appear after the first queue pass.';
                            }

                            $parts = array_filter([
                                $record->width && $record->height ? "{$record->width}x{$record->height}" : null,
                                $record->video_codec,
                                $record->audio_codec,
                                $record->duration_seconds ? number_format($record->duration_seconds, 1).'s' : null,
                                $record->source_size_bytes ? Number::fileSize($record->source_size_bytes) : null,
                            ]);

                            return $parts !== [] ? implode(' | ', $parts) : 'Probe information is not available yet.';
                        })
                        ->columnSpan(2),
                    Placeholder::make('poster_preview')
                        ->label('Poster')
                        ->content(function (?MediaItem $record): HtmlString|string {
                            if (!$record || !$record->posterUrl()) {
                                return 'A poster frame will be generated after probing the source.';
                            }

                            return new HtmlString(
                                '<img src="'.e($record->posterUrl()).'" alt="'.e($record->title ?? 'Poster').'" style="max-width: 18rem; border-radius: 0.75rem;">'
                            );
                        })
                        ->columnSpan(2),
                    Textarea::make('last_error')
                        ->rows(3)
                        ->disabled()
                        ->visible(fn (?MediaItem $record): bool => filled($record?->last_error))
                        ->columnSpanFull(),
                ])
                ->columns(4),

            Section::make('Delivery URLs')
                ->description('Use the HLS playlist in the portal for adaptive streaming. MP4 links are good fallbacks for direct playback or downloads.')
                ->visible(fn (?MediaItem $record): bool => filled($record))
                ->schema([
                    Placeholder::make('delivery_links')
                        ->label('Playback and asset URLs')
                        ->content(fn (?MediaItem $record): HtmlString|string => self::deliveryLinksContent($record))
                        ->columnSpanFull(),
                    Placeholder::make('remote_fetch_details')
                        ->label('Remote Fetch Details')
                        ->visible(fn (?MediaItem $record): bool => filled($record?->source_url))
                        ->content(fn (?MediaItem $record): HtmlString|string => self::remoteFetchContent($record))
                        ->columnSpanFull(),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->description(fn (MediaItem $record): string => $record->original_filename),
                Tables\Columns\TextColumn::make('source_type')
                    ->badge()
                    ->label('Source'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (MediaItemStatus|string $state): string => self::statusLabel($state))
                    ->color(fn (MediaItemStatus|string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('fetch_status')
                    ->label('Fetch')
                    ->badge()
                    ->toggleable()
                    ->formatStateUsing(fn (?string $state): string => strtoupper((string) ($state ?: 'n/a')))
                    ->color(fn (?string $state): string => match ($state) {
                        'ready' => 'success',
                        'failed' => 'danger',
                        'downloading', 'queued' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('fetch_progress')
                    ->label('Fetch %')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? "{$state}%" : '-')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source_host')
                    ->label('Host')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('telegram_channel')
                    ->label('Channel')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('category')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source_size_bytes')
                    ->label('Source Size')
                    ->formatStateUsing(fn (?int $state): string => $state ? Number::fileSize($state) : 'Unknown'),
                Tables\Columns\TextColumn::make('height')
                    ->label('Resolution')
                    ->formatStateUsing(fn (MediaItem $record): string => $record->width && $record->height ? "{$record->width}x{$record->height}" : 'Pending'),
                Tables\Columns\TextColumn::make('outputs_count')
                    ->counts('outputs')
                    ->label('Outputs'),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(MediaItemStatus::options()),
                SelectFilter::make('category')
                    ->options(fn (): array => MediaItem::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),
                SelectFilter::make('vj_name')
                    ->label('VJ')
                    ->options(fn (): array => MediaItem::query()
                        ->whereNotNull('vj_name')
                        ->distinct()
                        ->orderBy('vj_name')
                        ->pluck('vj_name', 'vj_name')
                        ->all()),
            ])
            ->actions([
                Action::make('queue')
                    ->label('Queue')
                    ->icon('heroicon-o-play')
                    ->requiresConfirmation()
                    ->action(fn (MediaItem $record): mixed => app(MediaPipelineService::class)->queueMedia($record)),
                Action::make('open_hls')
                    ->label('Open HLS')
                    ->icon('heroicon-o-play-circle')
                    ->url(fn (MediaItem $record): ?string => $record->primaryOutputUrl(OutputType::Hls), shouldOpenInNewTab: true)
                    ->visible(fn (MediaItem $record): bool => filled($record->primaryOutputUrl(OutputType::Hls))),
                Action::make('open_mp4')
                    ->label('Open MP4')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (MediaItem $record): ?string => $record->primaryOutputUrl(OutputType::Mp4), shouldOpenInNewTab: true)
                    ->visible(fn (MediaItem $record): bool => filled($record->primaryOutputUrl(OutputType::Mp4))),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            OutputsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaItems::route('/'),
            'create' => CreateMediaItem::route('/create'),
            'edit' => EditMediaItem::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = MediaItem::query()
            ->whereIn('status', [
                MediaItemStatus::PendingProbe->value,
                MediaItemStatus::Queued->value,
                MediaItemStatus::Processing->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function statusLabel(MediaItemStatus|string $state): string
    {
        $status = $state instanceof MediaItemStatus ? $state : MediaItemStatus::from($state);

        return $status->label();
    }

    public static function statusColor(MediaItemStatus|string $state): string
    {
        $status = $state instanceof MediaItemStatus ? $state : MediaItemStatus::from($state);

        return $status->color();
    }

    private static function deliveryLinksContent(?MediaItem $record): HtmlString|string
    {
        if (!$record) {
            return 'Queue the record first. This section will list the HLS playlist, MP4 fallback, poster URL, and every generated output.';
        }

        $record->loadMissing('outputs.preset');

        $blocks = [];
        $primaryHls = $record->primaryOutputUrl(OutputType::Hls);
        $primaryMp4 = $record->primaryOutputUrl(OutputType::Mp4);

        if ($primaryHls) {
            $blocks[] = self::urlBlock(
                'Recommended for portal playback',
                $primaryHls,
                'Paste this HLS master playlist URL into portal.naraboxtv.com to get adaptive streaming behavior similar to a managed streaming CDN.'
            );
        }

        if ($primaryMp4) {
            $blocks[] = self::urlBlock(
                'MP4 fallback',
                $primaryMp4,
                'Use this for direct file playback, downloads, or as a compatibility fallback when HLS is not available.'
            );
        }

        if ($record->posterUrl()) {
            $blocks[] = self::urlBlock(
                'Poster image',
                $record->posterUrl(),
                'Use this for thumbnails, artwork, or preview cards in the portal.'
            );
        }

        foreach ($record->outputs as $output) {
            $outputType = $output->type instanceof OutputType ? $output->type : OutputType::from((string) $output->type);

            $blocks[] = self::urlBlock(
                ($output->label ?: $outputType->label()).' output',
                $output->publicUrl(),
                $output->publicUrl()
                    ? sprintf('%s | %s | %s', $outputType->label(), $output->status->label(), $output->path ?: 'Path pending')
                    : 'This output has not finished yet. Re-queue it if needed.'
            );
        }

        return new HtmlString(implode('', $blocks));
    }

    private static function remoteFetchContent(?MediaItem $record): HtmlString|string
    {
        if (!$record) {
            return 'Remote fetch diagnostics will appear here after the worker starts downloading the source.';
        }

        $remoteFetch = $record->remoteFetchMetadata();

        if (!$remoteFetch) {
            return 'The worker will store the requested URL, final URL after redirects, content type, range-request support, and cache-style headers once the remote download begins.';
        }

        $details = [
            'Requested URL' => $remoteFetch['requested_url'] ?? null,
            'Final URL' => $remoteFetch['effective_url'] ?? null,
            'Content Type' => $remoteFetch['content_type'] ?? null,
            'Content Length' => isset($remoteFetch['content_length']) ? Number::fileSize((int) $remoteFetch['content_length']) : null,
            'Range Requests' => array_key_exists('supports_range_requests', $remoteFetch)
                ? ((bool) $remoteFetch['supports_range_requests'] ? 'Supported (bytes)' : 'Not reported')
                : null,
            'ETag' => $remoteFetch['etag'] ?? null,
            'Last Modified' => $remoteFetch['last_modified'] ?? null,
        ];

        $rows = collect($details)
            ->filter(static fn (mixed $value): bool => filled($value))
            ->map(static fn (mixed $value, string $label): string => '<div style="margin-bottom:0.45rem;"><strong>'.e($label).':</strong> <code>'.e((string) $value).'</code></div>')
            ->implode('');

        return $rows !== ''
            ? new HtmlString($rows)
            : 'The fetch job has started, but detailed headers are not available yet.';
    }

    private static function urlBlock(string $label, ?string $url, string $hint): string
    {
        $renderedUrl = $url
            ? '<a href="'.e($url).'" target="_blank" rel="noreferrer" style="word-break:break-all;"><code>'.e($url).'</code></a>'
            : '<span style="color:#64748b;">Pending</span>';

        return '<div style="margin-bottom:0.95rem; line-height:1.6;">'
            .'<strong>'.e($label).'</strong><br>'
            .$renderedUrl.'<br>'
            .'<span style="color:#64748b;">'.e($hint).'</span>'
            .'</div>';
    }

    private static function sourceDiskOptions(): array
    {
        $defaultDisk = (string) config('ffmpeg-worker.intake_disk');

        return [
            $defaultDisk => "Intake ({$defaultDisk})",
            'local' => 'Local',
            'public' => 'Public',
        ];
    }

    private static function usesServerPath(string $operation, Forms\Get $get, ?MediaItem $record): bool
    {
        if ($operation !== 'create') {
            return blank($record?->source_url);
        }

        return ($get('intake_mode') ?? 'server_path') !== 'remote_url';
    }

    private static function usesRemoteUrl(string $operation, Forms\Get $get, ?MediaItem $record): bool
    {
        if ($operation !== 'create') {
            return filled($record?->source_url);
        }

        return ($get('intake_mode') ?? 'server_path') === 'remote_url';
    }
}
