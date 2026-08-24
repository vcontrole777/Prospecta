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

    /**
     * Dentro da janela, responder ENFILEIRA uma Ação — não envia daqui.
     *
     * Quem fala com a Meta é o worker (ADR-0002). O que esta tela garante é que a Ação
     * nasce com o texto e apontando para a Conversa certa.
     */
    #[Test]
    public function responder_dentro_da_janela_enfileira_acao(): void
    {
        $id = $this->conversa('5511900000006', '-1 hour');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->set('resposta', 'Bom dia, seguem as informações.')
            ->call('responder')
            ->assertHasNoErrors()
            ->assertSet('resposta', '');

        $acao = DB::table('acao')->where('tipo', 'resposta_texto')->first();

        $this->assertNotNull($acao);
        $this->assertSame('pendente', $acao->status);

        // A Ação aponta para a MENSAGEM, e é nela que o texto vive — fonte única.
        $m = DB::table('conversa_mensagem')->where('id', $acao->referencia_id)->first();

        $this->assertNotNull($m);
        $this->assertSame($id, (int) $m->conversa_id);
        $this->assertSame('saida', $m->direcao);
        $this->assertSame('Bom dia, seguem as informações.', $m->texto);
    }

    /**
     * 🔴 DUAS RESPOSTAS NA MESMA CONVERSA. Regressão medida.
     *
     * A primeira versão apontava a Ação para a CONVERSA, e `acao` tem
     * `unique(tipo, referencia_id)` — então a segunda resposta do mesmo diálogo morria
     * com `Duplicate entry 'resposta_texto-2'`, em 500, com o texto perdido. Conversa
     * com uma resposta só não é conversa.
     *
     * A Ação aponta para a MENSAGEM: uma mensagem tem um envio, e aí a restrição diz a
     * coisa certa.
     */
    #[Test]
    public function varias_respostas_na_mesma_conversa(): void
    {
        $id = $this->conversa('5511900000019', '-1 hour');

        $tela = Livewire::test(Conversas::class)->call('selecionar', $id);

        foreach (['primeira', 'segunda', 'terceira'] as $texto) {
            $tela->set('resposta', $texto)->call('responder')->assertHasNoErrors();
        }

        $this->assertSame(3, DB::table('acao')->where('tipo', 'resposta_texto')->count());

        // E as três aparecem na conversa, na ordem, já visíveis antes de o worker rodar.
        $saidas = DB::table('conversa_mensagem')
            ->where('conversa_id', $id)->where('direcao', 'saida')
            ->orderBy('id')->pluck('texto')->all();

        $this->assertSame(['primeira', 'segunda', 'terceira'], $saidas);
    }

    /**
     * A resposta aparece na conversa ANTES de ser enviada, como pendente.
     *
     * `wamid` e `status` nulos são o "ainda não saiu". Sem isto o operador escreveria e
     * não veria nada até a próxima volta do worker — e clicaria de novo, mandando duas.
     */
    #[Test]
    public function a_resposta_aparece_na_conversa_como_pendente(): void
    {
        $id = $this->conversa('5511900000020', '-1 hour');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->set('resposta', 'aguardando envio')
            ->call('responder');

        $m = DB::table('conversa_mensagem')->where('conversa_id', $id)->where('direcao', 'saida')->first();

        $this->assertNotNull($m);
        $this->assertNull($m->wamid);
        $this->assertNull($m->status);
        $this->assertSame('aguardando envio', $m->texto);
    }

    /**
     * 🔴 FORA DA JANELA, RECUSA ANTES DE ENFILEIRAR.
     *
     * A Meta responde 131047 a texto livre fora das 24 h. Enfileirar assim mesmo faria
     * a Ação nascer condenada e o operador ver "falhou" sem causa — que é exatamente o
     * "campo que às vezes funciona" pelo qual esta tela ficou somente-leitura por
     * tanto tempo.
     */
    #[Test]
    public function fora_da_janela_recusa_e_nao_enfileira(): void
    {
        $id = $this->conversa('5511900000016', '-25 hours');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->set('resposta', 'oi')
            ->call('responder')
            ->assertHasErrors('resposta');

        $this->assertSame(0, DB::table('acao')->where('tipo', 'resposta_texto')->count());
    }

    /**
     * Número na Lista de bloqueio não recebe nem resposta digitada à mão.
     *
     * A Lista existe porque a pessoa pediu para não ser contatada, e o pedido foi ao
     * negócio — não à Campanha. Um "só mais uma mensagem" no teclado é justamente o
     * que ela impede.
     */
    #[Test]
    public function numero_bloqueado_nao_recebe_resposta(): void
    {
        $id = $this->conversa('5511900000017', '-1 hour');
        DB::table('bloqueio')->insert([
            'telefone' => '5511900000017',
            'origem' => 'opt_out',
        ]);

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->set('resposta', 'oi')
            ->call('responder')
            ->assertHasErrors('resposta');

        $this->assertSame(0, DB::table('acao')->where('tipo', 'resposta_texto')->count());
    }

    /** Texto vazio não vira Ação. */
    #[Test]
    public function resposta_vazia_nao_enfileira(): void
    {
        $id = $this->conversa('5511900000018', '-1 hour');

        Livewire::test(Conversas::class)
            ->call('selecionar', $id)
            ->set('resposta', '')
            ->call('responder')
            ->assertHasErrors('resposta');

        $this->assertSame(0, DB::table('acao')->where('tipo', 'resposta_texto')->count());
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

    /**
     * 🔴 SÓ QUEM RESPONDEU aparece na caixa.
     *
     * A caixa passou a criar `conversa` também na SAÍDA, para o Disparo aparecer no
     * diálogo. Sem este filtro, uma Campanha de 10 mil Leads encheria a tela com 10 mil
     * conversas mudas e enterraria as poucas que exigem atenção.
     */
    #[Test]
    public function lista_apenas_quem_respondeu(): void
    {
        $respondeu = $this->conversa('5511900000030', '-1 hour');
        $this->mensagem($respondeu, 'entrada', 'quero sim');

        // Só recebeu: `ultima_entrada_em` nulo é exatamente "nunca nos escreveu".
        $soRecebeu = (int) DB::table('conversa')->insertGetId([
            'telefone' => '5511900000031',
            'ultima_entrada_em' => null,
        ]);
        $this->mensagem($soRecebeu, 'saida', 'Disparo', status: 'entregue');

        $html = Livewire::test(Conversas::class)->html();

        $this->assertStringContainsString('5511900000030', $html);
        $this->assertStringNotContainsString('5511900000031', $html);
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
