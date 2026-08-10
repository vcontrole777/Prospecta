<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * Traduz o resultado de uma originação em decisão sobre o Lead. PURA.
 *
 * Esta classe É a tabela medida em 2026-08-09 contra tronco real. Antes dela o
 * projeto tinha uma suposição errada registrada como regra — ver o parágrafo do
 * `503` abaixo.
 *
 * | Condição                   | SIP   | Q.850 | Canal |
 * |----------------------------|-------|-------|-------|
 * | DDD inexistente            | 503   | 34    | 0 s   |
 * | Número inexistente         | 404   | 1     | 0 s   |
 * | Aparelho desligado         | 480   | 19    | 29 s  |
 * | Não perturbe               | 486   | 17    | —     |
 * | Não atendeu (nosso CANCEL) | 487   | —     | timeout nosso |
 * | Atendeu                    | 200   | —     | —     |
 */
final class Politica
{
    /**
     * Códigos que PODEM ser do lado da conta e por isso contam para a proteção.
     *
     * `503` está aqui apesar de ter quatro causas medidas — três delas do
     * destino. É justamente essa ambiguidade que obriga a proteção a ser por
     * TAXA com amostra mínima, e não por sequência crua. Ver `Protecao`.
     */
    private const CODIGOS_DE_CONTA = [401, 402, 403, 407, 503];

    public function decidir(?string $codigoSip, ?string $dialStatus, bool $atendeu): Decisao
    {
        if ($atendeu || $dialStatus === 'ANSWER') {
            // `desfecho` fica NULL de propósito: o `fastagi` é o dono. Ver Decisao.
            return new Decisao(Acao::Finalizado, true, false, 'atendida', null);
        }

        $codigo = $this->numero($codigoSip);

        // 404 é PERMANENTE. Não atribuído nunca vai funcionar, então aposenta na
        // hora em vez de gastar max_tentativas em toda rodada.
        if ($codigo === 404) {
            return new Decisao(Acao::Aposentar, true, false, 'numero_inexistente', 'invalido');
        }

        // 486 nesta rota significa NÃO PERTURBE, não ocupado — medido com
        // chamada em espera desativada. É escolha deliberada de quem recebe:
        // mais perto de um sinal fraco de opt-out do que de "tenta mais tarde".
        if ($codigo === 486) {
            return new Decisao(Acao::RecuoLongo, true, false, 'nao_perturbe', 'nao_atendeu');
        }

        // 480: aparelho desligado ou fora de área. TEMPORÁRIO — o aparelho pode
        // voltar. A rota toca anúncio em mídia antecipada e ocupa ~29 s de canal.
        if ($codigo === 480) {
            return new Decisao(Acao::Retentar, true, false, 'nao_alcancavel', 'nao_atendeu');
        }

        // 487 é NOSSO CANCEL, pelo timeout de toque. Não é recusa da rota.
        if ($codigo === 487) {
            return new Decisao(Acao::Retentar, true, false, 'nao_atendeu', 'nao_atendeu');
        }

        if ($codigo !== null && in_array($codigo, self::CODIGOS_DE_CONTA, true)) {
            // 🔴 `503` CONSOME a tentativa. A formulação anterior do projeto era
            // "erro do lado da conta não consome tentativa" — e a medição a
            // derrubou: `503` também vem para número ruim, então isentá-lo faria
            // número ruim ser rediscado para sempre, com a lista nunca
            // terminando. A proteção do tronco é o contador, não a isenção.
            // Desfecho `erro`, NÃO `nao_atendeu`. O 503 é falha de circuito ou de
            // conta, e registrá-lo como não atendeu diria ao operador que o Lead
            // não quis falar quando o tronco é que caiu — ele descartaria a lista
            // em vez de olhar o tronco. O `codigo_sip` guarda o detalhe.
            return new Decisao(Acao::Retentar, true, true, 'erro_de_conta_ou_rota', 'erro');
        }

        // Código desconhecido: retenta e NÃO conta para a proteção. Contar
        // desconhecido arriscaria pausar Campanha por uma condição nova e
        // inofensiva; e a proteção já cobre o caso que importa, que é o tronco
        // devolvendo o mesmo erro em tudo.
        return new Decisao(Acao::Retentar, true, false, 'desconhecido', 'erro');
    }

    private function numero(?string $codigoSip): ?int
    {
        if ($codigoSip === null) {
            return null;
        }
        // "SIP 503 Service Unavailable" -> 503
        return preg_match('/(\d{3})/', $codigoSip, $m) === 1 ? (int) $m[1] : null;
    }
}
