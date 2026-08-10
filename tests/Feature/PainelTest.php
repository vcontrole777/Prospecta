<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Painel: a home com os contadores por Campanha.
 *
 * Roda contra MySQL, não SQLite — ver o comentário no `phpunit.xml`. O agregado
 * usa `SUM(coluna = valor)`, que é sintaxe de MySQL.
 */
final class PainelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O painel inteiro exige sessão. Autenticar no setUp mantém cada teste falando
     * do que ele mede; que a rota SEJA protegida é afirmado em `EntrarTest`, e
     * precisa ser afirmado lá, senão este setUp esconderia a proteção sumindo.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    #[Test]
    public function a_home_responde_e_traz_o_cabecalho(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Campanhas');
    }

    #[Test]
    public function sem_campanha_mostra_estado_vazio_honesto(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Nenhuma Campanha')
            // A mensagem diz que a tela de criação não existe. Se alguém
            // construir a tela e esquecer de trocar o texto, isto falha — que é
            // exatamente quando deve falhar.
            ->assertSee('A tela de criação ainda não existe', escape: false);
    }

    #[Test]
    public function conta_leads_ligacoes_e_desfechos_da_campanha(): void
    {
        $c = $this->campanha(['nome' => 'Campanha A', 'status' => 1, 'max_tentativas' => 2]);

        $this->lead($c, '5511900000001', status: 1);
        $this->lead($c, '5511900000002', status: 2);   // em voo
        $this->lead($c, '5511900000003', status: 3);   // finalizado

        $this->ligacao($c, desfecho: 'opt_in', atendida: true);
        $this->ligacao($c, desfecho: 'nao_atendeu');
        $this->ligacao($c, desfecho: 'erro');

        $html = $this->get('/')->assertOk()->getContent();
        self::assertIsString($html);

        $celulas = $this->celulasDaPrimeiraLinha($html);

        // Campanha | Estado | Progresso | Em voo | Discadas | Atendidas | Taxa | Opt-in | Erros
        $this->assertStringContainsString('Campanha A', $celulas[0]);
        $this->assertStringContainsString('ativa', $celulas[1]);
        $this->assertStringContainsString('1/3', $celulas[2], 'progresso: 1 finalizado de 3 leads');
        $this->assertSame('1', trim($celulas[3]), 'em voo');
        $this->assertSame('3', trim($celulas[4]), 'discadas');
        $this->assertSame('1', trim($celulas[5]), 'atendidas');
        $this->assertSame('33%', trim($celulas[6]), 'taxa = 1/3');
        $this->assertSame('1', trim($celulas[7]), 'opt-in');
        $this->assertSame('1', trim($celulas[8]), 'erros');
    }

    /**
     * 🔴 TRAVA CONTRA O PRODUTO CARTESIANO.
     *
     * O agregado do painel são DUAS consultas de propósito. Uma consulta só, com
     * LEFT JOIN em `leads` E em `ligacao`, multiplica cada Lead por cada Ligação
     * da mesma Campanha: 4 Leads e 3 Ligações viram 12 linhas, e TODA contagem
     * sai inflada — leads=12, discadas=12.
     *
     * O sintoma é a pior espécie de defeito de tela: números plausíveis, errados
     * por um fator que muda conforme a Campanha avança. Ninguém desconfia de um
     * total que apenas parece alto.
     *
     * Os números aqui são propositalmente PRIMOS ENTRE SI (4 e 3) para que
     * qualquer inflação apareça como valor impossível, e não como coincidência.
     */
    #[Test]
    public function nao_infla_contagem_ao_juntar_leads_com_ligacoes(): void
    {
        $c = $this->campanha(['nome' => 'Sem fan-out', 'status' => 1]);

        for ($i = 1; $i <= 4; $i++) {
            $this->lead($c, '551190000000' . $i, status: 1);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->ligacao($c, desfecho: 'nao_atendeu');
        }

        $html = $this->get('/')->assertOk()->getContent();
        self::assertIsString($html);

        $celulas = $this->celulasDaPrimeiraLinha($html);

        $this->assertStringContainsString('0/4', $celulas[2], 'leads: 4, não 12');
        $this->assertSame('3', trim($celulas[4]), 'discadas: 3, não 12');
    }

    #[Test]
    public function taxa_distingue_ainda_nao_discou_de_zero_por_cento(): void
    {
        $c = $this->campanha(['nome' => 'Nova', 'status' => 1]);
        $this->lead($c, '5511900000009', status: 1);

        $celulas = $this->celulasDaPrimeiraLinha((string) $this->get('/')->assertOk()->getContent());

        // Travessão, não "0%". Zero por cento numa Campanha que nunca discou
        // diria que a lista é ruim antes de existir qualquer chamada.
        $this->assertSame('—', trim($celulas[6]));
    }

    // ---- auxiliares ------------------------------------------------------

    /** @param array<string,mixed> $atributos */
    private function campanha(array $atributos = []): int
    {
        return (int) DB::table('campanha')->insertGetId(array_merge([
            'tipo' => 'opt_in',
            'nome' => 'Campanha de teste',
            'status' => 1,
        ], $atributos));
    }

    private function lead(int $campanhaId, string $telefone, int $status): void
    {
        DB::table('leads')->insert([
            'campanha_id' => $campanhaId,
            'telefone' => $telefone,
            'status' => $status,
        ]);
    }

    private function ligacao(int $campanhaId, string $desfecho, bool $atendida = false): void
    {
        DB::table('ligacao')->insert([
            'campanha_id' => $campanhaId,
            'numero_tentativa' => 1,
            'desfecho' => $desfecho,
            'atendida_em' => $atendida ? now() : null,
            'encerrada_em' => now(),
        ]);
    }

    /**
     * As células de texto da primeira linha de dados da tabela.
     *
     * @return list<string>
     */
    private function celulasDaPrimeiraLinha(string $html): array
    {
        $achou = preg_match('#<tr class="border-b border-\[var\(--rule\)\].*?</tr>#s', $html, $m);
        $this->assertSame(1, $achou, 'nenhuma linha de Campanha na tabela');

        preg_match_all('#<td.*?</td>#s', $m[0], $tds);

        $celulas = [];
        foreach ($tds[0] as $td) {
            $texto = (string) preg_replace('/<[^>]+>/', ' ', $td);
            $celulas[] = trim((string) preg_replace('/\s+/', ' ', html_entity_decode($texto)));
        }

        $this->assertCount(9, $celulas, 'a tabela deveria ter 9 colunas');

        return $celulas;
    }
}
