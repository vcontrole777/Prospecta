<?php

declare(strict_types=1);

namespace App\Whatsapp;

/**
 * Interpretação de UM payload de webhook. PURA — nenhuma consulta, nenhum efeito.
 *
 * 🔴 A INTERPRETAÇÃO É AQUI, não no endpoint. O endpoint grava o corpo e devolve 200
 * (ADR-0002, espelho de entrada). É este objeto que sabe a forma do payload, e por
 * isso é testável contra payload de verdade sem banco, sem HTTP e sem a Meta.
 *
 * O payload é aninhado em quatro níveis — `entry[].changes[].value.{statuses,messages}`
 * — e cada nível pode faltar. Toda navegação abaixo é defensiva de propósito: a
 * documentação da Meta é a que envelhece mais rápido do projeto, e um campo que muda
 * de lugar não pode derrubar o worker inteiro.
 */
final class Leitura
{
    /** @var list<Status> */
    public array $status = [];

    /** @var list<Entrada> */
    public array $entradas = [];

    /**
     * Tipos de `changes[].field` que apareceram e não são `messages`.
     *
     * Guardado para o log: campo novo é a Meta acrescentando coisa, e é melhor ver no
     * log que existe um `field` desconhecido do que descartar em silêncio.
     *
     * @var list<string>
     */
    public array $camposIgnorados = [];

    /** @param array<array-key,mixed> $payload */
    public static function de(array $payload): self
    {
        $l = new self();

        foreach (self::lista($payload, 'entry') as $entry) {
            foreach (self::lista($entry, 'changes') as $change) {
                $campo = self::texto($change, 'field');

                if ($campo !== 'messages') {
                    if ($campo !== '' && ! in_array($campo, $l->camposIgnorados, true)) {
                        $l->camposIgnorados[] = $campo;
                    }
                    continue;
                }

                $valor = is_array($change['value'] ?? null) ? $change['value'] : [];

                foreach (self::lista($valor, 'statuses') as $s) {
                    $status = self::status($s);
                    if ($status !== null) {
                        $l->status[] = $status;
                    }
                }

                foreach (self::lista($valor, 'messages') as $m) {
                    $entrada = self::entrada($m);
                    if ($entrada !== null) {
                        $l->entradas[] = $entrada;
                    }
                }
            }
        }

        return $l;
    }

    /** @param array<array-key,mixed> $s */
    private static function status(array $s): ?Status
    {
        $wamid = self::texto($s, 'id');
        $estado = self::texto($s, 'status');

        if ($wamid === '' || $estado === '') {
            return null;
        }

        $codigo = null;
        $texto = null;

        // `errors` é lista; o primeiro erro é o que descreve a falha. Traz
        // `error_data.details` com o texto que a Meta escreveu para o usuário final.
        $erros = self::lista($s, 'errors');
        if ($erros !== []) {
            $e = $erros[0];
            $c = $e['code'] ?? null;
            $codigo = is_numeric($c) ? (int) $c : null;

            $dados = is_array($e['error_data'] ?? null) ? $e['error_data'] : [];
            $texto = self::texto($dados, 'details') ?: self::texto($e, 'title') ?: self::texto($e, 'message');
            $texto = $texto === '' ? null : $texto;
        }

        return new Status(
            wamid: $wamid,
            estado: $estado,
            erroCodigo: $codigo,
            erroTexto: $texto,
            correlacao: self::texto($s, 'biz_opaque_callback_data') ?: null,
        );
    }

    /** @param array<array-key,mixed> $m */
    private static function entrada(array $m): ?Entrada
    {
        $wamid = self::texto($m, 'id');
        $de = self::texto($m, 'from');

        if ($wamid === '' || $de === '') {
            return null;
        }

        $tipo = self::texto($m, 'type');
        $texto = '';
        $botao = null;

        // Três formas de o Lead responder, e o botão tem DUAS delas. Tratar só uma
        // faria metade dos toques em botão parecer mensagem sem texto.
        if ($tipo === 'text') {
            $texto = self::texto(is_array($m['text'] ?? null) ? $m['text'] : [], 'body');
        } elseif ($tipo === 'button') {
            // Botão de TEMPLATE: vem com `text` e `payload`.
            $b = is_array($m['button'] ?? null) ? $m['button'] : [];
            $texto = self::texto($b, 'text');
            $botao = self::texto($b, 'payload') ?: null;
        } elseif ($tipo === 'interactive') {
            // Botão de mensagem INTERATIVA: outra forma, outro caminho no payload.
            $i = is_array($m['interactive'] ?? null) ? $m['interactive'] : [];
            $r = is_array($i['button_reply'] ?? null) ? $i['button_reply'] : [];
            $texto = self::texto($r, 'title');
            $botao = self::texto($r, 'id') ?: null;
        }

        return new Entrada(
            wamid: $wamid,
            de: $de,
            tipo: $tipo === '' ? 'desconhecido' : $tipo,
            texto: $texto,
            botao: $botao,
        );
    }

    /**
     * @param array<array-key,mixed> $de
     * @return list<array<array-key,mixed>>
     */
    private static function lista(array $de, string $chave): array
    {
        $v = $de[$chave] ?? null;
        if (! is_array($v)) {
            return [];
        }

        $saida = [];
        foreach ($v as $item) {
            if (is_array($item)) {
                $saida[] = $item;
            }
        }

        return $saida;
    }

    /** @param array<array-key,mixed> $de */
    private static function texto(array $de, string $chave): string
    {
        $v = $de[$chave] ?? null;

        return is_string($v) ? $v : '';
    }
}
