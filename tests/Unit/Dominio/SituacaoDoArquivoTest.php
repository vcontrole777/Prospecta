<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\SituacaoDoArquivo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A regra que estava em três lugares antes de virar uma classe.
 *
 * O que ela existe para impedir: reportar "não existe" quando a verdade é "não consegui
 * ver". As duas causas pedem ações opostas — copiar arquivo, ou consertar permissão —, e
 * confundi-las já bloqueou ativação de Campanha, já acusaria o Asterisk de perder gravação,
 * e no expurgo chegaria a orfanar o disco inteiro.
 */
final class SituacaoDoArquivoTest extends TestCase
{
    private SituacaoDoArquivo $situacao;

    private string $dir;

    protected function setUp(): void
    {
        $this->situacao = new SituacaoDoArquivo();
        $this->dir = sys_get_temp_dir() . '/prospecta-situacao-' . getmypid();
        @mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @chmod($this->dir, 0o755);
        @rmdir($this->dir);
    }

    #[Test]
    public function caminho_vazio_nao_e_pergunta(): void
    {
        $this->assertSame(SituacaoDoArquivo::SEM_CAMINHO, $this->situacao->de(''));
    }

    #[Test]
    public function arquivo_presente_existe(): void
    {
        $f = $this->dir . '/x.alaw';
        file_put_contents($f, 'a');

        $this->assertSame(SituacaoDoArquivo::EXISTE, $this->situacao->de($f));
    }

    /** Diretório atravessável e arquivo fora dele: aí sim temos como afirmar ausência. */
    #[Test]
    public function arquivo_faltando_em_diretorio_acessivel_e_ausente(): void
    {
        $this->assertSame(SituacaoDoArquivo::AUSENTE, $this->situacao->de($this->dir . '/nao-existe.alaw'));
    }

    /**
     * 🔴 Diretório INEXISTENTE é prova de ausência, não de cegueira.
     *
     * Tratar `! is_dir()` como "não consigo ver" faria toda instância sem áudio instalado
     * exibir aviso de permissão — mandando o operador mexer em grupo de usuário quando o
     * que falta é copiar arquivo.
     */
    #[Test]
    public function diretorio_inexistente_conta_como_ausente(): void
    {
        $this->assertSame(
            SituacaoDoArquivo::AUSENTE,
            $this->situacao->de('/tmp/prospecta-nao-existe-' . getmypid() . '/x.alaw'),
        );
        $this->assertTrue($this->situacao->diretorioAcessivel('/tmp/prospecta-nao-existe-' . getmypid()));
    }

    /**
     * Diretório que existe e não abre devolve "não sei dizer".
     *
     * Pulado como root: o kernel dispensa root da conferência de modo, então
     * `is_readable` responde true para 0000. A condição é real nos serviços, que rodam
     * como `prospecta`.
     */
    #[Test]
    public function diretorio_fechado_e_sem_acesso(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignora modo de arquivo: is_readable responde true para 0000');
        }

        chmod($this->dir, 0o000);

        $this->assertSame(SituacaoDoArquivo::SEM_ACESSO, $this->situacao->de($this->dir . '/x.alaw'));
        $this->assertFalse($this->situacao->diretorioAcessivel($this->dir));
    }

    /**
     * Só leitura, sem travessia, também é cegueira.
     *
     * `r--` deixa listar e não deixa entrar; `--x` deixa entrar e não listar. `is_file`
     * sobre um caminho dentro do diretório precisa da travessia — conferir só uma das duas
     * permissões deixaria metade dos casos respondendo "ausente" por engano.
     */
    #[Test]
    public function diretorio_sem_travessia_tambem_e_sem_acesso(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignora modo de arquivo');
        }

        chmod($this->dir, 0o444);

        $this->assertFalse($this->situacao->diretorioAcessivel($this->dir));
    }

    #[Test]
    public function diretorio_acessivel_responde_verdadeiro(): void
    {
        $this->assertTrue($this->situacao->diretorioAcessivel($this->dir));
        $this->assertTrue($this->situacao->diretorioAcessivel(''));
    }
}
