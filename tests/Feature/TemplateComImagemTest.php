<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Imagem;
use App\Livewire\CampanhaForm;
use App\Models\User;
use App\Whatsapp\TemplatesAprovados;
use App\Worker\Transporte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O conserto do `(#132000) Number of parameters does not match the expected number
 * of params`, medido em 25/08/2026 contra a conta real.
 *
 * O erro tinha DUAS causas somadas no mesmo código, e a mensagem da Meta só falava
 * de uma delas — "parameters" — o que mandou a investigação para o lado errado:
 *
 *   1. `enviarTemplate` nunca mandava o componente `header`, e dois dos quatro
 *      templates aprovados têm `HEADER format=IMAGE`.
 *   2. O formulário deixava salvar Campanha com menos parâmetros do que o template
 *      pede. A Campanha 3 tinha `template_mapa` NULL para um corpo `Olá, {{1}}!`.
 *
 * Esta suíte cobre a segunda causa e a leitura que a primeira exigiu; a montagem do
 * envio está em `Tests\Unit\Worker\WhatsappCabecalhoTest`.
 */
final class TemplateComImagemTest extends TestCase
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

    /** Transporte que devolve a resposta de `message_templates` da Meta. */
    private function transporte(string $json): Transporte
    {
        return new class($json) implements Transporte
        {
            public function __construct(private readonly string $json)
            {
            }

            /**
             * @param array<string,mixed> $corpo
             * @param array<string,string> $cabecalhos
             * @return array{http:int, corpo:string, erro:?string}
             */
            public function postJson(string $url, array $corpo, array $cabecalhos): array
            {
                return ['http' => 200, 'corpo' => '{}', 'erro' => null];
            }

            /**
             * @param array<string,string> $cabecalhos
             * @return array{http:int, corpo:string, erro:?string}
             */
            public function getJson(string $url, array $cabecalhos): array
            {
                return ['http' => 200, 'corpo' => $this->json, 'erro' => null];
            }
        };
    }

    /**
     * 🔴 A LEITURA QUE FALTAVA. `TemplatesAprovados` filtrava `type === 'BODY'` e
     * ignorava o resto, então a tela não tinha como saber que um template exige
     * imagem — nem como pedi-la. O quadro abaixo é o de `abordagem_liv_pts_2`.
     */
    #[Test]
    public function le_o_formato_do_cabecalho_do_template(): void
    {
        $json = json_encode(['data' => [
            [
                'name' => 'abordagem_liv_pts_2',
                'status' => 'APPROVED',
                'language' => 'pt_BR',
                'components' => [
                    ['type' => 'HEADER', 'format' => 'IMAGE'],
                    ['type' => 'BODY', 'text' => 'Olá, {{1}}! Há pontos disponíveis.'],
                ],
            ],
            [
                'name' => 'abordagem_liv_link',
                'status' => 'APPROVED',
                'language' => 'pt_BR',
                'components' => [['type' => 'BODY', 'text' => 'Olá! Consulte seu saldo.']],
            ],
        ]], JSON_THROW_ON_ERROR);

        $r = (new TemplatesAprovados($this->transporte($json)))->listar('tok', 'waba');

        $this->assertTrue($r['ok']);
        $this->assertSame('IMAGE', $r['templates'][0]['cabecalho'], 'tem cabeçalho de imagem');
        $this->assertSame(1, $r['templates'][0]['parametros']);
        $this->assertSame('', $r['templates'][1]['cabecalho'], 'sem cabeçalho nenhum');
    }

    /**
     * 🔴 O DEFEITO QUE DEIXOU A CAMPANHA 3 NASCER IMPOSSÍVEL.
     *
     * Ela foi salva com zero parâmetros para um template que pede `{{1}}`, e só
     * descobriu na primeira mensagem. A contagem esperada já estava carregada na
     * lista de templates; só não era comparada com nada.
     */
    #[Test]
    public function recusa_quando_a_conta_de_parametros_nao_bate(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', [[
                'chave' => 'tpl|pt_BR', 'nome' => 'tpl', 'idioma' => 'pt_BR',
                'parametros' => 1, 'nomes' => ['1'], 'cabecalho' => '', 'corpo' => 'Olá, {{1}}!', 'rotulo' => 'tpl',
            ]])
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'Campanha')
            ->set('audio_mensagem_id', $this->audio)
            ->set('parametros', [])
            ->call('salvar')
            ->assertHasErrors('parametros');

        $this->assertSame(0, DB::table('campanha')->count(), 'não pode ter nascido');
    }

    /** E parâmetro a mais é recusado pelo mesmo motivo: a Meta conta os dois lados. */
    #[Test]
    public function recusa_parametro_a_mais(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', [[
                'chave' => 'tpl|pt_BR', 'nome' => 'tpl', 'idioma' => 'pt_BR',
                'parametros' => 0, 'nomes' => [], 'cabecalho' => '', 'corpo' => 'Sem parâmetro.', 'rotulo' => 'tpl',
            ]])
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'Campanha')
            ->set('audio_mensagem_id', $this->audio)
            ->set('parametros', ['Nome'])
            ->call('salvar')
            ->assertHasErrors('parametros');
    }

    /**
     * Template digitado à mão NÃO entra na conferência.
     *
     * Ele não está na lista da Meta, então não há contagem para comparar — e inventar
     * uma recusaria configuração legítima. É a mesma disciplina de não afirmar o que
     * não se mediu.
     */
    #[Test]
    public function template_manual_escapa_da_conferencia(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Campanha')
            ->set('audio_mensagem_id', $this->audio)
            ->set('template_nome', 'digitado_a_mao')
            ->set('parametros', ['Nome', 'CPF'])
            ->call('salvar')
            ->assertHasNoErrors();
    }

    #[Test]
    public function cobra_a_imagem_quando_o_template_tem_cabecalho_de_imagem(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', [[
                'chave' => 'tpl|pt_BR', 'nome' => 'tpl', 'idioma' => 'pt_BR',
                'parametros' => 0, 'nomes' => [], 'cabecalho' => 'IMAGE', 'corpo' => 'Oi.', 'rotulo' => 'tpl',
            ]])
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'Campanha')
            ->set('audio_mensagem_id', $this->audio)
            ->call('salvar')
            ->assertHasErrors('imagem_id');
    }

    /**
     * 🔴 IMAGEM ESCOLHIDA PARA TEMPLATE QUE NÃO PEDE É DESCARTADA NA GRAVAÇÃO.
     *
     * Guardá-la faria o envio mandar um `header` que o template não tem, e a Meta
     * recusa isso com o MESMO 132000 — o erro ao contrário. O operador pode ter
     * escolhido a imagem e depois trocado de template; a tela não pode confiar que o
     * estado ficou coerente.
     */
    #[Test]
    public function imagem_e_descartada_quando_o_template_nao_pede_cabecalho(): void
    {
        $imagem = (int) DB::table('imagem')->insertGetId([
            'nome' => 'Banner', 'token' => str_repeat('a', 32),
            'arquivo' => '1.jpg', 'mime' => 'image/jpeg', 'bytes' => 10, 'criado_em' => now(),
        ]);

        Livewire::test(CampanhaForm::class)
            ->set('templates', [[
                'chave' => 'tpl|pt_BR', 'nome' => 'tpl', 'idioma' => 'pt_BR',
                'parametros' => 0, 'nomes' => [], 'cabecalho' => '', 'corpo' => 'Oi.', 'rotulo' => 'tpl',
            ]])
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'Campanha')
            ->set('audio_mensagem_id', $this->audio)
            ->set('imagem_id', $imagem)
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertNull(DB::table('campanha')->value('imagem_id'));
    }

    /**
     * 🔴 A ROTA DA IMAGEM É PÚBLICA, e o teste existe para isso ficar deliberado.
     *
     * Quem busca é a Meta, que não faz login. Se alguém puser esta rota atrás de
     * `auth` para "arrumar", o cabeçalho passa a chegar quebrado e o disparo é
     * recusado — falha silenciosa e cara. Este teste é o aviso.
     */
    #[Test]
    public function a_imagem_e_servida_sem_login(): void
    {
        $dir = Imagem::diretorio();
        if (! is_dir($dir)) {
            mkdir($dir, 0o750, true);
        }
        file_put_contents($dir . '/9001.jpg', 'conteudo-falso');

        DB::table('imagem')->insert([
            'id' => 9001, 'nome' => 'Banner', 'token' => str_repeat('b', 32),
            'arquivo' => '9001.jpg', 'mime' => 'image/jpeg', 'bytes' => 14, 'criado_em' => now(),
        ]);

        // Sem `actingAs`: é exatamente assim que a Meta chega.
        app('auth')->guard()->logout();

        $this->get('/imagem/' . str_repeat('b', 32))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        @unlink($dir . '/9001.jpg');
    }

    /** Token que não existe é 404 — e não pista de que a galeria existe. */
    #[Test]
    public function token_desconhecido_e_404(): void
    {
        $this->get('/imagem/' . str_repeat('c', 32))->assertNotFound();
    }
}
