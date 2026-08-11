#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Expurgo de gravação — UM dono para apagar o arquivo e limpar a coluna.
 *
 * 🔴 Substitui `find -delete` + `ExecStartPost`. A forma anterior tinha DOIS
 * donos da mesma operação: o `find` apagava e um segundo processo marcava. Se o
 * segundo falhasse — e `ExecStartPost` falha sem desfazer o `ExecStart` — o disco
 * ficava sem o arquivo e o banco continuava apontando para ele. O painel
 * ofereceria um player para gravação inexistente, e a divergência cresceria a
 * cada execução sem ninguém notar. É a mesma lição de `ligacao.desfecho`: uma
 * operação, um dono.
 *
 * Dirigido pelo BANCO, não pelo diretório. A coluna é a fonte de verdade sobre
 * qual gravação existe, e a idade que importa é a da Ligação, não a mtime do
 * arquivo — mtime muda com backup, cópia e restauração.
 *
 * Bootstrap mínimo (ADR-0001): autoload e PDO, sem framework.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Dominio\CaminhoDeGravacao;
use App\Fastagi\Registro;

$conf = require dirname(__DIR__) . '/config/fastagi.php';

$log = new Registro(STDERR);
$log->capturarTudo();

$base = rtrim($conf['gravacao_dir'], '/');

