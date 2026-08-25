<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Envio de template pela WhatsApp Cloud API.
 *
 * Monta a requisição e traduz a resposta num `Envio`. Não decide nada sobre
 * retentativa — isso é da `PoliticaDeEnvio`.
 */
final class Whatsapp
{
    public function __construct(
        private readonly Transporte $transporte,
        private readonly string $token,
        private readonly string $numeroId,
        private readonly string $versaoApi = 'v21.0',
    ) {
    }

    /**
     * 🔴 OS BOTÕES NÃO VÃO NO ENVIO.
     *
     * Resposta rápida é definida na CRIAÇÃO do template, e o envio não a
     * referencia. É fácil errar porque procurar "como mandar botões" leva a
     * exemplos de mensagem interativa, que é outro recurso — e o payload daquele
     * recurso é recusado para template.
     *
     * Isso importa para o produto: é o toque no botão que abre a janela de
     * atendimento de 24 h, dentro da qual texto livre é ilimitado e não custa por
     * mensagem. O template pago é o pedágio de entrada.
     *
     * 🔴 O `header` FALTAVA, e isso tornava dois templates inenviáveis.
     *
     * Este método montava só o componente `body`. Template com `HEADER
     * format=IMAGE` exige também um componente `header` com a imagem em TODO envio,
     * e sem ele a Meta recusa com `(#132000) Number of parameters does not match the
     * expected number of params` — mensagem que fala de "parameters" e não menciona
     * imagem, o que mandou a investigação para o lado errado.
     *
     * Medido em 25/08/2026 contra a conta real: `abordagem_liv_pts` e
     * `abordagem_liv_pts_2` têm cabeçalho de imagem e falhavam em todo disparo.
     *
     * 🔴 E MANDAR O CABEÇALHO ONDE ELE NÃO É PEDIDO É O MESMO ERRO AO CONTRÁRIO.
     * `$imagemUrl` nulo não emite o componente; quem decide é o formato declarado
     * pelo template, lido em `TemplatesAprovados::cabecalho`.
     *
     * @param list<string> $parametros posicionais do corpo do template
     * @param ?string $imagemUrl URL pública da imagem do cabeçalho, quando o
     *        template declara `HEADER format=IMAGE`. A Meta busca por HTTP.
     */
    public function enviarTemplate(
        string $para,
        string $template,
        string $idioma,
        array $parametros,
        ?string $correlacao = null,
        ?string $imagemUrl = null,
    ): Envio {
        $corpo = [
            'messaging_product' => 'whatsapp',
            'to' => $para,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $idioma],
            ],
        ];

        $componentes = [];

        // Cabeçalho ANTES do corpo. A Meta aceita em qualquer ordem, mas a ordem do
        // template é a que se lê no gerenciador dela — divergir aqui só dificulta a
        // conferência de quem for depurar um envio recusado.
        if ($imagemUrl !== null && $imagemUrl !== '') {
            $componentes[] = [
                'type' => 'header',
                'parameters' => [['type' => 'image', 'image' => ['link' => $imagemUrl]]],
            ];
        }

        if ($parametros !== []) {
            $componentes[] = [
                'type' => 'body',
                'parameters' => array_map(
                    static fn (string $v): array => ['type' => 'text', 'text' => $v],
                    $parametros,
                ),
            ];
        }

        if ($componentes !== []) {
            $corpo['template']['components'] = $componentes;
        }

        // Viaja no envio e volta nos webhooks. É o campo que fecha o laço sem
        // tabela de tradução — mesmo padrão do `ChannelId` no AMI.
        if ($correlacao !== null) {
            $corpo['biz_opaque_callback_data'] = $correlacao;
        }

        $r = $this->transporte->postJson(
            sprintf('https://graph.facebook.com/%s/%s/messages', $this->versaoApi, $this->numeroId),
            $corpo,
            ['Authorization' => 'Bearer ' . $this->token],
        );

        return $this->interpretar($r);
    }

    /**
     * Texto livre — a resposta do operador dentro da janela de 24 h.
     *
     * 🔴 SÓ DENTRO DA JANELA, e quem verifica isso NÃO é este método. A Meta recusa
     * texto livre fora dela com erro 131047, e o operador leria "falhou" sem saber por
     * quê. A janela é checada em `Conversas` antes de a Ação nascer, e reconferida no
     * worker no instante do envio — porque entre digitar e enviar pode passar tempo, e
     * uma janela que vira no meio é caso real, não hipótese.
     *
     * `preview_url` fica FALSO: prévia de link muda a altura da mensagem e carrega
     * imagem de terceiro para o Lead. Quem quiser mandar link, manda o link.
     */
    public function enviarTexto(string $para, string $texto, ?string $correlacao = null): Envio
    {
        $corpo = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $texto],
        ];

        if ($correlacao !== null) {
            $corpo['biz_opaque_callback_data'] = $correlacao;
        }

        $r = $this->transporte->postJson(
            sprintf('https://graph.facebook.com/%s/%s/messages', $this->versaoApi, $this->numeroId),
            $corpo,
            ['Authorization' => 'Bearer ' . $this->token],
        );

        return $this->interpretar($r);
    }

    /** @param array{http:int, corpo:string, erro:?string} $r */
    private function interpretar(array $r): Envio
    {
        if ($r['erro'] !== null && $r['http'] === 0) {
            // Sem resposta nenhuma: é o único caso em que se sabe que a mensagem
            // não saiu, e por isso o único em que retentar é seguro.
            return new Envio(http: 0, semResposta: true, mensagem: $r['erro']);
        }

        $dados = json_decode($r['corpo'], true);
        if (! is_array($dados)) {
            return new Envio(http: $r['http'], mensagem: 'resposta não é JSON');
        }

        $wamid = null;
        $mensagens = $dados['messages'] ?? null;
        if (is_array($mensagens) && isset($mensagens[0]) && is_array($mensagens[0])) {
            $id = $mensagens[0]['id'] ?? null;
            $wamid = is_string($id) ? $id : null;
        }

        $erro = $dados['error'] ?? null;
        if (! is_array($erro)) {
            return new Envio(http: $r['http'], wamid: $wamid);
        }

        return new Envio(
            http: $r['http'],
            wamid: $wamid,
            codigo: is_numeric($erro['code'] ?? null) ? (int) $erro['code'] : null,
            transitorio: ($erro['is_transient'] ?? false) === true,
            mensagemDoUsuario: is_string($erro['error_user_msg'] ?? null) ? $erro['error_user_msg'] : null,
            fbtrace: is_string($erro['fbtrace_id'] ?? null) ? $erro['fbtrace_id'] : null,
            mensagem: is_string($erro['message'] ?? null) ? $erro['message'] : null,
        );
    }
}
