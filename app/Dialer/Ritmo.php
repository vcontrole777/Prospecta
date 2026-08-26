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

    /**
     * @param int $canaisContratados teto da CAMPANHA
     * @param int $canaisOcupados em voo NESTA Campanha
     * @param ?int $orcamentoDaInstancia quantas chamadas a INSTÂNCIA ainda aguenta
     *        agora, somando todas as Campanhas. Nulo desliga o teto de instância.
     *
     * 🔴 O ORÇAMENTO DA INSTÂNCIA EXISTE PORQUE O TETO ERA POR CAMPANHA.
     *
     * `PROSPECTA_CANAIS` é chamado de "o contratado da INSTÂNCIA" no código, e não
     * era: este método roda uma vez POR CAMPANHA, com os ocupados DAQUELA Campanha.
     * Com três no ar e 100 contratados, o produto segurava até 300 canais do tronco
     * sem ninguém ter pedido.
     *
     * Medido em 26/08/2026: com 60 contratados e duas Campanhas ativas, o pico
     * observado foi 117 simultâneas; com 100 e três Campanhas, 170 — e o `503` da
     * rota saltou de 0,66% para 8,95% enquanto o atendimento caía de 44,9% para
     * 19,0%. O tronco recusando é o sintoma de um teto que não era teto.
     */
    public function quantasOriginar(
        int $canaisContratados,
        int $canaisOcupados,
        int $leadsDisponiveis,
        float $intervaloSeg = 1.0,
        ?int $orcamentoDaInstancia = null,
    ): int {
        $livres = max(0, $canaisContratados - $canaisOcupados);
        $quantas = min($livres, max(0, $leadsDisponiveis));

        /*
         * O orçamento entra como TETO, não como substituto do livre da Campanha: os
         * dois valem ao mesmo tempo. Uma Campanha que pediu 20 simultâneas continua
         * limitada a 20 mesmo com a instância inteira ociosa.
         */
        if ($orcamentoDaInstancia !== null) {
            $quantas = min($quantas, max(0, $orcamentoDaInstancia));
        }

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
