<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A tela de chamadas ao vivo.
 *
 * Roda contra MySQL, não SQLite — ver o comentário no `phpunit.xml`. A consulta usa
 * `UNIX_TIMESTAMP` e `COUNT` sobre LEFT JOIN condicional.
 */
final class AoVivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function a_tela_responde(): void
    {
        $this->get('/ao-vivo')->assertOk()->assertSee('Ao vivo');
    }

    #[Test]
    public function sem_chamada_no_ar_diz_o_que_conferir(): void
    {
        $this->get('/ao-vivo')
            ->assertOk()
            ->assertSee('Nenhuma chamada no ar')
            // Estado vazio que só constata o vazio não ajuda: as duas causas
            // possíveis são Campanha parada e discador fora do ar.
            ->assertSee('discador');
    }

    /**
     * 🔴 O TESTE QUE PROTEGE A RAZÃO DE A TELA EXISTIR.
     *
     * Uma Ligação ABERTA e ATENDIDA tem que aparecer como "na URA". Antes de
     * `Fastagi\Banco::marcarAtendida`, `atendida_em` só era escrita no mesmo UPDATE
     * que fechava a Ligação — então TODA linha aberta era "chamando", sempre, e a
     * tela mostraria um tronco que nunca entrega chamada nenhuma.
     */
    #[Test]
    public function separa_quem_esta_chamando_de_quem_ja_atendeu(): void
    {
        $c = $this->campanha(['nome' => 'Campanha A', 'chamadas_simultaneas' => 10]);

        $this->ligacaoAberta($c, '5511900000001', atendida: false);
        $this->ligacaoAberta($c, '5511900000002', atendida: true);

        $r = $this->get('/ao-vivo')->assertOk();

        $r->assertSee('5511900000001');
        $r->assertSee('5511900000002');
        $r->assertSee('chamando');
        $r->assertSee('na URA');
    }

    /** Ligação encerrada não está no ar, por definição. */
    #[Test]
    public function ligacao_encerrada_nao_aparece(): void
    {
        $c = $this->campanha();

        DB::table('ligacao')->insert([
            'campanha_id' => $c,
            'lead_id' => $this->lead($c, '5511911111111'),
            'numero_tentativa' => 1,
            'iniciada_em' => now()->subMinute(),
            'atendida_em' => now()->subMinute(),
            'encerrada_em' => now(),
        ]);

        $this->get('/ao-vivo')
            ->assertOk()
            ->assertDontSee('5511911111111')
            ->assertSee('Nenhuma chamada no ar');
    }

    /**
     * 🔴 PAUSAR NÃO DESLIGA O QUE JÁ ESTÁ NO AR.
     *
     * Quem pausa uma Campanha para aliviar o tronco precisa ver que o alívio ainda
     * não chegou — senão pausa a segunda e a terceira atrás dela, e conclui que
     * pausar não funciona. É a mesma verdade que o docblock de
     * `Fila::contarOcupadosNaInstancia()` nomeia; aqui ela é uma linha na tela.
     */
    #[Test]
    public function campanha_pausada_com_chamada_no_ar_e_dita(): void
    {
        $c = $this->campanha(['nome' => 'Campanha Pausada', 'status' => 2]);
        $this->ligacaoAberta($c, '5511922222222', atendida: false);

        $this->get('/ao-vivo')
            ->assertOk()
            ->assertSee('Campanha Pausada')
            ->assertSee('seguem ocupando canal');
    }

    /**
     * O relógio do SERVIDOR vai junto.
     *
     * A duração é calculada no navegador; sem esta referência ela seria medida
     * contra o relógio da máquina do operador, que erra por minutos com frequência.
     */
    #[Test]
    public function manda_o_instante_de_inicio_e_o_relogio_do_servidor(): void
    {
        $c = $this->campanha();
        $this->ligacaoAberta($c, '5511933333333', atendida: false);

        $inicio = (int) DB::table('ligacao')->value(DB::raw('UNIX_TIMESTAMP(iniciada_em)'));

        $this->get('/ao-vivo')
            ->assertOk()
            // O epoch do início chega à página, para o contador correr sem o servidor.
            ->assertSee('dur(' . $inicio . ')', escape: false)
            ->assertSee('desvio:', escape: false);
    }

    /**
     * 🔴 O QUE A TELA NÃO PODE INVENTAR.
     *
     * `amd_status` e `tecla_digitada` só existem depois de `Fastagi\Banco::gravar()`,
     * que roda no desligamento. Numa Ligação aberta são NULL por construção, e uma
     * coluna dessas estaria vazia em 100% das linhas — pareceria que o classificador
     * parou de funcionar. Este teste trava a decisão de não as exibir.
     */
    #[Test]
    public function nao_promete_amd_nem_tecla_na_tela_ao_vivo(): void
    {
        $c = $this->campanha();
        $this->ligacaoAberta($c, '5511944444444', atendida: true);

        $r = $this->get('/ao-vivo')->assertOk();

        $r->assertDontSee('>AMD<', escape: false);
        $r->assertDontSee('Tecla');
    }

    /** @param array<string,mixed> $atributos */
    private function campanha(array $atributos = []): int
    {
        return (int) DB::table('campanha')->insertGetId(array_merge([
            'tipo' => 'opt_in',
            'nome' => 'Campanha de teste',
            'status' => 1,
        ], $atributos));
    }

    private function lead(int $campanhaId, string $telefone): int
    {
        return (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanhaId,
            'telefone' => $telefone,
            'status' => 1,
        ]);
    }

    private function ligacaoAberta(int $campanhaId, string $telefone, bool $atendida): void
    {
        DB::table('ligacao')->insert([
            'campanha_id' => $campanhaId,
            'lead_id' => $this->lead($campanhaId, $telefone),
            'numero_tentativa' => 1,
            'iniciada_em' => now()->subSeconds(30),
            'atendida_em' => $atendida ? now()->subSeconds(20) : null,
            'encerrada_em' => null,
        ]);
    }
}
