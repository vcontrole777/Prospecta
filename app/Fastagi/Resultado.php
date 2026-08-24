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

    /**
     * O que a pessoa DIGITOU e não passou na validação, por campo.
     *
     * 🔴 SEPARADO de `respostas`, e a separação é o ponto. `respostas` é o produto da
     * Campanha — vai para o CSV e para o sistema do cliente. Um CPF reprovado no dígito
     * verificador ali dentro seria entregue como se fosse bom. Aqui é diagnóstico: diz
     * se a pessoa digitou pouco, digitou errado, ou desistiu.
     *
     * Lista por campo, não só a última: duas tentativas curtas contam uma história
     * (prompt ruim) que a última sozinha esconderia.
     *
     * @var array<string,list<string>>
     */
    public array $tentativasInvalidas = [];
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
