<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * Log estruturado, com o id da Ligação em TODA linha.
 *
 * ADR-0007: o `fastagi` roda sem navegador, sem observador, com o Lead na
 * linha. É o pior lugar do sistema para um diagnóstico engolido.
 *
 * No MagnusBilling, a linha 3 do despachante é
 * `error_reporting(E_ALL ^ (E_NOTICE | E_WARNING | E_DEPRECATED))`, e é ela que
 * faz seis defeitos do caminho de DTMF PARECEREM funcionar. A supressão não é
 * dano colateral — é a causa. Aqui é o oposto: nada é suprimido, e warning vira
 * linha de log com o id da Ligação.
 *
 * Escreve em STDERR de propósito: em ativação por socket do systemd, STDOUT é o
 * socket do Asterisk. Escrever log lá corromperia o protocolo AGI.
 */
final class Registro
{
    /** @param resource $saida */
    public function __construct(
        private $saida,
        private ?int $ligacaoId = null,
    ) {
    }

    public function ligacao(int $id): void
    {
        $this->ligacaoId = $id;
    }

    /**
     * Instala manipuladores para que NADA seja suprimido: warning, notice e
     * deprecated viram linha de log em vez de desaparecerem.
     */
    public function capturarTudo(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');   // STDOUT é o socket; nunca escrever lá

        set_error_handler(function (int $nivel, string $msg, string $arq, int $linha): bool {
            $this->linha('php', $msg, ['nivel' => $nivel, 'arquivo' => basename($arq), 'linha' => $linha]);

            return true;
        });

        set_exception_handler(function (\Throwable $e): void {
            $this->linha('excecao', $e->getMessage(), [
                'classe' => $e::class,
                'arquivo' => basename($e->getFile()),
                'linha' => $e->getLine(),
            ]);
        });
    }

    /** @param array<string,scalar|null> $extra */
    public function linha(string $evento, string $mensagem, array $extra = []): void
    {
        $registro = ['ligacao' => $this->ligacaoId, 'evento' => $evento, 'mensagem' => $mensagem] + $extra;
        fwrite($this->saida, json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
