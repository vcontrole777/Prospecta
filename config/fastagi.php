<?php

declare(strict_types=1);

/**
 * Configuração do handler FastAGI.
 *
 * NÃO usa config() nem env() do Laravel: o handler roda com bootstrap mínimo
 * (ADR-0001) e não sobe o framework. Lê de variável de ambiente, que o systemd
 * fornece via EnvironmentFile.
 */

$ler = static function (string $chave, string $padrao = ''): string {
    $v = getenv($chave);
    if ($v !== false && $v !== '') {
        return $v;
    }

    // Só para desenvolvimento local: em produção o systemd passa o ambiente.
    static $arquivo = null;
    if ($arquivo === null) {
        $arquivo = [];
        $caminho = dirname(__DIR__) . '/.env';
        if (is_readable($caminho)) {
            foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
                if ($linha === '' || $linha[0] === '#' || ! str_contains($linha, '=')) {
                    continue;
                }
                [$k, $val] = explode('=', $linha, 2);
                $arquivo[trim($k)] = trim($val, " \t\"'");
            }
        }
    }

    return $arquivo[$chave] ?? $padrao;
};

return [
    'banco' => [
        'dsn' => sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $ler('DB_HOST', '127.0.0.1'),
            $ler('DB_PORT', '3306'),
            $ler('DB_DATABASE', 'prospecta'),
        ),
        'usuario' => $ler('DB_USERNAME', 'prospecta'),
        'senha' => $ler('DB_PASSWORD'),
    ],

    // Caminho de gravação derivado do id INTEIRO da Ligação (ADR-0003).
    'gravacao_dir' => $ler('PROSPECTA_GRAVACAO_DIR', '/var/spool/asterisk/gravacoes'),

    // Retenção da gravação, em dias. Lida pelo `bin/expurgo.php`, que apaga o
    // ARQUIVO e limpa a coluna — nunca a linha da Ligação.
    'gravacao_retencao_dias' => (int) $ler('PROSPECTA_GRAVACAO_RETENCAO_DIAS', '30'),

    // Prefixo de áudio como o Asterisk o resolve, relativo a sounds/.
    // 🔴 UMA variável de ambiente, DOIS valores derivados dela.
    //
    // O Asterisk resolve `Playback` relativo à raiz de sons dele, então o dialplan
    // precisa de `prospecta/arquivo`; o painel precisa do caminho absoluto para
    // escrever. Antes eram duas env separadas — `PROSPECTA_AUDIO_DIR` relativa e o
    // absoluto codificado no playbook — e duas fontes para a mesma coisa divergem na
    // primeira mudança: bastaria alguém renomear o diretório num lugar para o painel
    // gravar onde o PBX não procura, sem erro nenhum aparecendo.
    'audio_path' => rtrim($ler('PROSPECTA_AUDIO_PATH', '/var/lib/asterisk/sounds/prospecta'), '/'),
    'audio_dir' => basename(rtrim($ler('PROSPECTA_AUDIO_PATH', '/var/lib/asterisk/sounds/prospecta'), '/')),
];
