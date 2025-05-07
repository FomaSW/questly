<?php

use App\Http\Controllers\BotController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Telegram\Bot\Laravel\Facades\Telegram;

//Route::post('/' . env('TELEGRAM_BOT_TOKEN') . '/webhook', [BotController::class, 'handleWebhook']);
//

Route::post('/telegram/webhook', [BotController::class, 'handleWebhook'])
    ->withoutMiddleware([VerifyCsrfToken::class]);
