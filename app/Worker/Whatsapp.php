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
     * 🔴 POSICIONAL E NOMEADO, e a diferença é UMA chave no JSON. A Meta permite
     * template com `{{nome}}` no lugar de `{{1}}`; nesse caso cada parâmetro precisa
     * levar `parameter_name` junto do valor. Sem isso ela recusa com o MESMO
     * `(#132000)` do cabeçalho ausente — mensagem que fala de "parameters" e não
     * diz qual dos vários sentidos disso está errado.
     *
     * @param list<string> $parametros valores do corpo, NA ORDEM
     * @param ?string $imagemUrl URL pública da imagem do cabeçalho, quando o
     *        template declara `HEADER format=IMAGE`. A Meta busca por HTTP.
     * @param list<string> $nomes nomes dos parâmetros, na MESMA ordem dos valores.
     *        Vazio = template posicional, que é o caso da maioria.
     * @param bool $porMarketingApi manda pela Marketing Messages API em vez do
     *        `/messages`. O CORPO é idêntico — muda só o recurso. Ver `recurso()`.
     */
    public function enviarTemplate(
        string $para,
        string $template,
        string $idioma,
        array $parametros,
        ?string $correlacao = null,
        ?string $imagemUrl = null,
        array $nomes = [],
        bool $porMarketingApi = false,
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
            $doCorpo = [];
            foreach ($parametros as $i => $valor) {
                $p = ['type' => 'text', 'text' => $valor];
                // `parameter_name` SÓ quando há nome. Mandá-lo num template
                // posicional é recusa igual — o erro da Meta é o mesmo nos dois
                // sentidos, e por isso quem decide é o formato do template, lido em
                // `TemplatesAprovados::nomes`.
                if (isset($nomes[$i]) && $nomes[$i] !== '') {
                    $p['parameter_name'] = $nomes[$i];
                }
                $doCorpo[] = $p;
            }

            $componentes[] = ['type' => 'body', 'parameters' => $doCorpo];
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
            $this->recurso($porMarketingApi ? 'marketing_messages' : 'messages'),
            $corpo,
            ['Authorization' => 'Bearer ' . $this->token],
        );

        return $this->interpretar($r);
    }

    /**
     * A URL do recurso, sob o mesmo número.
     *
     * 🔴 O CORPO É IDÊNTICO NOS DOIS, e por isso não há um segundo método de envio.
     * A Marketing Messages API recebe o mesmo JSON que o `/messages`; o que muda é o
     * caminho, e com ele o tratamento que a Meta dá à entrega. Duplicar a montagem
     * dos componentes — cabeçalho, corpo, parâmetro nomeado — para trocar uma
     * palavra na URL criaria duas verdades sobre a forma da mensagem, e elas
     * divergiriam na primeira mudança.
     */
    private function recurso(string $nome): string
    {
        return sprintf('https://graph.facebook.com/%s/%s/%s', $this->versaoApi, $this->numeroId, $nome);
    }

    /**
     * Texto livre — a resposta do operador dentro da janela de 24 h.
     *
     * 🔴 NUNCA PELA MARKETING API. Texto livre dentro da janela de 24 h não é
     * marketing, e a MM API não o aceita. A separação é do CHAMADOR: este método só
     * conhece o `/messages`.
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
            $this->recurso('messages'),
            $corpo,
            ['Authorization' => 'Bearer ' . $this->token],
        );

        return $this->interpretar($r);
    }

    /**
     * Texto com um BOTÃO DE LINK — mensagem interativa `cta_url`.
     *
     * 🔴 SÓ DENTRO DA JANELA DE 24 H, exatamente como `enviarTexto`, e quem verifica
     * isso NÃO é este método. Fora dela a Meta recusa interativo como recusa texto
     * livre, e a checagem mora em `Conversas` antes de a Ação nascer, com reconfere
     * no worker no instante do envio.
     *
     * 🔴 O TOQUE NÃO VOLTA. `cta_url` abre o navegador da pessoa e o WhatsApp não
     * emite evento nenhum — ao contrário do botão de resposta rápida, cujo toque
     * chega como mensagem de entrada e ABRE a janela. Portanto: este botão não
     * renova janela, não gera Conversa e não conta como engajamento.
     *
     * A URL é responsabilidade de quem cadastrou. A Meta exige `https` e recusa o
     * resto; `RespostasRapidas` recusa antes, com o motivo escrito.
     */
    public function enviarTextoComBotao(
        string $para,
        string $texto,
        string $botaoTexto,
        string $botaoUrl,
        ?string $correlacao = null,
    ): Envio {
        $corpo = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $para,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'cta_url',
                'body' => ['text' => $texto],
                'action' => [
                    'name' => 'cta_url',
                    'parameters' => ['display_text' => $botaoTexto, 'url' => $botaoUrl],
                ],
            ],
        ];

        if ($correlacao !== null) {
            $corpo['biz_opaque_callback_data'] = $correlacao;
        }

        $r = $this->transporte->postJson(
            $this->recurso('messages'),
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
