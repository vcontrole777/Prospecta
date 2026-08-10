<?php

declare(strict_types=1);

namespace App\Whatsapp;

/** Atualização de entrega de uma mensagem que NÓS mandamos. */
final class Status
{
    public function __construct(
        public readonly string $wamid,
        /** Como a Meta manda: sent, delivered, read, played, failed. */
        public readonly string $estado,
        public readonly ?int $erroCodigo = null,
        public readonly ?string $erroTexto = null,
        /** `biz_opaque_callback_data`: o nosso `ligacao:{id}`, quando volta. */
        public readonly ?string $correlacao = null,
    ) {
    }
}
