#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Apaga as Campanhas que o painel marcou. Roda a cada minuto pelo systemd
 * (`prospecta-apagar-campanhas.timer`); sem marca, é uma consulta e sai.
 *
 * O trabalho mora em `App\Worker\ApagadorDeCampanha`, que diz por que isto existe fora
 * da requisição e em que ordem apaga. Aqui é só o bootstrap mínimo (ADR-0001).
 *
 * Instância única de graça: o timer do systemd não dispara uma unidade `oneshot` que
 * ainda está rodando, então uma Campanha grande que leva vários minutos não ganha uma
 * segunda volta concorrente.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Fastagi\Registro;
use App\Worker\ApagadorDeCampanha;

$conf = require dirname(__DIR__) . '/config/fastagi.php';

$log = new Registro(STDERR);
$log->capturarTudo();

$pdo = new PDO($conf['banco']['dsn'], $conf['banco']['usuario'], $conf['banco']['senha'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$apagador = new ApagadorDeCampanha(
    $pdo,
    $conf['gravacao_dir'],
    /** @param array<string, bool|float|int|string|null> $extra */
    static function (string $evento, string $mensagem, array $extra) use ($log): void {
        $log->linha($evento, $mensagem, $extra);
    },
);

$resumo = $apagador->executar();

// Silêncio quando não havia nada: a cada minuto, uma linha "nada a fazer" afogaria o
// journal e esconderia as que importam.
if (array_sum($resumo) > 0) {
    $log->linha('fim', 'volta de apagamento concluída', $resumo);
}

// Não-zero quando alguma Campanha travou, para o systemd marcar a unidade como falha e
// o problema aparecer em `systemctl --failed`, além da tela.
exit($resumo['com_erro'] > 0 ? 1 : 0);
