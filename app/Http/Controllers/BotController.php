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
    protected function handleCallback(array $callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $data = $callback['data'];

        // Підтвердження обробки callback
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/answerCallbackQuery", [
            'callback_query_id' => $callback['id'],
        ]);

        if ($data === 'add_task') {
            $this->sendMessage($chatId, "✏️ Напиши назву задачі:");
            Cache::put("add_task_{$chatId}_step", 'get_title', now()->addMinutes(5));
            return;
        }

        if (str_starts_with($data, 'priority:')) {
            $priorityKey = explode(':', $data)[1];
            $this->setTaskPriority($chatId, $priorityKey);
            return;
        }

        if ($data === 'settings') {
            $this->sendMessage($chatId, "⚙️ Налаштування поки недоступні.");
            return;
        }

        if ($data === 'list_tasks') {
            $tasks = Task::where('chat_id', $chatId)->orderByDesc('created_at')->take(5)->get();

            if ($tasks->isEmpty()) {
                $this->sendMessage($chatId, "📭 Список задач порожній.");
            } else {
                foreach ($tasks as $task) {
                    $doneText = $task->is_done ? "✅" : "⬜️";
                    $this->sendMessage($chatId, "{$doneText} {$task->title}\nПріоритет: {$task->priority}\nНагадування: " . ($task->reminder_time ? $task->reminder_time->format('d.m.Y H:i') : "не встановлено"), [
                        'reply_markup' => [
                            'inline_keyboard' => [
                                [['text' => '🗑 Видалити', 'callback_data' => "delete:{$task->id}"]],
                            ]
                        ]
                    ]);
                }
            }

            return;
        }

        if (str_starts_with($data, 'delete:')) {
            $taskId = (int) str_replace('delete:', '', $data);
            Task::where('chat_id', $chatId)->where('id', $taskId)->delete();
            $this->sendMessage($chatId, "🗑 Завдання видалено.");
        }
    }

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

        // Визначаємо крок
        $step = Cache::get("add_task_{$chatId}_step");

        if ($text === '/start') {
            $this->sendMessage($chatId, __("bot.welcome", ['name' => $user->first_name]), [
                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => '➕ Додати', 'callback_data' => 'add_task']],
                        [['text' => '📋 Список', 'callback_data' => 'list_tasks']],
                        [['text' => '⚙️ Налаштування', 'callback_data' => 'settings']],
                    ]
                ]
            ]);
            return;
        }

        // Крок 1: введення назви
        if ($step === 'get_title') {
            $this->startAddingTask($chatId, $text);
            return;
        }

        // Крок 3: введення нагадування
        if ($step === 'get_reminder') {
            $this->setTaskReminder($chatId, $text);
            return;
        }

        $this->sendMessage($chatId, "🤖 Я не впізнаю цю команду. Обери дію з меню або спробуй /start.");
    }

    protected function startAddingTask($chatId, $title)
    {
        if (empty($title)) {
            $this->sendMessage($chatId, "❗ Введіть назву задачі.");
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

        $this->sendMessage($chatId, "📊 Вибери пріоритет:", [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🔥 Високий', 'callback_data' => 'priority:high']],
                    [['text' => '⚖️ Середній', 'callback_data' => 'priority:medium']],
                    [['text' => '💤 Низький', 'callback_data' => 'priority:low']],
                ]
            ]
        ]);
    }
    protected function setTaskPriority($chatId, $priority)
    {
        $taskId = Cache::get("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, "❌ Задачу не знайдено.");
            return;
        }

        $priorityMap = [
            'high' => 'високий',
            'medium' => 'середній',
            'low' => 'низький'
        ];

        if (!isset($priorityMap[$priority])) {
            $this->sendMessage($chatId, "❗ Невірний пріоритет.");
            return;
        }

        $task->update(['priority' => $priorityMap[$priority]]);

        Cache::put("add_task_{$chatId}_step", 'get_reminder', now()->addMinutes(5));
        $this->sendMessage($chatId, "🕒 Введіть дату та час нагадування (наприклад, 2025-05-10 14:30):");
    }
    protected function setTaskReminder($chatId, $reminder)
    {
        $taskId = Cache::pull("add_task_{$chatId}_task_id");
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

        // Тут можна створити окремі нагадування за допомогою jobs або scheduler
        // Наприклад, використовуючи черги або Laravel scheduler (див. нижче)

        $this->sendMessage($chatId, "✅ Нагадування встановлено на {$reminderTime->format('d.m.Y H:i')}");

        $this->sendMessage($chatId, "✅ Завдання збережено!", [
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
