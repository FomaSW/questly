<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Facades\App;

class BotController extends Controller
{
    // Масиви для конвертації значень
    protected $languageCodes = [
        0 => 'uk',
        1 => 'en',
        2 => 'ru'
    ];

    protected $priorityValues = [
        'high' => 1,
        'medium' => 2,
        'low' => 3,
        // Додамо також українські та російські варіанти
        'високий' => 1,
        'середній' => 2,
        'низький' => 3,
        'высокий' => 1,
        'средний' => 2,
        'низкий' => 3
    ];

    protected $priorityLabels = [
        0 => 'high',
        1 => 'medium',
        2 => 'low'
    ];

    protected function handleCallback(array $callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $data = $callback['data']; // Виправлено раніше
        $user = User::where('chat_id', $chatId)->first();

        if ($user) {
            // Перевірка, що мова є рядком
            $language = $user->language;
            if (is_numeric($language)) {
                // Конвертуємо числовий індекс у код мови
                $language = $this->languageCodes[$language] ?? 'uk';

                // Оновлюємо запис користувача
                $user->update(['language' => $language]);
            }

            App::setLocale($language ?? 'uk');
        }

        // Підтвердження обробки callback
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/answerCallbackQuery", [
            'callback_query_id' => $callback['id'],
        ]);

        // Обробка вибору мови для нового користувача
        if (str_starts_with($data, 'lang:')) {
            $lang = explode(':', $data)[1];
            $this->setUserLanguage($chatId, $lang);
            return;
        }

        if ($data === 'add_task') {
            $this->sendMessage($chatId, __('bot.enter_task_title'));
            Cache::put("add_task_{$chatId}_step", 'get_title', now()->addMinutes(5));
            return;
        }

        if (str_starts_with($data, 'priority:')) {
            $priorityKey = explode(':', $data)[1];
            $this->setTaskPriority($chatId, $priorityKey);
            return;
        }

        if ($data === 'settings') {
            $this->showSettings($chatId);
            return;
        }

        if ($data === 'change_language') {
            $this->showLanguageOptions($chatId);
            return;
        }

        if ($data === 'motivation_settings') {
            $this->showMotivationSettings($chatId);
            return;
        }

        if (str_starts_with($data, 'motivation_time:')) {
            $time = explode(':', $data)[1];
            $this->setMotivationTime($chatId, $time);
            return;
        }

        if (str_starts_with($data, 'motivation_toggle:')) {
            $status = explode(':', $data)[1];
            $this->toggleMotivation($chatId, $status);
            return;
        }

        if ($data === 'list_tasks') {
            $this->listTasks($chatId);
            return;
        }

        if (str_starts_with($data, 'delete:')) {
            $taskId = (int) str_replace('delete:', '', $data);
            Task::where('chat_id', $chatId)->where('id', $taskId)->delete();
            $this->sendMessage($chatId, __('bot.task_deleted'));
            return;
        }

        if (str_starts_with($data, 'mark_done:')) {
            $taskId = (int) str_replace('mark_done:', '', $data);
            $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();
            if ($task) {
                $task->update(['is_done' => true]);
                $this->sendMessage($chatId, __('bot.task_marked_done'));
            }
            return;
        }

        if ($data === 'back_to_main') {
            $this->showMainMenu($chatId);
            return;
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

        $user = User::where('chat_id', $chatId)->first();

        if ($user) {
            // Виправляємо використання lang на language
            App::setLocale($this->languageCodes[$user->language] ?? 'uk');

            // Оновлюємо дані користувача
            $user->update([
                'username' => $message['from']['username'] ?? null,
                'first_name' => $message['from']['first_name'] ?? null,
                'last_name' => $message['from']['last_name'] ?? null
            ]);
        }

        // Визначаємо крок
        $step = Cache::get("add_task_{$chatId}_step");

        if ($text === '/start') {
            if (!$user) {
                // Новий користувач - пропонуємо вибрати мову
                $this->showLanguageSelectionForNewUser($chatId, $message);
                return;
            }

            $this->showMainMenu($chatId);
            return;
        }

        // Крок 1: введення назви
        if ($step === 'get_title') {
            $this->startAddingTask($chatId, $text);
            return;
        }

        // Крок 3: введення дедлайну
        if ($step === 'get_deadline') {
            $this->setTaskDeadline($chatId, $text);
            return;
        }

        if ($text === '/settings') {
            $this->showSettings($chatId);
            return;
        }

        if ($text === '/tasks') {
            $this->listTasks($chatId);
            return;
        }

        $this->sendMessage($chatId, __('bot.unknown_command'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.menu'), 'callback_data' => 'back_to_main']]
                ]
            ]
        ]);
    }

    protected function showLanguageSelectionForNewUser($chatId, $message)
    {
        $this->sendMessage($chatId, "🌐 Будь ласка, оберіть мову / Please select language / Пожалуйста, выберите язык", [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🇺🇦 Українська', 'callback_data' => 'lang:uk']],
                    [['text' => '🇬🇧 English', 'callback_data' => 'lang:en']],
                    [['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru']],
                ]
            ]
        ]);
    }

    protected function setUserLanguage($chatId, $langCode): void
    {
        // Перетворення кодів мов на числові індекси для збереження в БД
        $langIndices = [
            'uk' => 0,
            'en' => 1,
            'ru' => 2
        ];

        // Визначаємо числовий індекс для збереження
        $langIndex = $langIndices[$langCode] ?? 0;

        // Оновлюємо мову користувача
        $user = User::where('chat_id', $chatId)->first();

        if ($user) {
            $user->update(['language' => $langIndex]);
        } else {
            User::create([
                'chat_id' => $chatId,
                'language' => $langIndex
            ]);
        }

        // Встановлюємо локаль для поточного запиту
        App::setLocale($langCode);

        // Повідомлення користувача про зміну мови
        $messages = [
            'uk' => 'Мову змінено на українську',
            'en' => 'Language changed to English',
            'ru' => 'Язык изменен на русский'
        ];

        $message = $messages[$langCode] ?? 'Мову змінено';
        $this->sendMessage($chatId, $message);
        $this->showMainMenu($chatId);
    }


    protected function showMainMenu($chatId)
    {
        $user = User::where('chat_id', $chatId)->first();

        $this->sendMessage($chatId, __('bot.welcome', ['name' => $user->first_name]), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.add_task'), 'callback_data' => 'add_task']],
                    [['text' => __('bot.list_tasks'), 'callback_data' => 'list_tasks']],
                    [['text' => __('bot.settings'), 'callback_data' => 'settings']],
                ]
            ]
        ]);
    }

    protected function showSettings($chatId)
    {
        $this->sendMessage($chatId, __('bot.settings_title'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.change_language'), 'callback_data' => 'change_language']],
                    [['text' => __('bot.motivation_settings'), 'callback_data' => 'motivation_settings']],
                    [['text' => __('bot.back'), 'callback_data' => 'back_to_main']],
                ]
            ]
        ]);
    }

    protected function showLanguageOptions($chatId)
    {
        $this->sendMessage($chatId, __('bot.select_language'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => '🇺🇦 Українська', 'callback_data' => 'lang:uk']],
                    [['text' => '🇬🇧 English', 'callback_data' => 'lang:en']],
                    [['text' => '🇷🇺 Русский', 'callback_data' => 'lang:ru']],
                    [['text' => __('bot.back'), 'callback_data' => 'settings']],
                ]
            ]
        ]);
    }

    protected function showMotivationSettings($chatId)
    {
        $user = User::where('chat_id', $chatId)->first();
        $status = $user->motivation_enabled ? __('bot.enabled') : __('bot.disabled');
        $time = $user->motivation_time ?? '09:00';

        $this->sendMessage($chatId, __('bot.motivation_current', ['status' => $status, 'time' => $time]), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.motivation_time_morning'), 'callback_data' => 'motivation_time:09:00']],
                    [['text' => __('bot.motivation_time_afternoon'), 'callback_data' => 'motivation_time:13:00']],
                    [['text' => __('bot.motivation_time_evening'), 'callback_data' => 'motivation_time:19:00']],
                    [
                        ['text' => __('bot.enable'), 'callback_data' => 'motivation_toggle:1'],
                        ['text' => __('bot.disable'), 'callback_data' => 'motivation_toggle:0']
                    ],
                    [['text' => __('bot.back'), 'callback_data' => 'settings']],
                ]
            ]
        ]);
    }

    protected function setMotivationTime($chatId, $time)
    {
        $user = User::where('chat_id', $chatId)->first();
        $user->update(['motivation_time' => $time]);

        $this->sendMessage($chatId, __('bot.motivation_time_set', ['time' => $time]));
        $this->showMotivationSettings($chatId);
    }

    protected function toggleMotivation($chatId, $status)
    {
        $user = User::where('chat_id', $chatId)->first();
        $user->update(['motivation_enabled' => (bool) $status]);

        $message = (bool) $status ? __('bot.motivation_enabled') : __('bot.motivation_disabled');
        $this->sendMessage($chatId, $message);
        $this->showMotivationSettings($chatId);
    }

    protected function startAddingTask($chatId, $title)
    {
        if (empty($title)) {
            $this->sendMessage($chatId, __('bot.enter_valid_title'));
            return;
        }

        // Використовуємо числове значення для пріоритету (1 = medium)
        $task = Task::create([
            'chat_id' => $chatId,
            'title' => $title,
            'priority' => 1,
            'is_done' => false,
            'deadline' => now()->addMinutes(5),
        ]);

        Cache::put("add_task_{$chatId}_task_id", $task->id, now()->addMinutes(5));
        Cache::put("add_task_{$chatId}_step", 'get_priority', now()->addMinutes(5));

        $this->sendMessage($chatId, __('bot.select_priority'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.priority_high'), 'callback_data' => 'priority:high']],
                    [['text' => __('bot.priority_medium'), 'callback_data' => 'priority:medium']],
                    [['text' => __('bot.priority_low'), 'callback_data' => 'priority:low']],
                ]
            ]
        ]);
    }

    protected function setTaskPriority($chatId, $priorityKey)
    {
        $taskId = Cache::get("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, __('bot.task_not_found'));
            return;
        }

        // Конвертуємо текстовий пріоритет у числовий
        $priorityNumber = $this->priorityValues[$priorityKey] ?? 1;

        $task->update(['priority' => $priorityNumber]);

        Cache::put("add_task_{$chatId}_step", 'get_deadline', now()->addMinutes(5));
        $this->sendMessage($chatId, __('bot.enter_deadline'));
    }

    protected function setTaskDeadline($chatId, $deadline)
    {
        $taskId = Cache::pull("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, __('bot.task_not_found'));
            return;
        }

        try {
            $deadlineTime = Carbon::parse($deadline);

            if ($deadlineTime->isPast()) {
                $this->sendMessage($chatId, __('bot.deadline_in_past'));
                Cache::put("add_task_{$chatId}_task_id", $taskId, now()->addMinutes(5));
                Cache::put("add_task_{$chatId}_step", 'get_deadline', now()->addMinutes(5));
                return;
            }
        } catch (\Exception $e) {
            $this->sendMessage($chatId, __('bot.invalid_date_format'));
            Cache::put("add_task_{$chatId}_task_id", $taskId, now()->addMinutes(5));
            Cache::put("add_task_{$chatId}_step", 'get_deadline', now()->addMinutes(5));
            return;
        }

        $task->update([
            'deadline' => $deadlineTime,
        ]);

        // Створення нагадувань
        $this->createReminders($task);

        $this->sendMessage($chatId, __('bot.deadline_set', ['deadline' => $deadlineTime->format('d.m.Y H:i')]));

        $this->sendMessage($chatId, __('bot.task_saved'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('bot.mark_done'), 'callback_data' => "mark_done:{$task->id}"],
                        ['text' => __('bot.delete'), 'callback_data' => "delete:{$task->id}"]
                    ],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']],
                ]
            ]
        ]);

        Cache::forget("add_task_{$chatId}_step");
    }

    protected function createReminders($task)
    {
        // У реальному застосунку тут би були створені нагадування
        // Наприклад, через черги або Laravel scheduler

        // Для нагадування за день до дедлайну
        $dayBeforeReminder = $task->deadline->copy()->subDay();

        // Для нагадування за годину до дедлайну
        $hourBeforeReminder = $task->deadline->copy()->subHour();

        // Тут код для планування нагадувань
        // Наприклад:
        // ReminderJob::dispatch($task, 'day_before')->delay($dayBeforeReminder);
        // ReminderJob::dispatch($task, 'hour_before')->delay($hourBeforeReminder);
    }

    protected function listTasks($chatId)
    {
        $tasks = Task::where('chat_id', $chatId)
            ->where('is_done', false)
            ->orderBy('deadline')
            ->take(5)
            ->get();

        if ($tasks->isEmpty()) {
            $this->sendMessage($chatId, __('bot.no_tasks'), [
                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => __('bot.add_task'), 'callback_data' => 'add_task']],
                        [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']],
                    ]
                ]
            ]);
            return;
        }

        foreach ($tasks as $task) {
            $priorityEmoji = $this->getPriorityEmoji($task->priority);
            $deadlineText = $task->deadline ? $task->deadline->format('d.m.Y H:i') : __('bot.no_deadline');

            $this->sendMessage(
                $chatId,
                "{$priorityEmoji} {$task->title}\n" . __('bot.deadline') . ": {$deadlineText}",
                [
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => __('bot.mark_done'), 'callback_data' => "mark_done:{$task->id}"],
                                ['text' => __('bot.delete'), 'callback_data' => "delete:{$task->id}"]
                            ],
                        ]
                    ]
                ]
            );
        }

        $this->sendMessage($chatId, __('bot.task_list_footer'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.add_task'), 'callback_data' => 'add_task']],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']],
                ]
            ]
        ]);
    }

    protected function getPriorityEmoji($priority)
    {
        // Конвертуємо числовий пріоритет в емодзі
        $priorityMap = [
            0 => '🔥', // високий
            1 => '⚖️', // середній
            2 => '💤'  // низький
        ];

        return $priorityMap[$priority] ?? '⚪';
    }

    protected function sendMessage($chatId, $text, array $options = [])
    {
        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendMessage", array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $options));
    }

    // Метод для відправки мотиваційних повідомлень
    public function sendMotivationalMessages()
    {
        $currentTime = Carbon::now()->format('H:i');

        // Знаходимо всіх користувачів, у яких час мотиваційних повідомлень співпадає з поточним
        $users = User::where('motivation_enabled', true)
            ->where('motivation_time', $currentTime)
            ->get();

        foreach ($users as $user) {
            // Виправляємо використання lang на language
            App::setLocale($this->languageCodes[$user->language] ?? 'uk');

            // Отримуємо випадкове мотиваційне повідомлення
            $message = $this->getRandomMotivationalMessage();

            $this->sendMessage($user->chat_id, $message);
        }
    }

    // Отримання випадкового мотиваційного повідомлення
    protected function getRandomMotivationalMessage()
    {
        $messages = [
            'motivation_message_1',
            'motivation_message_2',
            'motivation_message_3',
            'motivation_message_4',
            'motivation_message_5',
        ];

        $randomKey = array_rand($messages);
        return __('bot.' . $messages[$randomKey]);
    }

    // Метод для відправки нагадувань
    public function sendReminders()
    {
        // Нагадування за день до дедлайну
        $dayBeforeTasks = Task::where('is_done', false)
            ->whereDate('deadline', Carbon::tomorrow())
            ->get();

        foreach ($dayBeforeTasks as $task) {
            $user = User::where('chat_id', $task->chat_id)->first();
            if ($user) {
                // Виправляємо використання lang на language
                App::setLocale($this->languageCodes[$user->language] ?? 'uk');
                $this->sendMessage(
                    $task->chat_id,
                    __('bot.reminder_day_before', ['task' => $task->title, 'deadline' => $task->deadline->format('d.m.Y H:i')])
                );
            }
        }

        // Нагадування за годину до дедлайну
        $hourBeforeTasks = Task::where('is_done', false)
            ->whereBetween('deadline', [Carbon::now(), Carbon::now()->addHour()])
            ->get();

        foreach ($hourBeforeTasks as $task) {
            $user = User::where('chat_id', $task->chat_id)->first();
            if ($user) {
                // Виправляємо використання lang на language
                App::setLocale($this->languageCodes[$user->language] ?? 'uk');
                $this->sendMessage(
                    $task->chat_id,
                    __('bot.reminder_hour_before', ['task' => $task->title, 'deadline' => $task->deadline->format('d.m.Y H:i')])
                );
            }
        }
    }
}
