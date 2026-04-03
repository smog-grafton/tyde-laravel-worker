<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTelegramIngestToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ffmpeg-worker.ingest_token');

        abort_if($expected === '', 503, 'TELEGRAM_INGEST_TOKEN is not configured.');

        $provided = (string) $request->bearerToken();

        abort_unless(hash_equals($expected, $provided), 401, 'Invalid ingest token.');

        return $next($request);
    }
}
