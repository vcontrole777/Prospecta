<?php

declare(strict_types=1);

/**
 * Configuração do PAINEL. Os processos de chamada leem `config/dialer.php` e
 * `config/fastagi.php` por getenv, com bootstrap mínimo (ADR-0001) — este arquivo
 * é só para o lado Laravel, e existe para `config:cache` funcionar: `env()` fora de
 * arquivo de configuração devolve null quando o cache está ativo.
 */
return [
    // Mesma variável que os processos de chamada usam. O prefixo relativo que o
    // Asterisk precisa no `Playback` é o basename disto.
    'audio_path' => rtrim(
        (string) env('PROSPECTA_AUDIO_PATH', '/var/lib/asterisk/sounds/prospecta'),
        '/',
    ),

    /*
     * O fuso em que o OPERADOR pensa, usado para exibir instantes nas telas.
     *
     * 🔴 NÃO é o fuso de armazenamento. O banco guarda em UTC e continua guardando —
     * `Conversas::momento()` interpreta as colunas em `app.timezone` e calcula a janela
     * de 24 h em cima disso. Este valor traduz só na saída, em `App\Suporte\Instante`.
     *
     * Mesma variável e mesmo padrão de `config/dialer.php`: o Horário de Trabalho e a
     * tela têm que falar do mesmo relógio, senão o operador vê "fora de horário" num
     * horário que a tela diz ser comercial.
     */
    'fuso' => (string) env('APP_TIMEZONE', 'America/Sao_Paulo'),

    // Limite de duração da mensagem de Campanha. Generoso de propósito: é o
    // operador que sabe quanto a oferta dele precisa falar. O limite duro que
    // importa é o da `abertura`, e aquele é áudio de sistema, não de Campanha.
    'audio_limite_seg' => (float) env('PROSPECTA_AUDIO_LIMITE_SEG', '120'),

    /*
     * O arquivo de pjsip que a tela de Rota escreve.
     *
     * 🔴 UM arquivo só, incluído pelo `pjsip.conf`. O painel roda como `prospecta` e não
     * escreve em `/etc/asterisk`; o provisionamento cria este arquivo com dono
     * `asterisk:prospecta` e modo 0660. Privilégio mínimo: o painel escreve aquele arquivo
     * e nada mais, e não ganha `sudo` nem a CLI do Asterisk.
     */
    'pjsip_rota' => (string) env('PROSPECTA_PJSIP_ROTA', '/etc/asterisk/pjsip-rota.conf'),
];
