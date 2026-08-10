<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Resultado de UMA tentativa de envio. Sem lógica — a leitura é da
 * `PoliticaDeEnvio`.
 */
final class Envio
{
    public function __construct(
        /** HTTP; 0 quando a requisição não chegou a ter resposta. */
        public readonly int $http,
        public readonly ?string $wamid = null,
        public readonly ?int $codigo = null,
        /**
         * 🔴 `is_transient` da própria Graph API.
         *
         * É a plataforma dizendo se vale retentar, e é regra melhor que manter
         * lista de códigos retentáveis — lista envelhece, e esta API deprecia
         * campo durante o desenvolvimento de quem a consome.
         */
        public readonly bool $transitorio = false,
        /** `error_user_msg`: texto que a Meta escreveu PARA o usuário final. */
        public readonly ?string $mensagemDoUsuario = null,
        /** `fbtrace_id`: o que um ticket de suporte com a Meta exige. */
        public readonly ?string $fbtrace = null,
        public readonly ?string $mensagem = null,
        /** Falha de conexão, antes de qualquer resposta. */
        public readonly bool $semResposta = false,
    ) {
    }

    public function aceito(): bool
    {
        return $this->http >= 200 && $this->http < 300 && $this->wamid !== null;
    }
}
