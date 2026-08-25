<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Livewire\RespostasRapidas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Mensagens prontas na caixa de entrada, e o Placeholder resolvido no uso.
 */
final class RespostasRapidasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function resposta(string $titulo, string $texto): int
    {
        return (int) DB::table('resposta_rapida')->insertGetId([
            'titulo' => $titulo, 'texto' => $texto,
            'ordem' => 1, 'ativo' => 1, 'criado_em' => now(),
        ]);
    }

    /**
     * Conversa com janela ABERTA — é a única em que a caixa de resposta existe, e
     * portanto a única em que os botões aparecem.
     *
     * @param array<string,string> $extras colunas do Lead
     */
    private function conversa(array $extras = [], bool $comLead = true): int
    {
        $leadId = null;
        if ($comLead) {
            $campanha = DB::table('campanha')->insertGetId([
                'nome' => 'C', 'tipo' => 'opt_in', 'status' => 0, 'criada_em' => now(),
            ]);
            $leadId = DB::table('leads')->insertGetId([
                'campanha_id' => $campanha, 'telefone' => '5511999999999',
                'dados_extras' => json_encode($extras), 'status' => 1, 'criado_em' => now(),
            ]);
        }

        return (int) DB::table('conversa')->insertGetId([
            'telefone' => '5511999999999',
            'lead_id' => $leadId,
            'ultima_entrada_em' => now(),   // janela aberta
            'criado_em' => now(),
        ]);
    }

    #[Test]
    public function cadastra_edita_e_desativa(): void
    {
        $t = Livewire::test(RespostasRapidas::class)
            ->set('titulo', 'Confirmar')
            ->set('texto', 'Consegui localizar sua consulta.')
            ->call('salvar')
            ->assertHasNoErrors();

        $linha = DB::table('resposta_rapida')->first();
        $this->assertNotNull($linha);
        $this->assertSame('Confirmar', $linha->titulo);
        // Primeira da tabela vazia nasce em 1, não em 0 vindo de um `max()` nulo.
        $this->assertSame(1, (int) $linha->ordem);

        $t->call('editar', (int) $linha->id)
            ->set('texto', 'Texto novo.')
            ->call('salvar')
            ->assertHasNoErrors();
        $this->assertSame('Texto novo.', DB::table('resposta_rapida')->value('texto'));

        // Desativar em vez de apagar: resposta sazonal volta.
        $t->call('alternar', (int) $linha->id);
        $this->assertSame(0, (int) DB::table('resposta_rapida')->value('ativo'));
    }

    /** Texto puro entra na caixa como está, sem depender de Lead nenhum. */
    #[Test]
    public function texto_puro_preenche_a_caixa(): void
    {
        $id = $this->resposta('Oi', 'Bom dia! Como posso ajudar?');
        $conversa = $this->conversa();

        Livewire::test(Conversas::class)
            ->call('selecionar', $conversa)
            ->call('usarResposta', $id)
            ->assertHasNoErrors()
            ->assertSet('resposta', 'Bom dia! Como posso ajudar?');
    }

    /**
     * 🔴 PREENCHE, NÃO ENVIA — e este teste é a trava dessa decisão.
     *
     * A mensagem é irreversível e vai para uma pessoa real. Se alguém trocar o
     * comportamento para enviar direto, isto quebra e obriga a decisão a ser tomada
     * de novo, em vez de virar efeito colateral.
     */
    #[Test]
    public function usar_resposta_nao_enfileira_nada(): void
    {
        $id = $this->resposta('Oi', 'Bom dia!');
        $conversa = $this->conversa();

        Livewire::test(Conversas::class)
            ->call('selecionar', $conversa)
            ->call('usarResposta', $id);

        $this->assertSame(0, DB::table('acao')->count(), 'nenhuma Ação nasceu');
        $this->assertSame(0, DB::table('conversa_mensagem')->count(), 'nada foi gravado na Conversa');
    }

    /** O Placeholder é resolvido com os dados do Lead desta Conversa. */
    #[Test]
    public function placeholder_e_resolvido_com_o_lead(): void
    {
        $id = $this->resposta('Saudação', 'Olá {nome}, localizei seu CPF {cpf}.');
        $conversa = $this->conversa(['nome' => 'Karen', 'cpf' => '123']);

        Livewire::test(Conversas::class)
            ->call('selecionar', $conversa)
            ->call('usarResposta', $id)
            ->assertHasNoErrors()
            ->assertSet('resposta', 'Olá Karen, localizei seu CPF 123.');
    }

    /**
     * 🔴 COLUNA QUE FALTA NÃO VIRA LACUNA.
     *
     * `Placeholder` falha com causa nomeada em vez de substituir por vazio, porque
     * "Olá , tudo bem?" saindo para uma pessoa real é pior que não preencher. A tela
     * mostra o motivo e deixa a caixa intacta.
     */
    #[Test]
    public function coluna_ausente_avisa_e_nao_preenche(): void
    {
        $id = $this->resposta('Saudação', 'Olá {nome}!');
        $conversa = $this->conversa(['cpf' => '123']);   // sem `nome`

        Livewire::test(Conversas::class)
            ->call('selecionar', $conversa)
            ->call('usarResposta', $id)
            ->assertHasErrors('resposta')
            ->assertSet('resposta', '');
    }

    /** Coluna presente mas VAZIA é o mesmo caso: some do texto e vira lacuna. */
    #[Test]
    public function coluna_vazia_tambem_avisa(): void
    {
        $id = $this->resposta('Saudação', 'Olá {nome}!');
        $conversa = $this->conversa(['nome' => '']);

        Livewire::test(Conversas::class)
            ->call('selecionar', $conversa)
            ->call('usarResposta', $id)
            ->assertHasErrors('resposta')
            ->assertSet('resposta', '');
    }

    /**
     * 🔴 CONVERSA SEM LEAD É CASO REAL, não defeito.
     *
     * `conversa.lead_id` é anulável: número desconhecido que escreve para o WhatsApp
     * abre Conversa sem Lead. Resposta com Placeholder não tem como funcionar ali, e
     * dizer isso é melhor que preencher com lacuna.
     */
    #[Test]
    public function conversa_sem_lead_recusa_resposta_com_placeholder(): void
    {
        $comPlaceholder = $this->resposta('Saudação', 'Olá {nome}!');
        $semPlaceholder = $this->resposta('Oi', 'Bom dia!');
        $conversa = $this->conversa(comLead: false);

        $t = Livewire::test(Conversas::class)->call('selecionar', $conversa);

        $t->call('usarResposta', $comPlaceholder)
            ->assertHasErrors('resposta')
            ->assertSet('resposta', '');

        // Mas texto puro continua servindo — é a maioria das respostas prontas.
        $t->call('usarResposta', $semPlaceholder)
            ->assertHasNoErrors()
            ->assertSet('resposta', 'Bom dia!');
    }

    /** Resposta desativada não aparece nem responde ao clique. */
    #[Test]
    public function desativada_nao_preenche(): void
    {
        $id = $this->resposta('Antiga', 'Promoção de julho.');
        DB::table('resposta_rapida')->where('id', $id)->update(['ativo' => 0]);
        $conversa = $this->conversa();

        Livewire::test(Conversas::class)
            ->call('selecionar', $conversa)
            ->call('usarResposta', $id)
            ->assertSet('resposta', '');
    }

    /**
     * Os botões só existem onde a caixa existe.
     *
     * A caixa de resposta só é renderizada quando o envio PODE dar certo — janela
     * aberta e número fora da Lista de bloqueio. Botão de resposta rápida fora disso
     * seria a promessa falsa que esta tela foi feita para evitar.
     */
    #[Test]
    public function os_botoes_somem_com_a_janela_fechada(): void
    {
        $this->resposta('Confirmar interesse', 'Texto.');

        $aberta = $this->conversa();
        Livewire::test(Conversas::class)
            ->call('selecionar', $aberta)
            ->assertSee('Confirmar interesse');

        // Janela fechada: última entrada há mais de 24 h.
        DB::table('conversa')->where('id', $aberta)
            ->update(['ultima_entrada_em' => now()->subHours(30)]);

        Livewire::test(Conversas::class)
            ->call('selecionar', $aberta)
            ->assertDontSee('Confirmar interesse');
    }
}
