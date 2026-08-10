<?php

declare(strict_types=1);

namespace App\Whatsapp;

/**
 * Verificação de `X-Hub-Signature-256`. PURA.
 *
 * 🔴 É A ÚNICA coisa entre o endpoint e a internet aberta.
 *
 * O webhook não pode ficar atrás de autenticação — quem chama é a Meta, não o
 * operador. Então a assinatura é o que separa evento legítimo de qualquer um
 * postando JSON no endereço. Sem ela, um terceiro escreve na tabela de eventos e,
 * pelo worker, marca Ação como entregue, cria Conversa e insere na lista de
 * bloqueio.
 */
final class Assinatura
{
    /**
     * A assinatura confere para este corpo?
     *
     * 🔴 O CORPO TEM QUE SER O CRU, byte a byte. HMAC sobre JSON reserializado
     * falha para todo pedido legítimo: a Meta assina os bytes que enviou, e
     * `json_encode(json_decode($corpo))` muda espaçamento, ordem de escape e
     * notação de número. O sintoma seria "assinatura inválida" em 100% dos casos,
     * mandando trocar o app secret, que estava certo.
     */
    public function confere(string $corpoCru, ?string $cabecalho, string $appSecret): bool
    {
        if ($cabecalho === null || $appSecret === '') {
            return false;
        }

        // A Meta manda `sha256=<hex>`. Aceitar sem o prefixo também não abre brecha
        // e evita falha boba em teste manual com curl.
        $recebida = str_starts_with($cabecalho, 'sha256=') ? substr($cabecalho, 7) : $cabecalho;

        if ($recebida === '') {
            return false;
        }

        $esperada = hash_hmac('sha256', $corpoCru, $appSecret);

        // `hash_equals` e não `===`: comparação de string vaza pelo tempo, e aqui o
        // atacante controla o valor comparado e pode medir. É o caso de livro.
        return hash_equals($esperada, $recebida);
    }

    /**
     * Identificador do evento para idempotência.
     *
     * 🔴 Derivado do CORPO, não de um campo do payload.
     *
     * O payload da Meta não tem um id de evento no topo — tem `entry`, `changes`, e
     * dentro deles `wamid` de mensagem ou de status. Escolher um desses obrigaria a
     * conhecer a forma do payload no ponto de ENTRADA, que é justamente onde o
     * ADR-0002 manda não interpretar nada.
     *
     * A Meta reentrega o MESMO corpo quando não recebe 200, então o resumo do corpo
     * é exatamente a chave de reentrega. Dois eventos diferentes só colidiriam se
     * fossem byte a byte iguais — o que é o mesmo evento.
     */
    public function identificador(string $corpoCru): string
    {
        return hash('sha256', $corpoCru);
    }
}
