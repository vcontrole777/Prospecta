<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dialer\Acao;
use App\Dialer\Decisao;
use App\Dialer\Fila;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A `Fila` decide QUANTAS VEZES cada Lead é discado, se um Lead bloqueado é discado, e
 * quando a Campanha termina. É a classe do dialer que mexe no banco, e era a única sem
 * teste — as puras (`Politica`, `Protecao`, `Ritmo`, `Janela`) já tinham.
 *
 * Foi aqui que `campanha.max_tentativas` acabou lida e jogada fora, com o operador
 * pedindo 2 tentativas e a discadora fazendo 3: 50% mais chamada paga do que foi
 * contratado, em silêncio, com a tela mostrando o número certo.
 *
 * `DatabaseTruncation` e não `RefreshDatabase`: `reivindicar` e `reconciliar` abrem
 * transação própria, e `PDO::beginTransaction` dentro da transação que o
 * `RefreshDatabase` mantém aberta falha com "there is already an active transaction".
 * Testar com transação por fora significaria não testar a transação de dentro.
 */
final class FilaTest extends TestCase
{
    use DatabaseTruncation;

    private Fila $fila;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        // O PDO DO FRAMEWORK, de propósito: a `Fila` recebe um PDO qualquer, e usar o
        // mesmo do `DB` faz o que o teste prepara e o que a `Fila` vê serem a mesma
        // coisa. Onde isso NÃO é possível — o `Banco` do fastagi abre a própria conexão
        // por exigência do bootstrap mínimo — o teste tem que se dobrar; aqui não.
        $this->pdo = DB::connection()->getPdo();
        $this->fila = new Fila($this->pdo);
    }

    // ------------------------------------------------------------- reivindicação

    #[Test]
    public function reivindicar_marca_em_voo_e_gasta_a_tentativa(): void
    {
        $c = $this->campanha(maxTentativas: 3);
        $a = $this->lead($c, '5511900000001');
        $b = $this->lead($c, '5511900000002');

        $lote = $this->fila->reivindicar($c, 2);

        $this->assertCount(2, $lote);
        $this->assertSame([$a, $b], array_column($lote, 'id'));

        // `tentativa` é a que ACABOU de começar, não a anterior: o `try` já foi
        // incrementado, e é este número que decide a aposentadoria.
        $this->assertSame([1, 1], array_column($lote, 'tentativa'));
        $this->assertSame(2, $this->statusDo($a));
        $this->assertSame(1, $this->tentativasDe($a));
    }

    /**
     * O `try` é incrementado ANTES de originar, e o Lead já reivindicado não sai de novo.
     *
     * O contrário — reservar o lote e validar depois — é o que faz duas passagens de
     * Campanha mal configurada queimarem a agenda inteira: a tentativa fica gasta em
     * número que nunca foi discado.
     */
    #[Test]
    public function reivindicar_nao_devolve_quem_ja_esta_em_voo(): void
    {
        $c = $this->campanha();
        $this->lead($c, '5511900000001');

        $this->assertCount(1, $this->fila->reivindicar($c, 10));
        $this->assertCount(0, $this->fila->reivindicar($c, 10));
    }

    #[Test]
    public function reivindicar_zero_nao_toca_no_banco(): void
    {
        $c = $this->campanha();
        $lead = $this->lead($c, '5511900000001');

        $this->assertSame([], $this->fila->reivindicar($c, 0));
        $this->assertSame(1, $this->statusDo($lead));
        $this->assertSame(0, $this->tentativasDe($lead));
    }

    // ------------------------------------------------------------------ aplicar

    /**
     * 🔴 O TETO É O DA CAMPANHA, e este teste é a rede sobre o defeito que já aconteceu.
     *
     * Com `max_tentativas = 2`: a primeira tentativa manda o Lead esperar a rodada, a
     * segunda o aposenta. Se a comparação escorregar para `>`, cada Campanha faz uma
     * discagem paga a mais por Lead — e nada em tela muda.
     */
    #[Test]
    public function aplicar_aposenta_exatamente_na_ultima_tentativa(): void
    {
        $c = $this->campanha(maxTentativas: 2);
        $lead = $this->lead($c, '5511900000001');
        $retentar = new Decisao(Acao::Retentar, true, false, 'teste', 'nao_atendeu');

        $this->fila->aplicar($lead, $retentar, 2, 1);
        $this->assertSame(4, $this->statusDo($lead), 'tentativa 1 de 2 tem que esperar a rodada');

        $this->fila->aplicar($lead, $retentar, 2, 2);
        $this->assertSame(3, $this->statusDo($lead), 'tentativa 2 de 2 tem que aposentar');
    }

    #[Test]
    public function aplicar_aposenta_na_hora_quando_a_decisao_e_aposentar(): void
    {
        $c = $this->campanha(maxTentativas: 5);
        $lead = $this->lead($c, '5511900000001');

        // 404 é permanente: número inexistente não melhora na terceira tentativa.
        $this->fila->aplicar($lead, new Decisao(Acao::Aposentar, true, false, 'invalido', 'invalido'), 5, 1);

        $this->assertSame(3, $this->statusDo($lead));
    }

    #[Test]
    public function aplicar_finaliza_quem_atendeu_sem_devolver_a_fila(): void
    {
        $c = $this->campanha(maxTentativas: 5);
        $lead = $this->lead($c, '5511900000001');

        $this->fila->aplicar($lead, new Decisao(Acao::Finalizado, true, false, 'atendida', null), 5, 1);

        $this->assertSame(3, $this->statusDo($lead));
    }

    // ------------------------------------------------------- não discamos: devolve

    /** Janela fechada: o Lead volta à Fila e a tentativa NÃO é cobrada. */
    #[Test]
    public function devolver_sem_gastar_desfaz_a_tentativa(): void
    {
        $c = $this->campanha();
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);

        $this->fila->devolverSemGastar($lead);

        $this->assertSame(1, $this->statusDo($lead));
        $this->assertSame(0, $this->tentativasDe($lead));
    }

    #[Test]
    public function lead_bloqueado_e_encerrado_sem_cobrar_a_tentativa(): void
    {
        $c = $this->campanha();
        $lead = $this->lead($c, '5511900000001');
        DB::table('bloqueio')->insert(['telefone' => '5511900000001', 'origem' => 'opt_out']);

        $this->assertTrue($this->fila->bloqueado('5511900000001'));
        $this->assertFalse($this->fila->bloqueado('5511900000002'));

        $this->fila->reivindicar($c, 1);
        $this->fila->encerrarSemDiscar($lead);

        $this->assertSame(3, $this->statusDo($lead));
        $this->assertSame(0, $this->tentativasDe($lead));
    }

    // ------------------------------------------------------------------- rodada

    #[Test]
    public function abrir_rodada_devolve_quem_aguardava_e_conta_a_rodada(): void
    {
        $c = $this->campanha();
        $a = $this->lead($c, '5511900000001', status: 4);
        $this->lead($c, '5511900000002', status: 3);   // aposentado: fica

        $voltaram = $this->fila->abrirProximaRodada($c);

        $this->assertSame(1, $voltaram);
        $this->assertSame(1, $this->statusDo($a));
        $this->assertSame(1, (int) DB::table('campanha')->where('id', $c)->value('rodada_atual'));
    }

    /**
     * Sem ninguém aguardando, a rodada NÃO avança.
     *
     * Incrementar de todo jeito faria `rodada_atual` subir a cada volta do dialer —
     * várias por segundo — numa Campanha que só espera as chamadas em voo terminarem. A
     * tela mostraria rodada 40 mil.
     */
    #[Test]
    public function abrir_rodada_sem_ninguem_aguardando_nao_avanca_o_contador(): void
    {
        $c = $this->campanha();
        $this->lead($c, '5511900000001', status: 3);

        $this->assertSame(0, $this->fila->abrirProximaRodada($c));
        $this->assertSame(0, (int) DB::table('campanha')->where('id', $c)->value('rodada_atual'));
    }

    // ------------------------------------------------------------ reconciliação

    /**
     * 🔴 O DEFEITO QUE ESTE TESTE EXISTE PARA TRANCAR.
     *
     * A reconciliação fechava a `ligacao` e não tocava em `leads`. Só `aplicar()` devolve
     * um Lead do status 2, e `aplicar()` roda no `Hangup` — o evento que se perdeu. O
     * Lead ficava em voo para sempre: nunca mais discado, e ainda contado por
     * `contarEmProcessamento`, o que impedia a Campanha de concluir para sempre.
     */
    #[Test]
    public function reconciliar_solta_o_lead_preso_em_voo(): void
    {
        $c = $this->campanha(maxTentativas: 3);
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);
        $ligacao = $this->ligacaoPresa($c, $lead, segundosAtras: 600);

        $resultado = $this->fila->reconciliar(300);

        $this->assertSame(['ligacoes' => 1, 'leads' => 1], $resultado);

        // Ainda tem tentativa: espera a próxima rodada, não é aposentado.
        $this->assertSame(4, $this->statusDo($lead));

        $g = (array) DB::table('ligacao')->where('id', $ligacao)->first();
        $this->assertNotNull($g['encerrada_em']);
        $this->assertSame('erro', $g['desfecho']);
    }

    /** Na última tentativa não há rodada seguinte: aposenta, com a regra de `aplicar`. */
    #[Test]
    public function reconciliar_aposenta_quem_estava_na_ultima_tentativa(): void
    {
        $c = $this->campanha(maxTentativas: 1);
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);
        $this->ligacaoPresa($c, $lead, segundosAtras: 600);

        $this->fila->reconciliar(300);

        $this->assertSame(3, $this->statusDo($lead));
    }

    /**
     * 🔴 NÃO RESSUSCITA quem o `fastagi` já concluiu.
     *
     * Há corrida real: o `fastagi` grava no fim da chamada e o `Hangup` pode chegar
     * antes. Se a reconciliação mexesse em Lead fora do status 2, ela discaria de novo
     * para quem atendeu e já foi tratado — e o desfecho gravado seria sobrescrito por um
     * `erro` que não aconteceu.
     */
    #[Test]
    public function reconciliar_nao_mexe_em_lead_ja_concluido(): void
    {
        $c = $this->campanha(maxTentativas: 3);
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);
        $ligacao = $this->ligacaoPresa($c, $lead, segundosAtras: 600, desfecho: 'opt_in');

        // O `aplicar` já rodou: o Lead está finalizado.
        DB::table('leads')->where('id', $lead)->update(['status' => 3]);

        $resultado = $this->fila->reconciliar(300);

        $this->assertSame(1, $resultado['ligacoes']);
        $this->assertSame(0, $resultado['leads'], 'nada a soltar: o Lead já estava concluído');
        $this->assertSame(3, $this->statusDo($lead));

        // E o desfecho verdadeiro sobrevive — o `COALESCE` não o troca por `erro`.
        $this->assertSame('opt_in', DB::table('ligacao')->where('id', $ligacao)->value('desfecho'));
    }

    /** Ligação dentro do limite é chamada VIVA. Encerrá-la mataria a conversa em curso. */
    #[Test]
    public function reconciliar_nao_toca_em_ligacao_recente(): void
    {
        $c = $this->campanha();
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);
        $this->ligacaoPresa($c, $lead, segundosAtras: 10);

        $this->assertSame(['ligacoes' => 0, 'leads' => 0], $this->fila->reconciliar(300));
        $this->assertSame(2, $this->statusDo($lead));
    }

    /** Ligação sem Lead — manual ou de agente de IA — fecha sem tentar soltar ninguém. */
    #[Test]
    public function reconciliar_lida_com_ligacao_sem_lead(): void
    {
        $ligacao = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => null, 'campanha_id' => null, 'numero_tentativa' => 1,
            'iniciada_em' => now()->subSeconds(600),
        ]);

        $this->assertSame(['ligacoes' => 1, 'leads' => 0], $this->fila->reconciliar(300));
        $this->assertNotNull(DB::table('ligacao')->where('id', $ligacao)->value('encerrada_em'));
    }

    /**
     * 🔴 A CONSEQUÊNCIA, e é o que o defeito custava de verdade.
     *
     * Com o Lead preso em 2, `contarEmProcessamento` nunca zerava e a guarda que impede
     * concluir Campanha com chamada em andamento nunca liberava. A Campanha ficava "no
     * ar" sem discar nada e sem nunca concluir, registrando `aguardando` a cada volta.
     */
    #[Test]
    public function depois_de_reconciliar_a_campanha_deixa_de_estar_em_voo(): void
    {
        $c = $this->campanha(maxTentativas: 1);
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);
        $this->ligacaoPresa($c, $lead, segundosAtras: 600);

        $this->assertSame(1, $this->fila->contarEmProcessamento($c), 'antes: preso em voo');
        $this->assertSame(0, $this->fila->contarDisponiveis($c));

        $this->fila->reconciliar(300);

        $this->assertSame(0, $this->fila->contarEmProcessamento($c), 'depois: nada em voo');
        $this->assertSame(0, $this->fila->contarDisponiveis($c));
        // Nada em voo e nada disponível: agora o dialer pode concluir a Campanha.
    }

    /** Reconciliar libera o canal na contabilidade — era o motivo original da varredura. */
    #[Test]
    public function reconciliar_libera_o_canal_ocupado(): void
    {
        $c = $this->campanha();
        $lead = $this->lead($c, '5511900000001');
        $this->fila->reivindicar($c, 1);
        $this->ligacaoPresa($c, $lead, segundosAtras: 600);

        $this->assertSame(1, $this->fila->contarOcupados($c));
        $this->fila->reconciliar(300);
        $this->assertSame(0, $this->fila->contarOcupados($c));
    }

    // ----------------------------------------------------------------- campanhas

    /**
     * O teto de canais é o MENOR entre o contratado da instância e o pedido da Campanha.
     * Ao contrário, uma Campanha pedindo 50 estouraria o que o cliente pagou.
     */
    #[Test]
    public function campanhas_ativas_limitam_ao_contratado_da_instancia(): void
    {
        $muitos = $this->campanha(simultaneas: 50);
        $poucos = $this->campanha(simultaneas: 2, nome: 'Segunda');

        $ativas = [];
        foreach ($this->fila->campanhasAtivas(10) as $a) {
            $ativas[$a['id']] = $a;
        }

        $this->assertSame(10, $ativas[$muitos]['contratado']);
        $this->assertSame(2, $ativas[$poucos]['contratado']);
    }

    #[Test]
    public function campanhas_ativas_ignora_pausada_e_concluida(): void
    {
        $noAr = $this->campanha();
        $this->campanha(status: 2, nome: 'Pausada');
        $this->campanha(status: 3, nome: 'Concluída');

        $this->assertSame([$noAr], array_column($this->fila->campanhasAtivas(10), 'id'));
    }

    // ---------------------------------------------------------------- auxiliares

    private function campanha(int $maxTentativas = 3, int $simultaneas = 5, int $status = 1, string $nome = 'Campanha'): int
    {
        return (int) DB::table('campanha')->insertGetId([
            'nome' => $nome, 'tipo' => 'opt_in', 'status' => $status,
            'max_tentativas' => $maxTentativas, 'chamadas_simultaneas' => $simultaneas,
        ]);
    }

    private function lead(int $campanha, string $telefone, int $status = 1): int
    {
        return (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => $telefone, 'status' => $status,
        ]);
    }

    /** Ligação aberta e antiga: é o que a reconciliação encontra. */
    private function ligacaoPresa(?int $campanha, ?int $lead, int $segundosAtras, ?string $desfecho = null): int
    {
        return (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha, 'numero_tentativa' => 1,
            'iniciada_em' => now()->subSeconds($segundosAtras),
            'encerrada_em' => null,
            'desfecho' => $desfecho,
        ]);
    }

    private function statusDo(int $leadId): int
    {
        return (int) DB::table('leads')->where('id', $leadId)->value('status');
    }

    private function tentativasDe(int $leadId): int
    {
        return (int) DB::table('leads')->where('id', $leadId)->value('try');
    }
}
