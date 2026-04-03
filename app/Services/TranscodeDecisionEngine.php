<?php

namespace App\Services;

use App\Enums\OutputType;
use App\Models\TranscodePreset;

class TranscodeDecisionEngine
{
    public function analyze(array $probe, ?TranscodePreset $preset = null): array
    {
        $video = $probe['video'] ?? [];
        $audio = $probe['audio'] ?? [];
        $extension = strtolower((string) ($probe['extension'] ?? ''));
        $sizeBytes = (int) ($probe['size_bytes'] ?? 0);

        $minSizeBytes = $this->toBytes((int) config('ffmpeg-worker.video_prep_min_size_mb_for_transcode', 50));
        $targetMaxBytes = $this->toBytes((int) ($preset?->max_size_mb ?: config('ffmpeg-worker.video_prep_target_max_mb', 1500)));
        $targetHeight = $preset?->target_height ?: (int) config('ffmpeg-worker.video_prep_max_height', 720);
        $sourceHeight = (int) ($video['height'] ?? 0);

        if (($preset?->output_type ?? null) === OutputType::Hls) {
            return [
                'should_transcode' => true,
                'should_copy' => false,
                'mode' => 'hls',
                'reason' => 'adaptive_hls_export',
                'target_height' => $targetHeight,
                'target_max_bytes' => null,
            ];
        }

        $sourceIsMp4 = $extension === '.mp4';
        $sourceIsH264 = in_array(strtolower((string) ($video['codec'] ?? '')), ['h264', 'avc1'], true);
        $audioIsAac = in_array(strtolower((string) ($audio['codec'] ?? '')), ['aac', 'mp4a'], true);
        $deliveryFriendly = $sourceIsMp4 && $sourceIsH264 && $audioIsAac;
        $needsDownscale = $sourceHeight > 0 && $targetHeight > 0 && $sourceHeight > $targetHeight && $sizeBytes >= $minSizeBytes;
        $needsCap = $targetMaxBytes > 0 && $sizeBytes > $targetMaxBytes;

        if ($needsCap) {
            return [
                'should_transcode' => true,
                'should_copy' => false,
                'mode' => 'cap',
                'reason' => $deliveryFriendly ? 'size_above_cap' : 'size_above_cap_and_needs_delivery_prep',
                'target_height' => $needsDownscale ? $targetHeight : null,
                'target_max_bytes' => $targetMaxBytes,
            ];
        }

        if (!$sourceIsMp4) {
            return $this->transcodeDecision('container_not_mp4', $needsDownscale ? $targetHeight : null);
        }

        if (!$sourceIsH264) {
            return $this->transcodeDecision('video_codec_not_h264', $needsDownscale ? $targetHeight : null);
        }

        if (!$audioIsAac) {
            return $this->transcodeDecision('audio_not_aac_or_missing', $needsDownscale ? $targetHeight : null);
        }

        if ($needsDownscale) {
            return $this->transcodeDecision('resolution_above_target', $targetHeight);
        }

        if ($sizeBytes >= $minSizeBytes && $sourceHeight >= 1080) {
            return $this->transcodeDecision('oversized_high_resolution', $targetHeight);
        }

        return [
            'should_transcode' => false,
            'should_copy' => true,
            'mode' => 'copy',
            'reason' => 'already_delivery_friendly',
            'target_height' => null,
            'target_max_bytes' => $targetMaxBytes ?: null,
        ];
    }

    public function buildCapAttempts(array $probe, TranscodePreset $preset): array
    {
        $duration = (float) ($probe['duration_seconds'] ?? 0.0);
        $targetMaxBytes = $this->toBytes((int) ($preset->max_size_mb ?: config('ffmpeg-worker.video_prep_target_max_mb', 1500)));
        $audioBitrate = max(64, (int) ($preset->audio_bitrate_kbps ?: 128));
        $attempts = max(1, (int) config('ffmpeg-worker.video_prep_cap_attempts', 3));
        $ratio = (float) config('ffmpeg-worker.video_prep_cap_overhead_ratio', 0.97);
        $minVideoBitrate = max(100, (int) config('ffmpeg-worker.video_prep_min_video_bitrate_kbps', 150));

        if ($duration <= 0.0 || $targetMaxBytes <= 0) {
            return [];
        }

        $totalTargetBitrateBps = (int) (($targetMaxBytes * $ratio * 8) / $duration);
        $baseVideoBitrate = max($minVideoBitrate, (int) floor($totalTargetBitrateBps / 1000) - $audioBitrate);

        $heightCandidates = $this->buildHeightCandidates(
            (int) ($probe['video']['height'] ?? 0),
            $preset->target_height ?: (int) config('ffmpeg-worker.video_prep_max_height', 720),
        );

        $plan = [];

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $plan[] = [
                'target_height' => $heightCandidates[min($attempt, count($heightCandidates) - 1)] ?? null,
                'video_bitrate_kbps' => max($minVideoBitrate, (int) floor($baseVideoBitrate * (0.9 ** $attempt))),
                'audio_bitrate_kbps' => $audioBitrate,
                'preset' => $preset->ffmpeg_preset,
            ];
        }

        return $plan;
    }

    private function transcodeDecision(string $reason, ?int $targetHeight): array
    {
        return [
            'should_transcode' => true,
            'should_copy' => false,
            'mode' => 'crf',
            'reason' => $reason,
            'target_height' => $targetHeight,
            'target_max_bytes' => null,
        ];
    }

    private function buildHeightCandidates(int $sourceHeight, int $maxHeight): array
    {
        $ladder = array_values(array_filter(array_map(
            'intval',
            config('ffmpeg-worker.video_prep_cap_height_ladder', [720, 480, 360])
        )));

        $candidates = array_values(array_filter($ladder, static function (int $height) use ($maxHeight, $sourceHeight): bool {
            return $height <= max(240, $maxHeight) && ($sourceHeight === 0 || $height <= $sourceHeight);
        }));

        if ($candidates === []) {
            return [$sourceHeight > 0 ? $sourceHeight : max(240, $maxHeight)];
        }

        return $candidates;
    }

    private function toBytes(int $megabytes): int
    {
        return $megabytes > 0 ? $megabytes * 1024 * 1024 : 0;
    }
}
