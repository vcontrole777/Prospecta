<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Integracoes;
use App\Models\User;
use App\Seguranca\Cofre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IntegracoesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAG-token-secreto-do-cliente-987654';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function cadastra_cifrando_a_credencial(): void
    {
        $this->cadastrar()->assertHasNoErrors();

        $coluna = (string) DB::table('integracao')->value('credenciais');

        // 🔴 O texto claro NÃO pode estar na coluna. Vazamento de banco vira
        // prejuízo direto: este token manda mensagem no nome da empresa.
        $this->assertStringNotContainsString(self::TOKEN, $coluna);
        $this->assertStringContainsString('cifrado', $coluna, 'o envelope JSON que a coluna JSON aceita');

        // E abre com a APP_KEY da instância.
        $cred = (new Cofre((string) config('app.key')))->decifrarDaColuna($coluna);
        $this->assertSame(self::TOKEN, $cred['token'] ?? null);
        $this->assertSame('555000', $cred['phone_number_id'] ?? null);
    }

    #[Test]
    public function a_lista_mostra_o_segredo_mascarado_e_nunca_o_claro(): void
    {
        $this->cadastrar();

        $html = Livewire::test(Integracoes::class)->html();

        $this->assertStringNotContainsString(self::TOKEN, $html, 'segredo no HTML fica no histórico do navegador');
        $this->assertStringContainsString('7654', $html, 'os 4 últimos, para reconhecer a chave');
        $this->assertStringNotContainsString('EAAG', $html, 'o prefixo identifica tipo e conta — não aparece');
        $this->assertStringContainsString('555000', $html, 'o que não é segredo aparece inteiro');
    }

    #[Test]
    public function editar_nao_carrega_o_segredo_no_formulario(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');

        Livewire::test(Integracoes::class)
            ->call('editar', $id)
            ->assertSet('nome', 'WhatsApp principal')
            ->assertSet('credenciais.token', '', 'o segredo não volta para a tela')
            ->assertSet('credenciais.phone_number_id', '555000');
    }

    /**
     * 🔴 A REGRA QUE IMPEDE A EDIÇÃO DE DESTRUIR O QUE NÃO TOCOU.
     *
     * O formulário nunca recebe o segredo decifrado. Se salvar com o campo vazio
     * apagasse o token, corrigir um erro de digitação no NOME derrubaria o envio de
     * mensagem da instância — e o sintoma apareceria depois, no worker, como
     * credencial inválida.
     */
    #[Test]
    public function segredo_em_branco_na_edicao_mantem_o_atual(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');

        Livewire::test(Integracoes::class)
            ->call('editar', $id)
            ->set('nome', 'WhatsApp principal — renomeado')
            ->call('salvar')
            ->assertHasNoErrors();

        $cred = (new Cofre((string) config('app.key')))
            ->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'));

        $this->assertSame('WhatsApp principal — renomeado', DB::table('integracao')->value('nome'));
        $this->assertSame(self::TOKEN, $cred['token'] ?? null, 'o token sobreviveu à edição do nome');
        $this->assertSame(1, DB::table('integracao')->count());
    }

    #[Test]
    public function segredo_preenchido_na_edicao_substitui(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');

        Livewire::test(Integracoes::class)
            ->call('editar', $id)
            ->set('credenciais.token', 'EAAG-token-novo-111')
            ->call('salvar')
            ->assertHasNoErrors();

        $cred = (new Cofre((string) config('app.key')))
            ->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'));

        $this->assertSame('EAAG-token-novo-111', $cred['token'] ?? null);
    }

    /** Na CRIAÇÃO o segredo é obrigatório: não há o que manter. */
    #[Test]
    public function exige_o_segredo_na_criacao(): void
    {
        Livewire::test(Integracoes::class)
            ->set('nome', 'Sem token')
            ->set('credenciais.token', '')
            ->set('credenciais.phone_number_id', '555000')
            ->set('credenciais.app_secret', 'x')
            ->set('credenciais.verify_token', 'y')
            ->call('salvar')
            ->assertHasErrors('credenciais.token');

        $this->assertSame(0, DB::table('integracao')->count());
    }

    #[Test]
    public function usar_poe_em_vigor_na_configuracao_global(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');

        Livewire::test(Integracoes::class)->call('usar', $id);

        $this->assertSame($id, (int) DB::table('configuracao')->where('id', 1)->value('integracao_whatsapp_id'));
    }

    #[Test]
    public function desativar_e_reativar(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');

        Livewire::test(Integracoes::class)->call('desativar', $id);
        $this->assertSame(0, (int) DB::table('integracao')->value('ativo'));

        Livewire::test(Integracoes::class)->call('reativar', $id);
        $this->assertSame(1, (int) DB::table('integracao')->value('ativo'));
    }

    /** Trocar o tipo troca o provedor: manter o antigo pediria campos inexistentes. */
    #[Test]
    public function trocar_o_tipo_troca_o_provedor_e_os_campos(): void
    {
        Livewire::test(Integracoes::class)
            ->assertSet('provedor', 'meta')
            ->set('tipo', 'tts')
            ->assertSet('provedor', 'elevenlabs')
            // Padrões vindos da documentação lida: alaw_8000 dispensa conversão e
            // flash_v2_5 é ~75 ms com metade do preço por caractere.
            ->assertSet('credenciais.output_format', 'alaw_8000')
            ->assertSet('credenciais.model_id', 'eleven_flash_v2_5');
    }

    /** Tipo que nenhum processo consome ainda avisa, em vez de fingir que funciona. */
    #[Test]
    public function avisa_quando_o_tipo_ainda_nao_tem_consumidor(): void
    {
        Livewire::test(Integracoes::class)
            ->assertDontSee('Nenhum processo do produto usa integração de')
            ->set('tipo', 'tts')
            ->assertSee('Nenhum processo do produto usa integração de');
    }

    #[Test]
    public function credencial_cifrada_com_outra_chave_aparece_como_ilegivel(): void
    {
        $outra = new Cofre('base64:' . base64_encode(str_repeat('z', 32)));

        DB::table('integracao')->insert([
            'tipo' => 'whatsapp', 'provedor' => 'meta', 'nome' => 'Chave antiga',
            'credenciais' => $outra->cifrarParaColuna(['token' => 'x']), 'ativo' => 1,
        ]);

        Livewire::test(Integracoes::class)
            ->assertSee('Credencial ilegível', escape: false)
            ->assertSee('APP_KEY');
    }

    /**
     * 🔴 O ELO QUE QUEBRARIA EM SILÊNCIO.
     *
     * O painel cifra com o `Cofre`; o worker lê com PDO puro e bootstrap mínimo. Se
     * a convenção do envelope divergisse entre os dois, o sintoma NÃO seria erro de
     * cifra — seria a Meta devolvendo "token inválido", e o operador trocaria uma
     * chave que estava correta.
     *
     * Aqui o `FilaDeAcoes` é o objeto de produção, com o mesmo `Cofre`. Que a
     * conexão separada do worker enxergue o dado está provado em `BancoConfiguracaoTest`.
     */
    #[Test]
    public function o_worker_le_o_que_o_painel_escreveu(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');
        Livewire::test(Integracoes::class)->call('usar', $id);

        $fila = new \App\Worker\FilaDeAcoes(
            DB::connection()->getPdo(),
            new Cofre((string) config('app.key')),
        );

        $cred = $fila->credenciaisWhatsapp();

        $this->assertNotNull($cred, 'o worker precisa achar a integração em vigor');
        $this->assertSame(self::TOKEN, $cred['token']);
        $this->assertSame('555000', $cred['numero_id']);
        $this->assertSame('v21.0', $cred['versao_api'], 'o padrão preenchido pela tela');
    }

    /** Integração DESATIVADA não é usada, mesmo estando selecionada em vigor. */
    #[Test]
    public function o_worker_ignora_integracao_desativada(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');
        Livewire::test(Integracoes::class)->call('usar', $id);
        Livewire::test(Integracoes::class)->call('desativar', $id);

        $fila = new \App\Worker\FilaDeAcoes(
            DB::connection()->getPdo(),
            new Cofre((string) config('app.key')),
        );

        $this->assertNull($fila->credenciaisWhatsapp());
    }

    #[Test]
    public function a_pagina_renderiza_inteira(): void
    {
        $this->get(route('integracoes'))
            ->assertOk()
            ->assertSee('Integrações', escape: false)
            ->assertSee('Nenhuma integração cadastrada', escape: false)
            ->assertDontSee('Unclosed');
    }

    private function cadastrar(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Integracoes::class)
            ->set('nome', 'WhatsApp principal')
            ->set('credenciais.token', self::TOKEN)
            ->set('credenciais.phone_number_id', '555000')
            // O webhook exige estes dois: sem app secret o endpoint recusa TODO
            // evento, e o verify token é o aperto de mão.
            ->set('credenciais.app_secret', 'app-secret-da-meta')
            ->set('credenciais.verify_token', 'token-do-webhook')
            ->call('salvar');
    }
}
