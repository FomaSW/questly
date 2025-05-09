<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Http\Controllers\BotController;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Відправлення мотиваційних повідомлень
        // Перевіряємо кожну хвилину, щоб точно попасти у заданий користувачем час
        $schedule->call(function () {
            app(BotController::class)->sendMotivationalMessages();
        })->everyMinute();

        // Відправка нагадувань кожну годину
        $schedule->call(function () {
            app(BotController::class)->sendReminders();
        })->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
