<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O expurgo, executado de verdade como subprocesso.
 *
 * É o único componente do produto que APAGA arquivo, e não tinha teste nenhum — só as
 * classes puras que ele usa (`CaminhoDeGravacao`, `Registro`) tinham.
 *
 * 🔴 SEGURO POR CONSTRUÇÃO, e a garantia não é o cuidado do teste.
 *
 * Cada execução recebe um `PROSPECTA_GRAVACAO_DIR` temporário, e o script confere
 * `CaminhoDeGravacao::contido($base, $arquivo)` antes de apagar qualquer coisa. Então
 * mesmo que o banco apontasse para `/var/spool/asterisk/gravacoes`, o caminho estaria FORA
 * do base e seria recusado. Dado o histórico desta suíte com o banco de produção, essa
 * segunda barreira é a que importa.
 *
 * `DatabaseTruncation` porque o script abre a própria conexão PDO (ADR-0001).
 */
final class ExpurgoTest extends TestCase
{
    use DatabaseTruncation;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/prospecta-expurgo-' . getmypid();
        $this->apagarDir();
        mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->apagarDir();
        parent::tearDown();
    }

    #[Test]
    public function apaga_a_gravacao_vencida_e_limpa_a_coluna(): void
    {
        [$ligacao, $arquivo] = $this->gravacao(diasAtras: 40);

        $r = $this->rodar();

        $this->assertSame(0, $r['saida'], $r['erro']);
        $this->assertFileDoesNotExist($arquivo);
        $this->assertNull(DB::table('ligacao')->where('id', $ligacao)->value('gravacao'));

        // A LINHA da Ligação fica. A Ligação aconteceu mesmo que a gravação não exista
        // mais; perder a linha junto seria perder o CDR por política de disco.
        $this->assertSame(1, DB::table('ligacao')->where('id', $ligacao)->count());
    }

    #[Test]
    public function nao_toca_em_gravacao_dentro_da_retencao(): void
    {
        [$ligacao, $arquivo] = $this->gravacao(diasAtras: 3);

        $this->assertSame(0, $this->rodar()['saida']);

        $this->assertFileExists($arquivo);
        $this->assertNotNull(DB::table('ligacao')->where('id', $ligacao)->value('gravacao'));
    }

    /**
     * 🔴 A GUARDA QUE ESTE TESTE EXISTE PARA TRANCAR.
     *
     * Sem ela, `is_file()` responde `false` para todo arquivo quando falta permissão no
     * diretório, o laço conta cada um como "já ausente" e LIMPA A COLUNA. Toda gravação da
     * instância fica no disco sem linha apontando para ela, em silêncio, e nenhuma execução
     * futura consegue apagá-la — o expurgo só conhece o que está no banco.
     *
     * O script conclui a passada, registra "N já ausentes" e sai com SUCESSO. Não há erro
     * nenhum para ninguém notar.
     *
     * Pulado como root, que dispensa a conferência de modo. A condição é real nesta
     * máquina: o diretório é `asterisk:asterisk` modo 2770 e o serviço roda como
     * `prospecta`.
     */
    #[Test]
    public function sem_acesso_ao_diretorio_aborta_sem_limpar_a_coluna(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignora modo de arquivo: is_readable responde true para 0000');
        }

        [$ligacao, $arquivo] = $this->gravacao(diasAtras: 40);
        chmod($this->dir, 0o000);

        try {
            $r = $this->rodar();

            $this->assertSame(1, $r['saida'], 'tem que sair com falha para o systemd marcar');
            $this->assertStringContainsString('sem acesso ao diretório', $r['erro']);
        } finally {
            chmod($this->dir, 0o755);
        }

        // O essencial: a coluna INTACTA. Limpá-la aqui orfanaria o arquivo para sempre.
        $this->assertSame($arquivo, DB::table('ligacao')->where('id', $ligacao)->value('gravacao'));
        $this->assertFileExists($arquivo);
    }

    /**
     * Diretório inexistente sai com SUCESSO e não limpa nada.
     *
     * Instância nova, ou com gravação desligada, não tem o diretório. Tratar isso como erro
     * faria o timer do systemd acumular falhas numa instância que está perfeitamente bem — e
     * falha rotineira ensina a ignorar falha.
     */
    #[Test]
    public function diretorio_inexistente_sai_com_sucesso(): void
    {
        [$ligacao, ] = $this->gravacao(diasAtras: 40);
        $this->apagarDir();

        $r = $this->rodar();

        $this->assertSame(0, $r['saida'], $r['erro']);
        $this->assertStringContainsString('não existe', $r['erro']);
        $this->assertNotNull(DB::table('ligacao')->where('id', $ligacao)->value('gravacao'));
    }

    /**
     * Caminho fora do diretório é RECUSADO, e a coluna fica apontando para ele.
     *
     * Recusar e limpar esconderia a linha; deixá-la é o que faz o problema reaparecer na
     * próxima execução em vez de sumir. É também a barreira que torna este teste seguro.
     */
    #[Test]
    public function caminho_fora_do_diretorio_e_recusado_e_a_coluna_fica(): void
    {
        $fora = sys_get_temp_dir() . '/prospecta-fora-' . getmypid() . '.gsm';
        file_put_contents($fora, 'x');
        $ligacao = $this->ligacao(40, $fora);

        $r = $this->rodar();

        $this->assertSame(1, $r['saida'], 'recusa tem que aparecer no código de saída');
        $this->assertFileExists($fora, 'arquivo fora do base NUNCA pode ser apagado');
        $this->assertSame($fora, DB::table('ligacao')->where('id', $ligacao)->value('gravacao'));

        @unlink($fora);
    }

    /**
     * Órfão é CONTADO e não apagado.
     *
     * Apagar o que não está no banco seria arma apontada para os dados: banco
     * momentaneamente vazio faria a varredura apagar tudo numa passada, sem volta.
     */
    #[Test]
    public function conta_orfao_sem_apagar(): void
    {
        $orfao = $this->dir . '/9999.gsm';
        file_put_contents($orfao, str_repeat('x', 4096));

        $r = $this->rodar();

        $this->assertSame(0, $r['saida'], $r['erro']);
        $this->assertFileExists($orfao, 'órfão NÃO é apagado');
        $this->assertStringContainsString('"orfaos":1', $r['erro']);
    }

    #[Test]
    public function sem_orfao_nao_relata_orfao(): void
    {
        [, $arquivo] = $this->gravacao(diasAtras: 3);
        $this->assertFileExists($arquivo);

        $r = $this->rodar();

        $this->assertStringContainsString('"orfaos":0', $r['erro']);
        $this->assertStringNotContainsString('sem linha de Ligação', $r['erro']);
    }

    // ---------------------------------------------------------------- auxiliares

    /**
     * Roda o script com o banco DE TESTE e o diretório temporário.
     *
     * O ambiente ganha do `.env` em `config/ambiente.php`, então isto não depende do
     * arquivo da instância — e é o que impede o subprocesso de encostar em produção.
     *
     * @return array{saida:int, erro:string}
     */
    private function rodar(): array
    {
        $r = Process::env([
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'PROSPECTA_GRAVACAO_DIR' => $this->dir,
            'PROSPECTA_GRAVACAO_RETENCAO_DIAS' => '15',
        ])->path(base_path())->run('php bin/expurgo.php');

        // O `Registro` escreve em STDERR de propósito: STDOUT é do protocolo do fastagi.
        return ['saida' => $r->exitCode() ?? -1, 'erro' => $r->errorOutput() . $r->output()];
    }

    /** @return array{0:int, 1:string} id da Ligação, caminho do arquivo */
    private function gravacao(int $diasAtras): array
    {
        $id = $this->ligacao($diasAtras, null);
        $arquivo = $this->dir . '/' . $id . '.gsm';
        file_put_contents($arquivo, str_repeat('x', 2048));
        DB::table('ligacao')->where('id', $id)->update(['gravacao' => $arquivo]);

        return [$id, $arquivo];
    }

    private function ligacao(int $diasAtras, ?string $gravacao): int
    {
        $campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 3,
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511900000001', 'status' => 3,
        ]);

        return (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha, 'numero_tentativa' => 1,
            'iniciada_em' => now()->subDays($diasAtras),
            'encerrada_em' => now()->subDays($diasAtras),
            'gravacao' => $gravacao,
        ]);
    }

    private function apagarDir(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }
}
