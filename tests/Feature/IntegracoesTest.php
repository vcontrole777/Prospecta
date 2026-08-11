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
use App\Worker\Transporte;
use Tests\TestCase;
use Tests\Unit\Worker\TransporteFalso;

final class IntegracoesTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAAG-token-secreto-do-cliente-987654';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /**
     * 🔴 A DESCOBERTA VAI POR DUBLÊ, SEMPRE.
     *
     * A tela consulta a Meta para achar o Phone Number ID. Sem o dublê, o teste sairia para a
     * internet com um token inventado — e passaria ou falharia por rede, não por regra.
     *
     * @param  list<array{id:string, numero:string, nome:string}>  $numeros
     */
    private function metaResponde(array $numeros = [['id' => '555000', 'numero' => '+55 11 9', 'nome' => 'Empresa']]): TransporteFalso
    {
        $falso = new TransporteFalso();
        $falso->respostasGet = [
            ['http' => 200, 'corpo' => (string) json_encode(['data' => [
                'is_valid' => true,
                'granular_scopes' => [['scope' => 'whatsapp_business_messaging', 'target_ids' => ['waba-1']]],
            ]]), 'erro' => null],
            ['http' => 200, 'corpo' => (string) json_encode(['data' => array_map(
                static fn (array $n): array => [
                    'id' => $n['id'],
                    'display_phone_number' => $n['numero'],
                    'verified_name' => $n['nome'],
                ],
                $numeros,
            )]), 'erro' => null],
        ];

        $this->app->instance(Transporte::class, $falso);

        return $falso;
    }

    /** A Meta recusando, para o caminho do campo manual. */
    private function metaRecusa(string $motivo = 'Invalid OAuth access token'): void
    {
        $falso = new TransporteFalso();
        $falso->respostasGet = [
            ['http' => 400, 'corpo' => (string) json_encode(['error' => ['message' => $motivo]]), 'erro' => null],
        ];

        $this->app->instance(Transporte::class, $falso);
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
            ->assertSet('phone_number_id', '555000', 'o número descoberto fica selecionado');
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
            ->set('credenciais.app_secret', 'x')
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


    // ------------------------------------------------- o que a tela deixou de pedir

    /**
     * 🔴 SÓ DOIS CAMPOS PEDIDOS, e é a razão desta mudança.
     *
     * Eram cinco: token, Phone Number ID, versão da API, App Secret e Verify Token. Três não
     * precisavam ser perguntados, e perguntar menos é perguntar menos coisa errada.
     */
    #[Test]
    public function pede_apenas_token_e_app_secret(): void
    {
        $campos = array_keys(Integracoes::CAMPOS['whatsapp']['meta']);

        $this->assertSame(['token', 'app_secret'], $campos);
    }

    /**
     * 🔴 O PHONE NUMBER ID É DESCOBERTO PELO TOKEN.
     *
     * Era um número de 15 dígitos copiado de outro painel: erra sem avisar, e o erro aparece
     * só na primeira mensagem que não sai — como `Unsupported get request`, que não menciona
     * número errado.
     */
    #[Test]
    public function descobre_o_phone_number_id_pelo_token(): void
    {
        $falso = $this->metaResponde([['id' => '999888777', 'numero' => '+55 11 3333-4444', 'nome' => 'Loja']]);

        $this->cadastrarSemDuble()->assertHasNoErrors();

        $cred = (new Cofre((string) config('app.key')))
            ->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'));

        $this->assertSame('999888777', $cred['phone_number_id'] ?? null);

        // Dois passos: `debug_token` acha as contas, `phone_numbers` acha os números.
        $this->assertCount(2, $falso->urlsGet);
        $this->assertStringContainsString('debug_token', $falso->urlsGet[0]);
        $this->assertStringContainsString('/phone_numbers', $falso->urlsGet[1]);
    }

    /** O verify token é GERADO, e não pedido: não havia nada para o operador escolher. */
    #[Test]
    public function gera_o_verify_token_e_a_versao_da_api(): void
    {
        $this->cadastrar()->assertHasNoErrors();

        $cred = (new Cofre((string) config('app.key')))
            ->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'));

        $this->assertSame('v21.0', $cred['versao_api'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) ($cred['verify_token'] ?? ''));
    }

    /**
     * 🔴 O VERIFY TOKEN GERADO É PRESERVADO entre gravações.
     *
     * O operador já o copiou para o painel da Meta. Gerar um novo a cada gravação faria o
     * webhook parar de ser aceito sem ninguém ter mexido nele — e o sintoma seria a Meta
     * recusando a verificação, que não aponta para cá.
     */
    #[Test]
    public function editar_nao_troca_o_verify_token(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');
        $cofre = new Cofre((string) config('app.key'));

        $antes = $cofre->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'))['verify_token'];

        $this->metaResponde();
        Livewire::test(Integracoes::class)
            ->call('editar', $id)
            ->set('nome', 'Outro nome')
            ->call('salvar')
            ->assertHasNoErrors();

        $depois = $cofre->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'))['verify_token'];

        $this->assertSame($antes, $depois);
    }

    /**
     * Com mais de um número, o operador ESCOLHE — e nada é gravado antes disso.
     *
     * Gravar um dos dois por conta própria mandaria mensagem pelo número errado, com a conta
     * certa: erro que só aparece quando alguém repara no remetente.
     */
    #[Test]
    public function com_varios_numeros_pergunta_qual_e_nao_grava(): void
    {
        $this->metaResponde([
            ['id' => 'aaa', 'numero' => '+55 11 1111-1111', 'nome' => 'Matriz'],
            ['id' => 'bbb', 'numero' => '+55 21 2222-2222', 'nome' => 'Filial'],
        ]);

        $componente = $this->cadastrarSemDuble();

        $this->assertSame(0, DB::table('integracao')->count(), 'não grava sem a escolha');
        $componente->assertSee('+55 11 1111-1111')->assertSee('Filial');

        // Escolhido, grava — sem consultar a Meta de novo.
        $componente->set('phone_number_id', 'bbb')->call('salvar')->assertHasNoErrors();

        $cred = (new Cofre((string) config('app.key')))
            ->decifrarDaColuna((string) DB::table('integracao')->value('credenciais'));
        $this->assertSame('bbb', $cred['phone_number_id'] ?? null);
    }

    /**
     * 🔴 AUTOMATIZAR NÃO PODE VIRAR BECO SEM SAÍDA.
     *
     * Token com escopo estreito, conta em revisão e falha de rede são casos reais. A tela diz
     * o motivo que a Meta deu e revela o campo manual, em vez de insistir numa busca que não
     * funciona.
     */
    #[Test]
    public function quando_a_descoberta_falha_mostra_o_motivo_e_libera_o_campo_manual(): void
    {
        $this->metaRecusa('Invalid OAuth access token');

        $componente = $this->cadastrarSemDuble();

        $this->assertSame(0, DB::table('integracao')->count());
        $componente->assertSee('Invalid OAuth access token')
            ->assertSet('numeroManual', true);

        // Preenchido à mão, grava — e não consulta a Meta de novo.
        $componente->set('phone_number_id', '123456')->call('salvar')->assertHasNoErrors();
        $this->assertSame(1, DB::table('integracao')->count());
    }

    /**
     * A caixa do webhook mostra a URL e AVISA que a Meta recusa sem HTTPS.
     *
     * Ela exige certificado válido e não aceita HTTP. Sem o aviso, o operador cadastraria,
     * levaria erro no painel dela e procuraria o problema do nosso lado.
     */
    #[Test]
    public function mostra_o_que_colar_na_meta_e_avisa_sobre_https(): void
    {
        $this->cadastrar();
        $id = (int) DB::table('integracao')->value('id');
        Livewire::test(Integracoes::class)->call('usar', $id);

        Livewire::test(Integracoes::class)
            ->assertSee('/webhook/whatsapp')
            ->assertSee('Cadastre o webhook no painel da Meta')
            ->assertSee('A Meta vai recusar esta URL');
    }

    private function cadastrar(): \Livewire\Features\SupportTesting\Testable
    {
        // A tela descobre o Phone Number ID pelo token, então todo cadastro passa pela Meta —
        // e no teste passa pelo dublê.
        $this->metaResponde();

        return $this->cadastrarSemDuble();
    }

    /** Para quem já preparou a resposta da Meta e quer controlá-la. */
    private function cadastrarSemDuble(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Integracoes::class)
            ->set('nome', 'WhatsApp principal')
            ->set('credenciais.token', self::TOKEN)
            // O webhook exige estes dois: sem app secret o endpoint recusa TODO
            // evento, e o verify token é o aperto de mão.
            ->set('credenciais.app_secret', 'app-secret-da-meta')
            ->call('salvar');
    }
}
