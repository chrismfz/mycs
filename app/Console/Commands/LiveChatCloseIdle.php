<?php

namespace App\Console\Commands;

use App\Support\LiveChats;
use Illuminate\Console\Command;

/** Closes the customers' chats where no one wrote for the minutes set in Settings (docs/livechat.md, step 3β). */
class LiveChatCloseIdle extends Command
{
    protected $signature = 'mycs:livechat-close-idle';

    protected $description = 'Close live chats with no message from the customer or an operator for the minutes set';

    public function handle(): int
    {
        $closed = LiveChats::closeIdle();
        $this->info("{$closed} idle chat(s) closed.");

        return self::SUCCESS;
    }
}
