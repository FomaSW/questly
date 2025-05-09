<?php

namespace App\Console;

use App\Http\Controllers\BotController;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // Нагадування про дедлайни (кожні 30 хвилин)
        $schedule->call(function() {
            app(BotController::class)->sendReminders();
        })->everyThirtyMinutes();

        // Мотиваційні повідомлення (щодня о 9:00, 13:00 та 19:00)
        $schedule->call(function() {
            app(BotController::class)->sendMotivationalMessages();
        })->dailyAt('09:00')->timezone('Europe/Warsaw');

        $schedule->call(function() {
            app(BotController::class)->sendMotivationalMessages();
        })->dailyAt('13:00')->timezone('Europe/Warsaw');

        $schedule->call(function() {
            app(BotController::class)->sendMotivationalMessages();
        })->dailyAt('19:00')->timezone('Europe/Warsaw');
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
