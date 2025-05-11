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
    protected $languageCodes = [
        0 => 'uk',
        1 => 'en',
        2 => 'ru'
    ];

    protected $priorityValues = [
        'High' => 1,
        'Medium' => 2,
        'Low' => 3,
        'Високий' => 1,
        'Середній' => 2,
        'Низький' => 3,
        'Высокий' => 1,
        'Средний' => 2,
        'Низкий' => 3
    ];

    protected $priorityLabels = [
        0 => 'high',
        1 => 'medium',
        2 => 'low'
    ];

    public function handleWebhook(Request $request)
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
            App::setLocale($this->languageCodes[$user->lang] ?? 'uk');
            $user->update([
                'username' => $message['from']['username'] ?? null,
                'first_name' => $message['from']['first_name'] ?? null,
                'last_name' => $message['from']['last_name'] ?? null
            ]);
        }

        $step = Cache::get("add_task_{$chatId}_step");

        if ($text === '/start') {
            if (!$user) {
                $this->showLanguageSelectionForNewUser($chatId, $message);
                return;
            }
            $this->showMainMenu($chatId);
            return;
        }

        if ($step === 'get_title') {
            $this->processTaskTitle($chatId, $text);
            return;
        }

        $this->sendMessage($chatId, __('bot.unknown_command'), $this->mainMenuKeyboard());
    }

    protected function handleCallback(array $callback)
    {
        $chatId = $callback['message']['chat']['id'];
        $data = $callback['data'];
        $user = User::where('chat_id', $chatId)->first();

        if ($user) {
            $language = $user->lang;
            if (is_numeric($language)) {
                $language = $this->languageCodes[$language] ?? 'uk';
                $user->update(['language' => $language]);
            }
            App::setLocale($language ?? 'uk');
        }

        Http::post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/answerCallbackQuery", [
            'callback_query_id' => $callback['id'],
        ]);

        if (str_starts_with($data, 'lang:')) {
            $lang = explode(':', $data)[1];
            $this->setUserLanguage($chatId, $lang);
            return;
        }

        if ($data === 'add_task') {
            $this->startAddingTask($chatId);
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

        if ($data === 'today_tasks') {
            $this->showTasksByDate($chatId, now()->startOfDay());
            return;
        }

        if ($data === 'tasks') {
            $this->showTasksByDate($chatId, null);
            return;
        }

        if ($data === 'tomorrow_tasks') {
            $this->showTasksByDate($chatId, now()->addDay()->startOfDay());
            return;
        }

        if ($data === 'archive') {
            $this->showArchive($chatId, $language);
            return;
        }

        if (str_starts_with($data, 'archive_day:')) {
            $date = explode(':', $data)[1];
            $this->showArchiveForDay($chatId, $date);
            return;
        }

        if ($data === 'back_to_archive') {
            $this->showArchive($chatId, $language);
            return;
        }

        if (str_starts_with($data, 'deadline:')) {
            $choice = explode(':', $data)[1];
            $this->setTaskDeadline($chatId, $choice);
            return;
        }

        if (str_starts_with($data, 'delete:')) {
            $taskId = (int) str_replace('delete:', '', $data);
            Task::where('chat_id', $chatId)->where('id', $taskId)->delete();
            $this->sendMessage($chatId, __('bot.task_deleted'), $this->mainMenuKeyboard());
            return;
        }

        if (str_starts_with($data, 'mark_done:')) {
            $taskId = (int) str_replace('mark_done:', '', $data);
            $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();
            if ($task) {
                $task->update(['is_done' => true]);
                $this->sendMessage($chatId, __('bot.task_marked_done'), $this->mainMenuKeyboard());
            }
            return;
        }

        if ($data === 'back_to_main') {
            $this->showMainMenu($chatId);
            return;
        }
    }

    protected function mainMenuKeyboard()
    {
        return [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('bot.add_task'), 'callback_data' => 'add_task']
                    ],
                    [
                        ['text' => __('bot.today_tasks'), 'callback_data' => 'today_tasks'],
                        ['text' => __('bot.tomorrow_tasks'), 'callback_data' => 'tomorrow_tasks']
                    ],
                    [
                        ['text' => __('bot.tasks'), 'callback_data' => 'tasks'],
                        ['text' => __('bot.archive'), 'callback_data' => 'archive'],
                    ],
                    [
                        ['text' => __('bot.settings'), 'callback_data' => 'settings']
                    ]
                ]
            ]
        ];
    }

    protected function backButtonKeyboard()
    {
        return [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']]
                ]
            ]
        ];
    }

    protected function showMainMenu($chatId, $type = 'welcome_start' )
    {
        $user = User::where('chat_id', $chatId)->first();
        $this->sendMessage($chatId, __("bot.$type", ['name' => $user->first_name]), $this->mainMenuKeyboard());
    }

    protected function showLanguageSelectionForNewUser($chatId, $message)
    {
        $this->sendMessage($chatId, "🌐 Будь ласка, оберіть мову / Please select language / Пожалуйста, выберите язык", [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => 'Українська', 'callback_data' => 'lang:uk']],
                    [['text' => 'English', 'callback_data' => 'lang:en']],
                    [['text' => 'Русский', 'callback_data' => 'lang:ru']],
                ]
            ]
        ]);
    }

    protected function setUserLanguage($chatId, $langCode)
    {
        $langIndices = [
            'uk' => 0,
            'en' => 1,
            'ru' => 2
        ];

        $langIndex = $langIndices[$langCode] ?? 0;

        $user = User::where('chat_id', $chatId)->first();

        if ($user) {
            $user->update(['lang' => $langIndex]);
        } else {
            User::create([
                'chat_id' => $chatId,
                'lang' => $langIndex,
                'first_name' => $message['from']['first_name'] ?? null,
                'username' => $message['from']['username'] ?? null
            ]);
        }

        App::setLocale($langCode);

        $messages = [
            'uk' => 'Мову змінено на українську',
            'en' => 'Language changed to English',
            'ru' => 'Язык изменен на русский'
        ];

        $message = $messages[$langCode] ?? 'Мову змінено';
        $this->sendMessage($chatId, $message);
        $this->showMainMenu($chatId, 'welcome');
    }

    protected function startAddingTask($chatId)
    {
        $this->sendMessage($chatId, __('bot.enter_task_title'), $this->backButtonKeyboard());
        Cache::put("add_task_{$chatId}_step", 'get_title', now()->addMinutes(5));
    }

    protected function processTaskTitle($chatId, $title)
    {
        if (empty($title)) {
            $this->sendMessage($chatId, __('bot.enter_valid_title'), $this->backButtonKeyboard());
            return;
        }

        $task = Task::create([
            'chat_id' => $chatId,
            'title' => $title,
            'priority' => 1,
            'is_done' => false,
        ]);

        Cache::put("add_task_{$chatId}_task_id", $task->id, now()->addMinutes(5));
        Cache::put("add_task_{$chatId}_step", 'get_priority', now()->addMinutes(5));

        $this->sendMessage($chatId, __('bot.select_priority'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.priority_high'), 'callback_data' => 'priority:high']],
                    [['text' => __('bot.priority_medium'), 'callback_data' => 'priority:medium']],
                    [['text' => __('bot.priority_low'), 'callback_data' => 'priority:low']],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']]
                ]
            ]
        ]);
    }

    protected function setTaskPriority($chatId, $priorityKey)
    {
        $taskId = Cache::get("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, __('bot.task_not_found'), $this->mainMenuKeyboard());
            return;
        }

        $priorityNumber = $this->priorityValues[$priorityKey] ?? 1;
        $task->update(['priority' => $priorityNumber]);

        $this->askForDeadline($chatId);
    }

    protected function askForDeadline($chatId)
    {
        $this->sendMessage($chatId, __('bot.select_deadline'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => __('bot.today'), 'callback_data' => 'deadline:today'],
                        ['text' => __('bot.tomorrow'), 'callback_data' => 'deadline:tomorrow']
                    ],
                    [
                        ['text' => __('bot.no_deadline'), 'callback_data' => 'deadline:none']
                    ],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']]
                ]
            ]
        ]);
        Cache::put("add_task_{$chatId}_step", 'get_deadline', now()->addMinutes(5));
    }

    protected function setTaskDeadline($chatId, $choice)
    {
        $taskId = Cache::get("add_task_{$chatId}_task_id");
        $task = Task::where('chat_id', $chatId)->where('id', $taskId)->first();

        if (!$task) {
            $this->sendMessage($chatId, __('bot.task_not_found'), $this->mainMenuKeyboard());
            return;
        }

        if ($choice === 'none') {
            $task->update(['deadline' => null]);
            $message = __('bot.task_added_no_deadline');
        } else {
            $deadline = now()->startOfDay();
            if ($choice === 'tomorrow') {
                $deadline = $deadline->addDay();
            }
            $task->update(['deadline' => $deadline]);
            $message = __('bot.deadline_set', ['deadline' => $deadline->format('d.m.Y')]);
        }

        Cache::forget("add_task_{$chatId}_task_id");
        Cache::forget("add_task_{$chatId}_step");

        $this->sendMessage($chatId, $message, $this->mainMenuKeyboard());
    }

    protected function taskOptionsKeyboard($taskId, $is_done = false)
    {
        $keyboard = [];
        if (!$is_done) {
            $keyboard[] = ['text' => __('bot.mark_done'), 'callback_data' => "mark_done:{$taskId}"];
        }
        $keyboard[] = ['text' => __('bot.delete'), 'callback_data' => "delete:{$taskId}"];
        return [
            'reply_markup' => [
                'inline_keyboard' => [
                    $keyboard,
                ]
            ]
        ];
    }

    protected function showTasksByDate($chatId, $date)
    {
        $query = Task::where('chat_id', $chatId);

        if ($date === null) {
            // Завдання без дедлайну
            $query->whereNull('deadline');
            $dateText = __('bot.no_deadline');
        } else {
            // Завдання з конкретною датою
            $query->whereDate('deadline', $date);
            $dateText = $date->format('d.m.Y');
        }

        $tasks = $query->orderBy('priority')
            ->get()
            ->map(function ($task) {
                if ($task->deadline) {
                    $task->deadline = Carbon::parse($task->deadline);
                }
                return $task;
            });

        if ($tasks->isEmpty()) {
            $this->sendMessage($chatId, __('bot.no_tasks_date', [
                'date' => $dateText
            ]), $this->mainMenuKeyboard());
            return;
        }

        foreach ($tasks as $task) {
            $priorityEmoji = $this->getPriorityEmoji($task->priority);
            $done = $task->is_done ? __('bot.done') : __('bot.not_done');
            $this->sendMessage(
                $chatId,
                "{$priorityEmoji} {$task->title}\n {$done}",
                $this->taskOptionsKeyboard($task->id, $task->is_done)
            );
        }

        $this->sendMessage($chatId, __('bot.task_list_footer'), $this->mainMenuKeyboard());
    }

    protected function showArchive($chatId , $language)
    {
        $today = Carbon::today();
        $tenDaysAgo = $today->copy()->subDays(9); // 10 днів включаючи сьогодні
        // Створюємо кнопки для кожного дня
        $daysButtons = [];
        for ($i = 0; $i <= 9; $i++) {
            $date = $today->copy()->subDays($i);
            $dayName = $date->locale($language)->isoFormat('dddd');
            $daysButtons[] = [
                'text' => $dayName . ' ' . $date->format('d.m.Y'),
                'callback_data' => 'archive_day:' . $date->format('Y-m-d')
            ];
        }

        // Розбиваємо на ряди по 2 кнопки
        $chunkedButtons = array_chunk($daysButtons, 2);

        // Додаємо кнопку "Назад"
        $chunkedButtons[] = [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']];

        $this->sendMessage($chatId, __('bot.archive_select_day'), [
            'reply_markup' => [
                'inline_keyboard' => $chunkedButtons
            ]
        ]);
    }

    protected function showArchiveForDay($chatId, $date)
    {
        $date = Carbon::parse($date);
        $tasks = Task::where('chat_id', $chatId)
            ->whereDate('deadline', $date)
            ->orderBy('deadline', 'desc')
            ->get()
            ->map(function ($task) {
                // Перетворюємо deadline з рядка на Carbon
                $task->deadline = Carbon::parse($task->deadline);
                return $task;
            });

        if ($tasks->isEmpty()) {
            $this->sendMessage($chatId, __('bot.archive_empty_day', [
                'date' => $date->format('d.m.Y')
            ]), $this->archiveDayKeyboard($date));
            return;
        }

        $message = "📅 Архів за " . $date->format('d.m.Y') . ":\n\n";
        foreach ($tasks as $task) {
            $priorityEmoji = $this->getPriorityEmoji($task->priority);
            $done = $task->is_done ? __('bot.done') : __('bot.not_done');
            $message .= "{$priorityEmoji} {$task->title} {$done}\n";
        }

        $this->sendMessage($chatId, $message, $this->archiveDayKeyboard($date));
    }

    protected function archiveDayKeyboard($date)
    {
        return [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.back_to_archive'), 'callback_data' => 'archive']],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']]
                ]
            ]
        ];
    }

    protected function showSettings($chatId)
    {
        $this->sendMessage($chatId, __('bot.settings_title'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => __('bot.change_language'), 'callback_data' => 'change_language']],
                    [['text' => __('bot.motivation_settings'), 'callback_data' => 'motivation_settings']],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'back_to_main']],
                ]
            ]
        ]);
    }

    protected function showLanguageOptions($chatId)
    {
        $this->sendMessage($chatId, __('bot.select_language'), [
            'reply_markup' => [
                'inline_keyboard' => [
                    [['text' => 'Українська', 'callback_data' => 'lang:uk']],
                    [['text' => 'English', 'callback_data' => 'lang:en']],
                    [['text' => 'Русский', 'callback_data' => 'lang:ru']],
                    [['text' => __('bot.back_to_menu'), 'callback_data' => 'settings']],
                ]
            ]
        ]);
    }

    protected function getPriorityEmoji($priority)
    {
        $priorityMap = [
            1 => '🔴', // високий
            2 => '️🟡', // середній
            3 => '🟢'  // низький
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

    public function sendMotivationalMessages()
    {
        $currentTime = Carbon::now()->format('H:i');

        $users = User::where('motivation_enabled', true)
            ->where('motivation_time', $currentTime)
            ->get();

        foreach ($users as $user) {
            App::setLocale($this->languageCodes[$user->lang] ?? 'uk');
            $message = $this->getRandomMotivationalMessage();
            $this->sendMessage($user->chat_id, $message);
        }
    }

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

    public function sendReminders()
    {
        // Нагадування за день до дедлайну
        $dayBeforeTasks = Task::where('is_done', false)
            ->whereDate('deadline', Carbon::tomorrow())
            ->get();

        foreach ($dayBeforeTasks as $task) {
            $user = User::where('chat_id', $task->chat_id)->first();
            if ($user) {
                App::setLocale($this->languageCodes[$user->lang] ?? 'uk');
                $this->sendMessage(
                    $task->chat_id,
                    __('bot.reminder_day_before', [
                        'task' => $task->title,
                        'deadline' => $task->deadline->format('d.m.Y')
                    ]),
                    $this->taskOptionsKeyboard($task->id, $task->is_done)
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
                App::setLocale($this->languageCodes[$user->lang] ?? 'uk');
                $this->sendMessage(
                    $task->chat_id,
                    __('bot.reminder_hour_before', [
                        'task' => $task->title,
                        'deadline' => $task->deadline->format('d.m.Y H:i')
                    ]),
                    $this->taskOptionsKeyboard($task->id, $task->is_done)
                );
            }
        }
    }
}
