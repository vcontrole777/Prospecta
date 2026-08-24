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
            // 🔴 O texto dizia "o tronco SIP é configurado no provisionamento, não pelo
            // painel", e estava errado duas vezes: `Tronco SIP` virou `Rota` em 11/08, e a
            // Rota TEM tela própria desde então. O parágrafo existe para nomear ausências
            // com honestidade, e passou a afirmar uma ausência que não existe mais.
            ->assertSee('A Rota tem tela própria', escape: false)
            ->assertSee('mensagem genérica', escape: false)
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

    #[Test]
    public function salva_a_chave_e_os_parametros_do_pos_atendimento(): void
    {
        Livewire::test(Configuracao::class)
            ->set('classificador_pos_ativo', true)
            ->set('pos_analise_total_ms', 6000)
            ->set('pos_silencio_inicial_ms', 2500)
            ->set('pos_saudacao_max_ms', 1500)
            ->set('pos_palavras_max', 2)
            ->call('salvar')
            ->assertHasNoErrors();

        $c = DB::table('configuracao')->where('id', 1)->first();
        $this->assertNotNull($c);
        $this->assertSame(1, (int) $c->classificador_pos_ativo);
        $this->assertSame(6000, (int) $c->pos_analise_total_ms);
        $this->assertSame(2500, (int) $c->pos_silencio_inicial_ms);
        $this->assertSame(1500, (int) $c->pos_saudacao_max_ms);
        $this->assertSame(2, (int) $c->pos_palavras_max);
    }

    /**
     * 🔴 A REGRA QUE ESTA TELA EXISTE PARA TER.
     *
     * Critério que pede mais tempo do que o orçamento total da análise nunca é alcançado,
     * e a ausência não gera erro nenhum: a classificação volta sem decisão e a Mensagem
     * toca do mesmo jeito. O sistema rodou assim com silêncio inicial 6000 e análise total
     * 5000 — `INITIALSILENCE` apareceu ZERO vezes em 8.406 classificações, e 54,6% das
     * atendidas voltaram NOTSURE gastando 12,7 horas de tronco. Medido em 21/08/2026.
     */
    #[Test]
    public function recusa_criterio_novo_maior_que_a_analise_total(): void
    {
        foreach (['pos_silencio_inicial_ms', 'pos_saudacao_max_ms'] as $campo) {
            Livewire::test(Configuracao::class)
                ->set('pos_analise_total_ms', 5000)
                ->set($campo, 7000)   // diferente do gravado: é o operador INTRODUZINDO
                ->call('salvar')
                ->assertHasErrors($campo);
        }
    }

    /** Baixar o orçamento até engolir o critério é o mesmo defeito pelo outro lado. */
    #[Test]
    public function recusa_analise_total_que_engole_o_criterio(): void
    {
        Livewire::test(Configuracao::class)
            ->set('pos_silencio_inicial_ms', 2500)
            ->set('pos_analise_total_ms', 2000)
            ->call('salvar')
            ->assertHasErrors('pos_silencio_inicial_ms');
    }

    /**
     * A combinação inválida que estas duas provas precisam.
     *
     * 🔴 GRAVADA À MÃO, e antes era herdada do default. Até 24/08/2026 a linha semeada
     * NASCIA com `silêncio inicial` 6000 contra `análise total` 5000, e estes testes
     * se apoiavam nisso sem dizer. A migration `pos_silencio_inicial_nasce_vivo`
     * consertou o default, e os dois quebraram — corretamente, porque a premissa
     * calada tinha deixado de valer.
     *
     * O cenário continua real: é o que existe em toda instalação que rodou antes da
     * correção e cujo `análise total` é pequeno demais para o conserto automático
     * alcançar. Só que agora está escrito.
     */
    private function gravaCombinacaoHerdadaInvalida(): void
    {
        DB::table('configuracao')->where('id', 1)->update([
            'pos_silencio_inicial_ms' => 6000,
            'pos_analise_total_ms' => 5000,
        ]);
    }

    /**
     * 🔴 DEFEITO HERDADO NÃO TRAVA A TELA.
     *
     * Se a recusa valesse para a combinação já gravada, ninguém conseguiria mexer no
     * horário de trabalho enquanto o número de outra pessoa estivesse ali — uma
     * validação zelosa transformada em sequestro da configuração inteira.
     */
    #[Test]
    public function combinacao_invalida_herdada_nao_bloqueia_o_resto(): void
    {
        $this->gravaCombinacaoHerdadaInvalida();

        // Confere a premissa em vez de confiar nela.
        $c = DB::table('configuracao')->where('id', 1)->first();
        $this->assertNotNull($c);
        $this->assertGreaterThanOrEqual((int) $c->pos_analise_total_ms, (int) $c->pos_silencio_inicial_ms);

        Livewire::test(Configuracao::class)
            ->set('gravacao_retencao_dias', 30)
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame(
            30,
            (int) DB::table('configuracao')->where('id', 1)->value('gravacao_retencao_dias'),
        );
    }

    /** Mas ela não passa em silêncio: a tela denuncia toda vez que abre. */
    #[Test]
    public function combinacao_invalida_herdada_aparece_como_aviso(): void
    {
        $this->gravaCombinacaoHerdadaInvalida();

        $this->get(route('configuracao'))
            ->assertOk()
            ->assertSee('Critério que nunca é alcançado', escape: false)
            ->assertSee('silêncio inicial', escape: false);
    }

    /**
     * E a instalação nova NÃO nasce mais com o aviso — que é o ponto da correção.
     *
     * Este teste é o par do que foi trocado em `PosAtendimentoTest`: lá a afirmação é
     * sobre a declaração, aqui é sobre o que o operador vê ao abrir a tela pela
     * primeira vez numa base recém-migrada.
     */
    #[Test]
    public function instalacao_nova_abre_sem_aviso_de_criterio_morto(): void
    {
        $this->get(route('configuracao'))
            ->assertOk()
            ->assertDontSee('Critério que nunca é alcançado', escape: false);
    }

    /** Combinação válida não gera aviso nenhum. */
    #[Test]
    public function combinacao_valida_nao_gera_aviso(): void
    {
        DB::table('configuracao')->where('id', 1)->update([
            'pos_silencio_inicial_ms' => 2500,
            'pos_saudacao_max_ms' => 1500,
            'pos_analise_total_ms' => 5000,
        ]);

        $this->get(route('configuracao'))
            ->assertOk()
            ->assertDontSee('Critério que nunca é alcançado', escape: false);
    }

    /** Empatar com o orçamento é o mesmo que estourá-lo: o relógio para AO ATINGIR. */
    #[Test]
    public function recusa_criterio_igual_a_analise_total(): void
    {
        Livewire::test(Configuracao::class)
            ->set('pos_analise_total_ms', 5000)
            ->set('pos_silencio_inicial_ms', 5000)
            ->call('salvar')
            ->assertHasErrors('pos_silencio_inicial_ms');
    }

    /** A recusa não pode gravar metade: nada muda enquanto houver erro. */
    #[Test]
    public function criterio_invalido_nao_grava_nada(): void
    {
        Livewire::test(Configuracao::class)
            ->set('gravacao_retencao_dias', 30)
            ->set('pos_analise_total_ms', 5000)
            ->set('pos_silencio_inicial_ms', 9000)
            ->call('salvar')
            ->assertHasErrors('pos_silencio_inicial_ms');

        $this->assertNotSame(
            30,
            (int) DB::table('configuracao')->where('id', 1)->value('gravacao_retencao_dias'),
        );
    }

    /**
     * As nove propriedades da tela repetem os defaults da declaração, e este teste é
     * o que impede a repetição de virar divergência.
     *
     * 🔴 SÃO LITERAIS PORQUE PRECISAM SER: propriedade tipada de Livewire tem que
     * nascer inicializada, e `mount()` não pode ser a única garantia — ele roda
     * depois. Na prática `mount()` sobrescreve as nove por
     * `PosAtendimento::deLinha()`, então o literal quase nunca chega ao operador; foi
     * por isso que o `6000` sobreviveu aqui sem ninguém notar, mesmo depois de
     * `PosAtendimento` ter sido criada para acabar com as sete grafias.
     *
     * Este teste é a oitava grafia deixando de ser possível.
     */
    #[Test]
    public function os_defaults_da_tela_nao_divergem_da_declaracao(): void
    {
        $daTela = (new \ReflectionClass(Configuracao::class))->getDefaultProperties();

        foreach (\App\Dominio\PosAtendimento::parametros() as $p) {
            $this->assertSame(
                $p->padrao,
                $daTela[$p->coluna] ?? null,
                "`{$p->coluna}`: a propriedade da tela e a declaração discordam do valor de fábrica",
            );
        }
    }

    #[Test]
    public function os_parametros_somem_da_tela_com_o_classificador_desligado(): void
    {
        // Campo de parâmetro visível com a chave desligada seria oferecer ajuste para algo
        // que não roda — a mesma promessa falsa que a seção "o que não está aqui" evita.
        Livewire::test(Configuracao::class)
            ->set('classificador_pos_ativo', false)
            ->assertDontSee('Silêncio inicial (ms)')
            ->set('classificador_pos_ativo', true)
            ->assertSee('Silêncio inicial (ms)');
    }
}
