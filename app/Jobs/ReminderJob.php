<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\App;

class ReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $task;
    protected $type;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Task $task, $type)
    {
        $this->task = $task;
        $this->type = $type; // 'day_before' або 'hour_before'
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $user = User::where('chat_id', $this->task->chat_id)->first();

        if (!$user || $this->task->is_done) {
            return;
        }
        $locale = [
            0 => 'uk',
            1 => 'en',
            2 => 'ru'
        ];

        App::setLocale($locale[$user->lang]);

        $message = ($this->type === 'day_before')
            ? __('bot.reminder_day_before', ['task' => $this->task->title, 'deadline' => $this->task->deadline->format('d.m.Y H:i')])
            : __('bot.reminder_hour_before', ['task' => $this->task->title, 'deadline' => $this->task->deadline->format('d.m.Y H:i')]);

        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $this->task->chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
        ]);
    }
}
