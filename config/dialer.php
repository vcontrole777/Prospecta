<?php

declare(strict_types=1);

/** Configuração do `dialer`. Sem env() do framework — bootstrap mínimo. */

$ler = static function (string $chave, string $padrao = ''): string {
    $v = getenv($chave);
    if ($v !== false && $v !== '') {
        return $v;
    }
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

    'ami' => [
        'host' => $ler('AMI_HOST', '127.0.0.1'),
        'porta' => (int) $ler('AMI_PORT', '5038'),
        'usuario' => $ler('AMI_USER', 'prospecta'),
        'segredo' => $ler('AMI_SECRET'),
    ],

    // Vendido por quantidade de canal: é o teto da instância.
    'canais_contratados' => (int) $ler('PROSPECTA_CANAIS', '10'),

    // Teto de chamadas por segundo da CONTA na operadora. Estourar volta como
    // 503 e dispara a proteção. Zero desliga o teto.
    'cps_maximo' => (int) $ler('PROSPECTA_CPS_MAXIMO', '0'),

    'intervalo_seg' => (float) $ler('PROSPECTA_INTERVALO_SEG', '1.0'),

    // Depois disto a Ligação é considerada presa e a reconciliação encerra.
    // Evento de AMI se perde, e isso é operação normal ao longo de semanas.
    'limite_ligacao_seg' => (int) $ler('PROSPECTA_LIMITE_LIGACAO_SEG', '300'),

    // Teto de eventos consumidos por volta. Sem ele, um PBX movimentado
    // faria o dialer drenar para sempre e nunca originar.
    'eventos_por_volta' => (int) $ler('PROSPECTA_EVENTOS_POR_VOLTA', '200'),

    'max_tentativas_padrao' => (int) $ler('PROSPECTA_MAX_TENTATIVAS', '3'),

    'tecnologia' => 'PJSIP',
    // 🔴 Fuso EXPLÍCITO para o Horário de Trabalho. Sem ele o PHP usa UTC e a janela
    // de 09h às 18h vira 06h às 15h no Brasil — a discadora para às 15h e começa às
    // 6h da manhã, com a tela mostrando o horário certo.
    'fuso' => $ler('APP_TIMEZONE', 'America/Sao_Paulo'),

    'tronco' => $ler('PROSPECTA_TRONCO', 'idt'),
    // Prefixo técnico é SELEÇÃO DE CAPACIDADE: na IDT, CallerID e DTMF só
    // funcionam na rota Platinum. Em rota mais barata os dois falham em silêncio.
    'tech_prefix' => $ler('PROSPECTA_TECH_PREFIX', '99901'),
    'contexto' => $ler('PROSPECTA_CONTEXTO', 'prospecta-saida'),
];
