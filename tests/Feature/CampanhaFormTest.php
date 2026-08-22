<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CampanhaForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CampanhaFormTest extends TestCase
{
    use RefreshDatabase;

    private int $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->audio = (int) DB::table('audio')->insertGetId([
            'nome' => 'Mensagem', 'arquivo' => 'msg.alaw', 'ativo' => 1,
        ]);
    }

    #[Test]
    public function cria_campanha_em_rascunho(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Consórcio agosto')
            ->set('audio_mensagem_id', $this->audio)
            ->set('template_nome', 'boas_vindas')
            ->call('salvar')
            ->assertHasNoErrors()
            ->assertRedirect(route('campanhas'));

        $c = DB::table('campanha')->first();
        $this->assertNotNull($c);
        $this->assertSame('Consórcio agosto', $c->nome);
        $this->assertSame('opt_in', $c->tipo);

        // 🔴 RASCUNHO, não ativa. Nascer ativa faria o dialer começar a discar no
        // instante do clique, antes de existir um Lead — e a Campanha vazia seria
        // marcada como concluída na mesma volta.
        $this->assertSame(0, (int) $c->status);
        $this->assertNull($c->iniciada_em);
    }

    /**
     * 🔴 BINA vazia grava NULL, nunca string vazia.
     *
     * Medido em tronco real: CallerID vazio pode sobrescrever o que a rota ia
     * colocar, ou sair como chamada ANÔNIMA, que tem atendimento perto de zero — e
     * a causa fica invisível, porque ninguém investiga um campo em branco.
     */
    #[Test]
    public function bina_em_branco_vira_null(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Sem bina')
            ->set('bina_regra', 'unica')
            ->set('bina', '')
            ->set('audio_mensagem_id', $this->audio)
            ->set('template_nome', 'tpl')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertNull(DB::table('campanha')->value('bina'));
    }

    #[Test]
    public function recusa_bina_fora_do_formato_canonico(): void
    {
        // Medido: BINA com `+` recebe `00` da rota e chega ao Lead como
        // +005562984853523 na tela do telefone.
        foreach (['+5511987654321', '11 98765-4321', '5511'] as $ruim) {
            Livewire::test(CampanhaForm::class)
                ->set('nome', 'x')
                ->set('bina', $ruim)
                ->set('audio_mensagem_id', $this->audio)
                ->set('template_nome', 'tpl')
                ->call('salvar')
                ->assertHasErrors('bina');
        }
    }

    /**
     * Teclas iguais tornam opt-in e opt-out indistinguíveis para o fastagi: o Lead
     * apertaria 1 e o sistema não saberia se ele aceitou ou pediu para sair.
     */
    #[Test]
    public function recusa_teclas_iguais(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'x')
            ->set('tecla_opt_in', '1')
            ->set('tecla_opt_out', '1')
            ->set('audio_mensagem_id', $this->audio)
            ->set('template_nome', 'tpl')
            ->call('salvar')
            ->assertHasErrors('tecla_opt_in');
    }

    /**
     * Sem template, o worker recusa a Ação com
     * `campanha_sem_template_ou_lead_sem_telefone`: o Lead aperta 1, a Ação é
     * gravada e o opt-in morre depois de dar certo. Exigir aqui troca falha
     * silenciosa por campo vermelho.
     */
    #[Test]
    public function whatsapp_exige_nome_do_template(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'x')
            ->set('canal_opt_in', 'whatsapp')
            ->set('template_nome', '')
            ->set('audio_mensagem_id', $this->audio)
            ->call('salvar')
            ->assertHasErrors('template_nome');

        // Em SMS não é exigido — aquele remetente não usa template.
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'y')
            ->set('canal_opt_in', 'sms')
            ->set('template_nome', '')
            ->set('audio_mensagem_id', $this->audio)
            ->call('salvar')
            ->assertHasNoErrors();
    }

    /**
     * 🔴 O mapa de template NÃO PODE TER LACUNA, e a lista torna isso
     * irrepresentável em vez de validado.
     *
     * `Placeholder::parametrosTemplate` lança com `{"1":…,"3":…}`, porque template
     * posicional com lacuna manda parâmetro no lugar errado e o Lead recebe a
     * mensagem de outra pessoa. Como a posição vem do índice da lista, remover o do
     * meio reindexa e o mapa continua 1,2,3.
     */
    #[Test]
    public function o_mapa_de_template_e_sempre_sequencial(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Com parâmetros')
            ->set('audio_mensagem_id', $this->audio)
            ->set('template_nome', 'tpl')
            ->set('parametros', ['Nome', 'Cidade', 'Valor'])
            ->call('removerParametro', 1)          // tira "Cidade", o do MEIO
            ->assertSet('parametros', ['Nome', 'Valor'])
            ->call('salvar')
            ->assertHasNoErrors();

        $mapa = json_decode((string) DB::table('campanha')->value('template_mapa'), true);
        $this->assertSame(['1' => 'Nome', '2' => 'Valor'], $mapa, 'sem lacuna: 1 e 2, não 1 e 3');
    }

    #[Test]
    public function editar_carrega_o_mapa_na_ordem_numerica(): void
    {
        // Ordem das chaves embaralhada de propósito: a ordem no JSON não é garantia
        // de nada, e ler na ordem errada trocaria os parâmetros de lugar.
        $id = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'Editar', 'status' => 0,
            'template_nome' => 'tpl', 'canal_opt_in' => 'whatsapp',
            'template_mapa' => '{"3":"Valor","1":"Nome","2":"Cidade"}',
        ]);

        Livewire::test(CampanhaForm::class, ['id' => $id])
            ->assertSet('nome', 'Editar')
            ->assertSet('parametros', ['Nome', 'Cidade', 'Valor']);
    }

    #[Test]
    public function editar_atualiza_em_vez_de_criar(): void
    {
        $id = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'Antigo', 'status' => 1,
        ]);

        Livewire::test(CampanhaForm::class, ['id' => $id])
            ->set('nome', 'Novo')
            ->set('audio_mensagem_id', $this->audio)
            ->set('template_nome', 'tpl')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame(1, DB::table('campanha')->count());
        $this->assertSame('Novo', DB::table('campanha')->value('nome'));
        // Editar NÃO mexe no status: pausar e iniciar são ações da lista.
        $this->assertSame(1, (int) DB::table('campanha')->value('status'));
    }

    /**
     * A PÁGINA INTEIRA, pela pilha HTTP: layout, componentes do Mary e assets.
     *
     * `Livewire::test` renderiza só a view do componente. Foi assim que um erro de
     * compilação do Blade — chave dupla dentro de string, `{{ '{{1}}' }}` — passou
     * de mim e só apareceu como erro em oito testes de uma vez, com a mensagem
     * "Unclosed '(' does not match '}'", que não aponta a linha culpada.
     */
    #[Test]
    public function a_pagina_de_nova_campanha_renderiza_inteira(): void
    {
        $this->get(route('campanha.nova'))
            ->assertOk()
            ->assertSee('Nova Campanha')
            ->assertSee('BINA')
            ->assertSee('URA')
            ->assertSee('Mensagem de opt-in')
            // Com áudio cadastrado, o seletor aparece — e o aviso, não.
            ->assertSee('Áudio da mensagem', escape: false)
            ->assertDontSee('Nenhum áudio cadastrado', escape: false)
            ->assertDontSee('Unclosed');
    }

    /**
     * Sem áudio nenhum, a tela AVISA em vez de oferecer um seletor vazio. Sem áudio
     * a Campanha liga e não fala nada, e a tela de cadastro de áudio não existe —
     * calar sobre isso faria o operador salvar uma Campanha muda sem saber.
     */
    #[Test]
    public function sem_audio_a_tela_avisa_em_vez_de_oferecer_seletor_vazio(): void
    {
        DB::table('campanha')->delete();
        DB::table('audio')->delete();

        $this->get(route('campanha.nova'))
            ->assertOk()
            ->assertSee('Nenhum áudio cadastrado', escape: false)
            ->assertSee('liga e não fala nada', escape: false)
            ->assertDontSee('Áudio da mensagem', escape: false);
    }

    #[Test]
    public function a_pagina_de_edicao_renderiza_inteira(): void
    {
        $id = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'Existente', 'status' => 0,
            'canal_opt_in' => 'whatsapp', 'template_nome' => 'tpl',
        ]);

        $this->get(route('campanha.editar', $id))
            ->assertOk()
            ->assertSee('Editar Campanha')
            ->assertSee('Existente');
    }

    #[Test]
    public function campanha_inexistente_da_404(): void
    {
        $this->get(route('campanha.editar', 99999))->assertNotFound();
    }

    /**
     * 🔴 A CAPTURA PRECISA DA TECLA, e a tela escondia os dois campos.
     *
     * O `Fluxo::captura` passou a exigir o mesmo portão do opt-in — Mensagem, tecla, e só
     * então as perguntas —, mas a tela seguiu com `@if ($tipo === 'opt_in')` em volta das
     * teclas. Numa Campanha de captura elas eram inalcançáveis e ficavam no default '1'.
     *
     * Custou uma Campanha inteira em 21/08/2026: áudio pedindo "digite 2", campo esperando
     * '1', as 4 pessoas que apertaram algo viraram `invalido`, zero CPF coletado.
     */
    #[Test]
    public function captura_MOSTRA_os_campos_de_tecla_na_tela(): void
    {
        // 🔴 RENDERIZAÇÃO, não só a propriedade. O defeito morava na blade — um
        // `@if ($tipo === 'opt_in')` em volta das teclas —, e `Livewire::test()->set()`
        // escreve na propriedade direto, então passaria mesmo com os campos escondidos.
        // Só assertar o HTML pega este bug.
        Livewire::test(CampanhaForm::class)
            ->set('tipo', 'captura')
            ->assertSee('Tecla de opt-in')
            ->assertSee('Tecla de opt-out')
            ->assertSee('Confirmação da captura');
    }

    #[Test]
    public function opt_in_NAO_mostra_a_confirmacao_da_captura(): void
    {
        // O avesso: numa Campanha de opt-in não há fim de coleta para confirmar, e o
        // retorno já é a "Confirmação de opt-in". Oferecer o campo ali seria campo morto.
        Livewire::test(CampanhaForm::class)
            ->set('tipo', 'opt_in')
            ->assertSee('Tecla de opt-in')
            ->assertDontSee('Confirmação da captura');
    }

    #[Test]
    public function captura_grava_a_tecla_de_opt_in(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Captura INSS')
            ->set('tipo', 'captura')
            ->set('audio_mensagem_id', $this->audio)
            ->set('tecla_opt_in', '2')
            ->set('campos', [[
                'nome' => 'CPF', 'tipo' => 'cpf', 'tamanho' => 11,
                'audio_prompt_id' => $this->audio, 'obrigatorio' => true,
                'tentativas' => 2, 'timeout_seg' => 15,
            ]])
            ->call('salvar')
            ->assertHasNoErrors();

        $c = DB::table('campanha')->where('nome', 'Captura INSS')->first();
        $this->assertNotNull($c);
        $this->assertSame('captura', $c->tipo);
        $this->assertSame('2', $c->tecla_opt_in);
    }

    /**
     * O "recebi, obrigado" do fim da captura. A coluna e o `Fluxo` existiam desde a
     * migration; o que faltava era o campo na tela, então nenhuma Campanha conseguia
     * apontar para o áudio e toda captura terminava muda.
     */
    #[Test]
    public function confirmacao_da_captura_vai_e_volta_da_tela(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Captura com retorno')
            ->set('tipo', 'captura')
            ->set('audio_mensagem_id', $this->audio)
            ->set('audio_confirma_captura_id', $this->audio)
            ->set('campos', [[
                'nome' => 'CPF', 'tipo' => 'cpf', 'tamanho' => 11,
                'audio_prompt_id' => $this->audio, 'obrigatorio' => true,
                'tentativas' => 2, 'timeout_seg' => 15,
            ]])
            ->call('salvar')
            ->assertHasNoErrors();

        $c = DB::table('campanha')->where('nome', 'Captura com retorno')->first();
        $this->assertNotNull($c);
        $this->assertSame($this->audio, (int) $c->audio_confirma_captura_id);

        // E volta para a tela ao editar: gravar sem recarregar esconderia a escolha do
        // operador na próxima abertura, que é como um campo "não salva" na prática.
        Livewire::test(CampanhaForm::class, ['id' => (int) $c->id])
            ->assertSet('audio_confirma_captura_id', $this->audio);
    }

    /** Cortesia é opcional: Campanha de captura sem o áudio continua salvando. */
    #[Test]
    public function confirmacao_da_captura_e_opcional(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Captura sem retorno')
            ->set('tipo', 'captura')
            ->set('audio_mensagem_id', $this->audio)
            ->set('campos', [[
                'nome' => 'CPF', 'tipo' => 'cpf', 'tamanho' => 11,
                'audio_prompt_id' => $this->audio, 'obrigatorio' => true,
                'tentativas' => 2, 'timeout_seg' => 15,
            ]])
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertNull(
            DB::table('campanha')->where('nome', 'Captura sem retorno')->value('audio_confirma_captura_id'),
        );
    }
}
