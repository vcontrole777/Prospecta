<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * O vocabulário da tela de chamadas ao vivo. PURA (ADR-0006).
 *
 * 🔴 SÓ DOIS ESTADOS, e a escassez é o ponto.
 *
 * Uma Ligação ABERTA — `encerrada_em IS NULL` — sabe muito pouco sobre si, e a tela
 * precisa dizer exatamente esse pouco. `amd_status`, `tecla_digitada`, `desfecho` e
 * `hangup_cause` só existem depois que `Fastagi\Banco::gravar()` roda, e ele roda
 * UMA vez, no desligamento, no mesmo UPDATE que preenche `encerrada_em`. Enquanto a
 * chamada está no ar essas colunas são NULL por construção, não por acaso.
 *
 * Uma coluna "AMD" na tabela ao vivo estaria vazia em 100% das linhas. Pior que
 * inútil: pareceria que o AMD não está classificando ninguém.
 *
 * O que resta é a transição que importa de verdade — o tronco entregou a chamada e o
 * handler assumiu — e ela é observável porque `atendida_em` passou a ser escrita na
 * ENTRADA do handler, não só na saída (ver `Fastagi\Banco::marcarAtendida`).
 */
final class EmVoo
{
    /** Originada; o tronco ainda não entregou. */
    public const CHAMANDO = 'chamando';

    /** Atendida; o handler está tocando áudio ou esperando tecla. */
    public const NA_URA = 'na URA';

    /**
     * O estado de uma Ligação aberta.
     *
     * @param ?string $atendidaEm `NULL` enquanto ninguém atendeu
     */
    public static function estado(?string $atendidaEm): string
    {
        return $atendidaEm === null || trim($atendidaEm) === ''
            ? self::CHAMANDO
            : self::NA_URA;
    }

    /**
     * Quanto do teto está em uso, em pontos percentuais.
     *
     * `null` quando não há teto conhecido: sem isso, um teto zerado — Campanha sem
     * `chamadas_simultaneas`, ou `PROSPECTA_CANAIS` ausente — viraria divisão por
     * zero ou, pior, um "0%" tranquilizador em cima de 200 chamadas no ar.
     *
     * Não é limitado a 100. Passar do teto é um fato que o operador precisa VER: foi
     * exatamente o defeito de 26/08/2026, quando cada Campanha ganhava o contratado
     * inteiro e três somavam 300 canais num tronco de 100.
     */
    public static function percentual(int $emVoo, int $teto): ?int
    {
        if ($teto < 1) {
            return null;
        }

        return (int) round($emVoo * 100 / $teto);
    }
}
