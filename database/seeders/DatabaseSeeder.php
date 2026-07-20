<?php

namespace Database\Seeders;

use App\Models\HolidayEntry;
use App\Models\Note;
use App\Models\Todo;
use App\Models\User;
use App\Models\WeekdayRule;
use App\Services\HolidayService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    private string $nodeDataPath;

    public function __construct()
    {
        $this->nodeDataPath = database_path('seed-data');
    }

    public function run(): void
    {
        $this->importUsers();
        $this->importTodos();
        $this->importNotes();
        $this->importHolidays();

        $holidayService = app(HolidayService::class);
        $year = (int) date('Y');
        $holidayService->importNationalHolidays($year);
        $holidayService->importNationalHolidays($year + 1);

        $financeUserId = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->value('id')
            ?? User::query()->orderBy('id')->value('id');

        if ($financeUserId) {
            app(\App\Services\FinanceService::class)
                ->actingAs((int) $financeUserId)
                ->ensureDefaultAccounts();
        }

        $this->resetSequences();
    }

    private function resetSequences(): void
    {
        foreach (['users', 'todos', 'notes', 'holiday_entries', 'weekday_rules', 'finance_accounts', 'finance_transactions', 'transit_favorites', 'map_routes'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1))");
        }
    }

    private function importUsers(): void
    {
        $path = $this->nodeDataPath.DIRECTORY_SEPARATOR.'users.json';
        if (! is_file($path)) {
            User::query()->updateOrCreate(
                ['email' => 'admin@example.com'],
                [
                    'display_name' => '管理者',
                    'password' => Hash::make('admin12345'),
                    'role' => 'admin',
                ]
            );

            return;
        }

        $raw = json_decode(file_get_contents($path), true);
        foreach ($raw['users'] ?? [] as $user) {
            User::query()->updateOrCreate(
                ['id' => $user['id']],
                [
                    'email' => $user['email'],
                    'display_name' => $user['displayName'],
                    'password' => str_replace(['$2b$', '$2a$'], '$2y$', $user['passwordHash']),
                    'role' => match ($user['role'] ?? 'standard') {
                        'user' => 'standard',
                        default => $user['role'] ?? 'standard',
                    },
                    'reset_token' => $user['resetToken'],
                    'reset_token_expires_at' => $user['resetTokenExpires'],
                    'created_at' => $user['createdAt'] ?? now(),
                    'updated_at' => $user['updatedAt'] ?? now(),
                ]
            );
        }
    }

    private function importTodos(): void
    {
        $path = $this->nodeDataPath.DIRECTORY_SEPARATOR.'todos.json';
        if (! is_file($path)) {
            return;
        }

        $raw = json_decode(file_get_contents($path), true);
        $items = $raw['todos'] ?? (is_array($raw) ? $raw : []);
        foreach ($items as $todo) {
            if (! is_array($todo) || empty($todo['id'])) {
                continue;
            }
            Todo::query()->updateOrCreate(
                ['id' => $todo['id']],
                [
                    'title' => $todo['title'],
                    'completed' => (bool) ($todo['completed'] ?? false),
                    'start_date' => $todo['startDate'] ?? $todo['dueDate'] ?? null,
                    'end_date' => $todo['endDate'] ?? $todo['dueDate'] ?? null,
                    'start_time' => $todo['startTime'] ?? $todo['time'] ?? null,
                    'end_time' => $todo['endTime'] ?? null,
                    'importance' => $this->normalizeImportance($todo['importance'] ?? 'medium'),
                    'category' => $todo['category'] ?? 'task',
                    'reminders' => $todo['reminders'] ?? [],
                    'notify_via' => $todo['notifyVia'] ?? null,
                    'notified_at' => $todo['notifiedAt'] ?? [],
                ]
            );
        }
    }

    private function importNotes(): void
    {
        $path = $this->nodeDataPath.DIRECTORY_SEPARATOR.'notes.json';
        if (! is_file($path)) {
            return;
        }

        $raw = json_decode(file_get_contents($path), true);
        foreach ($raw['notes'] ?? $raw as $note) {
            if (! is_array($note) || empty($note['id'])) {
                continue;
            }
            Note::query()->updateOrCreate(
                ['id' => $note['id']],
                [
                    'title' => $note['title'] ?? '',
                    'body' => $note['body'] ?? null,
                    'color' => $note['color'] ?? 'yellow',
                    'pinned' => (bool) ($note['pinned'] ?? false),
                    'archived' => (bool) ($note['archived'] ?? false),
                    'type' => $note['type'] ?? 'text',
                    'category' => $note['category'] ?? 'personal',
                    'items' => $note['items'] ?? null,
                    'registered_date' => $note['registeredDate'] ?? null,
                ]
            );
        }
    }

    private function importHolidays(): void
    {
        $path = $this->nodeDataPath.DIRECTORY_SEPARATOR.'holidays.json';
        if (! is_file($path)) {
            return;
        }

        $raw = json_decode(file_get_contents($path), true);
        foreach ($raw['entries'] ?? [] as $entry) {
            HolidayEntry::query()->updateOrCreate(
                ['date' => $entry['date'], 'source' => $entry['source']],
                ['name' => $entry['name']]
            );
        }
        foreach ($raw['weekdayRules'] ?? [] as $rule) {
            if (empty($rule['id'])) {
                continue;
            }
            WeekdayRule::query()->updateOrCreate(
                ['id' => $rule['id']],
                [
                    'name' => $rule['name'],
                    'start_date' => $rule['startDate'],
                    'end_date' => $rule['endDate'],
                    'weekdays' => $rule['weekdays'] ?? [],
                    'exceptions' => $rule['exceptions'] ?? [],
                ]
            );
        }
    }

    private function normalizeImportance(string $value): string
    {
        return match ($value) {
            'important', 'high' => 'high',
            'memo', 'low' => 'low',
            default => 'medium',
        };
    }
}
