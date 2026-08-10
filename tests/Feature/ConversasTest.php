<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConversasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function estado_vazio_diz_o_que_falta(): void
    {
        $this->get(route('conversas'))
            ->assertOk()
            ->assertSee('Nenhuma conversa', escape: false)
            // O webhook exige HTTPS válido: a Meta recusa autoassinado. Dizer isso
            // evita o operador achar que o produto está quebrado.
            // Fragmento curto: o texto do Blade quebra linha no meio da frase, e uma
            // asserção longa cruzaria o \n do HTML.
            ->assertSee('autoassinado', escape: false)
            ->assertDontSee('Unclosed');
    }

    #[Test]
    public function lista_a_conversa_com_a_ultima_mensagem(): void
    {
        $id = $this->conversa('5511987654321', '-1 hour');
        $this->mensagem($id, 'entrada', 'Quero saber mais');

        Livewire::test(Conversas::class)
            ->assertSee('5511987654321')
            ->assertSee('Quero saber mais')
            ->assertSee('1 msg');
    }

    /**
     * 🔴 A janela é a informação que decide se dá para responder, e a tela precisa
     * mostrar quanto resta: dentro dela texto livre é grátis, fora só template pago.
     */
    #[Test]
    public function mostra_a_janela_aberta_com_o_restante(): void
    {
        $id = $this->conversa('5511900000001', '-1 hour');
        $this->mensagem($id, 'entrada', 'oi');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->assertSee('janela aberta')
            ->assertSee('23 h 00 min')
            ->assertSee('custa por mensagem', escape: false);
    }

    #[Test]
    public function mostra_a_janela_fechada_e_a_consequencia(): void
    {
        $id = $this->conversa('5511900000002', '-30 hours');
        $this->mensagem($id, 'entrada', 'oi antigo');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->assertSee('janela fechada')
            // Diz o que muda: só template, e template é pago.
            ->assertSee('template aprovado', escape: false);
    }

    /**
     * Ordena por `ultima_entrada_em`, não por criação: quem escreveu agora é quem espera
     * resposta e por quem a janela corre. Ordenar por criação enterraria uma conversa
     * antiga que acabou de receber mensagem.
     */
    #[Test]
    public function ordena_por_entrada_mais_recente(): void
    {
        $antiga = $this->conversa('5511900000010', '-2 hours');
        $nova = $this->conversa('5511900000011', '-5 minutes');

        // A antiga foi criada primeiro, então por `id` ela viria antes.
        $this->assertLessThan($nova, $antiga);

        $html = Livewire::test(Conversas::class)->html();

        $this->assertLessThan(
            strpos($html, '5511900000010'),
            strpos($html, '5511900000011'),
            'a que escreveu mais recentemente aparece primeiro',
        );
    }

    #[Test]
    public function marca_quem_esta_na_lista_de_bloqueio(): void
    {
        $id = $this->conversa('5511900000003', '-1 hour');
        DB::table('bloqueio')->insert(['telefone' => '5511900000003', 'origem' => 'opt_out']);

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->assertSee('na lista de bloqueio', escape: false);
    }

    #[Test]
    public function mostra_o_estado_de_entrega_da_mensagem_que_saiu(): void
    {
        $id = $this->conversa('5511900000004', '-1 hour');
        $this->mensagem($id, 'saida', 'Bem-vindo!', status: 'lido', template: 'boas_vindas');
        $this->mensagem($id, 'saida', 'Falhou essa', status: 'falhou', erro: 131049);

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->assertSee('lido')
            ->assertSee('falhou')
            ->assertSee('erro 131049')
            ->assertSee('template boas_vindas');
    }

    /** Vincula ao Lead quando existe — é o que liga a resposta à Campanha que a gerou. */
    #[Test]
    public function mostra_o_lead_vinculado(): void
    {
        $campanha = DB::table('campanha')->insertGetId(['tipo' => 'opt_in', 'nome' => 'C', 'status' => 1]);
        $lead = DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511900000005', 'status' => 3,
        ]);
        $this->conversa('5511900000005', '-1 hour', $lead);

        Livewire::test(Conversas::class)->assertSee('lead #' . $lead);
    }

    /** A tela declara o limite em vez de deixar o operador procurar o campo de resposta. */
    #[Test]
    public function declara_que_e_somente_leitura(): void
    {
        $id = $this->conversa('5511900000006', '-1 hour');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->assertSee('somente leitura', escape: false)
            ->assertSee('texto livre no worker', escape: false);
    }

    /** A conversa selecionada vai para a URL, para o operador poder mandar o link. */
    #[Test]
    public function a_selecao_fica_na_url(): void
    {
        $id = $this->conversa('5511900000007', '-1 hour');

        Livewire::withQueryParams(['c' => $id])
            ->test(Conversas::class)
            ->assertSet('selecionada', $id)
            ->assertSee('5511900000007');
    }

    private function conversa(string $telefone, string $quando, ?int $leadId = null): int
    {
        return (int) DB::table('conversa')->insertGetId([
            'telefone' => $telefone,
            'lead_id' => $leadId,
            'ultima_entrada_em' => now()->modify($quando),
        ]);
    }

    private function mensagem(
        int $conversaId,
        string $direcao,
        string $texto,
        ?string $status = null,
        ?string $template = null,
        ?int $erro = null,
    ): void {
        DB::table('conversa_mensagem')->insert([
            'conversa_id' => $conversaId,
            'wamid' => 'wamid.' . uniqid(),
            'direcao' => $direcao,
            'tipo' => 'text',
            'texto' => $texto,
            'status' => $status,
            'template_nome' => $template,
            'erro_codigo' => $erro,
        ]);
    }
}
