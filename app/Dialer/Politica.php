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

    /**
     * 🔴 RECEBE O EVENTO, não três escalares já extraídos.
     *
     * A assinatura anterior empurrava "como ler um evento AMI" para o único
     * chamador — um laço em `bin/dialer.php` sem teste nenhum. A leitura errada
     * que vive lá custa 77,3% das Ligações não atendidas classificadas como
     * falha do sistema; ver `Sinalizacao`. Com o evento entrando inteiro, a
     * extração passa a ser coberta pela suíte pura que já existe para esta
     * classe.
     *
     * Nenhuma decisão muda aqui: o mapeamento do Q.850 é o passo seguinte, e
     * espera a distribuição real da rota.
     *
     * @param array<string,mixed> $evento
     * @param bool $atendeuObservado o que os observadores FORA do evento dizem —
     *        o `fastagi`, que roda dentro da chamada e por isso é autoridade, e o
     *        `Newstate` guardado desta Ligação. O observador que mora no próprio
     *        evento é lido aqui dentro, por `Sinalizacao::atendeu()`.
     */
    public function decidir(array $evento, bool $atendeuObservado): Decisao
    {
        $sinal = Sinalizacao::doEvento($evento);

        if ($atendeuObservado || $sinal->atendeu()) {
            // `desfecho` fica NULL de propósito: o `fastagi` é o dono. Ver Decisao.
            return new Decisao(Acao::Finalizado, true, false, 'atendida', null, $sinal);
        }

        $codigo = $sinal->codigoNumerico();

        // 404 é PERMANENTE. Não atribuído nunca vai funcionar, então aposenta na
        // hora em vez de gastar max_tentativas em toda rodada.
        if ($codigo === 404) {
            return new Decisao(Acao::Aposentar, true, false, 'numero_inexistente', 'invalido', $sinal);
        }

        // 486 nesta rota significa NÃO PERTURBE, não ocupado — medido com
        // chamada em espera desativada. É escolha deliberada de quem recebe:
        // mais perto de um sinal fraco de opt-out do que de "tenta mais tarde".
        if ($codigo === 486) {
            return new Decisao(Acao::RecuoLongo, true, false, 'nao_perturbe', 'nao_atendeu', $sinal);
        }

        // 480: aparelho desligado ou fora de área. TEMPORÁRIO — o aparelho pode
        // voltar. A rota toca anúncio em mídia antecipada e ocupa ~29 s de canal.
        if ($codigo === 480) {
            return new Decisao(Acao::Retentar, true, false, 'nao_alcancavel', 'nao_atendeu', $sinal);
        }

        // 487 é NOSSO CANCEL, pelo timeout de toque. Não é recusa da rota.
        if ($codigo === 487) {
            return new Decisao(Acao::Retentar, true, false, 'nao_atendeu', 'nao_atendeu', $sinal);
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
            return new Decisao(Acao::Retentar, true, true, 'erro_de_conta_ou_rota', 'erro', $sinal);
        }

        // Código desconhecido: retenta e NÃO conta para a proteção. Contar
        // desconhecido arriscaria pausar Campanha por uma condição nova e
        // inofensiva; e a proteção já cobre o caso que importa, que é o tronco
        // devolvendo o mesmo erro em tudo.
        // 🔴 É AQUI QUE OS 77,3% CAEM, e o Q.850 que os consertaria já está no
        // `$sinal`. Mapear ainda não: a regra sai da distribuição REAL desta
        // rota, colhida do log, e não do que o padrão prevê.
        return new Decisao(Acao::Retentar, true, false, 'desconhecido', 'erro', $sinal);
    }
}
