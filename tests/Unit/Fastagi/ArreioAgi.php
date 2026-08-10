<?php

declare(strict_types=1);

namespace Tests\Unit\Fastagi;

use App\Fastagi\Agi;

/**
 * Arreio de contexto sintético: dois fluxos em memória no lugar do socket do
 * Asterisk.
 *
 * É o padrão que o bloco `debug` do MagnusBilling usa — e que é, ironicamente,
 * o único código do caminho de chamada deles com validação por lista de
 * permissão. Aqui ele serve ao propósito registrado no #24: testar o handler
 * sem PBX, rodando O MESMO caminho de código.
 */
final class ArreioAgi
{
    /** @var resource */
    private $entrada;
    /** @var resource */
    private $saida;

    public readonly Agi $agi;

    /**
     * @param list<string> $respostas na ordem em que o Asterisk as devolveria
     * @param array<string,string> $ambiente
     */
    public function __construct(array $respostas, array $ambiente = [])
    {
        $e = fopen('php://memory', 'r+');
        $s = fopen('php://memory', 'r+');
        if ($e === false || $s === false) {
            throw new \RuntimeException('não abriu fluxo de memória');
        }
        $this->entrada = $e;
        $this->saida = $s;

        foreach ($ambiente as $k => $v) {
            fwrite($this->entrada, "{$k}: {$v}\n");
        }
        if ($ambiente !== []) {
            fwrite($this->entrada, "\n");
        }
        foreach ($respostas as $r) {
            fwrite($this->entrada, $r . "\n");
        }
        rewind($this->entrada);

        $this->agi = new Agi($this->entrada, $this->saida);
    }

    /** @return list<string> comandos que o handler enviou, na ordem */
    public function comandos(): array
    {
        rewind($this->saida);
        $bruto = stream_get_contents($this->saida);

        return $bruto === false || $bruto === ''
            ? []
            : array_values(array_filter(explode("\n", rtrim($bruto, "\n"))));
    }

    /** Fecha o fluxo de saída para simular o Lead desligando. */
    public function matarCanal(): void
    {
        fclose($this->saida);
    }
}
