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
use App\Whatsapp\Leitura;
use App\Whatsapp\OptOut;
use App\Worker\FilaDeAcoes;
use App\Worker\FilaDeEventos;
use App\Worker\Interrupcao;
use App\Seguranca\Cofre;
use App\Worker\PoliticaDeEnvio;
use App\Worker\TransporteCurl;
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
        $log->linha('aguardando_canal', 'Ações de canal sem remetente implementado', [
            'quantas' => $aguardando,
            'canal' => 'sms',
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

    // ---- Ações de disparo ---------------------------------------------------
    $cred = $fila->credenciaisWhatsapp();

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

    if ($acoes === []) {
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
        try {
            $parametros = $placeholder->parametrosTemplate(
                mapaDeTexto($mapa),
                is_array($dados) ? achatarParaTexto($dados) : [],
            );
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

        $envio = $whatsapp->enviarTemplate(
            $telefone,
            $template,
            $idioma,
            $parametros,
            // Correlação que volta no webhook, fechando o laço sem tabela de
            // tradução — mesmo padrão do `ChannelId` no AMI.
            'ligacao:' . $ligacaoId,
        );

        $d = $politica->decidir($envio, numero($a['tentativas'] ?? null) + 1, $conf['max_tentativas']);
        $fila->aplicar($acaoId, $d, $envio);

        $log->linha($d->status === 'enviado' ? 'enviado' : 'nao_enviado', $d->motivo, [
            'ligacao' => $ligacaoId,
            'acao' => $acaoId,
            'http' => $envio->http,
            'codigo' => $envio->codigo,
            'wamid' => $envio->wamid,
            'fbtrace' => $envio->fbtrace,
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

    $parada->dormir($canalParado ? $conf['intervalo_canal_parado_seg'] : 1);
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
