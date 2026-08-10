<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * Resposta de um comando AGI. `200 result=1 (valor)`
 */
final class Resposta
{
    public function __construct(
        public readonly int $codigo,
        public readonly string $resultado,
        public readonly ?string $dados = null,
        public readonly string $bruta = '',
    ) {
    }

    public function ok(): bool
    {
        return $this->codigo === 200;
    }

    public function inteiro(): int
    {
        return (int) $this->resultado;
    }
}
