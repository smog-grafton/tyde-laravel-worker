<?php

namespace App\Filament\Widgets;

use App\Enums\MediaItemStatus;
use App\Models\MediaItem;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class WorkerStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $queue = (string) config('ffmpeg-worker.queue');

        return [
            Stat::make('Waiting Files', (string) MediaItem::query()->whereIn('status', [
                MediaItemStatus::PendingProbe->value,
                MediaItemStatus::Queued->value,
            ])->count())
                ->description('Files still waiting for probe or transcode work')
                ->color('warning'),

            Stat::make('Processing', (string) MediaItem::query()->where('status', MediaItemStatus::Processing->value)->count())
                ->description('Media items actively being processed')
                ->color('info'),

            Stat::make('Failed', (string) MediaItem::query()->where('status', MediaItemStatus::Failed->value)->count())
                ->description('Items that need a retry or inspection')
                ->color('danger'),

            Stat::make('Queue Backlog', (string) DB::table('jobs')->where('queue', $queue)->count())
                ->description("Database jobs currently waiting on {$queue}")
                ->color('success'),
        ];
    }
}
