<?php

namespace App\Jobs;

use App\Http\Controllers\BotController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        try {
            Log::info('Starting reminders sending process');
            $botController = app(BotController::class);
            $botController->sendReminders();
            Log::info('Reminders sent successfully');
        } catch (\Exception $e) {
            Log::error('Error sending reminders: '.$e->getMessage());
        }
    }
}
