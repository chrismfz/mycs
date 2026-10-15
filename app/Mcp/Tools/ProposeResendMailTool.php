<?php

namespace App\Mcp\Tools;

use App\Services\Mcp\Proposals;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propose_resend_mail')]
#[Description('PROPOSE (does not send) resending a logged mail (see `email_logs`), to its address or another, with or without its Cc/Bcc. Once staff approve it in the admin it is queued as a new mail, as the log keeps it (a masked password stays masked). Returns the proposal\'s id; nothing is sent until approved.')]
class ProposeResendMailTool extends ProposeTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'email_log_id' => $schema->integer()->description('The mail\'s id (from `email_logs`).')->required(),
            'to' => $schema->string()->description('Another recipient (default: the original one).'),
            'with_copies' => $schema->boolean()->description('Also to its Cc/Bcc (default no).'),
        ];
    }

    protected function type(): string
    {
        return Proposals::RESEND_MAIL;
    }

    protected function payload(Request $request): array
    {
        return ['email_log_id' => (int) $request->get('email_log_id'), 'to' => is_string($request->get('to')) ? $request->get('to') : null,
            'with_copies' => (bool) $request->get('with_copies')];
    }
}
