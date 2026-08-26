#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * O `worker` — remetente de Ação, FORA da Ligação.
 *
 * 🔴 É a metade do ADR-0002 que faltava. A decisão diz que Ação é linha em tabela
 * e nunca requisição HTTP dentro da chamada: o `fastagi` grava `acao` e desliga,
 * porque uma chamada à Graph API com o Lead na linha significa silêncio de vários
 * segundos, e um fornecedor lento vira uma URA travada. Sem este processo, o
 * opt-in era gravado e nunca virava mensagem — o produto registrava interesse e
 * não fazia nada com ele.
 *
 * Serviço persistente, instância única garantida pelo systemd.
 *
 * Bootstrap mínimo (ADR-0001): autoload e PDO, sem framework.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Dominio\ErroDeDominio;
use App\Dominio\Placeholder;
use App\Fastagi\Registro;
use App\Worker\DecisaoDeEnvio;
use App\Worker\Envio;
use App\Whatsapp\JanelaDeAtendimento;
use App\Whatsapp\Leitura;
use App\Whatsapp\OptOut;
use App\Worker\FilaDeAcoes;
use App\Worker\FilaDeEventos;
use App\Worker\Interrupcao;
use App\Seguranca\Cofre;
use App\Worker\PoliticaDeEnvio;
use App\Worker\TransporteCurl;
use App\Sms\Leitura as LeituraSms;
use App\Worker\FilaDeEventosSms;
use App\Worker\Sms;
use App\Worker\Whatsapp;

$conf = require dirname(__DIR__) . '/config/worker.php';

$log = new Registro(STDERR);
$log->capturarTudo();
$log->linha('inicio', 'worker iniciado', ['lote' => $conf['lote']]);

