#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * O `dialer` — SERVIÇO PERSISTENTE, não execução de cron.
 *
 * 🔴 Por que não cron: o ADR-0005 trocou o ritmo para MALHA FECHADA, e cron por
 * minuto só consegue aplicar taxa calculada — que é exatamente a malha aberta
 * rejeitada. Taxa realimentada quer processo contínuo.
 *
 * Efeito colateral bom: o systemd garante instância única, então a trava de
 * arquivo que um cron exigiria deixa de ser necessária. O `flock` que o
 * MassiveCallCommand do MagnusBilling precisa ter não tem equivalente aqui.
 *
 * Bootstrap mínimo (ADR-0001): autoload e PDO, sem framework.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Dialer\Acao;
use App\Dialer\Ami;
use App\Dialer\Fila;
use App\Dialer\Janela;
use App\Dialer\Politica;
use App\Dialer\Protecao;
use App\Dialer\Ritmo;
use App\Dominio\Bina;
use App\Dominio\Rota;
use App\Fastagi\Registro;

$conf = require dirname(__DIR__) . '/config/dialer.php';

$log = new Registro(STDERR);
$log->capturarTudo();
$log->linha('inicio', 'dialer iniciado', ['canais' => $conf['canais_contratados']]);

$pdo = new PDO($conf['banco']['dsn'], $conf['banco']['usuario'], $conf['banco']['senha'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$socket = stream_socket_client(
    sprintf('tcp://%s:%d', $conf['ami']['host'], $conf['ami']['porta']),
    $errno, $errstr, 10,
);
if ($socket === false) {
    $log->linha('erro_fatal', "AMI inacessível: {$errstr}", ['errno' => $errno]);
    exit(1);
}
fgets($socket);   // banner "Asterisk Call Manager/x.y.z"

$ami = new Ami($socket, $socket);
$resp = $ami->login($conf['ami']['usuario'], $conf['ami']['segredo']);
if (($resp['Response'] ?? '') !== 'Success') {
    $log->linha('erro_fatal', 'login AMI recusado', ['resposta' => $resp['Message'] ?? '?']);
    exit(1);
}
$log->linha('ami', 'autenticado');

$fila = new Fila($pdo);
$politica = new Politica();
$protecao = new Protecao();
$ritmo = new Ritmo(cpsMaximo: $conf['cps_maximo']);
$bina = new Bina();

// Só registra a ausência de Rota UMA vez por período, não a cada volta: o laço gira
// várias vezes por segundo e encheria o journal com a mesma linha.
$avisouSemRota = false;

/**
 * Janela deslizante por Campanha, para a proteção. Em memória de propósito:
 * reinício do serviço zera e reavalia, o que é o comportamento desejável.
 *
 * @var array<int,list<array{conta:bool,atendeu:bool}>> $janela
 */
$janela = [];
/** @var array<int,int> $tentativaDaLigacao */
$tentativaDaLigacao = [];
/** @var array<int,int> $leadDaLigacao */
$leadDaLigacao = [];
/** @var array<int,int> $campanhaDaLigacao */
$campanhaDaLigacao = [];
/**
 * Teto de tentativas em vigor no momento da ORIGINAÇÃO, por Ligação.
 *
 * 🔴 Guardado por Ligação, não lido na hora do evento. Duas razões: no evento só
 * se tem o id da Campanha, e a Campanha pode ter sido editada enquanto a chamada
 * estava em voo — usar o valor novo decidiria a aposentadoria deste Lead por uma
 * regra que não existia quando ele foi discado.
 *
 * @var array<int,int> $maxTentativasDaLigacao
 */
$maxTentativasDaLigacao = [];
/**
 * Ligações que ATENDERAM, vistas pelo evento `Newstate` com estado `Up`.
 *
 * 🔴 EXISTE PORQUE `DialStatus=ANSWER` NUNCA CHEGA NESTE MODO.
 *
 * O produto usa `Originate` para um CONTEXTO, não um `Dial` — a chamada entra no
 * dialplan e vai para o `fastagi`. Nesse arranjo o Asterisk não emite `DialEnd` com
 * `DialStatus`, então `$atendeu` era SEMPRE falso e a `Politica` caía em
 * "desconhecido, retentar".
 *
 * Consequência MEDIDA na primeira chamada real: a pessoa atendeu, falou, ouviu a URA, e
 * o Lead ficou marcado para SER DISCADO DE NOVO. Só não aconteceu porque a bateria usava
 * `max_tentativas = 1`.
 *
 * @var array<int,bool> $atendeuDaLigacao
 */
$atendeuDaLigacao = [];

$rodando = true;
foreach ([SIGTERM, SIGINT] as $sinal) {
    pcntl_signal($sinal, static function () use (&$rodando, $log): void {
        $rodando = false;
        $log->linha('parada', 'sinal recebido, encerrando a volta atual');
    });
}

$ultimaReconciliacao = 0;

/**
 * Estado anterior do Horário de Trabalho, para registrar só a TRANSIÇÃO. `null` no começo faz a
 * primeira volta registrar o estado inicial, que é a informação que o operador quer
 * ao subir o serviço.
 */
$janelaAnterior = null;

while ($rodando) {
    pcntl_signal_dispatch();
    $inicioDaVolta = microtime(true);

    // ---- reconciliação periódica -------------------------------------------
    if (time() - $ultimaReconciliacao > 60) {
        $presas = $fila->reconciliar($conf['limite_ligacao_seg']);
        if ($presas['ligacoes'] > 0) {
            // Os DOIS números. Ligação fechada sem Lead solto significa que o `Hangup`
            // chegou e o `aplicar` rodou — normal. Lead solto é o que voltou à Fila, e é
            // o número que explica por que a Campanha ainda tem trabalho.
            $log->linha('reconciliacao', 'Ligações presas encerradas', [
                'ligacoes' => $presas['ligacoes'],
                'leads_soltos' => $presas['leads'],
            ]);
        }
        $ultimaReconciliacao = time();
    }

    // ---- drena eventos do AMI ----------------------------------------------
    // 🔴 LIMITE POR VOLTA. Com `Events: on` o Asterisk manda TODO evento da
    // máquina, e um laço sem teto nunca sai num PBX movimentado — o dialer
    // pararia de originar enquanto drena. Encontrado em teste de integração:
    // uma execução originou 4 chamadas e a seguinte, nenhuma, só porque havia
    // mais evento na fila. É defeito que só aparece sob carga.
    //
    // O que não for consumido nesta volta continua no socket para a próxima.
    $lidos = 0;
    while ($lidos < $conf['eventos_por_volta'] && $ami->temDados()) {
        $lidos++;
        $ev = $ami->ler();
        if ($ev === []) {
            break;
        }
        $tipo = $ev['Event'] ?? '';

        // `Newstate` entrou na lista: é onde o ATENDIMENTO aparece neste modo.
        if ($tipo !== 'DialEnd' && $tipo !== 'Hangup'
            && $tipo !== 'OriginateResponse' && $tipo !== 'Newstate') {
            continue;
        }

        $lig = (int) ($ev['Uniqueid'] ?? $ev['ChannelId'] ?? 0);
        if ($lig === 0 || ! isset($leadDaLigacao[$lig])) {
            continue;
        }

        // 🔴 `Newstate` com `Up` É o atendimento, e chega ANTES do desligamento.
        //
        // Anotar e sair: não é fim de chamada, então não há decisão a tomar aqui. Sem
        // isto o atendimento não seria observado em lugar nenhum.
        if ($tipo === 'Newstate') {
            if (($ev['ChannelStateDesc'] ?? '') === 'Up') {
                $atendeuDaLigacao[$lig] = true;
                $log->linha('atendida', 'canal atendido', ['ligacao' => $lig]);
            }
            continue;
        }

        // Três OBSERVADORES do mesmo fato, combinados por OU. O primeiro cobre o modo
        // `Dial`, que o produto não usa hoje mas pode usar; o segundo é o `Newstate`
        // desta chamada; o terceiro é o `fastagi`, que roda DENTRO da chamada atendida e
        // por isso é autoridade — vale como rede quando o `Newstate` se perde numa
        // reconexão do AMI.
        //
        // Nenhum dos três decide nada, os três só relatam. Redundância de OBSERVAÇÃO não
        // é regra duplicada.
        //
        // 🔴 O PRIMEIRO SAIU DAQUI, e a conta é a mesma: ele lia `DialStatus` do próprio
        // evento, e é a `Politica` que recebe o evento agora. Os dois que sobraram são os
        // que ela NÃO tem como ver — um deles consulta o banco.
        $atendeu = ($atendeuDaLigacao[$lig] ?? false)
            || $fila->foiAtendida($lig);

        // As três linhas de extração que ficavam aqui foram para `Dialer\Sinalizacao`.
        // Eram a única cópia da regra de leitura e não tinham teste; é onde os 77,3%
        // descritos abaixo se perdem. O que foi lido volta em `$d->sinal`.
        $d = $politica->decidir($ev, $atendeu);
        $campanhaId = $campanhaDaLigacao[$lig];

        // Diagnóstico entra sempre, inclusive em chamada atendida. O
        // `codigo_sip IS NULL` preserva o PRIMEIRO evento, porque DialEnd e
        // Hangup chegam os dois para a mesma Ligação e o segundo é mais pobre.
        $pdo->prepare('UPDATE ligacao SET codigo_sip = :c, hangup_cause = :h WHERE id = :id AND codigo_sip IS NULL')
            ->execute(['c' => $d->sinal?->codigoSip, 'h' => $d->sinal?->hangupCause, 'id' => $lig]);

        // 🔴 FECHA a Ligação, e só quando o dialer é o dono do desfecho — isto
        // é, quando não foi atendida e o `fastagi` não rodou. Ver `Decisao`.
        //
        // Sem este bloco a Ligação ficava com `encerrada_em` NULL para sempre, e
        // `reconciliar()` a marcava `erro` 300 s depois via COALESCE. O desfecho
        // medido decidia a retentativa e era descartado: painel e CDR em branco,
        // e "erro" gravado em chamada que na verdade não foi atendida.
        //
        // `duracao_falada_seg` e `atendida_em` ficam NULL porque ninguém falou.
        if ($d->desfecho !== null) {
            $pdo->prepare(
                'UPDATE ligacao
                    SET desfecho = :d,
                        encerrada_em = NOW(),
                        duracao_seg = TIMESTAMPDIFF(SECOND, iniciada_em, NOW())
                  WHERE id = :id AND desfecho IS NULL'
            )->execute(['d' => $d->desfecho, 'id' => $lig]);
        }

        // 🔴 O teto vem da CAMPANHA, não da configuração global.
        //
        // Aqui estava `$conf['max_tentativas_padrao']`, e a coluna
        // `campanha.max_tentativas` era lida por `campanhasAtivas()` e jogada
        // fora. O operador pedia 2 tentativas e a discadora fazia 3 — 50% mais
        // chamada do que foi contratado, silenciosamente, e a tela mostrando o
        // número que NÃO estava valendo. Encontrado ao construir o painel.
        //
        // O default global sobra apenas para Ligação sem mapa, que é o caso de
        // evento chegando depois de um reinício do dialer.
        $fila->aplicar(
            $leadDaLigacao[$lig],
            $d,
            $maxTentativasDaLigacao[$lig] ?? $conf['max_tentativas_padrao'],
            $tentativaDaLigacao[$lig] ?? 1,
        );

        $janela[$campanhaId][] = ['conta' => $d->contaParaProtecao, 'atendeu' => $d->acao === Acao::Finalizado];
        if (count($janela[$campanhaId]) > 30) {
            array_shift($janela[$campanhaId]);
        }

        /*
         * 🔴 ESTE LOG É QUE FOI COLHIDO EM 24/08/2026, e é por isso que continua aqui.
         *
         * A hipótese registrada era que o Q.850 consertaria as Ligações que viravam
         * `desconhecido`. Estas chaves foram postas para medi-la, e a medição a derrubou:
         * 90,5% dos eventos chegam por `OriginateResponse`, que não traz `Cause` nenhum.
         * Quem conserta é o `reason`, e ele já virou regra na `Politica`.
         *
         * `q850` fica porque é o único dado dos ~100 casos de `Hangup` que dizem algo
         * além do código SIP (102 e 127); `reason` fica porque virou entrada de decisão e
         * é o que separa `nao_atendeu` de `sem_sinal_de_toque` — a pergunta que a medição
         * deixou aberta e que só uma rodada reaberta responde. Enquanto ela estiver
         * aberta, este log é o instrumento; não tirar.
         */
        $log->linha('resultado', $d->motivo, [
            'ligacao' => $lig, 'acao' => $d->acao->name, 'codigo' => $d->sinal?->codigoSip,
            'q850' => $d->sinal?->q850,
            'reason' => $d->sinal?->reason,
            // NÃO `evento`: essa chave é autoritativa no `Registro` e seria descartada em
            // silêncio pela união de arrays — é a armadilha que o docblock dele descreve.
            'ami_evento' => $tipo,
        ]);

        unset(
            $leadDaLigacao[$lig],
            $tentativaDaLigacao[$lig],
            $campanhaDaLigacao[$lig],
            $maxTentativasDaLigacao[$lig],
            $atendeuDaLigacao[$lig],
        );
    }

    if ($lidos >= $conf['eventos_por_volta']) {
        $log->linha('eventos', 'teto da volta atingido; o resto fica para a próxima', ['lidos' => $lidos]);
    }

    // ---- Horário de Trabalho ------------------------------------------------
    //
    // 🔴 GOVERNA ORIGINAÇÃO, NÃO CHAMADA EM VOO. Quem atendeu às 17h59 termina a
    // conversa; cortar no relógio seria desligar na cara de quem estava falando, e
    // ainda gastaria o Lead com um desfecho que não é dele. O laço de eventos acima
    // continua rodando por isso — as chamadas em voo precisam ser reconciliadas.
    //
    // Reavaliado a cada volta, e não uma vez no início: o serviço é persistente e
    // atravessa o fechamento.
    // 🔴 `$horarioDeTrabalho`, NÃO `$janela`. A variável `$janela` já existe neste
    // arquivo e guarda a janela deslizante da PROTEÇÃO DE TRONCO, por Campanha.
    // Escrevi `$janela = $fila->janelaDeTrabalho()` na primeira versão e destruí
    // aquele array a cada volta — o que faria `$janela[$campanhaId]` lançar
    // "Cannot use object of type Janela as array" na primeira originação.
    //
    // O PHPStan nível 9 NÃO pegou: reatribuição dentro do laço escapa da análise de
    // fluxo dele. Num script procedural longo, nome reaproveitado é armadilha real.
    $horarioDeTrabalho = $fila->janelaDeTrabalho();
    $agora = new DateTimeImmutable('now', new DateTimeZone($conf['fuso']));
    $abertaAgora = $horarioDeTrabalho->aberta($agora);

    if ($abertaAgora !== $janelaAnterior) {
        // UMA linha por transição, não por volta. Registrar a cada volta encheria o
        // log de "fora do horário" a cada segundo durante a noite inteira, e o log
        // deixaria de servir para achar problema.
        $log->linha(
            $abertaAgora ? 'janela_aberta' : 'janela_fechada',
            $abertaAgora ? 'dentro do Horário de Trabalho, originando' : 'fora do Horário de Trabalho, sem originar',
            ['horario' => $horarioDeTrabalho->descricao(), 'agora' => $agora->format('D H:i')],
        );
        $janelaAnterior = $abertaAgora;
    }

    if (! $abertaAgora) {
        // Dorme e volta. As Ligações em voo seguem sendo acompanhadas na volta
        // seguinte, porque o laço de eventos vem antes deste ponto.
        usleep((int) ($conf['intervalo_seg'] * 1_000_000));
        continue;
    }

    // ---- a Rota em vigor ---------------------------------------------------
    //
    // 🔴 LIDA A CADA VOLTA, DO BANCO. Antes vinha de `PROSPECTA_TRONCO` e
    // `PROSPECTA_TECH_PREFIX`, lidos na PARTIDA — então o operador trocaria o prefixo na
    // tela de Rota, veria o valor novo salvo, e a discagem seguiria pelo antigo. Sem erro
    // em lugar nenhum, e o prefixo é seleção de CAPACIDADE: fora da Platinum, CallerID e
    // DTMF falham em silêncio.
    //
    // Uma linha por volta é custo irrelevante, e é o que faz a troca valer sem reiniciar —
    // reiniciar derrubaria a chamada em curso.
    $r = $fila->rota();
    $rota = new Rota(
        host: $r['host'],
        usuario: $r['usuario'],
        senha: '',   // o dialer não precisa da senha: quem autentica é o Asterisk
        fromDominio: $r['from_dominio'],
        fromUsuario: $r['from_usuario'],
        techPrefix: $r['tech_prefix'],
    );

    // 🔴 RECUSA EM VEZ DE DISCAR PARA UM ENDPOINT QUE NÃO EXISTE. Sem Rota, o `Originate`
    // falharia com erro de canal — que aparece como problema de tronco e manda o operador
    // investigar o fornecedor, quando o que falta é preencher uma tela.
    if (! $rota->configurado()) {
        if (! $avisouSemRota) {
            $log->linha('sem_rota', 'Rota não configurada no painel; não origino', []);
            $avisouSemRota = true;
        }
        usleep((int) ($conf['intervalo_seg'] * 1000000));
        continue;
    }
    $avisouSemRota = false;

    // ---- origina -----------------------------------------------------------
    foreach ($fila->campanhasAtivas($conf['canais_contratados'], $conf['timeout_toque_seg']) as $c) {
        $campanhaId = $c['id'];

        // proteção
        $j = $janela[$campanhaId] ?? [];
        $erros = count(array_filter($j, static fn (array $x): bool => $x['conta']));
        $atendidas = count(array_filter($j, static fn (array $x): bool => $x['atendeu']));
        if ($protecao->devePausar(count($j), $erros, $atendidas)) {
            $fila->pausarCampanha($campanhaId);
            $log->linha('pausada', $protecao->explicacao(count($j), $erros), ['campanha' => $campanhaId]);
            $janela[$campanhaId] = [];
            continue;
        }

        $disponiveis = $fila->contarDisponiveis($campanhaId);

        // Rodada esgotada: quem aguardava volta para a Fila.
        if ($disponiveis === 0) {
            // 🔴 Só conclui quando NADA está em voo. Encontrado em teste de
            // integração: sem esta guarda a Campanha era marcada como concluída
            // com quatro chamadas em andamento, porque "nenhum disponível" e
            // "nenhum aguardando" era verdade e havia gente em status 2.
            $emVoo = $fila->contarEmProcessamento($campanhaId);
            $voltaram = $fila->abrirProximaRodada($campanhaId);
            if ($voltaram === 0 && $emVoo === 0) {
                $pdo->prepare('UPDATE campanha SET status = 3 WHERE id = :id AND status = 1')
                    ->execute(['id' => $campanhaId]);
                $log->linha('concluida', 'Campanha sem Lead disponível nem em voo', ['campanha' => $campanhaId]);
            } elseif ($voltaram === 0) {
                $log->linha('aguardando', 'rodada esgotada, esperando as em voo', [
                    'campanha' => $campanhaId, 'em_voo' => $emVoo,
                ]);
            } else {
                $log->linha('rodada', 'próxima rodada aberta', ['campanha' => $campanhaId, 'leads' => $voltaram]);
            }
            continue;
        }

        $quantas = $ritmo->quantasOriginar(
            $c['contratado'],
            $fila->contarOcupados($campanhaId),
            $disponiveis,
            $conf['intervalo_seg'],
        );
        if ($quantas < 1) {
            continue;
        }

        $espacamento = $ritmo->espacamentoMicro($quantas, $conf['intervalo_seg']);

        foreach ($fila->reivindicar($campanhaId, $quantas) as $lead) {
            // Lista de bloqueio verificada NA DISCAGEM: alguém pode entrar nela
            // depois de o arquivo ser importado.
            if ($fila->bloqueado($lead['telefone'])) {
                $fila->encerrarSemDiscar($lead['id']);
                $log->linha('bloqueado', 'Lead na lista de bloqueio', ['lead' => $lead['id']]);
                continue;
            }

            $numero = $bina->derivar(
                $c['bina_regra'],
                $lead['telefone'],
                $c['bina'],
                $c['bina_regra'] === 'lista' ? $fila->binaDoDdd($lead['telefone']) : null,
            );

            $ligacaoId = $fila->criarLigacao($lead['id'], $campanhaId, $lead['tentativa'], $numero);
            $leadDaLigacao[$ligacaoId] = $lead['id'];
            $tentativaDaLigacao[$ligacaoId] = $lead['tentativa'];
            $campanhaDaLigacao[$ligacaoId] = $campanhaId;
            $maxTentativasDaLigacao[$ligacaoId] = $c['max_tentativas'];

            $resp = $ami->originar(
                canal: $rota->canal($conf['tecnologia'], $lead['telefone']),
                ligacaoId: $ligacaoId,
                contexto: $conf['contexto'],
                extensao: 's',
                timeoutSeg: $c['timeout_toque'],
                bina: $numero,
                variaveis: ['LIGACAO' => (string) $ligacaoId],
            );

            // 🔴 A RESPOSTA DO ORIGINATE É VERIFICADA. Encontrado em teste de
            // integração: com o contexto de dialplan ausente o Asterisk recusa
            // de forma SÍNCRONA — `Response: Error`, `Message: Extension does
            // not exist.` — e NÃO manda evento nenhum.
            //
            // Sem esta checagem o Lead ficaria preso em status 2 até a
            // reconciliação, o `codigo_sip` nunca seria gravado, e a `Politica`
            // inteira nunca rodaria. Com contexto mal escrito isso afetaria
            // 100% das chamadas, em silêncio.
            if (($resp['Response'] ?? '') !== 'Success') {
                $motivo = $resp['Message'] ?? 'sem resposta do AMI';

                $pdo->prepare("UPDATE ligacao SET encerrada_em = NOW(), desfecho = 'erro',
                               hangup_cause = :m WHERE id = :id")
                    ->execute(['m' => substr($motivo, 0, 60), 'id' => $ligacaoId]);

                // Não gasta a tentativa: não discamos. Foi recusa nossa, não do
                // destino.
                $fila->devolverSemGastar($lead['id']);
                unset(
                    $leadDaLigacao[$ligacaoId],
                    $tentativaDaLigacao[$ligacaoId],
                    $campanhaDaLigacao[$ligacaoId],
                    $maxTentativasDaLigacao[$ligacaoId],
                    $atendeuDaLigacao[$ligacaoId],
                );

                // Conta para a proteção: originação recusada é problema de
                // configuração ou de conta, exatamente o que ela existe para pegar.
                $janela[$campanhaId][] = ['conta' => true, 'atendeu' => false];
                if (count($janela[$campanhaId]) > 30) {
                    array_shift($janela[$campanhaId]);
                }

                $log->linha('recusada', 'Originate recusado pelo Asterisk', [
                    'ligacao' => $ligacaoId, 'motivo' => $motivo,
                ]);
                continue;
            }

            $log->linha('originada', 'Originate aceito', [
                'ligacao' => $ligacaoId, 'tentativa' => $lead['tentativa'], 'bina' => $numero ?? 'omitida',
            ]);

            if ($espacamento > 0) {
                usleep($espacamento);
            }
        }
    }

    // heartbeat: o painel usa para mostrar que o serviço está vivo
    $pdo->prepare("INSERT INTO heartbeat (processo, visto_em) VALUES ('dialer', NOW())
                   ON DUPLICATE KEY UPDATE visto_em = NOW()")->execute();

    $gasto = microtime(true) - $inicioDaVolta;
    $sobra = $conf['intervalo_seg'] - $gasto;
    if ($sobra > 0) {
        usleep((int) ($sobra * 1_000_000));
    }
}

$log->linha('fim', 'dialer encerrado');
exit(0);
