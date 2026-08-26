<?php

declare(strict_types=1);

namespace App\Sms;

/** Um estado de entrega que o httpSMS relatou. Sem lógica — a leitura é da `Leitura`. */
final class StatusSms
{
    public function __construct(
        /** O id da mensagem no httpSMS. É o que gravamos em `acao.wamid` no envio. */
        public readonly string $id,
        /** Já traduzido para o vocabulário do produto: enviado, entregue, falhou. */
        public readonly string $status,
        /** `error_message` do httpSMS, ex.: `MOBILE_APP_INACTIVE`. */
        public readonly ?string $erro = null,
        /** O `request_id` que mandamos no envio — `ligacao:42`. */
        public readonly ?string $requestId = null,
    ) {
    }
}
