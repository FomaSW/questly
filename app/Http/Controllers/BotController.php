<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Task;

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

        if ($user->lang !== null) {
            app()->setLocale($localeMap[$user->lang] ?? 'uk');
        }

        $languages = [
            '🇺🇦 Українська' => 0,
            '🇬🇧 English' => 1,
            '💩 Русский' => 2,
        ];

        $locale = [
            0 => 'uk',
            1 => 'en',
            2 => 'ru',
        ];

        if ($text === '/start') {
            $this->sendMessage($chatId, app()->getLocale());
            if ($user->lang !== null) {
                app()->setLocale($locale[$user->lang] ?? 'uk');
                $this->sendMessage($chatId, __("bot.welcome", ['name' => $user->first_name]), [
                    'reply_markup' => [
                        'keyboard' => [
                            ['📝 Додати завдання'],
                            ['📋 Список задач'],
                            ['⚙️ Налаштування'],
                        ],
                        'resize_keyboard' => true,
                    ]
                ]);
            } else {
                $this->sendMessage($chatId, "🌍 Обери мову:\n🇺🇦 Українська\n🇬🇧 English\n💩 Русский", [
                    'reply_markup' => [
                        'keyboard' => [['🇺🇦 Українська'], ['🇬🇧 English'], ['💩 Русский']],
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true,
                    ]
                ]);
            }
            return;
        }

        if (isset($languages[$text])) {
            $user->lang = $languages[$text];
            $user->save();

            app()->setLocale($locale[$user->lang]);
            $this->sendMessage($chatId, $locale[$user->lang]);
            $this->sendMessage($chatId, __("bot.language_selected"));
            $this->sendMessage($chatId, __("bot.welcome", ['name' => $user->first_name]), [
                'reply_markup' => [
                    'keyboard' => [
                        [__('bot.add')],
                        [__('bot.list')],
                        [__('bot.settings')],
                    ],
                    'resize_keyboard' => true,
                ]
            ]);
            return;
        }

        // Основні команди
        if ($text === __('bot.add')) {
            $this->sendMessage($chatId, "✏️ Напиши завдання у форматі:\n/додати Твоя назва задачі [пріоритет: високий|середній|низький]");
        } elseif ($text === __('bot.list')) {
            $this->listTasks($chatId);
        } elseif ($text === __('bot.settings')) {
            $this->sendMessage($chatId, __("bot.settings_menu"), [
                'reply_markup' => [
                    'keyboard' => [
                        [__('bot.language')],
                        ['⬅️ Назад']
                    ],
                    'resize_keyboard' => true,
                ]
            ]);
        } elseif ($text === __('bot.language')) {
            $this->sendMessage($chatId, "🌍 Обери мову:\n🇺🇦 Українська\n🇬🇧 English\n💩 Русский", [
                'reply_markup' => [
                    'keyboard' => [['🇺🇦 Українська'], ['🇬🇧 English'], ['💩 Русский']],
                    'one_time_keyboard' => true,
                    'resize_keyboard' => true,
                ]
            ]);
        } elseif (strpos($text, '/додати') === 0) {
            $this->addTask($chatId, $text);
        } elseif (Cache::has("edit_{$chatId}")) {
            $this->updateTaskTitle($chatId, $text);
        } else {
            $this->sendMessage($chatId, "🤖 Я не впізнаю цю команду. Обери дію з меню або спробуй /додати чи /список.");
        }
    }

    protected function handleCallback(array $callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $data = $callback['data'];
        [$action, $taskId] = explode(':', $data);
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, "❌ Задачу не знайдено.");
            return;
        }

        switch ($action) {
            case 'done':
                $task->update(['is_done' => true]);
                $this->sendMessage($chatId, $this->getMotivationMessage());
                break;
            case 'delete':
                $task->delete();
                $this->sendMessage($chatId, "🗑 Задачу видалено.");
                break;
            case 'edit':
                Cache::put("edit_{$chatId}", $task->id, now()->addMinutes(5));
                $this->sendMessage($chatId, "✏️ Введи нову назву для задачі:");
                break;
            case 'move':
                $task->update(['created_at' => now()->addDay()]);
                $this->sendMessage($chatId, "📅 Задачу перенесено на завтра.");
                $this->sendTaskCard($chatId, $task);
                break;
        }
    }

    protected function addTask($chatId, $text)
    {
        $params = trim(str_replace('/додати', '', $text));

        if (empty($params)) {
            $this->sendMessage($chatId, "❗ Напиши назву задачі після команди /додати. Наприклад: /додати Купити хліб [пріоритет: високий|середній|низький]");
            return;
        }

        $priority = 'середній';
        if (preg_match('/пріоритет:(високий|середній|низький)/ui', $params, $matches)) {
            $priority = strtolower($matches[1]);
            $title = trim(str_replace($matches[0], '', $params));
        } else {
            $title = $params;
        }

        $task = Task::create([
            'chat_id' => $chatId,
            'title' => $title,
            'priority' => $priority,
            'is_done' => false,
        ]);

        $this->sendTaskCard($chatId, $task);
    }

    protected function listTasks($chatId)
    {
        $tasks = Task::where('chat_id', $chatId)
            ->where('is_done', false)
            ->orderByRaw("FIELD(priority, 'високий', 'середній', 'низький')")
            ->get();

        if ($tasks->isEmpty()) {
            $this->sendMessage($chatId, "📭 У тебе немає активних задач.");
            return;
        }

        foreach ($tasks as $task) {
            $this->sendTaskCard($chatId, $task);
        }
    }

    protected function updateTaskTitle($chatId, $text)
    {
        $taskId = Cache::pull("edit_{$chatId}");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if ($task) {
            $task->update(['title' => $text]);
            $this->sendMessage($chatId, "✏️ Назву задачі оновлено.");
            $this->sendTaskCard($chatId, $task);
        } else {
            $this->sendMessage($chatId, "❌ Задачу не знайдено.");
        }
    }

    protected function sendTaskCard($chatId, Task $task)
    {
        $priorityEmoji = [
            'високий' => '🔴',
            'середній' => '🟡',
            'низький' => '🟢',
        ][$task->priority] ?? '🟢';

        $text = "$priorityEmoji *{$task->title}*";

        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '✅ Виконати', 'callback_data' => "done:{$task->id}"]],
                    [['text' => '✏️ Редагувати', 'callback_data' => "edit:{$task->id}"]],
                    [['text' => '📅 Перенести', 'callback_data' => "move:{$task->id}"]],
                    [['text' => '🗑 Видалити', 'callback_data' => "delete:{$task->id}"]],
                ]
            ])
        ]);
    }

    protected function sendMessage($chatId, $text, array $options = [])
    {
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $options));
    }

    protected function getMotivationMessage(): string
    {
        $messages = [
            "🎉 Ти молодець! Ціль досягнута!",
            "✅ Галочка поставлена — мрія ближче!",
            "🔥 Ще один крок до успіху!",
            "👏 Завдання закрите! Вперед до нових вершин!",
        ];

        return $messages[array_rand($messages)];
    }
}
