<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dialer\Fila;
use App\Livewire\Rota;
use App\Models\User;
use App\Seguranca\Cofre;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A tela de Rota, e o elo dela com o dialer.
 *
 * `DatabaseTruncation` para poder afirmar contra `Fila::rota()` de verdade: o que esta tela
 * promete não é "o valor aparece no formulário", é o DIALER discar pelo prefixo novo.
 *
 * O arquivo de pjsip aponta para um temporário em cada teste — nunca `/etc/asterisk`.
 */
final class RotaTest extends TestCase
{
    use DatabaseTruncation;

    private string $arquivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->arquivo = sys_get_temp_dir() . '/prospecta-pjsip-rota-' . getmypid() . '.conf';
        file_put_contents($this->arquivo, "; vazio\n");
        config(['prospecta.pjsip_rota' => $this->arquivo]);

        // AMI numa porta que não escuta: o `aplicar` grava o arquivo e falha na recarga, o
        // que é o caminho a testar sem depender de um Asterisk rodando.
        config(['dialer.ami' => ['host' => '127.0.0.1', 'porta' => 1, 'usuario' => 'x', 'segredo' => 'y']]);
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        @unlink($this->arquivo . '.novo');
        parent::tearDown();
    }

    #[Test]
    public function salvar_grava_os_campos_e_cifra_a_senha(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('usuario', 'meu_usuario')
            ->set('senha', 's3nh4-do-fornecedor')
            ->set('from_dominio', 'sip.fornecedor.com')
            ->set('tech_prefix', '99901')
            ->call('salvar')
            ->assertHasNoErrors();

        $c = (array) DB::table('configuracao')->where('id', 1)->first();
        $this->assertSame('200.1.2.3', $c['rota_host']);
        $this->assertSame('meu_usuario', $c['rota_usuario']);
        $this->assertSame('99901', $c['rota_tech_prefix']);

        // 🔴 A senha NÃO está em claro na coluna.
        $guardada = (string) $c['rota_senha'];
        $this->assertStringNotContainsString('s3nh4-do-fornecedor', $guardada);
        $this->assertStringContainsString('cifrado', $guardada);

        // E volta decifrável, senão o Asterisk receberia lixo.
        $cofre = new Cofre((string) config('app.key'));
        $this->assertSame('s3nh4-do-fornecedor', $cofre->decifrarDaColuna($guardada)['senha']);
    }

    /**
     * 🔴 O TESTE QUE JUSTIFICA A TELA: o DIALER passa a discar pelo prefixo novo.
     *
     * Antes, `PROSPECTA_TECH_PREFIX` era lido do ambiente na partida do dialer. A tela
     * escreveria no banco, mostraria o valor novo, e a discagem seguiria pelo antigo — sem
     * erro em lugar nenhum. E o prefixo é seleção de CAPACIDADE: fora da rota Platinum,
     * CallerID e DTMF falham em silêncio.
     */
    #[Test]
    public function o_dialer_passa_a_usar_o_prefixo_salvo_na_tela(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('tech_prefix', '99903')
            ->call('salvar');

        $r = (new Fila(DB::connection()->getPdo()))->rota();
        $rota = new \App\Dominio\Rota(host: $r['host'], techPrefix: $r['tech_prefix']);

        $this->assertSame('PJSIP/999035511987654321@rota', $rota->canal('PJSIP', '5511987654321'));
    }

    /**
     * Senha em branco PRESERVA a gravada.
     *
     * Quem vem trocar só o prefixo não deveria perder a credencial por não redigitá-la — e
     * senha vazia derrubaria a rota inteira na próxima recarga do Asterisk.
     */
    #[Test]
    public function salvar_sem_senha_preserva_a_que_estava(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('senha', 'primeira')
            ->call('salvar');

        $antes = (string) DB::table('configuracao')->where('id', 1)->value('rota_senha');

        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('tech_prefix', '99903')
            ->call('salvar');

        $cofre = new Cofre((string) config('app.key'));
        $depois = (string) DB::table('configuracao')->where('id', 1)->value('rota_senha');

        $this->assertSame('primeira', $cofre->decifrarDaColuna($depois)['senha']);
        $this->assertNotSame('', $antes);
    }

    /** A senha gravada NÃO volta para o formulário, nem cifrada — só a máscara. */
    #[Test]
    public function a_senha_nao_volta_para_a_tela(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('senha', 'segredo-do-fornecedor')
            ->call('salvar');

        $componente = Livewire::test(Rota::class);

        $this->assertSame('', $componente->get('senha'));
        $componente->assertDontSee('segredo-do-fornecedor');
        // A máscara mostra só os últimos 4 — o bastante para reconhecer qual senha é,
        // sem pôr a credencial no HTML.
        $mascarada = (string) $componente->get('senhaMascarada');
        $this->assertStringEndsWith('edor', $mascarada);
        $this->assertStringNotContainsString('segredo-do-fornecedor', $mascarada);
    }

    /**
     * 🔴 Valor com espaço ou quebra de linha é RECUSADO.
     *
     * O valor vai para um `.conf` que não tem sintaxe de aspas: uma quebra de linha aqui
     * partiria o arquivo, o Asterisk recusaria a recarga INTEIRA, e uma rota que estava
     * funcionando cairia — por causa de um campo de formulário.
     */
    #[Test]
    public function recusa_valor_que_quebraria_o_arquivo_de_configuracao(): void
    {
        Livewire::test(Rota::class)
            ->set('host', "200.1.2.3\n[malicioso]")
            ->call('salvar')
            ->assertHasErrors('host');

        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('senha', "boa\npassword=outra")
            ->call('salvar')
            ->assertHasErrors('senha');

        $this->assertNull(DB::table('configuracao')->where('id', 1)->value('rota_host'));
    }

    #[Test]
    public function host_e_obrigatorio(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '')
            ->call('salvar')
            ->assertHasErrors('host');
    }

    #[Test]
    public function prefixo_aceita_so_digitos(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('tech_prefix', '999-01')
            ->call('salvar')
            ->assertHasErrors('tech_prefix');
    }

    /**
     * Salvar ESCREVE o arquivo de pjsip, e o dono do texto é a classe pura.
     *
     * A recarga falha porque não há AMI no teste — e é justamente o que deve ser
     * relatado sem desfazer o que já foi salvo.
     */
    #[Test]
    public function salvar_escreve_o_pjsip_e_relata_a_recarga_que_falhou(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('usuario', 'usr')
            ->set('senha', 's3nh4')
            ->set('tech_prefix', '99901')
            ->call('salvar')
            ->assertSee('SALVA no banco, mas não entrou em vigor');

        $conf = (string) file_get_contents($this->arquivo);
        $this->assertStringContainsString('[rota]', $conf);
        $this->assertStringContainsString('contact=sip:200.1.2.3', $conf);
        $this->assertStringContainsString('password=s3nh4', $conf);

        // O valor no banco ficou, apesar da recarga ter falhado.
        $this->assertSame('200.1.2.3', DB::table('configuracao')->where('id', 1)->value('rota_host'));
    }

    /** O arquivo fica 0660: contém a senha em claro, e "outros" não pode ler. */
    #[Test]
    public function o_arquivo_de_pjsip_nao_fica_legivel_por_outros(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->set('senha', 's3nh4')
            ->call('salvar');

        clearstatcache(true, $this->arquivo);
        $modo = fileperms($this->arquivo) & 0o777;

        $this->assertSame(0, $modo & 0o007, sprintf('modo %o dá permissão a "outros"', $modo));
    }

    /** Não deixa arquivo temporário para trás: `rename` é atômico e consome o `.novo`. */
    #[Test]
    public function nao_deixa_arquivo_temporario(): void
    {
        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->call('salvar');

        $this->assertFileDoesNotExist($this->arquivo . '.novo');
    }

    #[Test]
    public function arquivo_de_pjsip_ausente_e_relatado_como_provisionamento(): void
    {
        @unlink($this->arquivo);

        Livewire::test(Rota::class)
            ->set('host', '200.1.2.3')
            ->call('salvar')
            ->assertSee('rode o playbook');
    }

    #[Test]
    public function aplicar_sem_host_recusa(): void
    {
        Livewire::test(Rota::class)
            ->call('aplicar')
            ->assertSee('Preencha o host');
    }

    #[Test]
    public function a_tela_exige_login(): void
    {
        auth()->logout();

        $this->get('/rota')->assertRedirect('/entrar');
    }
}
