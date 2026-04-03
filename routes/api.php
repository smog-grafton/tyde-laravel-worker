<?php

use App\Http\Controllers\Api\TelegramIntakeController;
use App\Http\Controllers\Api\MediaStatusController;
use App\Http\Middleware\EnsureTelegramIngestToken;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(EnsureTelegramIngestToken::class)
    ->group(function (): void {
        Route::post('/media/telegram-intake', TelegramIntakeController::class);
        Route::get('/media/{uuid}', MediaStatusController::class);
    });
