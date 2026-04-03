<?php

namespace App\Support;

class FfmpegBinaryLocator
{
    public static function resolve(string $binary, ?string $override = null, ?string $basePath = null): string
    {
        $explicit = trim((string) $override);
        if ($explicit !== '') {
            return $explicit;
        }

        foreach (self::candidates($binary, $basePath) as $candidate) {
            if (self::isUsableBinary($candidate)) {
                return $candidate;
            }
        }

        // Final fallback: let the OS PATH resolve it inside the container/host.
        return $binary;
    }

    /**
     * @return list<string>
     */
    public static function candidates(string $binary, ?string $basePath = null): array
    {
        $root = $basePath ?: base_path();

        return [
            $root."/ffmpeg/{$binary}",
            $root."/bin/{$binary}",
            "/usr/local/bin/{$binary}",
            "/usr/bin/{$binary}",
            "/opt/homebrew/bin/{$binary}",
        ];
    }

    public static function isUsableBinary(string $candidate): bool
    {
        return is_file($candidate) && is_executable($candidate);
    }
}
