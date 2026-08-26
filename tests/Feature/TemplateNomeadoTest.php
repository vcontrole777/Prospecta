<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dominio\ErroDeDominio;
use App\Dominio\Placeholder;
use App\Livewire\CampanhaForm;
use App\Models\User;
use App\Worker\Whatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Worker\TransporteFalso;

/**
 * Template com parâmetro NOMEADO — `{{nome}}` no lugar de `{{1}}`.
 *
 * 🔴 O PRODUTO SÓ FUNCIONAVA COM POSICIONAL, e não avisava que era assim. O contador
 * de parâmetros procurava dígitos, então template nomeado saía como "zero
 * parâmetros"; a validação RECUSAVA quem tentasse mapear um; e o envio ia sem o
 * corpo — recusado com o mesmo `(#132000)` que custou a investigação de 25/08/2026.
 *
 * Os quatro templates da conta real são POSICIONAIS, então nada estava quebrado.
 * Isto é a armadilha fechada antes de alguém cair nela.
 */
final class TemplateNomeadoTest extends TestCase
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

    /** @param list<string> $nomes */
    private function template(array $nomes): array
    {
        return [[
            'chave' => 'tpl|pt_BR', 'nome' => 'tpl', 'idioma' => 'pt_BR',
            'parametros' => count($nomes), 'nomes' => $nomes,
            'cabecalho' => '', 'corpo' => 'Olá!', 'rotulo' => 'tpl',
        ]];
    }

    // ---- o mapa se autodescreve ------------------------------------------

    /**
     * 🔴 A CHAVE DIZ O FORMATO, e é por isso que não há coluna nova no esquema.
     *
     * O worker não fala com a Meta — só tem o `campanha.template_mapa` —, então a
     * distinção precisa caber no próprio valor.
     */
    #[Test]
    public function a_chave_do_mapa_distingue_os_dois_formatos(): void
    {
        $p = new Placeholder();

        $this->assertFalse($p->mapaEhNomeado(['1' => 'nome', '2' => 'cpf']));
        $this->assertTrue($p->mapaEhNomeado(['nome' => 'cliente']));

        // Mapa vazio é POSICIONAL: template sem parâmetro é o caso mais comum, e é o
        // caminho que já existia.
        $this->assertFalse($p->mapaEhNomeado([]));
    }

    #[Test]
    public function resolve_os_valores_por_nome(): void
    {
        $this->assertSame(
            ['nome' => 'Karen', 'documento' => '123'],
            (new Placeholder())->parametrosNomeados(
                ['nome' => 'cliente', 'documento' => 'cpf'],
                ['cliente' => 'Karen', 'cpf' => '123'],
            ),
        );
    }

    /**
     * As três recusas valem igual nos dois formatos — elas moram no mesmo lugar.
     *
     * Duplicá-las entre o caminho posicional e o nomeado é que faria elas divergirem.
     */
    #[Test]
    public function coluna_ausente_e_valor_vazio_falham_igual_no_nomeado(): void
    {
        $p = new Placeholder();

        $this->expectException(ErroDeDominio::class);
        $p->parametrosNomeados(['nome' => 'cliente'], ['outra' => 'x']);
    }

    #[Test]
    public function valor_vazio_no_nomeado_tambem_falha(): void
    {
        $this->expectException(ErroDeDominio::class);
        (new Placeholder())->parametrosNomeados(['nome' => 'cliente'], ['cliente' => '   ']);
    }

    /**
     * 🔴 NOMEADO NÃO EXIGE SEQUÊNCIA, e o posicional exige.
     *
     * O caminho posicional recusa lacuna porque a Meta recebe por POSIÇÃO e uma
     * lacuna manda o valor no lugar errado. Nomeado não tem ordem: cada parâmetro se
     * identifica sozinho, e exigir sequência ali seria inventar uma regra que a
     * plataforma não tem.
     */
    #[Test]
    public function nomeado_aceita_qualquer_conjunto_de_nomes(): void
    {
        $r = (new Placeholder())->parametrosNomeados(
            ['saldo' => 'pontos', 'nome' => 'cliente'],
            ['pontos' => '500', 'cliente' => 'Ana'],
        );

        $this->assertSame(['saldo' => '500', 'nome' => 'Ana'], $r);
    }

    // ---- envio ------------------------------------------------------------

    /** `parameter_name` viaja junto do valor. Sem ele a Meta recusa com 132000. */
    #[Test]
    public function o_envio_nomeado_leva_parameter_name(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');

        (new Whatsapp($t, 'tok', '123', 'v21.0'))->enviarTemplate(
            '5511999999999', 'tpl', 'pt_BR',
            ['Karen', '123'],
            null, null,
            ['nome', 'documento'],
        );

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertSame([[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'Karen', 'parameter_name' => 'nome'],
                ['type' => 'text', 'text' => '123', 'parameter_name' => 'documento'],
            ],
        ]], $tpl['components']);
    }

    /**
     * 🔴 E O POSICIONAL CONTINUA SEM A CHAVE.
     *
     * Mandar `parameter_name` num template posicional é recusa igual — o erro da Meta
     * é o mesmo nos dois sentidos. Este teste é a trava contra "mandar sempre".
     */
    #[Test]
    public function o_envio_posicional_nao_leva_parameter_name(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');

        (new Whatsapp($t, 'tok', '123', 'v21.0'))
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', ['Karen'], null, null);

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertSame([[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => 'Karen']],
        ]], $tpl['components']);
    }

    // ---- formulário -------------------------------------------------------

    /**
     * 🔴 O MAPA SAI COM O NOME NA CHAVE, e é isso que ensina o worker depois.
     */
    #[Test]
    public function grava_o_mapa_com_a_chave_nomeada(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', $this->template(['nome', 'documento']))
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'C')
            ->set('audio_mensagem_id', $this->audio)
            ->set('parametros', ['cliente', 'cpf'])
            ->call('salvar')
            ->assertHasNoErrors();

        // Decodificado, e não a string: o MySQL normaliza o JSON com espaços, e
        // comparar texto testaria a formatação dele em vez do nosso mapa.
        $this->assertSame(
            ['nome' => 'cliente', 'documento' => 'cpf'],
            json_decode((string) DB::table('campanha')->value('template_mapa'), true),
        );
    }

    /** Posicional continua gravando por posição — as Campanhas que existem não mudam. */
    #[Test]
    public function posicional_continua_gravando_por_posicao(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', $this->template(['1', '2']))
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'C')
            ->set('audio_mensagem_id', $this->audio)
            ->set('parametros', ['nome', 'cpf'])
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame(
            ['1' => 'nome', '2' => 'cpf'],
            json_decode((string) DB::table('campanha')->value('template_mapa'), true),
        );
    }

    /**
     * 🔴 ESCOLHER TEMPLATE NOMEADO CRIA AS LINHAS SOZINHO.
     *
     * No modo nomeado o botão "+ parâmetro" não existe — a quantidade e os nomes vêm
     * do template. Sem criar as linhas aqui, a tela pediria "informe a coluna de cada
     * um" e não ofereceria campo nenhum para informar.
     */
    #[Test]
    public function escolher_template_nomeado_abre_uma_linha_por_parametro(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', $this->template(['nome', 'documento']))
            ->set('template_escolhido', 'tpl|pt_BR')
            ->assertSet('parametros', ['', ''])
            ->assertSee('por nome')
            ->assertSee('documento');
    }

    /** A conta de parâmetros vale igual: nem a mais, nem a menos. */
    #[Test]
    public function a_validacao_da_conta_vale_no_nomeado(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('templates', $this->template(['nome', 'documento']))
            ->set('template_escolhido', 'tpl|pt_BR')
            ->set('template_nome', 'tpl')
            ->set('nome', 'C')
            ->set('audio_mensagem_id', $this->audio)
            ->set('parametros', ['cliente'])
            ->call('salvar')
            ->assertHasErrors('parametros');
    }

    /**
     * 🔴 SALVAR SEM A LISTA DE TEMPLATES NÃO PODE VIRAR POSICIONAL.
     *
     * `salvar()` monta a chave a partir dos nomes do template. Sem a lista — Meta
     * fora do ar, token vencido — esses nomes vêm vazios, e a chave cairia na
     * POSIÇÃO: `{"nome":"cliente"}` viraria `{"1":"cliente"}` só porque o operador
     * mexeu no horário de trabalho. O worker leria como posicional, mandaria sem
     * `parameter_name`, e a Meta recusaria a Campanha inteira.
     */
    #[Test]
    public function salvar_sem_a_lista_preserva_o_formato_nomeado(): void
    {
        $id = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 0, 'criada_em' => now(),
            'template_nome' => 'tpl', 'template_idioma' => 'pt_BR',
            'template_mapa' => '{"nome":"cliente","documento":"cpf"}',
            'audio_mensagem_id' => $this->audio,
        ]);

        // Sem `set('templates', ...)`: é exatamente o caso de a lista não carregar.
        Livewire::test(CampanhaForm::class, ['id' => $id])
            ->call('salvar')
            ->assertHasNoErrors();

        $mapa = json_decode((string) DB::table('campanha')->where('id', $id)->value('template_mapa'), true);
        $this->assertIsArray($mapa);
        $this->assertSame(['nome', 'documento'], array_keys($mapa), 'continua nomeado');
    }

    /**
     * 🔴 REABRIR MAPA NOMEADO NÃO PODE ORDENAR POR `(int)`.
     *
     * `(int)'nome'` e `(int)'cpf'` são ambos ZERO: ordenar assim COLIDE as colunas e
     * o operador reabre a Campanha com o mapeamento destruído. Foi o que este teste
     * pegou na primeira escrita — devolvia `['cpf', '']` no lugar das duas colunas.
     *
     * Aqui a lista de templates não está carregada, que é o caso de a Meta estar fora
     * do ar ou o token vencido. Nada se perde e nada colide; a ordem do template é
     * aplicada quando ela existir.
     *
     * 🔴 A ORDEM DE VOLTA É A DO MySQL, e não a que foi gravada. A coluna é `json` e
     * ele NORMALIZA as chaves — por tamanho e depois alfabeticamente. Gravamos
     * `{"documento":…,"nome":…}` e volta `{"nome":…,"documento":…}`, por isso o
     * esperado abaixo é `cliente` antes de `cpf`. Medido, não suposto.
     */
    #[Test]
    public function reabrir_mapa_nomeado_preserva_as_colunas(): void
    {
        $id = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 0, 'criada_em' => now(),
            'template_nome' => 'tpl', 'template_idioma' => 'pt_BR',
            // Gravado FORA da ordem do template, de propósito.
            'template_mapa' => '{"documento":"cpf","nome":"cliente"}',
        ]);

        Livewire::test(CampanhaForm::class, ['id' => $id])
            ->assertSet('parametros', ['cliente', 'cpf']);
    }
}
