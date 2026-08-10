<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * O protocolo FastAGI. Fluxos INJETADOS, não STDIN/STDOUT fixos.
 *
 * Essa injeção é o que permite o arreio de contexto sintético: um teste passa
 * dois fluxos de memória e exercita o fluxo inteiro da chamada SEM Asterisk.
 * A ideia vem do bloco `debug` do MagnusBilling, que é — ironicamente — o único
 * código do caminho de chamada deles com validação por lista de permissão.
 */
final class Agi
{
    /** @var array<string,string> */
    private array $ambiente = [];

    /**
     * Sem Registro injetado: o PHPStan mostrou que a dependência nunca era
     * lida. Quem loga é o chamador, que tem o contexto da Ligação — o
     * protocolo em si não tem o que dizer.
     *
     * @param resource $entrada
     * @param resource $saida
     */
    public function __construct(
        private $entrada,
        private $saida,
    ) {
    }

    /**
     * Lê o bloco de variáveis que o Asterisk manda ao conectar, até a linha
     * vazia.
     *
     * @return array<string,string>
     */
    public function lerAmbiente(): array
    {
        while (($linha = fgets($this->entrada)) !== false) {
            $linha = rtrim($linha, "\r\n");
            if ($linha === '') {
                break;
            }
            $pos = strpos($linha, ':');
            if ($pos === false) {
                continue;
            }
            $this->ambiente[substr($linha, 0, $pos)] = trim(substr($linha, $pos + 1));
        }

        return $this->ambiente;
    }

    public function ambiente(string $chave): ?string
    {
        return $this->ambiente[$chave] ?? null;
    }

    public function enviar(string $comando): Resposta
    {
        // Checagem EXPLÍCITA em vez de `@fwrite`. O ADR-0007 proíbe suprimir
        // diagnóstico, e usar `@` aqui seria a mesma classe de decisão que a
        // linha 3 do despachante do MagnusBilling — a que faz seis defeitos
        // parecerem funcionar. Suprimir no código que existe para não suprimir
        // é contradição, e `is_resource()` diz a mesma coisa sem esconder nada.
        if (! is_resource($this->saida) || ! is_resource($this->entrada)) {
            throw new CanalMorto($comando);
        }

        // Socket fechado no meio: o Lead desligou. NÃO é erro fatal — a
        // transação final tem que acontecer de qualquer forma (ADR-0002).
        if (fwrite($this->saida, $comando . "\n") === false) {
            throw new CanalMorto($comando);
        }

        $linha = fgets($this->entrada);
        if ($linha === false) {
            throw new CanalMorto($comando);
        }
        $linha = rtrim($linha, "\r\n");

        // "200 result=1 (valor)"
        if (preg_match('/^(\d{3})\s+result=(-?\S*)(?:\s+\((.*)\))?/', $linha, $m) !== 1) {
            return new Resposta(0, '', null, $linha);
        }

        return new Resposta((int) $m[1], $m[2], $m[3] ?? null, $linha);
    }

    /**
     * Executa aplicação de dialplan.
     *
     * 🔴 ADR-0003, quinto contexto de valor hostil: argumento de aplicação
     * Asterisk. No MagnusBilling um CallerID cru com vírgula injeta OPÇÃO no
     * MixMonitor, e com barra escreve fora do diretório previsto. Por isso
     * argumento aqui passa por lista de permissão, não por escape.
     */
    public function executar(string $aplicacao, string ...$argumentos): Resposta
    {
        foreach ($argumentos as $a) {
            if (preg_match('/^[A-Za-z0-9 ._\/@:-]*$/', $a) !== 1) {
                throw new ArgumentoRecusado($aplicacao, $a);
            }
        }
        $args = $argumentos === [] ? '' : ' "' . implode(',', $argumentos) . '"';

        return $this->enviar('EXEC ' . $aplicacao . $args);
    }

    public function atender(): Resposta
    {
        return $this->enviar('ANSWER');
    }

    public function variavel(string $nome): ?string
    {
        $r = $this->enviar('GET VARIABLE ' . $nome);

        return $r->resultado === '1' ? ($r->dados ?? '') : null;
    }

    public function definirVariavel(string $nome, string $valor): Resposta
    {
        return $this->enviar(sprintf('SET VARIABLE %s "%s"', $nome, str_replace('"', '', $valor)));
    }

    /**
     * Toca arquivo e aceita dígito DURANTE a reprodução — barge-in de verdade.
     * Quem já entendeu a oferta e quer opt-in não deveria ser obrigado a
     * esperar os 30 s até o fim.
     */
    public function lerDigitos(string $arquivo, int $maximo, int $timeoutMs): string
    {
        $r = $this->enviar(sprintf('GET DATA %s %d %d', $arquivo, $timeoutMs, $maximo));

        return $r->resultado === '-1' ? '' : $r->resultado;
    }

    public function verbose(string $mensagem): void
    {
        $this->enviar('VERBOSE "' . str_replace('"', '', $mensagem) . '" 3');
    }

    public function desligar(): void
    {
        $this->enviar('HANGUP');
    }
}
