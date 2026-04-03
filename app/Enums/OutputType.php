<?php

namespace App\Enums;

enum OutputType: string
{
    case Mp4 = 'mp4';
    case Hls = 'hls';

    public function label(): string
    {
        return match ($this) {
            self::Mp4 => 'MP4',
            self::Hls => 'HLS',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(static fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
