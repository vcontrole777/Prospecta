<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * O que a sinalização disse sobre uma originação, lida de um evento do AMI.
 *
 * 🔴 A LEITURA MORA AQUI, e não no chamador, porque ela ERRA.
 *
 * As três linhas que faziam isto viviam em `bin/dialer.php`, num laço sem teste
 * nenhum, e a medição de 22/08/2026 mostrou o custo: 20.713 Ligações — 77,3% de
 * todas as não atendidas — chegam sem código SIP e viram `desconhecido`, com
 * desfecho `erro`. 62,6% delas duram entre 28 e 42 s contra um
 * `timeout_toque_seg` de 28: é o nosso próprio cancelamento por não atender,
 * registrado como falha do sistema.
 *
 * Enquanto a leitura ficou fora de um módulo não houve onde escrever um teste
 * que a exercitasse. Agora há.
 *
 * Esta classe é PURA e ainda NÃO MUDA DECISÃO NENHUMA: ela expõe o que já era
 * lido, mais o Q.850 que já chegava e era jogado fora. O mapeamento do Q.850
 * vira regra na `Politica` depois que a distribuição real desta rota for
 * colhida — registrar antes de mapear é deliberado, para o mapa sair do que a
 * rota faz e não do que o padrão prevê.
 */
final class Sinalizacao
{
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
         * O Q.850 numérico do campo `Cause`. MEDIÇÃO, não decisão — ainda.
         *
         * É o dado que conserta os 77,3%: medido em 22/08/2026 originando um
         * canal `Local` para um contexto que não atende, o `Hangup` traz
         * `Cause: 19` e `Cause-txt: User alerting, no answer`. Nenhuma linha do
         * projeto lia este campo.
         */
        public readonly ?int $q850,
        /** `Reason` do `OriginateResponse`. Também medição por enquanto. */
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

    /** O evento afirma, por si só, que a chamada foi atendida? */
    public function atendeu(): bool
    {
        return $this->dialStatus === 'ANSWER';
    }

    /**
     * Os três dígitos do código SIP, ou `null`.
     *
     * `"SIP 503 Service Unavailable"` vira `503`. Prosa sem três dígitos seguidos
     * vira `null`, que é o caminho por onde os 77,3% escapam hoje.
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
