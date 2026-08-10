<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Fastagi\Banco;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * O `Banco` diante da linha de `configuracao` presente e ausente.
 *
 * 🔴 `DatabaseTruncation`, não `RefreshDatabase`, e o motivo é o ADR-0001.
 *
 * `RefreshDatabase` envolve o teste numa transação e desfaz no fim. Mas o `Banco`
 * abre a PRÓPRIA conexão PDO — de propósito, porque o `fastagi` roda com bootstrap
 * mínimo e sem framework — e conexão separada não vê dado não comitado. Com
 * transação, o `Banco` não encontrava nada e o teste "provava" um defeito que não
 * existe.
 *
 * A truncagem comita, ao custo de apagar a linha semeada pela migration entre
 * testes — daí o `setUp` restaurar o pressuposto. Que a MIGRATION o crie é afirmado
 * em `ConfiguracaoSingletonTest`, pela conexão do framework.
 */
final class BancoConfiguracaoTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        // Restaura o pressuposto do esquema que a truncagem levou.
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);
    }

    #[Test]
    public function carrega_com_a_linha_presente(): void
    {
        $d = (new Banco($this->conf()))->carregar($this->ligacao(), '/tmp/grav', 'prospecta');

        $this->assertSame('opt_in', $d->tipo);
        $this->assertTrue($d->gravacaoAtiva, 'o default da coluna é gravar');
    }

    /**
     * 🔴 A REGRESSÃO. Com `CROSS JOIN`, tabela vazia devolvia ZERO linhas e o
     * `carregar` lançava "Ligação não encontrada" sobre uma Ligação que EXISTE —
     * mandando a investigação para o lugar errado enquanto toda chamada falhava.
     *
     * Com `LEFT JOIN`, a chamada acontece. Perde-se a gravação, não a chamada, que é
     * a ordem certa de prioridade.
     */
    #[Test]
    public function sem_a_linha_a_chamada_ainda_acontece(): void
    {
        $ligacao = $this->ligacao();
        DB::table('configuracao')->delete();

        $d = (new Banco($this->conf()))->carregar($ligacao, '/tmp/grav', 'prospecta');

        $this->assertSame('opt_in', $d->tipo, 'a chamada segue');
        $this->assertFalse($d->gravacaoAtiva, 'sem configuração, sem gravação');
    }

    /** Ligação que de fato não existe continua lançando — a mensagem virou verdade. */
    #[Test]
    public function ligacao_inexistente_ainda_lanca(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Ligação 99999 não encontrada/');

        (new Banco($this->conf()))->carregar(99999, '/tmp/grav', 'prospecta');
    }

    private function ligacao(): int
    {
        $campanha = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'C', 'status' => 1,
            'tecla_opt_in' => '1', 'tecla_opt_out' => '9', 'tentativas_ura' => 2,
        ]);
        $lead = DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 2,
        ]);

        return (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha,
            'numero_tentativa' => 1, 'iniciada_em' => now(),
        ]);
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
