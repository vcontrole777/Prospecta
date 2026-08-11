<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dialer\Fila;
use App\Livewire\Bloqueios;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `DatabaseTruncation` para poder afirmar contra a `Fila` DE VERDADE.
 *
 * O que esta tela promete não é "aparecer na lista": é o dialer não ligar. Testar só a
 * tela provaria a metade que não interessa — a normalização poderia divergir da do
 * dialer, o bloqueio apareceria em tela e a chamada sairia do mesmo jeito.
 */
final class BloqueiosTest extends TestCase
{
    use DatabaseTruncation;

    private Fila $fila;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->fila = new Fila(DB::connection()->getPdo());
    }

    /**
     * 🔴 O TESTE QUE JUSTIFICA A TELA.
     *
     * O operador cola do jeito que tem — com máscara, com espaço, sem o 55. O dialer
     * confere por IGUALDADE EXATA de texto. Se as duas normalizações divergirem, a tela
     * mostra o número bloqueado, o operador confia, e a chamada sai.
     */
    #[Test]
    public function numero_colado_com_mascara_bloqueia_o_dialer(): void
    {
        Livewire::test(Bloqueios::class)
            ->set('entrada', "(11) 98765-4321\n11 91234 5678")
            ->call('adicionar')
            ->assertHasNoErrors()
            ->assertSet('adicionados', 2)
            ->assertSet('recusados', []);

        $this->assertTrue($this->fila->bloqueado('5511987654321'));
        $this->assertTrue($this->fila->bloqueado('5511912345678'));
    }

    #[Test]
    public function grava_a_origem_como_manual(): void
    {
        Livewire::test(Bloqueios::class)
            ->set('entrada', '5511987654321')
            ->call('adicionar');

        // `manual` estava no enum e nada o escrevia — era o canal que faltava.
        $this->assertSame('manual', DB::table('bloqueio')->where('telefone', '5511987654321')->value('origem'));
    }

    /**
     * Número inválido é MOSTRADO, não contado em silêncio.
     *
     * Contá-lo como bloqueado seria dar confirmação de algo que não aconteceu: o
     * operador acharia que registrou o pedido, e a pessoa continuaria sendo chamada.
     */
    #[Test]
    public function numero_invalido_e_recusado_e_nomeado(): void
    {
        $componente = Livewire::test(Bloqueios::class)
            ->set('entrada', "5511987654321\n123\nnão é telefone")
            ->call('adicionar');

        $componente->assertSet('adicionados', 1);
        $this->assertSame(['123', 'não é telefone'], $componente->get('recusados'));
        $this->assertSame(1, DB::table('bloqueio')->count());
    }

    #[Test]
    public function repetido_na_mesma_colagem_e_na_lista_nao_duplica(): void
    {
        Livewire::test(Bloqueios::class)
            ->set('entrada', "5511987654321\n(11) 98765-4321")
            ->call('adicionar')
            ->assertSet('adicionados', 1)      // a mesma pessoa duas vezes
            ->assertSet('repetidos', 0)        // deduplicada antes do banco
            ->set('entrada', '5511987654321')
            ->call('adicionar')
            ->assertSet('adicionados', 0)
            ->assertSet('repetidos', 1);

        $this->assertSame(1, DB::table('bloqueio')->count());
    }

    #[Test]
    public function desbloquear_libera_o_dialer(): void
    {
        DB::table('bloqueio')->insert(['telefone' => '5511987654321', 'origem' => 'manual']);
        $this->assertTrue($this->fila->bloqueado('5511987654321'));

        Livewire::test(Bloqueios::class)->call('remover', '5511987654321');

        $this->assertFalse($this->fila->bloqueado('5511987654321'));
        $this->assertSame(0, DB::table('bloqueio')->count());
    }

    /**
     * Desbloquear NÃO ressuscita o Lead, e é decisão declarada.
     *
     * O dialer aposentou aquele Lead com `encerrarSemDiscar` ao encontrar o bloqueio.
     * Devolvê-lo à Fila daqui duplicaria a regra de retentativa num segundo lugar — e foi
     * exatamente assim que a reconciliação e o `aplicar` chegaram a discordar sobre
     * quantas discagens o operador pagou.
     */
    #[Test]
    public function desbloquear_nao_devolve_o_lead_a_fila(): void
    {
        $campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 1, 'max_tentativas' => 3,
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 3,
        ]);
        DB::table('bloqueio')->insert(['telefone' => '5511987654321', 'origem' => 'manual']);

        Livewire::test(Bloqueios::class)->call('remover', '5511987654321');

        $this->assertSame(3, (int) DB::table('leads')->where('id', $lead)->value('status'));
    }

    /**
     * A busca aceita o número como o operador o digita.
     *
     * É a única pergunta que esta busca existe para responder — "esta pessoa está na
     * lista?" — e ela chega por telefone, com máscara. Procurar o texto cru contra
     * `5511987654321` não acharia nada, e a tela responderia "não está bloqueado" sobre
     * alguém que está.
     */
    #[Test]
    public function a_busca_acha_pelo_numero_digitado_com_mascara(): void
    {
        DB::table('bloqueio')->insert([
            ['telefone' => '5511987654321', 'origem' => 'manual'],
            ['telefone' => '5521911112222', 'origem' => 'opt_out'],
        ]);

        Livewire::test(Bloqueios::class)
            ->set('busca', '(11) 98765-4321')
            ->assertSee('5511987654321')
            ->assertDontSee('5521911112222');
    }

    #[Test]
    public function a_busca_sem_resultado_diz_que_o_numero_nao_esta_bloqueado(): void
    {
        DB::table('bloqueio')->insert(['telefone' => '5511987654321', 'origem' => 'manual']);

        Livewire::test(Bloqueios::class)
            ->set('busca', '5531999998888')
            ->assertSee('Este número não está bloqueado');
    }

    #[Test]
    public function o_filtro_de_origem_separa_o_pedido_do_registro_a_mao(): void
    {
        DB::table('bloqueio')->insert([
            ['telefone' => '5511987654321', 'origem' => 'manual'],
            ['telefone' => '5521911112222', 'origem' => 'opt_out'],
        ]);

        Livewire::test(Bloqueios::class)
            ->set('origem', 'opt_out')
            ->assertSee('5521911112222')
            ->assertDontSee('5511987654321');
    }

    /** Origem fora do mapa é ignorada: vem da URL, e virar `where` daria lista vazia. */
    #[Test]
    public function origem_fora_do_mapa_nao_vira_where(): void
    {
        DB::table('bloqueio')->insert(['telefone' => '5511987654321', 'origem' => 'manual']);

        Livewire::test(Bloqueios::class)
            ->set('origem', 'inventada')
            ->assertSee('5511987654321');
    }

    /**
     * Bloqueio vindo da tecla 9 mostra de qual Campanha e Ligação veio; o registrado à
     * mão não tem Ligação e mostra um traço.
     *
     * O `LEFT JOIN` é o que garante isso: com `JOIN` simples a tela mostraria SÓ os da
     * URA, e o opt-out do WhatsApp — que não grava `ligacao_id` — desapareceria da lista
     * inteira.
     */
    #[Test]
    public function mostra_a_procedencia_sem_esconder_quem_nao_tem_ligacao(): void
    {
        $campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'Recadastramento', 'tipo' => 'opt_in', 'status' => 1,
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5521911112222', 'status' => 3,
        ]);
        $ligacao = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha, 'numero_tentativa' => 1, 'iniciada_em' => now(),
        ]);

        DB::table('bloqueio')->insert([
            ['telefone' => '5521911112222', 'origem' => 'opt_out', 'ligacao_id' => $ligacao],
            ['telefone' => '5511987654321', 'origem' => 'manual', 'ligacao_id' => null],
        ]);

        Livewire::test(Bloqueios::class)
            ->assertSee('Recadastramento')
            ->assertSee('5521911112222')
            ->assertSee('5511987654321');
    }

    #[Test]
    public function conta_por_origem(): void
    {
        DB::table('bloqueio')->insert([
            ['telefone' => '5511987654321', 'origem' => 'manual'],
            ['telefone' => '5511987654322', 'origem' => 'manual'],
            ['telefone' => '5521911112222', 'origem' => 'opt_out'],
        ]);

        $html = Livewire::test(Bloqueios::class)->html();

        // Os dois contadores, cada um sob o seu rótulo.
        $this->assertMatchesRegularExpression('/Registrado à mão.*?>2</s', $html);
        $this->assertMatchesRegularExpression('/Pediu na chamada.*?>1</s', $html);
    }

    #[Test]
    public function colagem_vazia_e_recusada_com_recado(): void
    {
        Livewire::test(Bloqueios::class)
            ->set('entrada', '')
            ->call('adicionar')
            ->assertHasErrors('entrada');

        $this->assertSame(0, DB::table('bloqueio')->count());
    }

    #[Test]
    public function a_tela_exige_login(): void
    {
        auth()->logout();

        $this->get('/bloqueios')->assertRedirect('/entrar');
    }
}
