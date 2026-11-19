<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Models\User;
use App\Worker\ApagadorDeCampanha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\CriaAudio;
use Tests\TestCase;

/**
 * Apagar Campanha: o painel MARCA, o `ApagadorDeCampanha` apaga em lotes.
 *
 * O apagador não abre transação, e é isso que permite `RefreshDatabase` aqui.
 */
final class ApagarCampanhaTest extends TestCase
{
    use CriaAudio;
    use RefreshDatabase;

    private int $audio;

    private string $dir;

    /** @var list<array{0:string,1:string,2:array<string,mixed>}> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->audio = $this->audioComArquivo();

        $this->dir = sys_get_temp_dir() . '/prospecta-apagar-' . getmypid();
        @mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        $this->limparAudios();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ painel

    #[Test]
    public function apagar_so_marca_e_nao_apaga_nada_na_requisicao(): void
    {
        $id = $this->campanha(['status' => 2]);
        $lead = $this->lead($id);
        $this->ligacao($id, $lead);

        Livewire::test(Campanhas::class)
            ->call('apagar', $id)
            ->assertSee('será apagada')
            ->assertSee('apagando');

        $this->assertNotNull(DB::table('campanha')->where('id', $id)->value('apagar_pedido_em'));
        $this->assertSame(1, DB::table('leads')->where('campanha_id', $id)->count());
        $this->assertSame(1, DB::table('ligacao')->where('campanha_id', $id)->count());
    }

    #[Test]
    public function apagar_recusa_campanha_no_ar(): void
    {
        $id = $this->campanha(['status' => 1]);

        Livewire::test(Campanhas::class)
            ->call('apagar', $id)
            ->assertSee('a Campanha está no ar');

        $this->assertNull(DB::table('campanha')->where('id', $id)->value('apagar_pedido_em'));
    }

    /** Campanha marcada não volta ao ar: o dialer discaria uma fila que está sumindo. */
    #[Test]
    public function iniciar_recusa_campanha_marcada_para_apagar(): void
    {
        $id = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $this->lead($id);

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('está sendo apagada');

        $this->assertSame(2, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    #[Test]
    public function reiniciar_recusa_campanha_marcada_para_apagar(): void
    {
        $id = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $lead = $this->lead($id, 3);
        $this->ligacao($id, $lead);

        Livewire::test(Campanhas::class)
            ->call('reiniciar', $id)
            ->assertSee('está sendo apagada');

        $this->assertSame(3, (int) DB::table('leads')->where('id', $lead)->value('status'));
    }

    #[Test]
    public function a_tela_mostra_o_erro_do_apagamento(): void
    {
        $this->campanha(['status' => 2, 'apagar_pedido_em' => now(), 'apagar_erro' => 'gravação presa no disco']);

        Livewire::test(Campanhas::class)->assertSee('gravação presa no disco');
    }

    // ---------------------------------------------------------------- apagador

    #[Test]
    public function apaga_a_campanha_inteira_e_mantem_a_lista_de_bloqueio(): void
    {
        $id = $this->campanha(['status' => 3, 'apagar_pedido_em' => now()]);
        $lead = $this->lead($id, 3);
        $ligacao = $this->ligacao($id, $lead);

        DB::table('acao')->insert(['tipo' => 'disparo', 'referencia_id' => $ligacao, 'status' => 'pendente']);
        DB::table('bloqueio')->insert(['telefone' => '5511900001111', 'ligacao_id' => $ligacao, 'origem' => 'opt_out']);

        $definicao = (int) DB::table('campo_definicao')->insertGetId(['nome' => 'CPF', 'tipo' => 'numero', 'tamanho' => 11]);
        DB::table('campanha_campo')->insert([
            'campanha_id' => $id, 'campo_definicao_id' => $definicao, 'ordem' => 1,
            'obrigatorio' => 1, 'tentativas' => 2, 'timeout_seg' => 10, 'audio_prompt_id' => $this->audio,
        ]);

        $this->assertSame('apagadas', $this->apagador()->apagar($id));

        $this->assertSame(0, DB::table('campanha')->where('id', $id)->count());
        $this->assertSame(0, DB::table('leads')->where('campanha_id', $id)->count());
        $this->assertSame(0, DB::table('ligacao')->where('id', $ligacao)->count());
        $this->assertSame(0, DB::table('acao')->where('referencia_id', $ligacao)->count());
        $this->assertSame(0, DB::table('campanha_campo')->where('campanha_id', $id)->count());

        $this->assertSame(1, DB::table('bloqueio')->where('telefone', '5511900001111')->count(), 'Opt-out é global e permanente');
    }

    /** 🔴 `campanha_id` em todo `where`: um deslize apagaria a instância num clique. */
    #[Test]
    public function nao_toca_em_outra_campanha(): void
    {
        $apagar = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $this->ligacao($apagar, $this->lead($apagar));

        $outra = $this->campanha(['status' => 2]);
        $ligacaoDaOutra = $this->ligacao($outra, $this->lead($outra));

        $resumo = $this->apagador()->executar();

        $this->assertSame(['apagadas' => 1, 'aguardando' => 0, 'com_erro' => 0], $resumo);
        $this->assertSame(1, DB::table('campanha')->where('id', $outra)->count());
        $this->assertSame(1, DB::table('leads')->where('campanha_id', $outra)->count());
        $this->assertSame(1, DB::table('ligacao')->where('id', $ligacaoDaOutra)->count());
    }

    /** Sem marca, nada acontece — nem com a Campanha concluída. */
    #[Test]
    public function so_apaga_o_que_foi_marcado(): void
    {
        $id = $this->campanha(['status' => 3]);

        $this->assertSame(['apagadas' => 0, 'aguardando' => 0, 'com_erro' => 0], $this->apagador()->executar());
        $this->assertSame(1, DB::table('campanha')->where('id', $id)->count());
    }

    /**
     * 🔴 LIGAÇÃO EM CURSO ESPERA: o fastagi ainda vai gravar o desfecho dela. Nada é
     * apagado nesta volta, nem de outras Ligações da mesma Campanha.
     */
    #[Test]
    public function espera_a_ligacao_em_curso_terminar(): void
    {
        $id = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $this->ligacao($id, $this->lead($id, 2), encerrada: false);
        $this->ligacao($id, $this->lead($id, 3));

        $this->assertSame('aguardando', $this->apagador()->apagar($id));

        $this->assertSame(1, DB::table('campanha')->where('id', $id)->count());
        $this->assertSame(2, DB::table('ligacao')->where('campanha_id', $id)->count());
        $this->assertSame(2, DB::table('leads')->where('campanha_id', $id)->count());
    }

    #[Test]
    public function apaga_a_gravacao_do_disco(): void
    {
        $id = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $ligacao = $this->ligacao($id, $this->lead($id));

        $arquivo = $this->dir . '/' . $ligacao . '.gsm';
        file_put_contents($arquivo, 'x');
        DB::table('ligacao')->where('id', $ligacao)->update(['gravacao' => $arquivo]);

        $this->assertSame('apagadas', $this->apagador()->apagar($id));
        $this->assertFileDoesNotExist($arquivo);
    }

    /**
     * 🔴 GRAVAÇÃO QUE NÃO SAI DO DISCO SEGURA A CAMPANHA. A Ligação fica (senão o arquivo
     * sai do alcance de qualquer expurgo), a Campanha fica, e o motivo vai para a tela.
     */
    #[Test]
    public function gravacao_fora_da_base_retem_a_ligacao_e_a_campanha(): void
    {
        $id = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $retida = $this->ligacao($id, $this->lead($id));
        $comum = $this->ligacao($id, $this->lead($id));

        $fora = sys_get_temp_dir() . '/prospecta-fora-' . getmypid() . '.gsm';
        file_put_contents($fora, 'x');
        DB::table('ligacao')->where('id', $retida)->update(['gravacao' => $fora]);

        try {
            $this->assertSame('com_erro', $this->apagador()->apagar($id));

            $this->assertFileExists($fora);
            $this->assertSame(1, DB::table('ligacao')->where('id', $retida)->count());
            $this->assertSame(0, DB::table('ligacao')->where('id', $comum)->count(), 'o resto sai mesmo assim');
            $this->assertSame(1, DB::table('campanha')->where('id', $id)->count());
            $this->assertStringContainsString(
                '1 gravação(ões) não saíram do disco',
                (string) DB::table('campanha')->where('id', $id)->value('apagar_erro'),
            );
        } finally {
            @unlink($fora);
        }
    }

    /**
     * Mais de um lote de Ligações. O cursor por id é o que impede o laço de parar no
     * primeiro lote — ou de girar para sempre sobre uma Ligação retida.
     */
    #[Test]
    public function atravessa_mais_de_um_lote(): void
    {
        $id = $this->campanha(['status' => 2, 'apagar_pedido_em' => now()]);
        $lead = $this->lead($id);

        $linhas = [];
        for ($i = 0; $i < 2500; $i++) {
            $linhas[] = [
                'lead_id' => $lead, 'campanha_id' => $id, 'numero_tentativa' => 1,
                'iniciada_em' => now(), 'encerrada_em' => now(), 'desfecho' => 'nao_atendeu',
            ];
        }
        foreach (array_chunk($linhas, 500) as $lote) {
            DB::table('ligacao')->insert($lote);
        }

        $this->assertSame('apagadas', $this->apagador()->apagar($id));
        $this->assertSame(0, DB::table('ligacao')->where('campanha_id', $id)->count());
    }

    /** Campanha no ar nunca é apagada, nem marcada — a marca sozinha não basta. */
    #[Test]
    public function nao_apaga_campanha_no_ar_mesmo_marcada(): void
    {
        $id = $this->campanha(['status' => 1, 'apagar_pedido_em' => now()]);
        $this->lead($id);

        $this->apagador()->executar();

        $this->assertSame(1, DB::table('campanha')->where('id', $id)->count());
        $this->assertSame(1, DB::table('leads')->where('campanha_id', $id)->count());
    }

    private function apagador(): ApagadorDeCampanha
    {
        return new ApagadorDeCampanha(
            DB::connection()->getPdo(),
            $this->dir,
            function (string $evento, string $mensagem, array $extra): void {
                $this->log[] = [$evento, $mensagem, $extra];
            },
        );
    }

    /** @param array<string,mixed> $atributos */
    private function campanha(array $atributos = []): int
    {
        return (int) DB::table('campanha')->insertGetId(array_merge([
            'tipo' => 'opt_in',
            'nome' => 'Campanha',
            'status' => 0,
            'canal_opt_in' => 'whatsapp',
            'template_nome' => 'boas_vindas',
            'audio_mensagem_id' => $this->audio,
        ], $atributos));
    }

    private function lead(int $campanhaId, int $status = 1): int
    {
        return (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanhaId,
            'telefone' => '551190000' . random_int(1000, 9999),
            'status' => $status,
        ]);
    }

    private function ligacao(int $campanhaId, int $leadId, bool $encerrada = true): int
    {
        return (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $leadId,
            'campanha_id' => $campanhaId,
            'numero_tentativa' => 1,
            'iniciada_em' => now(),
            'encerrada_em' => $encerrada ? now() : null,
            'desfecho' => $encerrada ? 'nao_atendeu' : null,
        ]);
    }
}
