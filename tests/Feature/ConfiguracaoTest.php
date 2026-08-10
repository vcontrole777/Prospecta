<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Configuracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConfiguracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function salva_o_horario_e_os_dias(): void
    {
        Livewire::test(Configuracao::class)
            ->set('horario_inicio', '09:00')
            ->set('horario_fim', '18:00')
            ->set('dias_semana', [5, 1, 3])
            ->call('salvar')
            ->assertHasNoErrors();

        $c = DB::table('configuracao')->where('id', 1)->first();
        $this->assertNotNull($c);
        $this->assertStringStartsWith('09:00', (string) $c->horario_inicio);
        $this->assertStringStartsWith('18:00', (string) $c->horario_fim);
        // Ordenado ao gravar: a `Janela` não depende da ordem, mas ler `[1,3,5]` no
        // banco é mais fácil que `[5,1,3]` para quem for depurar.
        $this->assertSame([1, 3, 5], json_decode((string) $c->dias_semana, true));
    }

    /**
     * 🔴 Lista vazia grava NULL, não `[]`. Os dois significariam "todos os dias" para a
     * `Janela`, e dois valores para o mesmo significado dão duas leituras possíveis.
     */
    #[Test]
    public function sem_dia_marcado_grava_null(): void
    {
        Livewire::test(Configuracao::class)
            ->set('horario_inicio', '08:00')
            ->set('horario_fim', '20:00')
            ->set('dias_semana', [])
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertNull(DB::table('configuracao')->where('id', 1)->value('dias_semana'));
    }

    #[Test]
    public function os_dois_horarios_em_branco_significam_sem_restricao(): void
    {
        DB::table('configuracao')->where('id', 1)->update(['horario_inicio' => '09:00', 'horario_fim' => '18:00']);

        Livewire::test(Configuracao::class)
            ->set('horario_inicio', '')
            ->set('horario_fim', '')
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertSee('sem restrição de horário', escape: false);

        $c = DB::table('configuracao')->where('id', 1)->first();
        $this->assertNotNull($c);
        $this->assertNull($c->horario_inicio);
        $this->assertNull($c->horario_fim);
    }

    /**
     * Meio caminho é recusado: só um horário preenchido faria a `Janela` liberar, e o
     * operador acharia que restringiu. É pior que não configurar.
     */
    #[Test]
    public function recusa_apenas_um_dos_horarios(): void
    {
        Livewire::test(Configuracao::class)
            ->set('horario_inicio', '09:00')
            ->set('horario_fim', '')
            ->call('salvar')
            ->assertHasErrors('horario_fim');

        Livewire::test(Configuracao::class)
            ->set('horario_inicio', '')
            ->set('horario_fim', '18:00')
            ->call('salvar')
            ->assertHasErrors('horario_inicio');
    }

    #[Test]
    public function salva_a_gravacao_e_a_retencao(): void
    {
        Livewire::test(Configuracao::class)
            ->set('gravacao_ativa', false)
            ->set('gravacao_retencao_dias', 7)
            ->call('salvar')
            ->assertHasNoErrors();

        $c = DB::table('configuracao')->where('id', 1)->first();
        $this->assertNotNull($c);
        $this->assertSame(0, (int) $c->gravacao_ativa);
        $this->assertSame(7, (int) $c->gravacao_retencao_dias);
    }

    #[Test]
    public function recusa_retencao_impossivel(): void
    {
        foreach ([0, -5] as $ruim) {
            Livewire::test(Configuracao::class)
                ->set('gravacao_retencao_dias', $ruim)
                ->call('salvar')
                ->assertHasErrors('gravacao_retencao_dias');
        }
    }

    /**
     * 🔴 A tela usa a MESMA classe `Janela` que o dialer. Se usasse lógica própria,
     * mostraria "discando agora" enquanto o dialer estaria parado — e o operador
     * confiaria na tela.
     */
    #[Test]
    public function mostra_a_janela_em_vigor_e_o_relogio_da_aplicacao(): void
    {
        Livewire::test(Configuracao::class)
            ->set('horario_inicio', '09:00')
            ->set('horario_fim', '18:00')
            ->set('dias_semana', [1, 2, 3, 4, 5])
            ->assertSee('09:00 às 18:00, seg, ter, qua, qui, sex', escape: false)
            // O fuso na tela é o que evita configurar certo e a discadora agir errado:
            // o servidor pode rodar em UTC.
            ->assertSee((string) config('app.timezone'));
    }

    /** Nomeia o que não está na tela, em vez de deixar a ausência parecer recurso faltando. */
    #[Test]
    public function diz_o_que_nao_esta_na_tela(): void
    {
        $this->get(route('configuracao'))
            ->assertOk()
            ->assertSee('O que não está aqui', escape: false)
            ->assertSee('tronco SIP é configurado no provisionamento', escape: false)
            ->assertDontSee('Unclosed');
    }

    /** Sem a linha única, TODA chamada falha. A tela a recria em vez de quebrar. */
    #[Test]
    public function recria_a_linha_unica_se_alguem_a_apagou(): void
    {
        DB::table('configuracao')->delete();

        Livewire::test(Configuracao::class)->assertOk();

        $this->assertSame(1, DB::table('configuracao')->count());
    }
}
