<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dialer\Fila;
use App\Livewire\Binas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BinasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * Normaliza com a MESMA classe da importação. Sem isso o `ddd` gerado pelo banco —
     * `SUBSTRING(numero, 3, 2)` — sairia errado para número sem país, e o casamento com
     * o destino nunca aconteceria.
     */
    #[Test]
    public function normaliza_e_deriva_o_ddd_pelo_banco(): void
    {
        Livewire::test(Binas::class)
            ->set('entrada', "11 98765-4321\n(62) 98765-4322\n5511987654323")
            ->call('adicionar')
            ->assertSet('adicionados', 3)
            ->assertSet('recusados', []);

        $linhas = DB::table('bina')->orderBy('numero')->get();
        $this->assertCount(3, $linhas);

        // `ddd` é coluna GERADA: a tela nunca a escreve, e ela sai certa porque o
        // número está canônico.
        $porNumero = [];
        foreach ($linhas as $l) {
            $porNumero[$l->numero] = $l->ddd;
        }

        $this->assertSame('11', $porNumero['5511987654321'] ?? null);
        $this->assertSame('62', $porNumero['5562987654322'] ?? null);
        $this->assertSame('11', $porNumero['5511987654323'] ?? null);
    }

    #[Test]
    public function aceita_virgula_e_ponto_e_virgula(): void
    {
        Livewire::test(Binas::class)
            ->set('entrada', '11987654321, 11987654322; 11987654323')
            ->call('adicionar')
            ->assertSet('adicionados', 3);
    }

    #[Test]
    public function dedup_dentro_da_colagem_e_contra_o_que_existe(): void
    {
        Livewire::test(Binas::class)
            ->set('entrada', '11987654321')
            ->call('adicionar')
            ->assertSet('adicionados', 1);

        // O mesmo número em três grafias diferentes, mais um já cadastrado.
        Livewire::test(Binas::class)
            ->set('entrada', "11 98765-4321\n+5511987654321\n5511987654321\n11987654999")
            ->call('adicionar')
            ->assertSet('adicionados', 1)
            ->assertSet('repetidos', 1);

        $this->assertSame(2, DB::table('bina')->count());
    }

    #[Test]
    public function recusa_o_que_nao_e_telefone_e_diz_quais(): void
    {
        Livewire::test(Binas::class)
            ->set('entrada', "11987654321\n2098765432\nnada disso")
            ->call('adicionar')
            ->assertSet('adicionados', 1)
            // DDD 20 não existe; "nada disso" não é número.
            ->assertSet('recusados', ['2098765432', 'nada disso']);

        $this->assertSame(1, DB::table('bina')->count());
    }

    /**
     * 🔴 O AVISO QUE A TELA EXISTE PARA DAR. DDD com Lead e sem BINA ativa faz a regra
     * `lista` cair no número único da Campanha — ou em nenhum CallerID — sem erro
     * nenhum. A Campanha disca, o atendimento despenca, e nada liga causa e efeito.
     */
    #[Test]
    public function avisa_ddd_com_lead_e_sem_bina(): void
    {
        $c = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'C', 'status' => 1, 'bina_regra' => 'lista',
        ]);
        DB::table('leads')->insert([
            ['campanha_id' => $c, 'telefone' => '5562900000001', 'status' => 1],
            ['campanha_id' => $c, 'telefone' => '5562900000002', 'status' => 1],
            ['campanha_id' => $c, 'telefone' => '5511900000003', 'status' => 1],
        ]);

        Livewire::test(Binas::class)
            ->assertSee('sem BINA ativa', escape: false)
            ->assertSee('62')
            ->assertSee('2 leads');

        // Cadastrando BINA de 62, o aviso some para 62 e permanece para 11.
        Livewire::test(Binas::class)
            ->set('entrada', '5562988887777')
            ->call('adicionar')
            ->assertSee('11');

        $html = Livewire::test(Binas::class)->html();
        $this->assertStringContainsString('1 leads', $html, 'o DDD 11 continua descoberto');
    }

    /** Alarme falso ensina a ignorar o painel: Campanha que não usa `lista` não conta. */
    #[Test]
    public function nao_avisa_para_campanha_que_nao_usa_a_regra_lista(): void
    {
        $c = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'Espelho', 'status' => 1, 'bina_regra' => 'espelho',
        ]);
        DB::table('leads')->insert(['campanha_id' => $c, 'telefone' => '5562900000001', 'status' => 1]);

        Livewire::test(Binas::class)->assertDontSee('sem BINA ativa', escape: false);
    }

    #[Test]
    public function ativa_e_desativa_por_numero_e_por_ddd(): void
    {
        Livewire::test(Binas::class)
            ->set('entrada', "5511900000001\n5511900000002\n5562900000003")
            ->call('adicionar');

        $id = (int) DB::table('bina')->where('numero', '5511900000001')->value('id');

        Livewire::test(Binas::class)->call('alternar', $id);
        $this->assertSame(0, (int) DB::table('bina')->where('id', $id)->value('ativo'));

        Livewire::test(Binas::class)->call('desativarDdd', '11');
        $this->assertSame(0, DB::table('bina')->where('ddd', '11')->where('ativo', 1)->count());
        $this->assertSame(1, DB::table('bina')->where('ddd', '62')->where('ativo', 1)->count(), 'o 62 não foi tocado');

        Livewire::test(Binas::class)->call('ativarDdd', '11');
        $this->assertSame(2, DB::table('bina')->where('ddd', '11')->where('ativo', 1)->count());
    }

    /**
     * Remover é seguro aqui, ao contrário do áudio: `ligacao.bina` guarda o número como
     * TEXTO, então o histórico não se perde.
     */
    #[Test]
    public function remover_nao_apaga_o_historico_da_ligacao(): void
    {
        Livewire::test(Binas::class)->set('entrada', '5511900000001')->call('adicionar');
        $id = (int) DB::table('bina')->value('id');

        $c = DB::table('campanha')->insertGetId(['tipo' => 'opt_in', 'nome' => 'C', 'status' => 1]);
        $lead = DB::table('leads')->insertGetId(['campanha_id' => $c, 'telefone' => '5511987654321', 'status' => 3]);
        DB::table('ligacao')->insert([
            'lead_id' => $lead, 'campanha_id' => $c, 'numero_tentativa' => 1,
            'iniciada_em' => now(), 'bina' => '5511900000001',
        ]);

        Livewire::test(Binas::class)->call('remover', $id);

        $this->assertSame(0, DB::table('bina')->count());
        $this->assertSame('5511900000001', DB::table('ligacao')->value('bina'), 'a Ligação lembra qual BINA saiu');
    }

    /**
     * 🔴 O ELO COM O DIALER. A tela grava; quem lê é `Fila::binaDoDdd`, casando por DDD
     * e por `ativo`. Se a tela gravasse noutro formato, o sorteio nunca acharia nada.
     */
    #[Test]
    public function o_dialer_encontra_o_que_a_tela_gravou(): void
    {
        Livewire::test(Binas::class)
            ->set('entrada', "62 98888-7777\n11 97777-6666")
            ->call('adicionar');

        $fila = new Fila(DB::connection()->getPdo());

        $this->assertSame('5562988887777', $fila->binaDoDdd('5562984853523'), 'DDD 62 casa com o de 62');
        $this->assertSame('5511977776666', $fila->binaDoDdd('5511987654321'), 'DDD 11 casa com o de 11');
        $this->assertNull($fila->binaDoDdd('5551987654321'), 'DDD sem BINA devolve null');

        // Desativado sai do sorteio.
        DB::table('bina')->where('ddd', '62')->update(['ativo' => 0]);
        $this->assertNull($fila->binaDoDdd('5562984853523'));
    }

    #[Test]
    public function a_pagina_renderiza_inteira(): void
    {
        $this->get(route('binas'))
            ->assertOk()
            ->assertSee('Lista de BINA', escape: false)
            ->assertSee('Nenhum número cadastrado', escape: false)
            ->assertDontSee('Unclosed');
    }
}
