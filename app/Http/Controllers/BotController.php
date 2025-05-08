<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Task;
use Carbon\Carbon;

class BotController extends Controller
{
    public function handleWebhook(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->all();
        if (isset($data['message'])) {
            $this->handleMessage($data['message']);
        } elseif (isset($data['callback_query'])) {
            $this->handleCallback($data['callback_query']);
        }

        return response()->json(['status' => 'ok']);
    }

    protected function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');

        if ($message['chat']['type'] !== 'private') {
            $this->sendMessage($chatId, __('bot.only_private'));
            return;
        }

        $user = User::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'username' => $message['from']['username'] ?? null,
                'first_name' => $message['from']['first_name'] ?? null,
                'last_name' => $message['from']['last_name'] ?? null
            ]
        );

        if ($text === '/start') {
            $this->sendMessage($chatId, __("bot.welcome", ['name' => $user->first_name]), [
                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => __('bot.add'), 'callback_data' => 'add_task']],
                        [['text' => __('bot.list'), 'callback_data' => 'list_tasks']],
                        [['text' => __('bot.settings'), 'callback_data' => 'settings']],
                    ],
                    'resize_keyboard' => true,
                ]
            ]);
            return;
        }

        // Основні команди
        if ($text === __('bot.add')) {
            $this->sendMessage($chatId, "✏️ Напиши завдання у форматі: Назва задачі");
            Cache::put("add_task_{$chatId}_step", 'get_title', now()->addMinutes(5));
        } elseif (Cache::get("add_task_{$chatId}_step") === 'get_title') {
            $this->startAddingTask($chatId, $text);
        } elseif (Cache::get("add_task_{$chatId}_step") === 'get_priority') {
            $this->setTaskPriority($chatId, $text);
        } elseif (Cache::get("add_task_{$chatId}_step") === 'get_reminder') {
            $this->setTaskReminder($chatId, $text);
        } else {
            $this->sendMessage($chatId, "🤖 Я не впізнаю цю команду. Обери дію з меню або спробуй /додати чи /список.");
        }
    }

    protected function startAddingTask($chatId, $title)
    {
        if (empty($title)) {
            $this->sendMessage($chatId, "❗ Напиши назву задачі.");
            return;
        }

        $task = Task::create([
            'chat_id' => $chatId,
            'title' => $title,
            'priority' => 'середній',
            'is_done' => false,
        ]);

        Cache::put("add_task_{$chatId}_task_id", $task->id, now()->addMinutes(5));
        Cache::put("add_task_{$chatId}_step", 'get_priority', now()->addMinutes(5));

        $this->sendMessage($chatId, "📊 Вибери пріоритет для задачі:\n1. Високий\n2. Середній\n3. Низький", [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '1. Високий', 'callback_data' => "priority:high"]],
                    [['text' => '2. Середній', 'callback_data' => "priority:medium"]],
                    [['text' => '3. Низький', 'callback_data' => "priority:low"]],
                ]
            ]
        ]);
    }

    protected function setTaskPriority($chatId, $priority)
    {
        $taskId = Cache::pull("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, "❌ Задачу не знайдено.");
            return;
        }

        switch ($priority) {
            case 'high':
                $task->update(['priority' => 'високий']);
                break;
            case 'medium':
                $task->update(['priority' => 'середній']);
                break;
            case 'low':
                $task->update(['priority' => 'низький']);
                break;
            default:
                $this->sendMessage($chatId, "❌ Невірний вибір пріоритету.");
                return;
        }

        Cache::put("add_task_{$chatId}_step", 'get_reminder', now()->addMinutes(5));
        $this->sendMessage($chatId, "⏰ Введіть дату та час для нагадування (наприклад, 2025-05-10 14:30).");
    }

    protected function setTaskReminder($chatId, $reminder)
    {
        $taskId = Cache::get("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, "❌ Задачу не знайдено.");
            return;
        }

        try {
            $reminderTime = Carbon::parse($reminder);
        } catch (\Exception $e) {
            $this->sendMessage($chatId, "❗ Невірний формат дати/часу.");
            return;
        }

        $task->update([
            'reminder_time' => $reminderTime,
        ]);

        $this->sendMessage($chatId, "✅ Нагадування встановлено для: {$reminderTime->format('d-m-Y H:i')}");
        $this->sendMessage($chatId, "📅 Завдання додано успішно!", [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🗑 Видалити', 'callback_data' => "delete:{$task->id}"]],
                ]
            ]
        ]);

        Cache::forget("add_task_{$chatId}_step");
    }

    protected function sendMessage($chatId, $text, array $options = [])
    {
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options));
    }
}
