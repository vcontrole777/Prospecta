<?php

declare(strict_types=1);

namespace App\Whatsapp;

/** Mensagem que o Lead mandou. */
final class Entrada
{
    public function __construct(
        public readonly string $wamid,
        /** Como a Meta manda: só dígitos, com país. Normalizar é do chamador. */
        public readonly string $de,
        public readonly string $tipo,
        public readonly string $texto,
        /**
         * `payload` do botão de resposta rápida, quando houve toque.
         *
         * É o toque no botão que ABRE a janela de atendimento de 24 h, dentro da qual
         * texto livre é ilimitado e não custa por mensagem. Guardar o payload é o que
         * permite saber qual botão, e não só que houve um.
         */
        public readonly ?string $botao = null,
    ) {
    }
}
