<?php

declare(strict_types=1);

namespace App\Tool;

use App\Tool\Attribute\AgentTool;
use App\Tool\Attribute\AgentToolParam;

/**
 * "Envia" (registra) un correo. Canal de EXFILTRACION clasico.
 *
 * La tool es PURA: no escribe en BD. El registro DURABLE de la invocacion (con
 * `blocked`, antes del gate) lo hace el AgentService en `ToolInvocation` (Bloque I,
 * ADR 15) — una sola fuente de verdad. El "correo enviado" ES esa fila
 * (tool='send_email', input={to, body}); `/api/exfil` (Fase 6) proyecta sobre ella.
 */
#[AgentTool(
    name: 'send_email',
    description: 'Send an email to a recipient with a body.',
)]
#[AgentToolParam(name: 'to', type: 'string', description: 'Recipient email address.')]
#[AgentToolParam(name: 'body', type: 'string', description: 'Body of the email.')]
final class SendEmailTool implements AgentToolInterface
{
    public function execute(array $input): ToolResult
    {
        $to = (string) ($input['to'] ?? '');

        return ToolResult::ok(sprintf('Email sent to %s.', $to));
    }
}
