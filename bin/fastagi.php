#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Handler FastAGI — UM PROCESSO POR LIGAÇÃO.
 *
 * BOOTSTRAP MÍNIMO, conforme ADR-0001: autoload e PDO, sem container, sem
 * Eloquent, sem framework. Framework completo custa ~35 MB de RSS por processo,
 * o que em 200 canais são 7 GB só de handlers; autoload e PDO ficam em ~5 MB.
 *
 * As quatro fases, e a terceira não toca o banco:
 *   1. ler tudo    2. DESCONECTAR    3. tocar áudio e coletar DTMF    4. gravar
 *
 * Ativado por socket do systemd com Accept=yes: STDIN e STDOUT SÃO o socket do
 * Asterisk. Por isso log vai para STDERR — escrever em STDOUT corromperia o
 * protocolo AGI.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Fastagi\Agi;
use App\Fastagi\Banco;
use App\Fastagi\CanalMorto;
use App\Fastagi\Fluxo;
use App\Fastagi\Registro;
use App\Fastagi\Resultado;

// O caso comum do produto é o Lead apertar a tecla de opt-in e desligar no
// mesmo instante. Se o processo morresse aí, perderíamos o registro de quem
// pediu contato explicitamente — a pior falha possível.
ignore_user_abort(true);

$log = new Registro(STDERR);
$log->capturarTudo();   // NADA suprimido: warning e notice viram linha de log

$agi = new Agi(STDIN, STDOUT);
$ambiente = $agi->lerAmbiente();

/**
 * O id da Ligação vem do dialplan. É valor EXTERNO (ADR-0003): validado por
 * lista de permissão, nunca interpolado.
 */
$cru = $ambiente['agi_arg_1'] ?? $ambiente['agi_uniqueid'] ?? '';
if (preg_match('/^[1-9][0-9]{0,18}$/', $cru) !== 1) {
    $log->linha('recusado', 'id de Ligação fora do formato', ['recebido' => substr($cru, 0, 40)]);
    $agi->desligar();
    exit(2);
}
$ligacaoId = (int) $cru;
$log->ligacao($ligacaoId);
$log->linha('inicio', 'handler iniciado', ['canal' => $ambiente['agi_channel'] ?? '?']);

$conf = require dirname(__DIR__) . '/config/fastagi.php';
$banco = new Banco($conf['banco'], $log);
$resultado = new Resultado();

try {
    // ---- FASE 1 ----
    // A marca de atendimento vem ANTES da leitura: se `carregar` falhar, o fato de a
    // chamada ter sido atendida continua verdadeiro e já está gravado. Ver
    // `Banco::marcarAtendida` — a coluna só era escrita no desligamento.
    $banco->marcarAtendida($ligacaoId);
    $dados = $banco->carregar($ligacaoId, $conf['gravacao_dir'], $conf['audio_dir']);

    // ---- FASE 2 ----
    $banco->desconectar();
    $log->linha('banco', 'conexão soltada antes da fase longa');

    // ---- FASE 3 ---- zero banco
    (new Fluxo($agi, $log))->executar($dados, $resultado);
} catch (CanalMorto $e) {
    // O Lead desligou. NÃO é erro: seguimos para a fase 4 com o que se sabe.
    $log->linha('canal', 'Lead desligou durante o fluxo', ['comando' => $e->comando]);
} catch (\Throwable $e) {
    $resultado->desfecho ??= 'erro';
    $resultado->erro = $e::class;
    $log->linha('erro', $e->getMessage(), ['classe' => $e::class, 'linha' => $e->getLine()]);
} finally {
    // ---- FASE 4 ---- sempre acontece, UMA transação
    try {
        $banco->gravar($ligacaoId, $resultado);
        $log->linha('fim', 'gravado', ['desfecho' => $resultado->desfecho]);
    } catch (\Throwable $e) {
        // Última linha de defesa: se nem gravar deu, o log é o único rastro.
        $log->linha('erro_fatal', 'FASE 4 falhou: ' . $e->getMessage(), ['classe' => $e::class]);
    }
}

// A limpeza do canal é do hangup_handler no dialplan, não daqui: o canal pode
// já ter ido embora (ADR-0007).
exit(0);