$pdo = new PDO($conf['banco']['dsn'], $conf['banco']['usuario'], $conf['banco']['senha'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$fila = new FilaDeAcoes($pdo, new Cofre($conf['app_key']));

$eventosSms = new FilaDeEventosSms($pdo);
$leituraSms = new LeituraSms();
$eventos = new FilaDeEventos($pdo);
$optOut = new OptOut();
$politica = new PoliticaDeEnvio();
$placeholder = new Placeholder();
$transporte = new TransporteCurl($conf['timeout_http_seg']);

$parada = new Interrupcao();
foreach ([SIGTERM, SIGINT] as $sinal) {
    pcntl_signal($sinal, static function () use ($parada, $log): void {
        $parada->pedir();
        $log->linha('parada', 'sinal recebido, encerrando a volta atual');
    });
}

$avisouCanalAusente = false;
$aguardandoCanalAvisado = -1;

while (! $parada->pedida()) {

    // Canal que ainda não tem remetente. Não é erro, mas precisa aparecer: uma
    // Campanha configurada para SMS pareceria simplesmente não fazer nada.
    $aguardando = $fila->contarAguardandoCanal();
    if ($aguardando > 0 && $aguardando !== $aguardandoCanalAvisado) {
        // Sem `'canal' => 'sms'` fixo: o SMS ganhou remetente, e nomear um canal
        // que já é atendido mandaria quem lesse o log procurar o problema errado.
        $log->linha('aguardando_canal', 'Ações de canal sem remetente implementado', [
            'quantas' => $aguardando,
        ]);
        $aguardandoCanalAvisado = $aguardando;
    }

    // ---- eventos do webhook -------------------------------------------------
    //
    // 🔴 ANTES das Ações, e de propósito. O webhook grava e devolve 200 (ADR-0002,
    // espelho de entrada); é aqui que o evento vira efeito. Vem primeiro porque um
    // opt-out recém-chegado deve bloquear o telefone ANTES de a volta atual disparar
    // mensagem para ele — a ordem inversa manda a mensagem e bloqueia depois.
    //
    // E não depende de integração cadastrada: processar evento é leitura do que já
    // chegou, não envio.
    $lote = $eventos->reivindicar($conf['eventos_por_volta']);

    foreach ($lote as $ev) {
        $payload = json_decode($ev['payload'], true);

        if (! is_array($payload)) {
            // Assinado e gravado, mas ilegível agora. Marca processado para não ficar
            // em laço: reprocessar não vai fazer virar JSON.
            $log->linha('evento_ilegivel', 'payload não é JSON', ['evento' => $ev['id']]);
            $eventos->marcarProcessado($ev['id']);
            continue;
        }

        $leitura = Leitura::de($payload);

        foreach ($leitura->status as $st) {
            if (! $eventos->aplicarStatus($st)) {
                // Estado que a Meta acrescentou. Não é erro nosso, e some no silêncio
                // se não for registrado — a documentação deles é a que muda mais.
                $log->linha('estado_desconhecido', 'estado de entrega não mapeado', [
                    'evento' => $ev['id'],
                    'estado' => $st->estado,
                    'wamid' => $st->wamid,
                ]);
            }
        }

        foreach ($leitura->entradas as $entrada) {
            $telefone = $eventos->registrarEntrada($entrada);

            if ($telefone !== '' && $optOut->pediu($entrada)) {
                $eventos->bloquear($telefone);
                $log->linha('opt_out', 'pedido de descadastro por mensagem', [
                    'evento' => $ev['id'],
                    'telefone' => $telefone,
                    'botao' => $entrada->botao,
                ]);
            }
        }

        if ($leitura->camposIgnorados !== []) {
            $log->linha('campo_ignorado', 'webhook trouxe campo que não tratamos', [
                'evento' => $ev['id'],
                'campos' => implode(',', $leitura->camposIgnorados),
            ]);
        }

        // Marcado por ÚLTIMO: todo efeito acima é idempotente pelo esquema, então
        // uma queda no meio reprocessa sem dano. Marcar antes perderia o evento.
        $eventos->marcarProcessado($ev['id']);
    }

    if ($lote !== []) {
        $log->linha('eventos', 'eventos do webhook processados', [
            'quantos' => count($lote),
            'restam' => $eventos->pendentes(),
        ]);
    }

    // ---- eventos do webhook de SMS ------------------------------------------
    //
    // Antes dos Disparos, como os do WhatsApp: um status que chegou muda o que a
    // tela mostra, e processá-lo depois de uma volta inteira de envio atrasaria a
    // informação sem ganho nenhum.
    $loteSms = $eventosSms->proximos($conf['eventos_por_volta']);

    foreach ($loteSms as $ev) {
        $cloud = json_decode($ev['payload'], true);

        if (is_array($cloud)) {
            $status = $leituraSms->status($cloud);
            if ($status !== null && $eventosSms->aplicar($status)) {
                $log->linha('status_sms', 'estado de entrega aplicado', [
                    'id' => $status->id,
                    'status' => $status->status,
                    'erro' => $status->erro,
                ]);
            }

            /*
             * 🔴 O APARELHO CAINDO É O REMETENTE MORRENDO, e sem isto a Campanha
             * simplesmente para de mandar. No WhatsApp o remetente é a nuvem da
             * Meta; aqui é um celular que descarrega, perde sinal ou é desligado por
             * alguém. Uma linha no log é a diferença entre "o SMS parou" e "o SMS
             * parou PORQUE o aparelho está fora".
             */
            $vivo = $leituraSms->heartbeat($cloud);
            if ($vivo !== null) {
                $log->linha(
                    $vivo ? 'aparelho_online' : 'aparelho_offline',
                    $vivo ? 'o celular do httpSMS voltou' : 'o celular do httpSMS está fora — o SMS não sai',
                    [],
                );
            }
        }

        $eventosSms->marcarProcessado($ev['id']);
    }

    if ($loteSms !== []) {
        $log->linha('eventos_sms', 'eventos do httpSMS processados', [
            'quantos' => count($loteSms),
            'restam' => $eventosSms->pendentes(),
        ]);
    }

    // ---- Ações de disparo ---------------------------------------------------
    $cred = $fila->credenciaisWhatsapp();

    /*
     * 🔴 SÓ O DISPARO VAI PELA MARKETING API. A resposta do operador nas Conversas
     * continua no `/messages` mais abaixo: texto livre dentro da janela de 24 h não
     * é marketing, e a MM API não o aceita.
     *
     * Lido a cada volta, junto das credenciais: é chave para um problema de
     * plataforma — a Meta aceitando a mensagem e desistindo de entregar —, e voltar
     * atrás tem que ser um clique na tela, não um restart.
     */
    $porMarketingApi = $fila->marketingApiAtiva();

    if ($cred === null) {
        // Avisa UMA vez e não a cada volta: sem integração cadastrada isto seria
        // uma linha a cada 5 s, e o log deixaria de servir para achar problema.
        if (! $avisouCanalAusente) {
            $log->linha('sem_integracao', 'nenhuma integração de WhatsApp ativa em configuracao');
            $avisouCanalAusente = true;
        }
        $parada->dormir($conf['intervalo_canal_parado_seg']);
        continue;
    }
    $avisouCanalAusente = false;

    $whatsapp = new Whatsapp(
        $transporte,
        $cred['token'],
        $cred['numero_id'],
        $cred['versao_api'],
    );

    $acoes = $fila->reivindicar($conf['lote']);

    /*
     * 🔴 SÓ REIVINDICA SMS SE HOUVER COM QUE MANDAR.
     *
     * É a mesma disciplina que o filtro antigo tinha: reivindicado sem remetente, o
     * registro gastaria uma tentativa por volta e morreria por esgotamento contra
     * uma configuração incompleta. Sem credencial, ele fica pendente e esperando —
     * que é o estado honesto.
     */
    $credSms = $fila->credenciaisSms();
    $acoesSms = $credSms === null ? [] : $fila->reivindicar($conf['lote'], 'sms');

    // 🔴 AS DUAS FILAS ANTES DA SAÍDA ANTECIPADA, e o lugar disto é load-bearing.
    // Com a reivindicação das respostas depois do `continue` abaixo, responder só
    // funcionava quando havia um Disparo na MESMA volta — ou seja, quase nunca, porque
    // Disparo nasce de Opt-in e resposta nasce de alguém digitando. As duas filas são
    // independentes; a volta só está vazia quando as duas estão.
    $respostas = $fila->reivindicarRespostas($conf['lote']);

    if ($acoes === [] && $acoesSms === [] && $respostas === []) {
        // Ações que sobraram pendentes com as tentativas no teto. Acontece quando o
        // processo morre entre reivindicar e aplicar — a linha volta a pendente com
        // a tentativa já contada. Sem esta varredura ficariam presas para sempre.
        $esgotadas = $fila->marcarEsgotadas($conf['max_tentativas']);
        if ($esgotadas > 0) {
            $log->linha('esgotadas', 'Ações sem tentativa restante encerradas', ['quantas' => $esgotadas]);
        }
        $parada->dormir($conf['intervalo_vazio_seg']);
        continue;
    }

    $canalParado = false;

    foreach ($acoes as $a) {
        // `pedida()` despacha os sinais por dentro — ver Interrupcao.
        if ($parada->pedida()) {
            break;
        }

        $acaoId = numero($a['id'] ?? null);
        $ligacaoId = numero($a['referencia_id'] ?? null);
        $telefone = texto($a['telefone'] ?? null);
        $template = texto($a['template_nome'] ?? null);
        $idioma = texto($a['template_idioma'] ?? null) ?: 'pt_BR';

        if ($acaoId === 0 || $telefone === '' || $template === '') {
            // Configuração incompleta da Campanha, não falha de envio. Terminal
            // porque retentar não conserta o que falta no cadastro.
            $fila->aplicar(
                $acaoId,
                new DecisaoDeEnvio('falhou', false, false, 'campanha_sem_template_ou_lead_sem_telefone'),
                new Envio(http: 0),
            );
            $log->linha('invalida', 'Ação sem template ou sem telefone', ['acao' => $acaoId, 'ligacao' => $ligacaoId]);
            continue;
        }

        $dados = json_decode(texto($a['dados_extras'] ?? null), true);
        $mapa = json_decode(texto($a['template_mapa'] ?? null), true);

        // Parâmetros POSICIONAIS: o template do WhatsApp numera, não nomeia. O mapa
        // guarda posição => nome da coluna, e é a `Placeholder` que resolve —
        // mesma classe que o painel usa para pré-visualizar, para que a
        // pré-visualização não possa divergir do que é enviado (ADR-0006).
        //
        // 🔴 `parametrosTemplate` LANÇA quando o mapa não é sequencial, e é certo
        // que lance: template posicional com lacuna manda parâmetro no lugar
        // errado, e o Lead recebe a mensagem de outra pessoa. Mas sem captura aqui
        // uma Campanha com mapa malformado derrubaria o PROCESSO, parando o envio
        // de todas as outras. A Ação falha; o worker continua.
        /*
         * 🔴 DOIS FORMATOS DE TEMPLATE, e a CHAVE DO MAPA é quem diz qual.
         *
         * A Meta permite `{{nome}}` no lugar de `{{1}}`. O worker não fala com ela —
         * só tem o `campanha.template_mapa` —, então a distinção precisa caber no
         * próprio valor: chave `"1"` é posição, chave `"nome"` é nome. Sem coluna
         * nova, e as Campanhas que já existem seguem no caminho posicional sem saber
         * que houve mudança.
         */
        $mapaTexto = mapaDeTexto($mapa);
        $doLead = is_array($dados) ? achatarParaTexto($dados) : [];
        $nomes = [];

        try {
            if ($placeholder->mapaEhNomeado($mapaTexto)) {
                $nomeados = $placeholder->parametrosNomeados($mapaTexto, $doLead);
                $nomes = array_keys($nomeados);
                $parametros = array_values($nomeados);
            } else {
                $parametros = $placeholder->parametrosTemplate($mapaTexto, $doLead);
            }
        } catch (ErroDeDominio $e) {
            $fila->aplicar(
                $acaoId,
                new DecisaoDeEnvio('falhou', false, false, 'template_mapa_invalido'),
                new Envio(http: 0, mensagem: $e->getMessage()),
            );
            $log->linha('invalida', 'template_mapa inválido', [
                'acao' => $acaoId,
                'ligacao' => $ligacaoId,
                'erro' => $e->getMessage(),
            ]);
            continue;
        }

        /*
         * URL da imagem do cabeçalho, quando a Campanha tem uma.
         *
         * 🔴 MONTADA À MÃO porque aqui NÃO há framework — sem `route()`, mesma
         * disciplina do dialer. A base vem de `APP_URL` via `config/worker.php`.
         *
         * Sem `url_base` configurada a URL sairia relativa, a Meta não conseguiria
         * buscar, e o disparo INTEIRO seria recusado com erro de mídia. Preferir
         * nulo nesse caso é pior de outro jeito — o template exige a imagem —, mas
         * ao menos o erro que volta fala de cabeçalho ausente em vez de URL quebrada.
         */
        $imagemToken = texto($a['imagem_token'] ?? null);
        $imagemUrl = null;
        if ($imagemToken !== '' && $conf['url_base'] !== '') {
            $imagemUrl = $conf['url_base'] . '/imagem/' . $imagemToken;
        }

        $envio = $whatsapp->enviarTemplate(
            $telefone,
            $template,
            $idioma,
            $parametros,
            // Correlação que volta no webhook, fechando o laço sem tabela de
            // tradução — mesmo padrão do `ChannelId` no AMI.
            'ligacao:' . $ligacaoId,
            $imagemUrl,
            $nomes,
            $porMarketingApi,
        );

        $d = $politica->decidir($envio, numero($a['tentativas'] ?? null) + 1, $conf['max_tentativas']);
        $fila->aplicar($acaoId, $d, $envio);

        // A mensagem que SAIU também é parte da Conversa. Sem isto a caixa de entrada
        // mostrava só o lado do Lead — o operador via a resposta dele e não via o que
        // tinha sido enviado. E o `aplicarStatus` do webhook só faz UPDATE por `wamid`,
        // então sem a linha os estados de entrega não tinham onde pousar.
        if ($d->status === 'enviado' && $envio->wamid !== null) {
            $eventos->registrarSaida($telefone, $envio->wamid, '', $template);
        }

        $log->linha($d->status === 'enviado' ? 'enviado' : 'nao_enviado', $d->motivo, [
            'ligacao' => $ligacaoId,
            'acao' => $acaoId,
            'http' => $envio->http,
            'codigo' => $envio->codigo,
            'wamid' => $envio->wamid,
            'fbtrace' => $envio->fbtrace,
            // Qual endpoint mandou. Sem isto, comparar a entrega antes e depois da
            // MM API dependeria de lembrar quando a chave foi virada.
            'via' => $porMarketingApi ? 'marketing_api' : 'messages',
        ]);

        if ($d->pararCanal) {
            // Token inválido ou aplicativo sem permissão: TODA Ação da fila
            // falharia igual. Parar preserva a informação de quais ainda estavam
            // pendentes, em vez de marcar a fila inteira como pausada.
            $log->linha('canal_parado', 'interrompendo a volta: ' . $d->motivo, ['acao' => $acaoId]);
            $canalParado = true;
            break;
        }
    }

    // ---- Disparos por SMS ---------------------------------------------------
    //
    // 🔴 LAÇO PRÓPRIO, e não um ramo dentro do de WhatsApp. Os dois compartilham a
    // forma — resolver o texto, mandar, aplicar a decisão — e mais nada: um monta
    // template com componentes e o outro monta uma linha de texto; um fala com a
    // Meta e o outro com um celular Android. Um `if` no meio do laço de cima
    // obrigaria toda leitura futura a separar mentalmente os dois caminhos.
    if ($credSms !== null && $acoesSms !== []) {
        $sms = new Sms($transporte, $credSms['api_key'], $credSms['remetente']);

        foreach ($acoesSms as $a) {
            if ($parada->pedida()) {
                break;
            }

            $acaoId = numero($a['id'] ?? null);
            $ligacaoId = numero($a['referencia_id'] ?? null);
            $telefone = texto($a['telefone'] ?? null);
            $modelo = texto($a['texto_sms'] ?? null);

            if ($telefone === '' || $modelo === '') {
                // Campanha de SMS sem texto é configuração incompleta, não falha de
                // envio. Falhar com causa NOMEADA é o que faz o operador procurar na
                // tela em vez de no tronco.
                $fila->aplicar(
                    $acaoId,
                    new DecisaoDeEnvio('falhou', false, false, 'campanha_sem_texto_sms_ou_lead_sem_telefone'),
                    new Envio(http: 0, mensagem: 'texto do SMS ou telefone ausente'),
                );
                $log->linha('invalida', 'Campanha sem texto de SMS ou Lead sem telefone', [
                    'acao' => $acaoId, 'ligacao' => $ligacaoId,
                ]);
                continue;
            }

            $dados = json_decode(texto($a['dados_extras'] ?? null), true);

            try {
                // MESMO Placeholder da Mensagem da chamada e do template. Uma coluna
                // que falta falha aqui com causa nomeada, em vez de sair
                // "Olá , confira" para uma pessoa real.
                $conteudo = $placeholder->resolverTexto(
                    $modelo,
                    is_array($dados) ? achatarParaTexto($dados) : [],
                );
            } catch (ErroDeDominio $e) {
                $fila->aplicar(
                    $acaoId,
                    new DecisaoDeEnvio('falhou', false, false, 'texto_sms_invalido'),
                    new Envio(http: 0, mensagem: $e->getMessage()),
                );
                $log->linha('invalida', 'texto de SMS inválido', [
                    'acao' => $acaoId, 'ligacao' => $ligacaoId, 'erro' => $e->getMessage(),
                ]);
                continue;
            }

            $envio = $sms->enviar($telefone, $conteudo, 'ligacao:' . $ligacaoId);

            $d = $politica->decidir($envio, numero($a['tentativas'] ?? null) + 1, $conf['max_tentativas']);
            $fila->aplicar($acaoId, $d, $envio);

            $log->linha($d->status === 'enviado' ? 'enviado' : 'nao_enviado', $d->motivo, [
                'ligacao' => $ligacaoId,
                'acao' => $acaoId,
                'http' => $envio->http,
                'id' => $envio->wamid,
                'via' => 'sms',
            ]);

            if ($d->pararCanal) {
                $log->linha('canal_parado', 'interrompendo os SMS da volta: ' . $d->motivo, ['acao' => $acaoId]);
                break;
            }
        }
    }

    // ---- Respostas de texto escritas na tela de Conversas --------------------
    //
    // Depois dos Disparos e no MESMO ciclo, não em processo separado: as duas saídas
    // usam o mesmo número e a mesma cota da Meta, e um segundo processo enviando em
    // paralelo tornaria o limite por segundo impossível de respeitar de um lado só.
    //
    // Pula quando o canal parou: token inválido derruba as duas formas igual, e insistir
    // aqui só encheria o log com a mesma falha.
    if (! $canalParado) {
        foreach ($respostas as $r) {
            if ($parada->pedida()) {
                break;
            }

            $acaoId = numero($r['id'] ?? null);
            $mensagemId = numero($r['referencia_id'] ?? null);
            $conversaId = numero($r['conversa_id'] ?? null);
            $telefone = texto($r['telefone'] ?? null);
            $mensagem = texto($r['texto'] ?? null);

            if ($acaoId === 0 || $telefone === '' || trim($mensagem) === '') {
                $fila->aplicar(
                    $acaoId,
                    new DecisaoDeEnvio('falhou', false, false, 'resposta_sem_texto_ou_sem_telefone'),
                    new Envio(http: 0),
                );
                $log->linha('invalida', 'resposta sem texto ou sem telefone', [
                    'acao' => $acaoId, 'conversa' => $conversaId,
                ]);
                continue;
            }

            // 🔴 RECONFERE A JANELA AQUI. A tela já verificou, mas entre digitar e este
            // instante pode ter passado tempo — e a janela de 24 h corre pelo relógio do
            // Lead, não pelo nosso. Enviar fora dela devolve 131047, o operador leria
            // "falhou" sem causa, e a mensagem estaria perdida. Falhar com motivo
            // NOMEADO deixa a tela explicar que a janela fechou.
            $janela = new JanelaDeAtendimento(dataOuNulo($r['ultima_entrada_em'] ?? null));

            if (! $janela->aberta(new DateTimeImmutable('now'))) {
                $fila->aplicar(
                    $acaoId,
                    new DecisaoDeEnvio('falhou', false, false, 'janela_de_24h_fechada'),
                    new Envio(http: 0),
                );
                $log->linha('janela_fechada', 'janela de 24 h fechou antes do envio', [
                    'acao' => $acaoId, 'conversa' => $conversaId,
                ]);
                continue;
            }

            /*
             * Texto puro ou texto COM BOTÃO DE LINK, e quem decide são as duas colunas
             * que a tela gravou na mensagem.
             *
             * 🔴 OS DOIS EM PAR, sempre. A tela recusa um só preenchido antes de
             * gravar; conferir aqui de novo é barato e cobre a linha que tenha vindo
             * de outro caminho — SQL manual, restauração parcial. Botão sem URL a Meta
             * recusa, e a mensagem inteira se perde.
             */
            $botaoTexto = texto($r['botao_texto'] ?? null);
            $botaoUrl = texto($r['botao_url'] ?? null);

            $envio = $botaoTexto !== '' && $botaoUrl !== ''
                ? $whatsapp->enviarTextoComBotao(
                    $telefone,
                    $mensagem,
                    $botaoTexto,
                    $botaoUrl,
                    'conversa:' . $conversaId,
                )
                : $whatsapp->enviarTexto($telefone, $mensagem, 'conversa:' . $conversaId);

            $d = $politica->decidir($envio, numero($r['tentativas'] ?? null) + 1, $conf['max_tentativas']);
            $fila->aplicar($acaoId, $d, $envio);

            // A linha da mensagem já existe — a tela a criou ao enfileirar, para a
            // resposta aparecer na conversa na hora. Aqui ela só ganha o `wamid`, que é
            // o que permite os webhooks de entrega casarem depois.
            $eventos->confirmarSaida($mensagemId, $envio->wamid, $d->status === 'enviado');

            $log->linha($d->status === 'enviado' ? 'respondido' : 'nao_respondido', $d->motivo, [
                'acao' => $acaoId,
                'conversa' => $conversaId,
                'http' => $envio->http,
                'codigo' => $envio->codigo,
                'wamid' => $envio->wamid,
            ]);

            if ($d->pararCanal) {
                $log->linha('canal_parado', 'interrompendo a volta: ' . $d->motivo, ['acao' => $acaoId]);
                $canalParado = true;
                break;
            }
        }
    }

    $parada->dormir($canalParado ? $conf['intervalo_canal_parado_seg'] : 1);
}

/**
 * `ultima_entrada_em` chega como string do banco, ou nulo quando o Lead nunca escreveu.
 * Nulo é janela FECHADA, e `JanelaDeAtendimento` já trata assim.
 */
function dataOuNulo(mixed $v): ?DateTimeImmutable
{
    if (! is_string($v) || trim($v) === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($v);
    } catch (\Throwable) {
        return null;
    }
}

$log->linha('fim', 'worker encerrado');

function numero(mixed $v): int
{
    return is_numeric($v) ? (int) $v : 0;
}

function texto(mixed $v): string
{
    return is_string($v) ? $v : '';
}

/**
 * O mapa vem de coluna JSON, então chega como `mixed`. Valor não textual é
 * DESCARTADO em vez de convertido — e descartar quebra a sequência, o que faz
 * `parametrosTemplate` lançar. É o comportamento certo: mapa corrompido tem que
 * falhar alto, não mandar parâmetro na posição errada.
 *
 * @return array<array-key,string>
 */
function mapaDeTexto(mixed $mapa): array
{
    if (! is_array($mapa)) {
        return [];
    }

    $saida = [];
    foreach ($mapa as $k => $v) {
        if (is_string($v)) {
            $saida[$k] = $v;
        }
    }

    return $saida;
}

/**
 * `dados_extras` é JSON livre, vindo da planilha importada. A `Placeholder` espera
 * escalar por coluna, então valor aninhado é descartado em vez de virar "Array"
 * no meio da mensagem que o Lead recebe.
 *
 * @param array<array-key,mixed> $dados
 * @return array<string,string|int|float|null>
 */
function achatarParaTexto(array $dados): array
{
    $saida = [];
    foreach ($dados as $k => $v) {
        if (is_string($v) || is_int($v) || is_float($v) || $v === null) {
            $saida[(string) $k] = $v;
        }
    }

    return $saida;
}
