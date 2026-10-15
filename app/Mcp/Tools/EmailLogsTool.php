<?php

namespace App\Mcp\Tools;

use App\Models\EmailLog;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('email_logs')]
#[Description('The mail audit (docs/mail.md): mails newest first with their status (pending, sending, sent, failed), template, recipient, what they were about, who sent them, attempts and error. Filter by status, recipient, template, account. Bodies are not returned. For "did the customer get the invoice / the welcome mail?".')]
#[IsReadOnly]
#[IsIdempotent]
class EmailLogsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum([EmailLog::STATUS_PENDING, EmailLog::STATUS_SENDING, EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED]),
            'to' => $schema->string()->description('A recipient address (exact).'),
            'template' => $schema->string()->description('A template key, e.g. new_invoice_created, server_suspended.'),
            'client_id' => $schema->integer()->description('An account id.'),
            'limit' => $schema->integer()->description('How many (default 20, at most 100).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.email_logs.viewAny';
    }

    protected function run(Request $request): array
    {
        $query = EmailLog::query()->latest('id')
            ->when($request->get('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->get('to'), fn ($query, $to) => $query->where('to', $to))
            ->when($request->get('template'), fn ($query, $template) => $query->where('template_key', $template))
            ->when($request->get('client_id'), fn ($query, $id) => $query->where('client_id', (int) $id));

        return ['mails' => $query->limit(self::limit($request, 20, 100))->get()->map(fn (EmailLog $log) => [
            'id' => $log->id, 'status' => $log->status, 'template' => $log->template_key, 'to' => $log->to, 'cc' => $log->cc, 'subject' => $log->subject,
            'about' => $log->relatedLabel(), 'account_id' => $log->client_id, 'sent_by' => $log->trigger, 'resend_of' => $log->resent_from_id,
            'attempts' => $log->attempts, 'queued' => $log->created_at?->toDateTimeString(), 'sent' => $log->sent_at?->toDateTimeString(),
            'error' => Scrub::text($log->error), 'message_id' => $log->message_id,
        ])->all()];
    }
}
