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

    // Limite de duração da mensagem de Campanha. Generoso de propósito: é o
    // operador que sabe quanto a oferta dele precisa falar. O limite duro que
    // importa é o da `abertura`, e aquele é áudio de sistema, não de Campanha.
    'audio_limite_seg' => (float) env('PROSPECTA_AUDIO_LIMITE_SEG', '120'),
];
