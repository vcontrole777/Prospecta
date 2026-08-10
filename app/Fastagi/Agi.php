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
     * O Asterisk já avisou que o canal caiu?
     *
     * `GET VARIABLE` continua respondendo em canal morto, então saber disto permite ao
     * chamador decidir o que ainda vale ler.
     */
    private bool $desligou = false;

    public function desligou(): bool
    {
        return $this->desligou;
    }

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

        // 🔴 LÊ ATÉ ACHAR A RESPOSTA, pulando a notificação ASSÍNCRONA de desligamento.
        //
        // O Asterisk envia uma linha `HANGUP` sozinha, a qualquer momento, sem ser
        // resposta a nada. A versão anterior lia UMA linha e, quando ela não casava com
        // a expressão, devolvia `Resposta(0, '')` em SILÊNCIO — deixando a resposta
        // verdadeira do comando anterior na fila e DESLOCANDO todo o diálogo em um.
        //
        // Medido em chamada real, com `agi set debug on`:
        //
        //   EXEC AMD ...            -> 200 result=0
        //                           -> HANGUP                 <- consumido como resposta
        //   GET VARIABLE AMDSTATUS  -> 200 result=1 (HANGUP)   <- lido como AMDCAUSE
        //   GET DATA ...            -> 511 dead channel
        //
        // O resultado NÃO foi um erro visível: foi DADO FABRICADO. O `fastagi` gravou
        // `amdStatus = null` e `amdCause = 'HANGUP'`, e numa chamada anterior extraiu a
        // tecla "1" de um `200 result=1 (SUCCESS)` de `GET VARIABLE` — registrando um
        // dígito que a pessoa nunca apertou, com desfecho `invalido` inventado.
        //
        // Pular a linha e seguir lendo preserva o desenho: o `Fluxo` consegue ler
        // `AMDSTATUS = HANGUP` e gravar `nao_atendeu`, que é o desfecho verdadeiro.
        while (true) {
            $linha = fgets($this->entrada);
            if ($linha === false) {
                throw new CanalMorto($comando);
            }
            $linha = rtrim($linha, "\r\n");

            if ($linha === '') {
                continue;
            }

            if ($linha === 'HANGUP') {
                // Só registra. `GET VARIABLE` continua funcionando em canal morto — é o
                // que permite ler o veredito do AMD depois do desligamento.
                $this->desligou = true;
                continue;
            }

            break;
        }

        // 🔴 `511 Command Not Permitted on a dead channel` é CANAL MORTO, e antes caía
        // no mesmo silêncio: não casa com a expressão, virava `Resposta(0, '')`, e o
        // fluxo seguia tentando tocar áudio e ler dígito num canal que não existe.
        if (str_starts_with($linha, '511')) {
            $this->desligou = true;

            throw new CanalMorto($comando);
        }

        // "200 result=1 (valor)"
        if (preg_match('/^(\d{3})\s+result=(-?\S*)(?:\s+\((.*)\))?/', $linha, $m) !== 1) {
            // 510 (comando inválido) e 520 (uso inválido) caem aqui. NÃO é canal morto:
            // é defeito nosso na montagem do comando. A linha crua vai na `Resposta` para
            // o chamador poder registrá-la — é o que impede o silêncio.
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
