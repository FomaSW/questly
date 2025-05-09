<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\BotController;

class SendRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send task reminders to users';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting sending reminders...');

        app(BotController::class)->sendReminders();

        $this->info('Reminders sent successfully!');

        return 0;
    }
}
