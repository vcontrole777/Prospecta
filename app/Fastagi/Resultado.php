<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * Tudo que a FASE 4 grava, numa transação só.
 *
 * `desfecho` só recebe valor quando o desfecho é CONHECIDO. O MagnusBilling
 * grava "atendida por humano" ANTES de checar se a originação falhou, e
 * originação falhada entra no relatório como atendimento.
 */
final class Resultado
{
    public ?string $desfecho = null;
    public ?string $teclaDigitada = null;
    /** @var array<string,string> */
    public array $respostas = [];
    public ?string $amdStatus = null;
    public ?string $amdCause = null;
    public ?string $gravacao = null;
    public bool $atendida = false;
    public ?int $atendidaEm = null;
    public ?string $erro = null;

    public function opt(): bool
    {
        return $this->desfecho === 'opt_in';
    }
}
