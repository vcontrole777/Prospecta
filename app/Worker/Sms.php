<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Envio de SMS pelo httpSMS — um celular Android com a nossa própria API.
 *
 * 🔴 NÃO É GATEWAY DE OPERADORA, e isso muda o que se pode esperar. Quem manda a
 * mensagem é um APARELHO com um chip, rodando o app do httpSMS: a vazão é a de um
 * telefone, o custo é o do plano do chip, e o aparelho pode estar sem sinal,
 * descarregado ou desligado. Um gateway aceita milhares por minuto; isto não.
 *
 * Consequência prática: o `aceito` daqui significa "o httpSMS enfileirou para o
 * aparelho", não "a operadora entregou". A entrega de verdade chega por webhook,
 * que ainda não é consumido — ver `Worker\FilaDeEventos`, que hoje só trata
 * WhatsApp.
 *
 * 🔴 REUSA `Envio` E `PoliticaDeEnvio`, e a razão é a parte cara delas: a regra de
 * QUANDO é seguro retentar. O httpSMS também não tem chave de idempotência — ele
 * tem `request_id`, que serve para o cliente rastrear, não para a plataforma
 * deduplicar —, então a premissa que governa a política vale igual: retentar
 * depois de timeout PODE mandar duas mensagens para a mesma pessoa.
 *
 * Os códigos específicos da Meta que a política conhece — `131049`, `190` — não
 * aparecem aqui e simplesmente não disparam. O que vale é o genérico, e é o que
 * importa: 4xx não retenta, 5xx e falha de conexão retentam.
 *
 * O campo `wamid` do `Envio` carrega o id do httpSMS. O nome é do WhatsApp e fica
 * torto aqui; trocá-lo mexeria em `PoliticaDeEnvio`, `FilaDeAcoes`,
 * `conversa_mensagem` e nos webhooks, e o ganho seria só de nome.
 */
final class Sms
{
    public function __construct(
        private readonly Transporte $transporte,
        private readonly string $apiKey,
        /**
         * O número do APARELHO que envia, em E.164.
         *
         * Não é BINA configurável: é o chip que está no celular rodando o app. O
         * httpSMS usa este campo para saber qual aparelho seu deve mandar.
         */
        private readonly string $remetente,
        private readonly string $endpoint = 'https://api.httpsms.com/v1/messages/send',
    ) {
    }

    /**
     * Enfileira um SMS para o aparelho mandar.
     *
     * @param string $para telefone canônico do produto — só dígitos, com país
     * @param ?string $correlacao vira `request_id`, que volta nos webhooks do
     *        httpSMS. Mesmo papel do `biz_opaque_callback_data` no WhatsApp.
     */
    public function enviar(string $para, string $texto, ?string $correlacao = null): Envio
    {
        $corpo = [
            /*
             * 🔴 E.164 COM O `+`, e o produto guarda SEM ele.
             *
             * `Dominio\Telefone` normaliza para só dígitos — `5511987654321` —
             * porque é o que o Asterisk quer no `Originate`. O httpSMS recusa sem o
             * `+`, e a recusa é um 422 que fala de formato, não de telefone.
             */
            'from' => self::e164($this->remetente),
            'to' => self::e164($para),
            'content' => $texto,
        ];

        if ($correlacao !== null) {
            $corpo['request_id'] = $correlacao;
        }

        $r = $this->transporte->postJson($this->endpoint, $corpo, [
            // `x-api-key`, e NÃO `Authorization: Bearer`. O httpSMS usa cabeçalho
            // próprio, e mandar Bearer devolve 401 sem dizer o que faltou.
            'x-api-key' => $this->apiKey,
        ]);

        return $this->interpretar($r);
    }

    /** @param array{http:int, corpo:string, erro:?string} $r */
    private function interpretar(array $r): Envio
    {
        if ($r['erro'] !== null && $r['http'] === 0) {
            // Sem resposta nenhuma: o único caso em que se SABE que nada saiu, e
            // por isso o único em que retentar é seguro. Mesma leitura do WhatsApp.
            return new Envio(http: 0, semResposta: true, mensagem: $r['erro']);
        }

        $dados = json_decode($r['corpo'], true);
        if (! is_array($dados)) {
            return new Envio(http: $r['http'], mensagem: 'resposta não é JSON');
        }

        $id = null;
        $d = $dados['data'] ?? null;
        if (is_array($d) && is_string($d['id'] ?? null)) {
            $id = $d['id'];
        }

        $mensagem = is_string($dados['message'] ?? null) ? $dados['message'] : null;

        /*
         * 🔴 SEM `id` NÃO É ACEITO, mesmo com 200.
         *
         * `Envio::aceito()` já exige o id, e é a regra certa: resposta 200 sem
         * identificador é resposta que não permite casar o webhook de entrega
         * depois. Tratar como aceito criaria uma Ação `enviado` que nunca ganharia
         * status — indistinguível de uma que a operadora engoliu.
         */
        return new Envio(
            http: $r['http'],
            wamid: $id,
            mensagem: $mensagem,
        );
    }

    /**
     * O canônico do produto vira E.164.
     *
     * Idempotente de propósito: número que já venha com `+` não ganha outro.
     */
    private static function e164(string $telefone): string
    {
        $so = preg_replace('/\D/', '', $telefone) ?? $telefone;

        return '+' . $so;
    }
}
