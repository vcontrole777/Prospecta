<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dialer\Fila;
use App\Fastagi\Banco;
use App\Fastagi\Resultado;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `Banco::gravar` — a FASE 4 do fastagi, e o lado de escrita que não tinha teste.
 *
 * Só `carregar` era coberto. É aqui que o desfecho, as respostas, a Ação de disparo do
 * opt-in e o bloqueio do opt-out são gravados, numa transação só (ADR-0002): gravar a
 * tecla e criar a Ação tem que ser atômico, senão "a Ação dispara no instante da tecla"
 * é retórica — o processo pode morrer entre as duas escritas e quem apertou 1 fica sem
 * disparo.
 *
 * `DatabaseTruncation` porque o `Banco` abre a PRÓPRIA conexão PDO, de propósito: o
 * fastagi roda com bootstrap mínimo (ADR-0001). Conexão separada não vê dado não
 * comitado.
 */
final class BancoGravarTest extends TestCase
{
    use DatabaseTruncation;

    private int $campanha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 1, 'max_tentativas' => 3,
        ]);
    }

    // ------------------------------------------------------------------- opt-in

    #[Test]
    public function opt_in_grava_o_desfecho_e_cria_a_acao_de_disparo(): void
    {
        $ligacao = $this->ligacao();

        $r = new Resultado();
        $r->desfecho = 'opt_in';
        $r->teclaDigitada = '1';
        $r->atendida = true;
        $r->atendidaEm = time();

        $this->banco()->gravar($ligacao, $r);

        $l = (array) DB::table('ligacao')->where('id', $ligacao)->first();
        $this->assertSame('opt_in', $l['desfecho']);
        $this->assertSame('1', $l['tecla_digitada']);
        $this->assertNotNull($l['atendida_em']);
        $this->assertNotNull($l['encerrada_em']);

        $acao = (array) DB::table('acao')->where('referencia_id', $ligacao)->first();
        $this->assertSame('disparo', $acao['tipo']);
        $this->assertSame('pendente', $acao['status']);
    }

    /**
     * Gravar duas vezes não gera dois disparos.
     *
     * A idempotência é do ESQUEMA — `UNIQUE(tipo, referencia_id)` —, e o
     * `ON DUPLICATE KEY UPDATE id = id` é o no-op que a torna reentrante sem engolir
     * outros erros, ao contrário de `INSERT IGNORE`. Duas Ações seriam duas mensagens
     * pagas para a mesma pessoa.
     */
    #[Test]
    public function gravar_o_mesmo_opt_in_duas_vezes_nao_duplica_a_acao(): void
    {
        $ligacao = $this->ligacao();

        $r = new Resultado();
        $r->desfecho = 'opt_in';
        $r->teclaDigitada = '1';

        $this->banco()->gravar($ligacao, $r);
        $this->banco()->gravar($ligacao, $r);

        $this->assertSame(1, DB::table('acao')->where('referencia_id', $ligacao)->count());
    }

    // ------------------------------------------------------------------ opt-out

    /**
     * Opt-out bloqueia o DIALER, não só grava uma linha.
     *
     * O bloqueio é global e conferido por igualdade exata de texto na discagem. Afirmar
     * contra a `Fila` de verdade é o que prova que a tecla 9 tem efeito — a linha existir
     * não basta.
     */
    #[Test]
    public function opt_out_entra_na_lista_de_bloqueio_e_bloqueia_o_dialer(): void
    {
        $ligacao = $this->ligacao('5511987654321');

        $r = new Resultado();
        $r->desfecho = 'opt_out';
        $r->teclaDigitada = '9';

        $this->banco()->gravar($ligacao, $r);

        $b = (array) DB::table('bloqueio')->where('telefone', '5511987654321')->first();
        $this->assertSame('opt_out', $b['origem']);
        $this->assertSame($ligacao, (int) $b['ligacao_id']);

        $fila = new Fila(DB::connection()->getPdo());
        $this->assertTrue($fila->bloqueado('5511987654321'));
    }

    #[Test]
    public function opt_in_nao_bloqueia_ninguem(): void
    {
        $ligacao = $this->ligacao('5511987654321');

        $r = new Resultado();
        $r->desfecho = 'opt_in';

        $this->banco()->gravar($ligacao, $r);

        $this->assertSame(0, DB::table('bloqueio')->count());
    }

    // ------------------------------------------------------------------ captura

    /**
     * 🔴 CAPTURA NÃO ENFILEIRA NADA, e é o conserto que este teste tranca.
     *
     * Havia aqui um `INSERT INTO acao ('evento_captura_concluida', ...)` com o comentário
     * "processada pelo worker". O worker não a processava: `FilaDeAcoes::reivindicar` só
     * reivindica `tipo = 'disparo'`, e `marcarEsgotadas` também. A linha não era consumida
     * e nem chegava a falhar — ficava `pendente` para sempre, uma por captura, formando
     * uma fila que nunca drena e que faria o operador achar que o envio travou.
     *
     * Nada se perdeu: as respostas estão em `ligacao.respostas`, na mesma transação, e o
     * `payload` era cópia delas.
     */
    #[Test]
    public function captura_concluida_grava_as_respostas_e_nenhuma_acao(): void
    {
        $ligacao = $this->ligacao();

        $r = new Resultado();
        $r->desfecho = 'captura_concluida';
        $r->respostas = ['CPF' => '11144477735', 'Matricula' => '778899'];
        $r->atendida = true;
        $r->atendidaEm = time();

        $this->banco()->gravar($ligacao, $r);

        $l = (array) DB::table('ligacao')->where('id', $ligacao)->first();
        $this->assertSame('captura_concluida', $l['desfecho']);
        $this->assertSame(
            ['CPF' => '11144477735', 'Matricula' => '778899'],
            json_decode((string) $l['respostas'], true),
        );

        $this->assertSame(0, DB::table('acao')->count(), 'nenhum consumidor existe para essa Ação');
    }

    #[Test]
    public function captura_parcial_tambem_nao_enfileira(): void
    {
        $ligacao = $this->ligacao();

        $r = new Resultado();
        $r->desfecho = 'captura_parcial';
        $r->respostas = ['CPF' => '11144477735'];

        $this->banco()->gravar($ligacao, $r);

        $this->assertSame(0, DB::table('acao')->count());
    }

    // -------------------------------------------------------------- as durações

    /**
     * 🔴 `duracao_falada_seg` fica NULA quando ninguém atendeu.
     *
     * A duração total conta desde `iniciada_em` e inclui o toque. Sem a distinção, uma
     * chamada de 40 s que nunca foi atendida entraria no relatório como conversa de 40
     * segundos, e a média de tempo falado — que é a medida de custo — subiria com base em
     * silêncio.
     */
    #[Test]
    public function sem_atendimento_a_duracao_falada_fica_nula(): void
    {
        $ligacao = $this->ligacao();

        $r = new Resultado();
        $r->desfecho = 'nao_atendeu';
        $r->atendida = false;

        $this->banco()->gravar($ligacao, $r);

        $l = (array) DB::table('ligacao')->where('id', $ligacao)->first();
        $this->assertNull($l['duracao_falada_seg']);
        $this->assertNull($l['atendida_em']);
        $this->assertNotNull($l['duracao_seg']);
    }

    /**
     * `atendida_em` usa `COALESCE`: quem já tinha carimbo não o perde.
     *
     * O dialer pode ter gravado o instante do `Newstate` antes; sobrescrever com o
     * momento do fim da chamada faria a duração falada virar zero.
     */
    #[Test]
    public function atendida_em_ja_gravado_nao_e_sobrescrito(): void
    {
        $ligacao = $this->ligacao();
        $antes = now()->subSeconds(30)->format('Y-m-d H:i:s');
        DB::table('ligacao')->where('id', $ligacao)->update(['atendida_em' => $antes]);

        $r = new Resultado();
        $r->desfecho = 'opt_in';
        $r->atendida = true;
        $r->atendidaEm = time();

        $this->banco()->gravar($ligacao, $r);

        $l = (array) DB::table('ligacao')->where('id', $ligacao)->first();
        $this->assertSame($antes, $l['atendida_em']);
        $this->assertGreaterThanOrEqual(30, (int) $l['duracao_falada_seg']);
    }

    // ---------------------------------------------------------------- auxiliares

    private function banco(): Banco
    {
        return new Banco([
            'dsn' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                (string) config('database.connections.mysql.host'),
                (string) config('database.connections.mysql.port'),
                (string) config('database.connections.mysql.database'),
            ),
            'usuario' => (string) config('database.connections.mysql.username'),
            'senha' => (string) config('database.connections.mysql.password'),
        ]);
    }

    private function ligacao(string $telefone = '5511900000001'): int
    {
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $this->campanha, 'telefone' => $telefone, 'status' => 2,
        ]);

        return (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $this->campanha,
            'numero_tentativa' => 1, 'iniciada_em' => now()->subSeconds(40),
        ]);
    }
}
