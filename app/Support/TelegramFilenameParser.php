<?php

namespace App\Support;

use Illuminate\Support\Str;

class TelegramFilenameParser
{
    public function sanitize(string $filename): string
    {
        $base = trim((string) basename($filename));
        $base = str_replace(['/', '\\'], '-', $base);
        $base = preg_replace('/[^\w=\-.()\[\] ]+/u', ' ', $base) ?: $base;
        $base = preg_replace('/\s+/', ' ', $base) ?: $base;

        return $base !== '' ? $base : 'telegram_media.bin';
    }

    public function parse(string $filename): array
    {
        $cleaned = $this->sanitize($filename);
        $stem = pathinfo($cleaned, PATHINFO_FILENAME);

        preg_match('/=(\d{1,3})(?=\D|$)/', $stem, $episodeMatch);
        preg_match('/\bVJ\s+([A-Z0-9 _.\-]+)$/i', $stem, $vjMatch);

        $episode = isset($episodeMatch[1]) ? (int) $episodeMatch[1] : null;
        $vj = isset($vjMatch[1]) ? trim(str_replace('_', ' ', $vjMatch[1])) : null;

        $title = $stem;
        if ($episodeMatch !== []) {
            $title = str_replace($episodeMatch[0], ' ', $title);
        }

        if ($vjMatch !== []) {
            $title = Str::before($title, $vjMatch[0]);
        }

        $title = preg_replace('/\s+/', ' ', trim($title, " -_=.")) ?: $stem;

        return [
            'original_filename' => $cleaned,
            'title_guess' => $title,
            'episode_guess' => $episode,
            'vj_guess' => $vj,
            'extension' => strtolower((string) pathinfo($cleaned, PATHINFO_EXTENSION)),
        ];
    }
}
