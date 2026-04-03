<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MediaProbeService
{
    public function probe(string $disk, string $path): array
    {
        return $this->probeAbsolutePath(Storage::disk($disk)->path($path));
    }

    public function probeAbsolutePath(string $absolutePath): array
    {
        $result = Process::timeout((int) config('ffmpeg-worker.probe_timeout_seconds', 120))->run([
            (string) config('laravel-ffmpeg.ffprobe.binaries', 'ffprobe'),
            '-v',
            'error',
            '-print_format',
            'json',
            '-show_format',
            '-show_streams',
            $absolutePath,
        ]);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output()));
        }

        $payload = json_decode($result->output(), true);

        if (!is_array($payload)) {
            throw new RuntimeException('ffprobe returned invalid JSON.');
        }

        $streams = collect($payload['streams'] ?? []);
        $videoStream = $streams->firstWhere('codec_type', 'video');
        $audioStream = $streams->firstWhere('codec_type', 'audio');
        $format = $payload['format'] ?? [];

        $sizeBytes = isset($format['size']) ? (int) $format['size'] : (is_file($absolutePath) ? filesize($absolutePath) : 0);

        return [
            'absolute_path' => $absolutePath,
            'extension' => '.'.strtolower((string) pathinfo($absolutePath, PATHINFO_EXTENSION)),
            'container_format' => $format['format_name'] ?? null,
            'duration_seconds' => $this->toFloat($format['duration'] ?? null),
            'size_bytes' => $sizeBytes ?: null,
            'video' => $videoStream ? [
                'codec' => $videoStream['codec_name'] ?? null,
                'width' => isset($videoStream['width']) ? (int) $videoStream['width'] : null,
                'height' => isset($videoStream['height']) ? (int) $videoStream['height'] : null,
                'frame_rate' => $this->parseRatio($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? null),
            ] : null,
            'audio' => $audioStream ? [
                'codec' => $audioStream['codec_name'] ?? null,
                'sample_rate' => isset($audioStream['sample_rate']) ? (int) $audioStream['sample_rate'] : null,
                'channels' => isset($audioStream['channels']) ? (int) $audioStream['channels'] : null,
            ] : null,
            'streams' => $streams->values()->all(),
            'raw' => $payload,
        ];
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function parseRatio(?string $value): ?float
    {
        if (!$value || $value === '0/0') {
            return null;
        }

        if (!str_contains($value, '/')) {
            return (float) $value;
        }

        [$numerator, $denominator] = array_map('floatval', explode('/', $value, 2));

        if ($denominator === 0.0) {
            return null;
        }

        return $numerator / $denominator;
    }
}
