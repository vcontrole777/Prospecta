<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Lê um CloudEvent do httpSMS. PURA.
 *
 * Mesma disciplina de `Whatsapp\Leitura`: interpretar o payload é regra, e regra
 * mora em classe testável — não no controller, que só precisa dizer 200 depressa.
 */
final class Leitura
{
    /**
     * Os eventos de ENTREGA, e o que cada um significa para nós.
     *
     * 🔴 `message.phone.received` FICA DE FORA de propósito. Ele é SMS de ENTRADA —
     * alguém respondendo —, e a caixa de entrada do produto é modelada em cima do
     * WhatsApp: janela de 24 h, template fora dela, `conversa.telefone` único. Nada
     * disso existe em SMS. Encaixar à força criaria Conversa que mente sobre as
     * próprias regras; é decisão de produto, não de código.
     *
     * `phone.heartbeat.*` também fica fora daqui: não é status de mensagem, é o
     * APARELHO caindo. Ver `heartbeat()`.
     *
     * @var array<string,string> tipo do evento => status nosso
     */
    private const ENTREGA = [
        'message.phone.sent' => 'enviado',
        'message.phone.delivered' => 'entregue',
        'message.send.failed' => 'falhou',
        'message.send.expired' => 'falhou',
    ];

    /**
     * O status de entrega que este evento carrega, ou nulo quando não é um.
     *
     * @param array<array-key,mixed> $evento CloudEvent já decodificado
     */
    public function status(array $evento): ?StatusSms
    {
        $tipo = is_string($evento['type'] ?? null) ? $evento['type'] : '';
        $nosso = self::ENTREGA[$tipo] ?? null;

        if ($nosso === null) {
            return null;
        }

        /** @var array<array-key,mixed> $data */
        $data = is_array($evento['data'] ?? null) ? $evento['data'] : [];

        $id = self::idDaMensagem($data);
        if ($id === null) {
            return null;
        }

        return new StatusSms(
            id: $id,
            status: $nosso,
            erro: self::texto($data['error_message'] ?? null),
            requestId: self::texto($data['request_id'] ?? null),
        );
    }

    /**
     * 🔴 O NOME DO CAMPO DO ID MUDA ENTRE EVENTOS, e ler só um deles perde metade.
     *
     * Conferido na documentação do httpSMS, quadro a quadro:
     *
     *   message.phone.sent       -> data.id
     *   message.phone.delivered  -> data.id
     *   message.send.failed      -> data.id
     *   message.send.expired     -> data.message_id   ← diferente
     *   message.phone.received   -> data.message_id   ← diferente
     *
     * Não é erro nosso nem suposição: é assim que a plataforma manda. Quem lesse só
     * `data.id` veria a expiração passar em silêncio — e expiração é justamente o
     * caso em que a mensagem NÃO saiu, o que mais importa saber.
     *
     * @param array<array-key,mixed> $data
     */
    private static function idDaMensagem(array $data): ?string
    {
        return self::texto($data['id'] ?? null) ?? self::texto($data['message_id'] ?? null);
    }

    /**
     * O aparelho caiu ou voltou?
     *
     * 🔴 ISTO É O REMETENTE MORRENDO, e sem o aviso a Campanha simplesmente para de
     * mandar. Diferente do WhatsApp, onde o remetente é a nuvem da Meta, aqui é um
     * celular que descarrega, perde sinal ou é desligado por alguém. Uma linha no log
     * é a diferença entre "o SMS parou" e "o SMS parou PORQUE o aparelho está fora".
     *
     * @param array<array-key,mixed> $evento
     * @return ?bool true = voltou, false = caiu, null = não é evento de aparelho
     */
    public function heartbeat(array $evento): ?bool
    {
        return match ($evento['type'] ?? null) {
            'phone.heartbeat.online' => true,
            'phone.heartbeat.offline' => false,
            default => null,
        };
    }

    /**
     * O `id` do CloudEvent, que é o que dá idempotência.
     *
     * O httpSMS reentrega quando recebe 5XX — até quatro vezes. Guardar por este id
     * faz o reprocessamento ser inofensivo, como o `insertOrIgnore` do WhatsApp.
     *
     * @param array<array-key,mixed> $evento
     */
    public function identificador(array $evento): ?string
    {
        return self::texto($evento['id'] ?? null);
    }

    private static function texto(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : null;
    }
}
