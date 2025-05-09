<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\BotController;

class SendMotivationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:send-motivation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send motivational messages to users';

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
        $this->info('Starting sending motivational messages...');

        app(BotController::class)->sendMotivationalMessages();

        $this->info('Motivational messages sent successfully!');

        return 0;
    }
}
