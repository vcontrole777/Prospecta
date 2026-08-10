<?php

declare(strict_types=1);

namespace App\Whatsapp;

/**
 * Tradução dos estados de entrega da Meta para os nossos enums. PURA.
 *
 * 🔴 SÃO CINCO, não quatro. Além de enviado, entregue, lido e falhou existe
 * `played`, para mensagem de áudio. Código que assume quatro trata o quinto como
 * desconhecido — e o sintoma é uma mensagem que o Lead ouviu aparecer como apenas
 * entregue.
 *
 * 🔴 E O MESMO ESTADO TEM DOIS NOMES NO NOSSO ESQUEMA.
 *
 * `conversa_mensagem.status` usa `reproduzido`; `acao.status` usa `tocado`. É a mesma
 * coisa com duas palavras, e é divergência de vocabulário no banco — o tipo de coisa
 * que o `CONTEXT.md` existe para evitar. Enquanto os dois enums existirem assim, o
 * mapeamento vive AQUI, num lugar só, para a divergência não se espalhar por cada
 * consulta que atualiza estado.
 *
 * O conserto de verdade é uma migration alinhando as duas colunas. Fica registrado
 * como dívida nomeada em vez de virar dois mapas espalhados.
 */
final class Estados
{
    /** O que a Meta manda em `statuses[].status`. */
    private const DA_META = ['sent', 'delivered', 'read', 'played', 'failed'];

    /** @var array<string,string> */
    private const PARA_MENSAGEM = [
        'sent' => 'enviado',
        'delivered' => 'entregue',
        'read' => 'lido',
        'played' => 'reproduzido',
        'failed' => 'falhou',
    ];

    /** @var array<string,string> */
    private const PARA_ACAO = [
        'sent' => 'enviado',
        'delivered' => 'entregue',
        'read' => 'lido',
        'played' => 'tocado',
        'failed' => 'falhou',
    ];

    /**
     * Ordem de PROGRESSO. Existe porque webhook chega FORA DE ORDEM: `read` pode
     * chegar antes de `delivered`, e aplicar cegamente faria a mensagem regredir de
     * lida para entregue na tela.
     *
     * @var array<string,int>
     */
    private const PROGRESSO = [
        'pendente' => 0,
        'enviado' => 1,
        'entregue' => 2,
        'lido' => 3,
        'reproduzido' => 4,
        'tocado' => 4,
        // `falhou` fica fora da escala: não é etapa, é término. Ver `avanca`.
    ];

    public function conhecido(string $daMeta): bool
    {
        return in_array($daMeta, self::DA_META, true);
    }

    public function paraMensagem(string $daMeta): ?string
    {
        return self::PARA_MENSAGEM[$daMeta] ?? null;
    }

    public function paraAcao(string $daMeta): ?string
    {
        return self::PARA_ACAO[$daMeta] ?? null;
    }

    /**
     * O estado novo é um AVANÇO sobre o atual?
     *
     * 🔴 Webhook chega fora de ordem, e sem esta checagem a tela regride: `read`
     * antes de `delivered` faria "lido" virar "entregue" depois.
     *
     * `falhou` é caso próprio: só se aplica sobre estado anterior a `entregue`. Uma
     * mensagem já entregue que recebe `failed` depois é evento tardio de outra
     * tentativa, e sobrescrever apagaria o fato de que ela chegou.
     */
    public function avanca(?string $atual, string $novo): bool
    {
        if ($atual === null || $atual === '') {
            return true;
        }

        if ($novo === 'falhou') {
            return (self::PROGRESSO[$atual] ?? 0) < self::PROGRESSO['entregue'];
        }

        if ($atual === 'falhou') {
            // Falhou e depois chegou: o sucesso vence, porque é mais recente na
            // realidade — a falha era de uma tentativa anterior.
            return true;
        }

        return (self::PROGRESSO[$novo] ?? 0) > (self::PROGRESSO[$atual] ?? 0);
    }
}
