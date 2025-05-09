<?php

namespace App\Http;

use App\Models\Task;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class Kernel extends HttpKernel
{
    protected $middleware = [
        // глобальні middleware, якщо потрібно
    ];

    protected $middlewareGroups = [
        'web' => [
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];

    protected $routeMiddleware = [
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->call(function () {
            $now = Carbon::now();

            $tasks = Task::whereNotNull('reminder_time')
                ->where('is_done', false)
                ->get();

            foreach ($tasks as $task) {
                if ($task->reminder_time->diffInMinutes($now) === 60) {
                    // за годину
                    $this->sendTelegramReminder($task, '⏳ Через годину:');
                }

                if ($task->reminder_time->diffInHours($now) === 24) {
                    // за день
                    $this->sendTelegramReminder($task, '📅 Завтра:');
                }
            }
        })->everyMinute();
    }

    protected function sendTelegramReminder($task, $prefix)
    {
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $task->chat_id,
            'text' => "$prefix {$task->title}",
        ]);
    }

}
