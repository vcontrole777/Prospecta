<?php

declare(strict_types=1);

/** Configuração do `worker`. Sem env() do framework — bootstrap mínimo. */

$ler = (require dirname(__DIR__) . '/bootstrap/ambiente.php')(dirname(__DIR__));

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

    // Quantas Ações por volta. Baixo de propósito: cada uma é uma requisição HTTP
    // com timeout, e uma volta longa demora a reagir a sinal de parada.
    // 🔴 A `APP_KEY` é chave de DADOS aqui, não só de sessão.
    //
    // A credencial de fornecedor é cifrada pelo painel e decifrada aqui, e as duas
    // pontas só compartilham esta chave — o worker não tem o `Crypt` do Laravel
    // porque roda com bootstrap mínimo (ADR-0001). Trocar a APP_KEY torna toda
    // credencial ilegível e exige recadastrar cada integração.
    'app_key' => $ler('APP_KEY'),

    /*
     * Base para montar a URL publica da imagem de cabecalho de template.
     *
     * O worker NAO sobe o framework (mesma disciplina do dialer), entao nao existe
     * `route()` aqui -- a URL e montada com isto mais o token da imagem. Errar esta
     * variavel faz a Meta nao conseguir buscar a imagem e recusar o disparo inteiro,
     * o que aparece como falha de envio e nao como configuracao errada.
     */
    'url_base' => rtrim($ler('APP_URL', ''), '/'),

    'lote' => (int) $ler('PROSPECTA_WORKER_LOTE', '20'),

    // 🔴 TETO BAIXO, e não é falta de ambição.
    //
    // A Cloud API não tem chave de idempotência: retentativa depois de timeout PODE
    // enviar a mesma mensagem duas vezes, e o Lead recebe duas enquanto o cliente
    // paga duas. Ver `PoliticaDeEnvio`. Três é o compromisso entre sobreviver a
    // falha transitória e limitar o dano quando a ambiguidade acontece.
    'max_tentativas' => (int) $ler('PROSPECTA_WORKER_MAX_TENTATIVAS', '3'),

    // Quantos eventos do webhook por volta.
    //
    // 🔴 Chave PRÓPRIA, não a do dialer. `PROSPECTA_EVENTOS_POR_VOLTA` existe e é do
    // dialer, para eventos do AMI — coisa diferente, com volume diferente. Escrevi o
    // laço usando a chave do dialer por descuido e o `$conf` vindo de `require` não
    // acusa chave ausente: seria `null`, e `reivindicar(null)` pegaria zero evento
    // para sempre, em silêncio.
    'eventos_por_volta' => (int) $ler('PROSPECTA_WORKER_EVENTOS_POR_VOLTA', '100'),

    // Espera quando a fila está vazia.
    'intervalo_vazio_seg' => (int) $ler('PROSPECTA_WORKER_INTERVALO_SEG', '5'),

    // Espera quando o CANAL está quebrado — token inválido, aplicativo sem
    // permissão. Longo porque só ação humana resolve, e insistir a cada 5 s
    // encheria o log escondendo o resto.
    'intervalo_canal_parado_seg' => (int) $ler('PROSPECTA_WORKER_PAUSA_SEG', '300'),

    'timeout_http_seg' => (int) $ler('PROSPECTA_WORKER_TIMEOUT_SEG', '15'),
];
