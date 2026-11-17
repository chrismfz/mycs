<?php

namespace App\Console\Commands;

use App\Support\LiveChats;
use App\Support\VisitorChatTools;
use Illuminate\Console\Command;

/**
 * Closes the chats where no one wrote for the minutes set in Settings (docs/livechat.md, step 3β), and visitors' chats a
 * login froze and never continued (step 4, §3 Δ). It also clears a visitor's network address 30 days after their chat
 * closed (§6α).
 */
class LiveChatCloseIdle extends Command
{
    protected $signature = 'mycs:livechat-close-idle';

    protected $description = 'Close live chats with no message from the customer or an operator for the minutes set';

    public function handle(): int
    {
        $closed = LiveChats::closeIdle() + VisitorChatTools::expireHandoffs();
        $this->info("{$closed} idle chat(s) closed.");
        // A visitor's address goes 30 days after their chat closed (§6α).
        VisitorChatTools::forgetOldAddresses();

        return self::SUCCESS;
    }
}
