<?php

namespace App\Console\Commands;

use App\Services\ReminderNotificationService;
use Illuminate\Console\Command;

class SendTodoReminders extends Command
{
    protected $signature = 'todos:send-reminders';

    protected $description = 'Due ToDo reminders を LINE / Messenger へ送信する';

    public function handle(ReminderNotificationService $reminders): int
    {
        $stats = $reminders->dispatchDueReminders();
        $this->info(sprintf(
            'reminders checked=%d sent=%d skipped=%d errors=%d',
            $stats['checked'],
            $stats['sent'],
            $stats['skipped'],
            $stats['errors']
        ));

        return self::SUCCESS;
    }
}
