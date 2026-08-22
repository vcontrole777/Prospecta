<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\ImportarLista;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\CriaAudio;
use Tests\TestCase;

final class ImportarListaTest extends TestCase
{
    use CriaAudio;
    use RefreshDatabase;

    private int $campanha;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $this->campanha = (int) DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'Lista de agosto', 'status' => 0,
        ]);
    }

    #[Test]
    public function importa_normalizando_o_telefone_e_guardando_as_outras_colunas(): void
    {
        $this->enviar("Nome,Telefone,Cidade\nMaria,(11) 98765-4321,Osasco\nJoão,1134567890,Santos\n")
            ->call('importar')
            ->assertSet('inseridos', 2)
            ->assertSet('rejeitados', 0);

        $leads = DB::table('leads')->orderBy('id')->get();
        $this->assertCount(2, $leads);

        // Canônico: país, DDD, número. É o que a lista de bloqueio e a BINA esperam.
        $this->assertSame('5511987654321', $leads[0]->telefone);
        $this->assertSame('551134567890', $leads[1]->telefone);

        // As outras colunas viram material de substituição, chaveadas pelo NOME do
        // cabeçalho — o mesmo nome que o mapa de template da Campanha referencia.
        $this->assertSame(
            ['Nome' => 'Maria', 'Cidade' => 'Osasco'],
            json_decode((string) $leads[0]->dados_extras, true),
        );
        $this->assertArrayNotHasKey('Telefone', (array) json_decode((string) $leads[0]->dados_extras, true));
    }

    /**
     * 🔴 O caso do Excel em português: `;` como separador e cp1252 como
     * acentuação. Errar o separador dá "0 importados" num arquivo cheio; errar a
     * acentuação põe "JoÃ£o" dentro da mensagem que o Lead recebe.
     */
    #[Test]
    public function aceita_o_csv_do_excel_brasileiro(): void
    {
        $latin = mb_convert_encoding(
            "Nome;Telefone\nJoão;11987654321\nCecília;11912345678\n",
            'Windows-1252',
            'UTF-8',
        );

        $this->enviar($latin)
            ->assertSet('delimitador', ';')
            ->assertSet('codificacao', 'Windows-1252')
            ->call('importar')
            ->assertSet('inseridos', 2);

        $extras = json_decode((string) DB::table('leads')->orderBy('id')->value('dados_extras'), true);
        $this->assertSame('João', $extras['Nome'] ?? null, 'o acento tem que sobreviver até o banco');
    }

    /**
     * Duplicata dentro da Campanha é impedida porque duas chamadas para a mesma
     * pessoa na mesma rodada é a coisa que mais rápido gera reclamação.
     */
    #[Test]
    public function nao_duplica_dentro_do_arquivo_nem_contra_o_que_ja_existe(): void
    {
        DB::table('leads')->insert([
            'campanha_id' => $this->campanha, 'telefone' => '5511987654321', 'status' => 1,
        ]);

        // O mesmo número três vezes: uma já no banco e duas no arquivo, escritas de
        // formas DIFERENTES — é a normalização que faz a unicidade funcionar.
        $this->enviar("Telefone\n(11) 98765-4321\n+5511987654321\n11912345678\n")
            ->call('importar')
            ->assertSet('inseridos', 1)
            ->assertSet('duplicados', 2);

        $this->assertSame(2, DB::table('leads')->count());
    }

    #[Test]
    public function recusa_o_que_viraria_chamada_perdida_e_diz_a_linha(): void
    {
        // DDD 20 não existe (vira 503, que conta para a proteção); o "móvel" que não
        // começa com 9 vira 404, que aposenta o Lead.
        $this->enviar("Telefone\n11987654321\n2098765432\n11887654321\nnada\n")
            ->call('importar')
            ->assertSet('inseridos', 1)
            ->assertSet('rejeitados', 3);

        $this->assertSame(1, DB::table('leads')->count());
        $this->assertSame('5511987654321', DB::table('leads')->value('telefone'));
    }

    #[Test]
    public function os_exemplos_recusados_apontam_a_linha_do_arquivo(): void
    {
        $comp = $this->enviar("Telefone\n11987654321\nnada\n")->call('importar');

        $exemplos = $comp->get('exemplosRejeitados');
        $this->assertCount(1, $exemplos);
        // Linha 3 do arquivo: 1 é o cabeçalho, 2 é o número válido.
        $this->assertSame(3, $exemplos[0]['linha']);
        $this->assertSame('nada', $exemplos[0]['valor']);
    }

    #[Test]
    public function adivinha_a_coluna_de_telefone_pelo_nome(): void
    {
        foreach (['Telefone', 'Celular', 'fone', 'WhatsApp', 'Móvel'] as $nome) {
            $this->enviar("Nome,{$nome}\nMaria,11987654321\n")
                ->assertSet('colunaTelefone', 1, "cabeçalho: {$nome}");
        }
    }

    /**
     * Cabeçalho genérico — planilha exportada de CRM costuma ter "Campo 1" — cai
     * para adivinhação por CONTEÚDO.
     */
    #[Test]
    public function adivinha_pela_amostra_quando_o_cabecalho_e_generico(): void
    {
        $this->enviar("Campo 1,Campo 2,Campo 3\nMaria,Osasco,11987654321\nJoao,Santos,11912345678\n")
            ->assertSet('colunaTelefone', 2);
    }

    /** O operador manda: a adivinhação é sugestão, e a prévia existe para conferir. */
    #[Test]
    public function o_operador_pode_trocar_a_coluna(): void
    {
        $this->enviar("A,B\n11987654321,11912345678\n")
            ->set('colunaTelefone', 1)
            ->call('importar')
            ->assertSet('inseridos', 1);

        $this->assertSame('5511912345678', DB::table('leads')->value('telefone'));
    }

    /**
     * 🔴 O LAÇO INTEIRO, sem SQL: criar já existia, importar é isto, e iniciar tem a
     * guarda de "nenhum Lead disponível".
     *
     * Antes desta tela a guarda era intransponível pelo painel — a Campanha ficava
     * presa em rascunho para sempre, e a única saída era INSERT à mão. Este teste é
     * o que afirma que o caminho do operador fecha.
     */
    #[Test]
    public function depois_de_importar_a_campanha_pode_ir_ao_ar(): void
    {
        $audio = $this->audioComArquivo();
        DB::table('campanha')->where('id', $this->campanha)->update([
            'audio_mensagem_id' => $audio,
            'canal_opt_in' => 'whatsapp',
            'template_nome' => 'boas_vindas',
        ]);

        // Antes de importar, a guarda recusa e DIZ o motivo.
        Livewire::test(\App\Livewire\Campanhas::class)
            ->call('iniciar', $this->campanha)
            ->assertSee('nenhum Lead disponível');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $this->campanha)->value('status'));

        $this->enviar("Nome,Telefone\nMaria,11987654321\n")
            ->call('importar')
            ->assertSet('inseridos', 1);

        // Depois de importar, vai ao ar.
        Livewire::test(\App\Livewire\Campanhas::class)->call('iniciar', $this->campanha);

        $this->assertSame(1, (int) DB::table('campanha')->where('id', $this->campanha)->value('status'));
    }

    #[Test]
    public function a_pagina_renderiza_inteira(): void
    {
        $this->get(route('campanha.importar', $this->campanha))
            ->assertOk()
            ->assertSee('Importar lista')
            ->assertSee('Lista de agosto')
            ->assertDontSee('Unclosed');
    }

    #[Test]
    public function campanha_inexistente_da_404(): void
    {
        $this->get(route('campanha.importar', 99999))->assertNotFound();
    }

    protected function tearDown(): void
    {
        $this->limparAudios();
        parent::tearDown();
    }

    /** Envia o CSV e devolve o componente já na etapa de conferência. */
    /**
     * 🔴 ARQUIVO COM TELEFONE REPETIDO. Regressão medida em produção, com 500 na cara
     * do operador.
     *
     * O `INSERT` é em LOTE, e a cláusula era `ON DUPLICATE KEY UPDATE id = id`. O MySQL 8
     * recusa atribuição à coluna AUTO_INCREMENT quando o lote traz duplicata dentro dele:
     *
     *     SQLSTATE[HY000]: General error: 1869
     *     Auto-increment value in UPDATE conflicts with internally generated values
     *
     * Não aparecia em teste porque todo arquivo de exemplo tinha telefones distintos —
     * e lista real de discagem repete número o tempo todo. Um caractere de diferença
     * entre "importa" e "500".
     */
    #[Test]
    public function importa_arquivo_com_telefone_repetido(): void
    {
        $this->enviar(
            "Nome,Telefone\n"
            . "Maria,11987654321\n"
            . "João,11912345678\n"
            . "Maria de novo,11987654321\n"     // repetido DENTRO do arquivo
            . "Ana,11955554444\n"
        )
            ->call('importar')
            ->assertSet('inseridos', 3)
            ->assertSet('duplicados', 1)
            ->assertSet('etapa', 'pronto');

        // A repetida não vira segunda linha: o UNIQUE(campanha_id, telefone) é quem
        // garante que o mesmo número não seja discado duas vezes pela Campanha.
        $this->assertSame(3, DB::table('leads')->count());
        $this->assertSame(1, DB::table('leads')->where('telefone', '5511987654321')->count());
    }

    /** Reimportar o MESMO arquivo não duplica nada — a operação é reentrante. */
    #[Test]
    public function reimportar_o_mesmo_arquivo_nao_duplica(): void
    {
        $csv = "Nome,Telefone\nMaria,11987654321\nJoão,11912345678\n";

        $this->enviar($csv)->call('importar')->assertSet('inseridos', 2);
        $this->enviar($csv)->call('importar')->assertSet('inseridos', 0)->assertSet('duplicados', 2);

        $this->assertSame(2, DB::table('leads')->count());
    }

    private function enviar(string $conteudo): \Livewire\Features\SupportTesting\Testable
    {
        $arquivo = UploadedFile::fake()->createWithContent('lista.csv', $conteudo);

        return Livewire::test(ImportarLista::class, ['id' => $this->campanha])
            ->set('arquivo', $arquivo)
            ->assertHasNoErrors()
            ->assertSet('etapa', 'mapear');
    }
}
