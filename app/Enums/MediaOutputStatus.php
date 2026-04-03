<?php

namespace App\Enums;

enum MediaOutputStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Processing => 'info',
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Skipped => 'gray',
        };
    }
}
