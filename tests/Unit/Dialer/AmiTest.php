<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Ami;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A conexão AMI perdida, que até 16/11/2026 era indistinguível de uma recusa.
 *
 * O incidente: o Asterisk caiu com SIGSEGV em 13/11/2026, o systemd o subiu de novo,
 * e o dialer passou três dias escrevendo no socket do processo morto — cada
 * `Originate` saía "sem resposta do AMI", e a `Protecao` pausou as Campanhas.
 *
 * 🔴 PAR DE SOCKETS, e não `php://memory`, onde o caminho é de rede. O `stream_select`
 * do `temDados` não aceita fluxo de memória, e o `Broken pipe` só existe num socket de
 * verdade. Fechar uma ponta do par é o que o Asterisk reiniciado faz com a conexão do
 * processo anterior.
 */
final class AmiTest extends TestCase
{
    /** O caminho que pegou em produção: a queda aparece primeiro na drenagem de eventos. */
    #[Test]
    public function asterisk_que_fechou_o_socket_e_conexao_perdida_na_leitura(): void
    {
        [$dialer, $asterisk] = $this->par();
        $ami = new Ami($dialer, $dialer);

        fclose($asterisk);

        // EOF deixa o socket "legível": o laço entra e lê.
        $this->assertTrue($ami->temDados(0.0));
        $this->assertSame([], $ami->ler());
        $this->assertTrue($ami->conexaoPerdida());
    }

    /** O sintoma registrado no journal: `fwrite(): ... errno=32 Broken pipe`. */
    #[Test]
    public function originate_em_cano_quebrado_e_perda_e_nao_recusa(): void
    {
        [$dialer, $asterisk] = $this->par();
        $ami = new Ami($dialer, $dialer);
        fclose($asterisk);

        // Em produção o `Registro` captura este warning para o log; aqui o teste faz
        // o papel dele, para conferir que é o MESMO erro do incidente.
        $avisos = [];
        set_error_handler(static function (int $nivel, string $msg) use (&$avisos): bool {
            $avisos[] = $msg;

            return true;
        });
        try {
            $resp = $ami->originar('PJSIP/5511999990000@rota', 42, 'prospecta', 's', 28);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $resp);
        $this->assertTrue($ami->conexaoPerdida());
        $this->assertStringContainsString('Broken pipe', implode(' | ', $avisos));
    }

    /**
     * A conexão cai DEPOIS de a ação sair, enquanto se espera a resposta. O evento
     * que chegou antes da queda continua saindo em `ler()`: é o `Hangup` de uma
     * chamada em voo, e descartá-lo é o defeito que os `pendentes` existem para evitar.
     */
    #[Test]
    public function eof_esperando_resposta_e_perda_e_preserva_o_evento_ja_lido(): void
    {
        $entrada = $this->memoria("Event: Hangup\r\nUniqueid: 41\r\nCause: 16\r\n\r\n");
        $ami = new Ami($entrada, $this->memoria(''));

        $this->assertSame([], $ami->originar('PJSIP/5511999990000@rota', 42, 'prospecta', 's', 28));
        $this->assertTrue($ami->conexaoPerdida());
        $this->assertSame(['Event' => 'Hangup', 'Uniqueid' => '41', 'Cause' => '16'], $ami->ler());
    }

    /**
     * 🔴 RECUSA CONTINUA SENDO RECUSA. O Asterisk vivo dizendo não é problema de
     * configuração ou de conta — é o que a `Protecao` existe para pegar, e não pode ser
     * confundido com o cano quebrado.
     */
    #[Test]
    public function recusa_do_asterisk_nao_e_perda(): void
    {
        [$dialer, $asterisk] = $this->par();
        $ami = new Ami($dialer, $dialer);

        fwrite($asterisk, "Response: Error\r\nActionID: prospecta-1\r\nMessage: Extension does not exist.\r\n\r\n");

        $resp = $ami->originar('PJSIP/5511999990000@rota', 42, 'prospecta', 's', 28);

        $this->assertSame('Error', $resp['Response'] ?? null);
        $this->assertFalse($ami->conexaoPerdida());
    }

    /** Asterisk vivo e calado: nada a ler, e nada perdido. */
    #[Test]
    public function asterisk_vivo_sem_evento_nao_e_perda(): void
    {
        [$dialer, $asterisk] = $this->par();
        $ami = new Ami($dialer, $dialer);

        $this->assertFalse($ami->temDados(0.0));
        $this->assertFalse($ami->conexaoPerdida());

        fclose($asterisk);
    }

    /** @return array{0: resource, 1: resource} ponta do dialer, ponta do Asterisk */
    private function par(): array
    {
        $par = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($par === false) {
            throw new \RuntimeException('não abriu par de sockets');
        }

        return [$par[0], $par[1]];
    }

    /** @return resource */
    private function memoria(string $conteudo)
    {
        $f = fopen('php://memory', 'r+');
        if ($f === false) {
            throw new \RuntimeException('não abriu fluxo de memória');
        }
        fwrite($f, $conteudo);
        rewind($f);

        return $f;
    }
}
