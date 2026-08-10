<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * Decide se a Campanha deve ser pausada por erro de conta ou tronco. PURA.
 *
 * 🔴 POR QUE NÃO É SEQUÊNCIA CRUA
 *
 * A regra registrada no projeto era "N erros consecutivos pausam a Campanha".
 * A bateria de 2026-08-09 mostrou que `503` tem QUATRO causas medidas, e três
 * são do destino — inclusive **ocupado**.
 *
 * Consequência: discar uma lista no horário de almoço, quando muita gente está
 * ao telefone, geraria N `503` consecutivos e **pausaria a Campanha sem nada
 * estar errado**.
 *
 * A regra corrigida exige três coisas ao mesmo tempo:
 *   1. amostra mínima — não pausar por três chamadas
 *   2. taxa alta, não sequência
 *   3. ZERO atendimento na janela — tronco morto não atende ninguém, lista com
 *      gente ocupada atende alguém
 */
final class Protecao
{
    public function __construct(
        private int $amostraMinima = 10,
        private float $taxaLimite = 0.9,
    ) {
    }

    public function devePausar(int $tentativas, int $errosDeConta, int $atendidas): bool
    {
        if ($tentativas < $this->amostraMinima) {
            return false;
        }

        // Uma atendida na janela prova que o tronco funciona. Lista com gente
        // ocupada atende alguém; tronco morto não atende ninguém.
        if ($atendidas > 0) {
            return false;
        }

        return ($errosDeConta / $tentativas) >= $this->taxaLimite;
    }

    /**
     * Mensagem para o operador. A causa importa porque estouro de CPS da
     * operadora também volta como `503`: sem isto o operador lê "campanha
     * pausada" sem saber que o motivo pode ser ritmo, não tronco morto.
     */
    public function explicacao(int $tentativas, int $errosDeConta): string
    {
        $pct = $tentativas > 0 ? (int) round($errosDeConta / $tentativas * 100) : 0;

        return "{$errosDeConta} de {$tentativas} chamadas ({$pct}%) falharam com erro de conta ou rota "
            . 'e nenhuma foi atendida. Pode ser tronco sem saldo, rota sem cobertura, '
            . 'ou estouro do limite de chamadas por segundo da operadora.';
    }
}
