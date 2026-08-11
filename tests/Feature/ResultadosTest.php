<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Resultados;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResultadosTest extends TestCase
{
    use RefreshDatabase;

    private int $campanha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'Recadastramento', 'tipo' => 'captura', 'status' => 1,
        ]);
    }

    #[Test]
    public function campanha_inexistente_da_404(): void
    {
        $this->get('/campanhas/99999/resultados')->assertNotFound();
    }

    #[Test]
    public function mostra_a_contagem_por_desfecho(): void
    {
        $this->ligacao('5511900000001', 'captura_concluida', ['CPF' => '11144477735']);
        $this->ligacao('5511900000002', 'captura_concluida', ['CPF' => '11144477735']);
        $this->ligacao('5511900000003', 'erro', []);
        $this->ligacao('5511900000004', 'nao_atendeu', [], atendida: false);

        Livewire::test(Resultados::class, ['id' => $this->campanha])
            ->assertSee('Recadastramento')
            ->assertSee('Captura concluída (2)')
            ->assertSee('Erro (1)');
    }

    /**
     * 🔴 Ligação sem desfecho é NOMEADA, não escondida.
     *
     * `desfecho` fica nulo enquanto a chamada está em curso, e também quando ela morre
     * antes de concluir. Se a tela só somasse os desfechos, o total não fecharia com a
     * soma e o operador não teria como saber para onde foram as que faltam.
     */
    #[Test]
    public function ligacao_sem_desfecho_aparece_como_em_curso(): void
    {
        $this->ligacao('5511900000001', null, []);

        Livewire::test(Resultados::class, ['id' => $this->campanha])
            ->assertSee('sem desfecho')
            ->assertSee('em curso');
    }

    #[Test]
    public function o_filtro_de_desfecho_restringe_a_tabela(): void
    {
        $this->ligacao('5511911111111', 'captura_concluida', ['CPF' => '11144477735']);
        $this->ligacao('5511922222222', 'erro', []);

        Livewire::test(Resultados::class, ['id' => $this->campanha])
            ->assertSee('5511911111111')
            ->assertSee('5511922222222')
            ->set('desfecho', 'erro')
            ->assertSee('5511922222222')
            ->assertDontSee('5511911111111');
    }

    /**
     * Desfecho que não está no mapa é IGNORADO como filtro, e a tela mostra tudo.
     *
     * O valor vem da URL — é `#[Url]` —, então chega texto arbitrário. Pôr texto
     * arbitrário num `where` contra coluna ENUM faz o MySQL responder com aviso e zero
     * linhas: a tela diria "nenhuma ligação" para uma Campanha cheia, e a causa não
     * apareceria em lugar nenhum.
     */
    #[Test]
    public function desfecho_fora_do_mapa_nao_vira_where(): void
    {
        $this->ligacao('5511911111111', 'captura_concluida', ['CPF' => '11144477735']);

        Livewire::test(Resultados::class, ['id' => $this->campanha])
            ->set('desfecho', 'nao_existe_esse')
            ->assertSee('5511911111111');
    }

    #[Test]
    public function so_atendidas_esconde_quem_nunca_atendeu(): void
    {
        $this->ligacao('5511911111111', 'captura_concluida', ['CPF' => '11144477735']);
        $this->ligacao('5511922222222', 'nao_atendeu', [], atendida: false);

        Livewire::test(Resultados::class, ['id' => $this->campanha])
            ->set('somenteAtendidas', true)
            ->assertSee('5511911111111')
            ->assertDontSee('5511922222222');
    }

    /**
     * 🔴 O TESTE QUE JUSTIFICA A TELA INTEIRA.
     *
     * O CSV é POSICIONAL. Derivar as colunas das chaves do JSON de cada linha — o que é
     * a implementação óbvia — alinha certo enquanto todas as capturas são completas e
     * desalinha silenciosamente na primeira parcial: a Ligação que só respondeu o CPF
     * tem uma chave, a seguinte tem duas, e a matrícula de uma cai debaixo do cabeçalho
     * da outra. O arquivo abre, tem o número certo de linhas, e está errado.
     *
     * Aqui a segunda Ligação é parcial DE PROPÓSITO, e a coluna da matrícula tem que
     * ficar vazia em vez de sumir.
     */
    #[Test]
    public function o_csv_alinha_as_colunas_quando_a_captura_foi_parcial(): void
    {
        $this->campos(['CPF', 'Matricula', 'Nascimento']);

        $completa = $this->ligacao('5511911111111', 'captura_concluida', [
            'CPF' => '11144477735', 'Matricula' => '778899', 'Nascimento' => '01011990',
        ]);

        // Parcial: obrigatório esgotado no meio, então as respostas param no CPF.
        $parcial = $this->ligacao('5511922222222', 'captura_parcial', [
            'CPF' => '52998224725',
        ]);

        // 🔴 O CASO QUE DE VERDADE DESALINHA: campo OPCIONAL pulado no MEIO.
        //
        // `Fluxo::captura` faz `continue` quando o campo não é obrigatório e o Lead não
        // respondeu, e segue perguntando os seguintes. As respostas deixam de ser um
        // prefixo e ficam com um buraco — {CPF, Nascimento}. Derivando a coluna das
        // chaves da linha, o Nascimento cairia debaixo do cabeçalho "Matricula": uma data
        // gravada como matrícula, num arquivo que abre sem erro nenhum.
        $comBuraco = $this->ligacao('5511933333333', 'captura_concluida', [
            'CPF' => '39053344705', 'Nascimento' => '25121985',
        ]);

        $linhas = $this->csv();

        $this->assertSame(['CPF', 'Matricula', 'Nascimento'], array_slice($linhas[0], 13, 3));
        $colunas = count($linhas[0]);

        $porLigacao = [];
        foreach (array_slice($linhas, 1) as $linha) {
            // Toda linha tem a MESMA largura do cabeçalho. Sem esta asserção uma linha
            // curta passaria como "índice inexistente" em vez de falha de alinhamento.
            $this->assertCount($colunas, $linha);
            $porLigacao[$linha[0]] = $linha;
        }

        $this->assertSame(['11144477735', '778899', '01011990'], array_slice($porLigacao[(string) $completa], 13, 3));
        $this->assertSame(['52998224725', '', ''], array_slice($porLigacao[(string) $parcial], 13, 3));

        // O buraco fica NO LUGAR do campo pulado, e o Nascimento na coluna dele.
        $this->assertSame(['39053344705', '', '25121985'], array_slice($porLigacao[(string) $comBuraco], 13, 3));
    }

    /**
     * A ordem das colunas é a `ordem` da Campanha, não a ordem alfabética nem a de
     * inserção do JSON. É a ordem em que a URA perguntou, e é o que faz a planilha
     * bater com o roteiro.
     */
    #[Test]
    public function o_csv_segue_a_ordem_dos_campos_da_campanha(): void
    {
        $this->campos(['Zebra', 'Alfa']);
        $this->ligacao('5511911111111', 'captura_concluida', ['Alfa' => '111', 'Zebra' => '999']);

        $linhas = $this->csv();

        $this->assertSame(['Zebra', 'Alfa'], array_slice($linhas[0], 13, 2));
        $this->assertSame(['999', '111'], array_slice($linhas[1], 13, 2));
    }

    /** O CSV respeita o mesmo filtro da tela — senão o operador confere um conjunto e exporta outro. */
    #[Test]
    public function o_csv_respeita_o_filtro_da_tela(): void
    {
        $this->campos(['CPF']);
        $this->ligacao('5511911111111', 'captura_concluida', ['CPF' => '11144477735']);
        $this->ligacao('5511922222222', 'erro', []);

        $linhas = $this->csv(['desfecho' => 'erro']);

        $this->assertCount(2, $linhas);              // cabeçalho + uma
        $this->assertSame('5511922222222', $linhas[1][1]);
    }

    /** O BOM é o que faz o Excel abrir em UTF-8; sem ele todo acento quebra. */
    #[Test]
    public function o_csv_comeca_com_bom_e_usa_ponto_e_virgula(): void
    {
        $this->campos(['CPF']);
        $this->ligacao('5511911111111', 'captura_concluida', ['CPF' => '11144477735']);

        $bruto = $this->csvBruto();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $bruto);
        $this->assertStringContainsString('ligacao;telefone;', $bruto);
    }

    /**
     * 🔴 Caminho de gravação no banco com arquivo ausente do disco é AVISO na tela.
     *
     * Medido: com o diretório sem permissão o MixMonitor erra no log do Asterisk e a
     * chamada segue normal, deixando a coluna preenchida. A linha "existe" e o áudio não.
     */
    #[Test]
    public function gravacao_que_nao_esta_no_disco_e_avisada(): void
    {
        $id = $this->ligacao('5511911111111', 'captura_concluida', ['CPF' => '11144477735']);
        DB::table('ligacao')->where('id', $id)->update(['gravacao' => '/tmp/nao-existe-mesmo/' . $id . '.alaw']);

        Livewire::test(Resultados::class, ['id' => $this->campanha])
            ->assertSee('não está no disco');
    }

    // ---------------------------------------------------------------- auxiliares

    /** @param list<string> $nomes */
    private function campos(array $nomes): void
    {
        foreach ($nomes as $i => $nome) {
            $definicao = (int) DB::table('campo_definicao')->insertGetId([
                'nome' => $nome, 'tipo' => $nome === 'CPF' ? 'cpf' : 'numero', 'tamanho' => 11,
            ]);
            DB::table('campanha_campo')->insert([
                'campanha_id' => $this->campanha,
                'campo_definicao_id' => $definicao,
                'ordem' => $i + 1,
                'obrigatorio' => 1,
                'tentativas' => 2,
                'timeout_seg' => 15,
            ]);
        }
    }

    /** @param array<string,string> $respostas */
    private function ligacao(string $telefone, ?string $desfecho, array $respostas, bool $atendida = true): int
    {
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $this->campanha, 'telefone' => $telefone, 'status' => 3,
        ]);

        return (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead,
            'campanha_id' => $this->campanha,
            'numero_tentativa' => 1,
            'iniciada_em' => now(),
            'atendida_em' => $atendida ? now() : null,
            'duracao_seg' => $atendida ? 30 : 12,
            'duracao_falada_seg' => $atendida ? 22 : null,
            'desfecho' => $desfecho,
            'respostas' => $respostas === [] ? null : json_encode($respostas),
        ]);
    }

    /**
     * O CSV já separado em células.
     *
     * @param  array<string,mixed>  $filtros
     * @return list<list<string>>
     */
    private function csv(array $filtros = []): array
    {
        $bruto = $this->csvBruto($filtros);

        // Tira o BOM antes de partir, senão ele cola na primeira célula do cabeçalho e
        // a comparação falha por três bytes invisíveis.
        $bruto = (string) preg_replace('/^\xEF\xBB\xBF/', '', $bruto);

        $saida = [];
        foreach (explode("\n", trim($bruto)) as $linha) {
            if ($linha === '') {
                continue;
            }
            $saida[] = str_getcsv(rtrim($linha, "\r"), ';');
        }

        return $saida;
    }

    /**
     * O CSV cru, como o navegador o receberia.
     *
     * O Livewire captura o `php://output` do `streamDownload` por conta própria e guarda
     * o resultado em base64 no efeito `download` — é assim que o `assertFileDownloaded`
     * lê o conteúdo. Ler o efeito é, portanto, ler os bytes que o operador baixaria.
     *
     * @param  array<string,mixed>  $filtros
     */
    private function csvBruto(array $filtros = []): string
    {
        $componente = Livewire::test(Resultados::class, ['id' => $this->campanha]);

        foreach ($filtros as $chave => $valor) {
            $componente->set($chave, $valor);
        }

        $componente->call('exportar')->assertFileDownloaded();

        $conteudo = data_get($componente->effects, 'download.content');

        return is_string($conteudo) ? (string) base64_decode($conteudo, true) : '';
    }
}
