<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CampanhaForm;
use App\Livewire\Conversas;
use App\Livewire\RespostasRapidas;
use App\Models\User;
use App\Worker\Whatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Worker\TransporteFalso;

/**
 * Botão de link na resposta rápida, e as colunas dos Leads no formulário de Campanha.
 */
final class BotaoDeLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function conversaAberta(): int
    {
        return (int) DB::table('conversa')->insertGetId([
            'telefone' => '5511999999999', 'lead_id' => null,
            'ultima_entrada_em' => now(), 'criado_em' => now(),
        ]);
    }

    // ---- envio ------------------------------------------------------------

    /**
     * 🔴 A FORMA DO `cta_url`, que é o que a Meta aceita.
     *
     * Errar a estrutura aqui não dá erro de PHP: dá recusa da Meta com a mensagem
     * dela, na primeira resposta enviada de verdade.
     */
    #[Test]
    public function monta_a_mensagem_interativa_cta_url(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');

        (new Whatsapp($t, 'tok', '123', 'v21.0'))->enviarTextoComBotao(
            '5511999999999',
            'Seus pontos estão disponíveis.',
            'Ver meus pontos',
            'https://exemplo.test/pontos',
            'conversa:7',
        );

        $this->assertSame('interactive', $t->corpo['type'] ?? null);

        $i = $t->corpo['interactive'];
        $this->assertIsArray($i);
        $this->assertSame('cta_url', $i['type']);
        $this->assertSame(['text' => 'Seus pontos estão disponíveis.'], $i['body']);
        $this->assertSame([
            'name' => 'cta_url',
            'parameters' => [
                'display_text' => 'Ver meus pontos',
                'url' => 'https://exemplo.test/pontos',
            ],
        ], $i['action']);

        // A correlação continua viajando, como no texto puro e no template.
        $this->assertSame('conversa:7', $t->corpo['biz_opaque_callback_data'] ?? null);
    }

    // ---- cadastro ---------------------------------------------------------

    /** Os dois campos andam em par: um só preenchido é estado sem significado. */
    #[Test]
    public function recusa_botao_pela_metade(): void
    {
        Livewire::test(RespostasRapidas::class)
            ->set('titulo', 'X')->set('texto', 'Y')
            ->set('botao_texto', 'Ver')->set('botao_url', '')
            ->call('salvar')
            ->assertHasErrors('botao_url');

        Livewire::test(RespostasRapidas::class)
            ->set('titulo', 'X')->set('texto', 'Y')
            ->set('botao_texto', '')->set('botao_url', 'https://a.test')
            ->call('salvar')
            ->assertHasErrors('botao_texto');
    }

    /** A Meta só aceita `https` — recusar aqui dá o motivo em vez de "falhou". */
    #[Test]
    public function recusa_endereco_sem_https(): void
    {
        Livewire::test(RespostasRapidas::class)
            ->set('titulo', 'X')->set('texto', 'Y')
            ->set('botao_texto', 'Ver')->set('botao_url', 'http://exemplo.test')
            ->call('salvar')
            ->assertHasErrors('botao_url');
    }

    /** Sem botão nenhum grava NULO, não string vazia — senão o worker mandaria interativo vazio. */
    #[Test]
    public function sem_botao_grava_nulo(): void
    {
        Livewire::test(RespostasRapidas::class)
            ->set('titulo', 'X')->set('texto', 'Y')
            ->call('salvar')
            ->assertHasNoErrors();

        $r = DB::table('resposta_rapida')->first();
        $this->assertNotNull($r);
        $this->assertNull($r->botao_texto);
        $this->assertNull($r->botao_url);
    }

    // ---- caixa de entrada -------------------------------------------------

    /**
     * 🔴 O BOTÃO FICA FORA DA CAIXA, e por isso é anexado e não "digitado".
     *
     * Botão é dado estruturado e não cabe num `textarea`. Anexando, o operador
     * continua editando o texto e mantém a revisão antes de enviar — que é a razão
     * de o clique preencher em vez de enviar.
     */
    #[Test]
    public function usar_resposta_anexa_o_botao_e_responder_o_grava(): void
    {
        $id = (int) DB::table('resposta_rapida')->insertGetId([
            'titulo' => 'Pontos', 'texto' => 'Seus pontos chegaram.',
            'botao_texto' => 'Ver pontos', 'botao_url' => 'https://exemplo.test/p',
            'ordem' => 1, 'ativo' => 1, 'criado_em' => now(),
        ]);

        Livewire::test(Conversas::class)
            ->call('selecionar', $this->conversaAberta())
            ->call('usarResposta', $id)
            ->assertSet('resposta', 'Seus pontos chegaram.')
            ->assertSet('botaoTexto', 'Ver pontos')
            ->assertSet('botaoUrl', 'https://exemplo.test/p')
            ->call('responder')
            ->assertHasNoErrors();

        $m = DB::table('conversa_mensagem')->first();
        $this->assertNotNull($m);
        $this->assertSame('Ver pontos', $m->botao_texto);
        $this->assertSame('https://exemplo.test/p', $m->botao_url);
        // Continua sendo UMA Ação de `resposta_texto`: o botão viaja na mensagem.
        $this->assertSame('resposta_texto', DB::table('acao')->value('tipo'));
    }

    /** Remover o botão não apaga o texto já escrito. */
    #[Test]
    public function remover_botao_preserva_o_texto(): void
    {
        $id = (int) DB::table('resposta_rapida')->insertGetId([
            'titulo' => 'P', 'texto' => 'Texto.',
            'botao_texto' => 'Ver', 'botao_url' => 'https://a.test',
            'ordem' => 1, 'ativo' => 1, 'criado_em' => now(),
        ]);

        Livewire::test(Conversas::class)
            ->call('selecionar', $this->conversaAberta())
            ->call('usarResposta', $id)
            ->call('removerBotao')
            ->assertSet('resposta', 'Texto.')
            ->assertSet('botaoTexto', '');
    }

    /**
     * 🔴 TROCAR DE CONVERSA DESCARTA O BOTÃO.
     *
     * Ele pertencia ao rascunho anterior. Sobrar para outra pessoa é o pior tipo de
     * resto: a mensagem sairia com um link que não era para ela.
     */
    #[Test]
    public function trocar_de_conversa_descarta_o_botao(): void
    {
        $id = (int) DB::table('resposta_rapida')->insertGetId([
            'titulo' => 'P', 'texto' => 'Texto.',
            'botao_texto' => 'Ver', 'botao_url' => 'https://a.test',
            'ordem' => 1, 'ativo' => 1, 'criado_em' => now(),
        ]);
        $outra = (int) DB::table('conversa')->insertGetId([
            'telefone' => '5511888888888', 'lead_id' => null,
            'ultima_entrada_em' => now(), 'criado_em' => now(),
        ]);

        Livewire::test(Conversas::class)
            ->call('selecionar', $this->conversaAberta())
            ->call('usarResposta', $id)
            ->assertSet('botaoTexto', 'Ver')
            ->call('selecionar', $outra)
            ->assertSet('botaoTexto', '')
            ->assertSet('botaoUrl', '');
    }

    // ---- colunas na Campanha ---------------------------------------------

    /**
     * 🔴 AS COLUNAS QUE OS LEADS TÊM, na tela.
     *
     * O campo de parâmetro era texto em branco e o operador tinha que ADIVINHAR o
     * nome. Errar não dá erro na tela: dá `template_mapa_invalido` no worker, uma
     * Ligação por vez, com a Campanha já no ar.
     */
    #[Test]
    public function mostra_as_colunas_dos_leads_da_campanha(): void
    {
        $c = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 0, 'criada_em' => now(),
        ]);
        DB::table('leads')->insert([
            'campanha_id' => $c, 'telefone' => '5511999999999',
            'dados_extras' => json_encode(['nome' => 'A', 'cpf' => '1']),
            'status' => 1, 'criado_em' => now(),
        ]);

        Livewire::test(CampanhaForm::class, ['id' => $c])
            ->assertSee('Colunas disponíveis')
            ->assertSee('nome')
            ->assertSee('cpf');
    }

    /**
     * E DENUNCIA conjunto divergente, que é o sintoma de importação sem cabeçalho.
     *
     * Medido em 25/08/2026: a Campanha 3 tinha 29.554 Leads com `["cpf","nome"]` e
     * 9.997 com `["MILTON NATAL","247.942.998-93"]` — o nome e o CPF de uma pessoa
     * virados em nome de coluna. Um mapeamento serviria a uns e falharia nos outros.
     */
    #[Test]
    public function avisa_quando_os_leads_tem_conjuntos_de_colunas_diferentes(): void
    {
        $c = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 0, 'criada_em' => now(),
        ]);
        DB::table('leads')->insert([
            ['campanha_id' => $c, 'telefone' => '5511999999991',
                'dados_extras' => json_encode(['nome' => 'A', 'cpf' => '1']),
                'status' => 1, 'criado_em' => now()],
            ['campanha_id' => $c, 'telefone' => '5511999999992',
                'dados_extras' => json_encode(['MILTON NATAL' => 'B', '247.942.998-93' => '2']),
                'status' => 1, 'criado_em' => now()],
        ]);

        Livewire::test(CampanhaForm::class, ['id' => $c])
            ->assertSee('conjuntos de colunas')
            ->assertSee('sem linha de cabeçalho');
    }

    /** Campanha nova não tem Lead, e a tela diz isso em vez de mostrar lista vazia. */
    #[Test]
    public function campanha_sem_leads_explica_em_vez_de_listar_nada(): void
    {
        Livewire::test(CampanhaForm::class)
            ->assertSee('Nenhum Lead importado ainda');
    }
}
