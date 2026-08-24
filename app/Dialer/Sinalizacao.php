<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * O que a sinalização disse sobre uma originação, lida de um evento do AMI.
 *
 * 🔴 A LEITURA MORA AQUI, e não no chamador, porque ela ERRAVA.
 *
 * As três linhas que faziam isto viviam em `bin/dialer.php`, num laço sem teste
 * nenhum, e enquanto ficaram fora de um módulo não houve onde escrever um teste
 * que as exercitasse. Agora há.
 *
 * 🔴 E A MEDIÇÃO DE 24/08/2026 DERRUBOU A HIPÓTESE QUE ESTAVA AQUI.
 *
 * Este arquivo afirmava que o Q.850 consertaria as Ligações que viram
 * `desconhecido`. Ele não conserta. Medido sobre 8.829 Ligações de campanha real,
 * 46 minutos:
 *
 * - 88,4% dos resultados chegam por `OriginateResponse`, e esse evento **não traz
 *   `Cause`, nem `TechCause`, nem `Cause-txt`**. Só traz `Reason`.
 * - O Q.850 só existe no `Hangup` (11,6%), e lá é REDUNDANTE com o código SIP que
 *   a `Politica` já lia: 19→480, 1→404, 21→403, 34→503.
 * - Dos 4.371 `desconhecido`: `Reason=3` 46,6%, `Reason=0` 50,4%, e Q.850 com
 *   informação nova (102, 127, 18, 28) apenas 2,3%.
 *
 * Mapear Q.850 consertaria ~2% do problema. Quem conserta é o `Reason`, e é por
 * isso que `controleDeOriginacao()` existe. O `q850` continua exposto porque é o
 * único dado dos ~100 do `Hangup` — mas ele não é a regra, e não é trabalho
 * pendente.
 */
final class Sinalizacao
{
    /**
     * O `Reason` do `OriginateResponse` é o enum `AST_CONTROL` do Asterisk.
     *
     * 🔴 NÃO é Q.850 e NÃO é código SIP, e confundir os três é fácil porque os
     * números são pequenos e plausíveis nas três tabelas — `3` é `RINGING` aqui,
     * "No route to destination" no Q.850 e nada no SIP.
     *
     * `ATENDEU` está confirmado por medição direta: em 24/08/2026, as 3.155
     * ocorrências de `Reason: 4` correspondem a 3.155 Ligações com `atendida_em`
     * preenchido — sem uma exceção. `TOCANDO` e `NENHUM` são inferidos pela
     * assinatura de duração, e a nota de `Politica` registra o que isso custa.
     */
    public const CONTROLE_NENHUM = 0;
    public const CONTROLE_TOCANDO = 3;
    public const CONTROLE_ATENDEU = 4;

    private function __construct(
        /**
         * O código SIP como texto, ex.: `SIP 503 Service Unavailable`.
         *
         * `TechCause` só existe a partir do Asterisk 22.7.0 — é o piso de versão
         * do projeto. O recurso para `Cause-txt` é INÚTIL por construção e está
         * aqui só porque estava antes: `Cause-txt` é prosa (`User alerting, no
         * answer`) e `codigoNumerico()` procura três dígitos seguidos. Ver o
         * teste que fixa esse fato.
         */
        public readonly ?string $codigoSip,
        /** `Cause-txt` cru, como gravado em `ligacao.hangup_cause`. */
        public readonly ?string $hangupCause,
        /**
         * O Q.850 numérico do campo `Cause`. MEDIÇÃO, e vai continuar sendo.
         *
         * Só chega em `Hangup`, e lá quase sempre repete o que o código SIP já
         * disse. Guardado pelos ~100 casos em que diz algo novo — 102 e 127 —, não
         * porque vá virar regra.
         */
        public readonly ?int $q850,
        /**
         * `Reason` do `OriginateResponse`. É POR AQUI QUE A DECISÃO SAI hoje, para
         * 96,9% das Ligações que antes viravam `desconhecido`.
         */
        public readonly ?string $reason,
        /** `DialStatus`, com recurso ao `Reason` — a combinação que já valia. */
        public readonly ?string $dialStatus,
    ) {
    }

    /** @param array<string,mixed> $evento */
    public static function doEvento(array $evento): self
    {
        return new self(
            codigoSip: self::texto($evento['TechCause'] ?? null) ?? self::texto($evento['Cause-txt'] ?? null),
            hangupCause: self::texto($evento['Cause-txt'] ?? null),
            q850: self::inteiroOuNulo($evento['Cause'] ?? null),
            reason: self::texto($evento['Reason'] ?? null),
            dialStatus: self::texto($evento['DialStatus'] ?? null) ?? self::texto($evento['Reason'] ?? null),
        );
    }

    /**
     * O `Reason` como número, ou `null` quando o evento não trouxe um.
     *
     * `Hangup` nunca traz: nas 970 medidas em 24/08/2026 o campo esteve ausente em
     * todas. Quem traz é o `OriginateResponse`.
     */
    public function controleDeOriginacao(): ?int
    {
        return is_numeric($this->reason) ? (int) $this->reason : null;
    }

    /**
     * O evento afirma, por si só, que a chamada foi atendida?
     *
     * `Reason: 4` entra aqui porque é a mesma afirmação por outro campo, e porque
     * deixá-lo de fora tinha custo medido: 45 Ligações atendidas por dia de
     * campanha caíam em `desconhecido` e eram marcadas para RETENTATIVA — o
     * discador ligaria de novo para quem já tinha atendido.
     */
    public function atendeu(): bool
    {
        return $this->dialStatus === 'ANSWER'
            || $this->controleDeOriginacao() === self::CONTROLE_ATENDEU;
    }

    /**
     * Os três dígitos do código SIP, ou `null`.
     *
     * `"SIP 503 Service Unavailable"` vira `503`. Prosa sem três dígitos seguidos
     * vira `null` — e como o `OriginateResponse` não traz código nenhum, este
     * método devolve `null` em 88,4% dos eventos. É por isso que a `Politica` não
     * pode depender só dele.
     */
    public function codigoNumerico(): ?int
    {
        if ($this->codigoSip === null) {
            return null;
        }

        return preg_match('/(\d{3})/', $this->codigoSip, $m) === 1 ? (int) $m[1] : null;
    }

    private static function texto(mixed $v): ?string
    {
        return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
    }

    private static function inteiroOuNulo(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
