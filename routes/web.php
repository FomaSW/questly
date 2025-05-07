<?php

use Illuminate\Support\Facades\Route;
use Telegram\Bot\Laravel\Facades\Telegram;

Route::post('/' . env('TELEGRAM_BOT_TOKEN') . '/webhook', [BotController::class, 'handleWebhook']);


Route::post('/telegram/webhook', function () {
    $update = Telegram::commandsHandler(true);
    return response()->json(['status' => 'ok']);
});
