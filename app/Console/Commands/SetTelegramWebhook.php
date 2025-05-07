<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class SetTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:set-telegram-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $url = env('TELEGRAM_WEBHOOK_URL');
        $response = Telegram::setWebhook(['url' => $url]);
        $this->info($response ? "✅ Вебхук встановлено: $url" : "❌ Помилка!");
    }
}
