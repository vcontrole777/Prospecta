<?php

declare(strict_types=1);

namespace App\Audio;

use Symfony\Component\Process\Process;

/**
 * Converte o áudio enviado para o formato do PBX e VERIFICA o resultado.
 *
 * 🔴 A verificação não é opcional, e não é a mesma coisa que o código de saída do
 * `sox`. O `sox` termina com sucesso e parâmetro errado — conversão que "deu certo"
 * em estéreo 44,1 kHz é silêncio na chamada. A checagem é a invariante de tamanho
 * da `Alaw`, que num arquivo sem cabeçalho é a única coisa que existe para checar.
 *
 * 🔴 `Process` com ARRAY de argumentos, nunca string. Sem shell no caminho, o
 * contexto "shell" do ADR-0003 fecha por construção: nome de arquivo com `;` ou
 * `$(...)` é só um nome de arquivo. Não há escape para esquecer.
 */
final class Conversor
{
    public function __construct(
        private readonly Alaw $formato = new Alaw(),
        private readonly int $timeoutSeg = 60,
    ) {
    }

    /**
     * Converte e devolve a duração em segundos.
     *
     * @throws AudioRecusado
     */
    public function converter(string $origem, string $destino, ?float $limiteSeg = null): float
    {
        $segundos = $this->duracaoDaOrigem($origem);

        if ($limiteSeg !== null && $segundos > $limiteSeg) {
            throw AudioRecusado::longoDemais($segundos, $limiteSeg);
        }

        $sox = new Process($this->formato->argumentosSox($origem, $destino));
        $sox->setTimeout($this->timeoutSeg);
        $sox->run();

        if (! $sox->isSuccessful()) {
            throw AudioRecusado::conversaoFalhou(trim($sox->getErrorOutput()));
        }

        clearstatcache(true, $destino);
        $bytes = is_file($destino) ? (int) filesize($destino) : 0;

        if ($bytes === 0) {
            throw AudioRecusado::vazio();
        }

        if (! $this->formato->tamanhoConfere($bytes, $segundos)) {
            // Remove o arquivo ruim: deixá-lo no diretório de áudio do Asterisk
            // seria deixar disponível exatamente o que produz chamada muda.
            @unlink($destino);

            throw AudioRecusado::incompativel($bytes, $this->formato->tamanhoEsperado($segundos));
        }

        // A duração VERDADEIRA é a do arquivo final, derivada do tamanho — e é o que
        // vai para o banco. A da origem pode diferir por arredondamento de quadro.
        return $this->formato->duracaoSeg($bytes);
    }

    /**
     * Duração da origem, pelo cabeçalho. Só funciona em formato COM cabeçalho —
     * que é justamente o que se recebe de upload, e o motivo pelo qual a
     * verificação do resultado precisa ser por tamanho.
     *
     * @throws AudioRecusado
     */
    private function duracaoDaOrigem(string $origem): float
    {
        $p = new Process(['sox', '--i', '-D', $origem]);
        $p->setTimeout($this->timeoutSeg);
        $p->run();

        if (! $p->isSuccessful()) {
            throw AudioRecusado::ilegivel(trim($p->getErrorOutput()));
        }

        $segundos = (float) trim($p->getOutput());

        if ($segundos <= 0.0) {
            throw AudioRecusado::ilegivel('duração informada como zero.');
        }

        return $segundos;
    }
}