$pdo = new PDO($conf['banco']['dsn'], $conf['banco']['usuario'], $conf['banco']['senha'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// 🔴 A RETENÇÃO VEM DO BANCO, com o ambiente só como recurso.
//
// Havia DOIS valores para o mesmo número, com defaults DIFERENTES: a variável
// `PROSPECTA_GRAVACAO_RETENCAO_DIAS` valia 30 e era o que este script usava, enquanto
// `configuracao.gravacao_retencao_dias` valia 15 e NÃO ERA LIDA POR NINGUÉM. Quem
// olhasse o banco veria 15; o expurgo apagava aos 30.
//
// O banco ganha porque retenção é decisão do cliente por instância, e mudar no painel
// não pode exigir reimplantação. O ambiente sobra para o caso de a linha única de
// `configuracao` não existir — que a migration impede, e mesmo assim.
// `query` devolve `false` em erro, e chamar método em `false` seria fatal — o que
// derrubaria o expurgo por causa de uma leitura de configuração.
$st = $pdo->query('SELECT gravacao_retencao_dias FROM configuracao WHERE id = 1');
$daConfig = $st === false ? null : $st->fetchColumn();

$doBanco = is_numeric($daConfig) && (int) $daConfig > 0;
$dias = $doBanco ? (int) $daConfig : $conf['gravacao_retencao_dias'];

$log->linha('retencao', 'retenção em vigor', [
    'dias' => $dias,
    'origem' => $doBanco ? 'configuracao' : 'ambiente',
]);

$caminho = new CaminhoDeGravacao();

$busca = $pdo->prepare(
    'SELECT id, gravacao
       FROM ligacao
      WHERE gravacao IS NOT NULL
        AND iniciada_em < NOW() - INTERVAL :dias DAY
      ORDER BY id
      LIMIT 5000'
);
$busca->bindValue('dias', $dias, PDO::PARAM_INT);
$busca->execute();

// 🔴 Apaga o ARQUIVO, nunca a linha da Ligação. A Ligação aconteceu mesmo que a
// gravação não exista mais; perder a linha junto seria perder o CDR por causa de
// política de disco. A coluna aceita NULL exatamente para isto.
$limpar = $pdo->prepare('UPDATE ligacao SET gravacao = NULL WHERE id = :id');

$apagados = 0;
$ausentes = 0;
$recusados = 0;

foreach ($busca->fetchAll() as $linha) {
    if (! is_array($linha)) {
        continue;
    }

    $id = is_numeric($linha['id'] ?? null) ? (int) $linha['id'] : 0;
    $arquivo = is_string($linha['gravacao'] ?? null) ? $linha['gravacao'] : '';

    if ($id === 0 || $arquivo === '') {
        continue;
    }

    // Contenção antes de apagar. Ver `CaminhoDeGravacao`: hoje o caminho é
    // derivado de um inteiro pelo nosso código e não pode escapar, e a checagem
    // existe para que continue assim quando isso mudar.
    if (! $caminho->contido($base, $arquivo)) {
        $recusados++;
        // NÃO limpa a coluna. Recusar e seguir esconderia a linha; deixá-la
        // apontando para fora é o que faz o problema aparecer na próxima
        // execução em vez de sumir.
        $log->linha('recusado', 'caminho fora do diretório de gravação', [
            'ligacao' => $id,
            'arquivo' => $arquivo,
        ]);
        continue;
    }

    // 🔴 ORDEM: apaga o arquivo, DEPOIS limpa a coluna.
    //
    // Nesta ordem, morrer no meio deixa a linha apontando para arquivo que não
    // existe — e a próxima execução a reencontra, não acha o arquivo, e limpa. É
    // idempotente e converge.
    //
    // Na ordem inversa, morrer no meio deixa arquivo no disco sem nenhuma linha
    // apontando para ele: vazamento permanente, invisível, que só um `find`
    // manual encontraria. A ordem é carga estrutural, não estilo.
    if (is_file($arquivo)) {
        if (! unlink($arquivo)) {
            // Sem supressão de erro e sem seguir adiante: o ADR-0007 proíbe
            // engolir diagnóstico, e limpar a coluna de um arquivo que continua
            // no disco é exatamente o vazamento descrito acima.
            $log->linha('erro', 'não foi possível apagar', ['ligacao' => $id, 'arquivo' => $arquivo]);
            continue;
        }
        $apagados++;
    } else {
        // Caso normal, não anomalia: execução anterior apagou e morreu antes de
        // limpar a coluna, ou alguém removeu o arquivo à mão.
        $ausentes++;
    }

    $limpar->execute(['id' => $id]);
}

/*
 * 🔴 CONTA OS ÓRFÃOS, E NÃO OS APAGA.
 *
 * Órfão é arquivo no disco sem nenhuma linha de `ligacao` apontando para ele. A ordem
 * "apaga o arquivo, depois limpa a coluna" acima existe justamente para não CRIAR
 * órfãos — mas ela não detecta os que já existem, e eles existem: perder as linhas de
 * `ligacao` por qualquer motivo (banco recriado, restauração parcial) deixa a gravação no
 * disco fora do alcance deste expurgo PARA SEMPRE. Aconteceu nesta instância: quatro
 * gravações de chamadas reais ficaram sem linha.
 *
 * O efeito é acumulação silenciosa, e num produto de uma VPS por cliente o que enche é o
 * disco — que é também onde o Asterisk grava. Ninguém percebe até parar.
 *
 * NÃO APAGA, e a recusa é deliberada. Um expurgo que apaga o que não está no banco é uma
 * arma apontada para os dados: banco momentaneamente vazio — recriado, restaurando,
 * migrando — faria esta varredura apagar TODAS as gravações da instância numa passada, sem
 * volta. O ganho de apagar automaticamente é espaço; o risco é perda total. Contar dá ao
 * operador o número e a decisão.
 */
$orfaos = 0;
$bytesOrfaos = 0;

/*
 * 🔴 A CONSULTA TEM QUE TER DADO CERTO ANTES DE QUALQUER CONTAGEM.
 *
 * `PDO::query` devolve `false` quando falha. Sem esta guarda, `$conhecidos` ficaria vazio
 * e TODO arquivo do diretório seria contado como órfão: o relatório diria "a instância
 * inteira está órfã" por causa de uma consulta que não rodou.
 *
 * É o mesmo raciocínio que faz esta varredura não apagar nada — só aplicado ao relatório.
 * Conjunto de referência incompleto não vale para afirmar ausência, nem para apagar nem
 * para contar. Melhor não relatar do que relatar errado: número errado num relatório de
 * disco é o que faz alguém apagar o que não devia.
 */
$consulta = $pdo->query('SELECT gravacao FROM ligacao WHERE gravacao IS NOT NULL');

if ($consulta === false) {
    $log->linha('erro', 'não pude listar as gravações conhecidas; não conto órfãos nesta volta', [
        'diretorio' => $base,
    ]);
} else {
    $conhecidos = [];
    foreach ($consulta as $linha) {
        if (is_array($linha) && is_string($linha['gravacao'] ?? null)) {
            $conhecidos[$linha['gravacao']] = true;
        }
    }

    // `glob` sobre o diretório de gravação, não recursivo: é onde o MixMonitor escreve, e
    // o caminho é montado por nós a partir de um inteiro (ADR-0003).
    foreach (glob($base . '/*') ?: [] as $arquivo) {
        if (! is_file($arquivo) || isset($conhecidos[$arquivo])) {
            continue;
        }

        $orfaos++;
        $bytesOrfaos += (int) filesize($arquivo);
    }
}

if ($orfaos > 0) {
    $log->linha('orfaos', 'gravações no disco sem linha de Ligação — este expurgo NÃO as apaga', [
        'quantas' => $orfaos,
        'megabytes' => round($bytesOrfaos / 1048576, 1),
        'diretorio' => $base,
    ]);
}

$log->linha('fim', 'expurgo concluído', [
    'retencao_dias' => $dias,
    'apagados' => $apagados,
    'ja_ausentes' => $ausentes,
    'recusados' => $recusados,
    'orfaos' => $orfaos,
]);

exit($recusados > 0 ? 1 : 0);
