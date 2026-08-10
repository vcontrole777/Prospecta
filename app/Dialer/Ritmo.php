<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * Quantas Ligações originar AGORA. PURA.
 *
 * 🔴 MALHA FECHADA, não taxa calculada (ADR-0005).
 *
 * O MagnusBilling usa malha aberta e o defeito é demonstrável: `$i % $sleep`
 * com `$sleep` float, e `%` é operador inteiro em PHP, então `1,667` vira `1` e
 * a condição fica sempre verdadeira. Com frequência 100 saem **60 originações
 * por minuto em vez de 100** — 40% abaixo, sem erro nenhum em log.
 *
 * Aqui não há taxa: conta-se canal livre de verdade e preenche.
 */
final class Ritmo
{
    /**
     * @param int $cpsMaximo teto de chamadas por segundo da CONTA na operadora.
     *        Estourar volta como `503` e dispara a proteção — que é o
     *        comportamento certo com mensagem confusa, então é melhor não
     *        estourar. Zero desliga o teto.
     */
    public function __construct(
        private int $cpsMaximo = 0,
    ) {
    }

    public function quantasOriginar(
        int $canaisContratados,
        int $canaisOcupados,
        int $leadsDisponiveis,
        float $intervaloSeg = 1.0,
    ): int {
        $livres = max(0, $canaisContratados - $canaisOcupados);
        $quantas = min($livres, max(0, $leadsDisponiveis));

        if ($this->cpsMaximo > 0) {
            $tetoDaVolta = (int) floor($this->cpsMaximo * $intervaloSeg);
            $quantas = min($quantas, max(1, $tetoDaVolta));
        }

        return $quantas;
    }

    /**
     * Espaçamento entre originações dentro da volta, em microssegundos.
     *
     * Distribuir evita entregar um lote inteiro no mesmo instante, o que a
     * operadora leria como pico de CPS.
     */
    public function espacamentoMicro(int $quantas, float $intervaloSeg = 1.0): int
    {
        if ($quantas <= 1) {
            return 0;
        }

        return (int) floor($intervaloSeg * 1_000_000 / $quantas);
    }
}
