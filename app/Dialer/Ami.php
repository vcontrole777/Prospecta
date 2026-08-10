<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * Cliente AMI. Fluxos INJETADOS, como o `Agi` — é o que permite testar sem
 * Asterisk.
 */
final class Ami
{
    private int $sequencia = 0;

    /**
     * Pacotes lidos enquanto se esperava a resposta de uma ação. São eventos
     * assíncronos que chegaram no meio, e precisam ser devolvidos depois — não
     * descartados, senão `DialEnd` e `Hangup` se perdem.
     *
     * @var list<array<string,string>>
     */
    private array $pendentes = [];

    /**
     * @param resource $entrada
     * @param resource $saida
     */
    public function __construct(
        private $entrada,
        private $saida,
    ) {
    }

    /** @return array<string,string> */
    public function login(string $usuario, string $segredo): array
    {
        return $this->acao([
            'Action' => 'Login',
            'Username' => $usuario,
            'Secret' => $segredo,
            'Events' => 'on',
        ]);
    }

    /**
     * Origina a chamada.
     *
     * 🔴 `Async: true` é obrigatório. Sem ele a ação BLOQUEIA até a chamada
     * terminar, e uma Ligação de 40 s congelaria a originação seguinte por 40 s.
     *
     * 🔴 `ChannelId` recebe o id da Ligação. O `Uniqueid` que volta nos eventos
     * passa a SER a nossa chave primária: sem tabela de tradução, e o caminho da
     * gravação deriva de um inteiro (ADR-0003).
     *
     * 🔴 `CallerID` é OMITIDO quando a BINA é nula. Medido: string vazia pode
     * sobrescrever o que a rota ia colocar, ou sair como chamada ANÔNIMA, que
     * tem atendimento perto de zero — e a causa seria invisível, porque ninguém
     * investiga um campo em branco.
     *
     * @param array<string,string> $variaveis
     * @return array<string,string>
     */
    public function originar(
        string $canal,
        int $ligacaoId,
        string $contexto,
        string $extensao,
        int $timeoutSeg,
        ?string $bina = null,
        array $variaveis = [],
    ): array {
        $campos = [
            'Action' => 'Originate',
            'Channel' => $canal,
            'Context' => $contexto,
            'Exten' => $extensao,
            'Priority' => '1',
            'ChannelId' => (string) $ligacaoId,
            'Timeout' => (string) ($timeoutSeg * 1000),
            'Async' => 'true',
        ];

        if ($bina !== null && $bina !== '') {
            $campos['CallerID'] = $bina;
        }

        $linhas = [];
        foreach ($campos as $k => $v) {
            $linhas[] = "{$k}: {$v}";
        }
        foreach ($variaveis as $k => $v) {
            $linhas[] = "Variable: {$k}={$v}";
        }

        return $this->enviarLinhas($linhas);
    }

    /**
     * @param array<string,string> $campos
     * @return array<string,string>
     */
    public function acao(array $campos): array
    {
        $linhas = [];
        foreach ($campos as $k => $v) {
            $linhas[] = "{$k}: {$v}";
        }

        return $this->enviarLinhas($linhas);
    }

    /**
     * Há evento esperando? Sem isto o laço bloquearia em `fgets` até o próximo
     * evento chegar, e pararia de originar enquanto espera.
     */
    public function temDados(float $timeoutSeg = 0.0): bool
    {
        if ($this->pendentes !== []) {
            return true;
        }
        if (! is_resource($this->entrada)) {
            return false;
        }
        $ler = [$this->entrada];
        $escrever = null;
        $erro = null;
        $seg = (int) $timeoutSeg;
        $micro = (int) (($timeoutSeg - $seg) * 1_000_000);

        return stream_select($ler, $escrever, $erro, $seg, $micro) > 0;
    }

    /**
     * Próximo pacote: evento ou resposta. Array vazio no fim do fluxo.
     *
     * @return array<string,string>
     */
    public function ler(): array
    {
        // Evento que chegou enquanto se esperava a resposta de uma ação sai
        // primeiro: descartá-lo perderia DialEnd e Hangup.
        if ($this->pendentes !== []) {
            return array_shift($this->pendentes);
        }

        return $this->lerDoFluxo();
    }

    /**
     * 🔴 CORRELAÇÃO POR `ActionID`.
     *
     * Sem ela, isto lia o PRÓXIMO pacote e o tratava como resposta — mas com
     * `Events: on` e chamadas em voo, o próximo pacote é frequentemente um
     * EVENTO assíncrono. Sem a chave `Response`, o chamador concluía que a ação
     * foi recusada.
     *
     * Encontrado em teste de integração: "Originate aceito" e "Originate
     * recusado — sem resposta do AMI" apareciam intercalados para originações que
     * TODAS deram certo. É defeito que **piora com carga**, porque quanto mais
     * chamada em voo, mais evento intercalado — e num teste de uma chamada só ele
     * não aparece.
     *
     * @param list<string> $linhas
     * @return array<string,string>
     */
    private function enviarLinhas(array $linhas): array
    {
        if (! is_resource($this->saida)) {
            return [];
        }

        $id = 'prospecta-' . ++$this->sequencia;
        $linhas[] = "ActionID: {$id}";

        // Sem supressão de erro: o ADR-0007 proíbe, e `is_resource` diz o mesmo
        // sem esconder nada.
        if (fwrite($this->saida, implode("\r\n", $linhas) . "\r\n\r\n") === false) {
            return [];
        }

        // Lê até achar a resposta COM o nosso ActionID, guardando o que vier no
        // meio. O teto evita laço infinito se a resposta nunca chegar.
        for ($i = 0; $i < 500; $i++) {
            $pacote = $this->lerDoFluxo();
            if ($pacote === []) {
                return [];
            }
            if (($pacote['ActionID'] ?? null) === $id) {
                return $pacote;
            }
            $this->pendentes[] = $pacote;
        }

        return [];
    }

    /** @return array<string,string> */
    private function lerDoFluxo(): array
    {
        if (! is_resource($this->entrada)) {
            return [];
        }

        $pacote = [];
        while (($linha = fgets($this->entrada)) !== false) {
            $linha = rtrim($linha, "\r\n");
            if ($linha === '') {
                if ($pacote !== []) {
                    return $pacote;
                }
                continue;
            }
            $pos = strpos($linha, ':');
            if ($pos !== false) {
                $pacote[substr($linha, 0, $pos)] = trim(substr($linha, $pos + 1));
            }
        }

        return $pacote;
    }
}
