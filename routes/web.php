<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/env-check', function () {
    return [
        'app_env' => \Illuminate\Support\Env::getRepository(),
        'app_url' => env('APP_URL'),
        'db_host' => env('DB_HOST'),
        'telegram_token' => env('TELEGRAM_BOT_TOKEN'),
    ];
});
