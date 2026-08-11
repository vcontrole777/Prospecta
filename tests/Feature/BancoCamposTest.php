<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Fastagi\Banco;
use App\Livewire\CampanhaForm;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\CriaAudio;
use Tests\TestCase;

/**
 * 🔴 O ELO ENTRE A TELA E O FASTAGI, nos campos de captura.
 *
 * A tela grava `campanha_campo` + `campo_definicao`; quem lê é `Banco::campos`, que junta
 * as duas e ordena por `ordem`. Se a tela gravasse noutra forma, a URA perguntaria fora de
 * sequência — ou não perguntaria nada — e nenhum teste de tela pegaria isso.
 *
 * `DatabaseTruncation` e não `RefreshDatabase`, pela mesma razão de `BancoConfiguracaoTest`:
 * o `Banco` abre a PRÓPRIA conexão PDO, de propósito, porque o `fastagi` roda com bootstrap
 * mínimo (ADR-0001). Conexão separada não vê dado não comitado, e com transação este teste
 * "provaria" um defeito que não existe.
 */
final class BancoCamposTest extends TestCase
{
    use CriaAudio;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);
    }

    protected function tearDown(): void
    {
        $this->limparAudios();
        parent::tearDown();
    }

    #[Test]
    public function o_fastagi_le_os_campos_na_ordem_que_a_tela_gravou(): void
    {
        $prompt = $this->audioComArquivo('Pergunta', 2);

        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Recadastramento')
            ->set('tipo', 'captura')
            ->set('campos', [
                $this->campo('CPF', 'cpf', 11, $prompt),
                $this->campo('Contrato', 'numero', 6, $prompt),
            ])
            ->call('salvar')
            ->assertHasNoErrors();

        $campanha = (int) DB::table('campanha')->value('id');
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 2,
        ]);
        $ligacao = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha, 'numero_tentativa' => 1, 'iniciada_em' => now(),
        ]);

        $d = (new Banco($this->conf()))->carregar($ligacao, '/tmp/grav', 'prospecta');

        $this->assertSame('captura', $d->tipo);
        $this->assertCount(2, $d->campos);

        // Ordem preservada: é a ordem em que a URA vai perguntar.
        $this->assertSame(['CPF', 'Contrato'], array_column($d->campos, 'nome'));
        $this->assertSame([11, 6], array_column($d->campos, 'tamanho'));
        $this->assertSame(['cpf', 'numero'], array_column($d->campos, 'tipo'));

        // 🔴 O prompt chega como CAMINHO, não nulo. Nulo faz o `Fluxo` recusar a captura
        // inteira com AUDIO_NAO_CONFIGURADO — o que é melhor que tocar silêncio, mas só
        // acontece se a tela deixar passar campo sem áudio.
        foreach ($d->campos as $campo) {
            $this->assertNotNull($campo['audio'], $campo['nome']);
            $this->assertStringStartsWith('prospecta/', (string) $campo['audio']);
        }
    }

    /**
     * Campanha de opt-in não traz campo nenhum, mesmo se sobrar linha de outra época — o
     * `Fluxo` só entra em `captura()` quando `tipo` é captura.
     */
    #[Test]
    public function campanha_de_opt_in_nao_traz_campos(): void
    {
        $audio = $this->audioComArquivo('Mensagem', 3);

        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Opt-in')
            ->set('tipo', 'opt_in')
            ->set('audio_mensagem_id', $audio)
            ->set('template_nome', 'tpl')
            ->call('salvar')
            ->assertHasNoErrors();

        $campanha = (int) DB::table('campanha')->value('id');
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 2,
        ]);
        $ligacao = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha, 'numero_tentativa' => 1, 'iniciada_em' => now(),
        ]);

        $d = (new Banco($this->conf()))->carregar($ligacao, '/tmp/grav', 'prospecta');

        $this->assertSame('opt_in', $d->tipo);
        $this->assertSame([], $d->campos);
        $this->assertNotNull($d->audioMensagem);
    }

    /** @return array{nome:string, tipo:string, tamanho:int, audio_prompt_id:?int, obrigatorio:bool, tentativas:int, timeout_seg:int} */
    private function campo(string $nome, string $tipo, int $tamanho, int $prompt): array
    {
        return [
            'nome' => $nome, 'tipo' => $tipo, 'tamanho' => $tamanho,
            'audio_prompt_id' => $prompt, 'obrigatorio' => true,
            'tentativas' => 2, 'timeout_seg' => 15,
        ];
    }

    /** @return array{dsn:string,usuario:string,senha:string} */
    private function conf(): array
    {
        return [
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) config('database.connections.mysql.host'),
                (string) config('database.connections.mysql.port'),
                (string) config('database.connections.mysql.database'),
            ),
            'usuario' => (string) config('database.connections.mysql.username'),
            'senha' => (string) config('database.connections.mysql.password'),
        ];
    }
}
