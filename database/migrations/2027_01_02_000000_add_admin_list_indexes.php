<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for what every admin page counts (the menu's badges) and what the lists, the dashboard and the cron filter
 * or sort by, measured at ten times myip's data (200k invoices, 160k tickets, 3M audit rows): without them each count
 * or sort reads the whole table.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> (a too long generated name has its own: inv_tx_*) */
    private const INDEXES = [
        'invoices' => [['status', 'due_at']],
        'tickets' => [['status', 'id'], ['created_at']],
        'services' => [['status', 'expires_at']],
        'ticket_messages' => [['ticket_id', 'created_at']],
        'invoice_transactions' => [['status', 'is_credit_transaction', 'created_at'], ['created_at']], // see name()
        'orders' => [['created_at']],
        'email_logs' => [['created_at']],
        'audits' => [['created_at']],
    ];

    /** Laravel's own name, shortened where it passes MariaDB's 64 characters. @param  list<string>  $columns */
    private static function name(string $table, array $columns): string
    {
        $name = $table . '_' . implode('_', $columns) . '_index';

        return strlen($name) <= 64 ? $name : 'inv_tx_' . implode('_', $columns) . '_index';
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns, self::name($blueprint->getTable(), $columns));
                }
            });
        }
    }

    public function down(): void
    {
        // MariaDB let the foreign key on ticket_id use the new (ticket_id, created_at) index and dropped its own: back first,
        // once (a second rollback finds it already there: review of #408).
        if (!Schema::hasIndex('ticket_messages', ['ticket_id'])) {
            Schema::table('ticket_messages', fn (Blueprint $blueprint) => $blueprint->index('ticket_id'));
        }
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex(self::name($blueprint->getTable(), $columns));
                }
            });
        }
    }
};
