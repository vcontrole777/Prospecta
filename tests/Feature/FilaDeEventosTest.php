<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dialer\Fila;
use App\Whatsapp\Entrada;
use App\Whatsapp\Status;
use App\Worker\FilaDeEventos;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A fila de eventos do WhatsApp: o lado de ENTRADA.
 *
 * O webhook só guarda o evento cru em `evento_whatsapp`; é esta classe que o transforma em
 * estado de entrega, em Conversa e em bloqueio. O `WebhookWhatsappTest` cobre a recepção
 * — assinatura, reentrega, 200 — e parava aí: nada exercitava o consumo.
 *
 * `DatabaseTruncation` para poder afirmar contra a `Fila` do dialer de verdade no opt-out:
 * o que o "SAIR" promete não é uma linha em `bloqueio`, é a próxima chamada não sair.
 */
final class FilaDeEventosTest extends TestCase
{
    use DatabaseTruncation;

    private FilaDeEventos $fila;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fila = new FilaDeEventos(DB::connection()->getPdo());
    }

    // -------------------------------------------------------------- reivindicação

    #[Test]
    public function reivindicar_traz_os_mais_antigos_primeiro_e_ignora_processados(): void
    {
        $a = $this->evento('{"a":1}');
        $b = $this->evento('{"b":2}');
        $c = $this->evento('{"c":3}');
        DB::table('evento_whatsapp')->where('id', $b)->update(['processado_em' => now()]);

        $lote = $this->fila->reivindicar(10);

        // Ordem por `id` importa: os estados de uma mensagem chegam em sequência, e
        // processar fora de ordem faria a checagem de avanço rejeitar o estado certo.
        $this->assertSame([$a, $c], array_column($lote, 'id'));
        $this->assertSame(2, $this->fila->pendentes());
    }

    #[Test]
    public function marcar_processado_tira_da_fila(): void
    {
        $id = $this->evento('{"a":1}');

        $this->fila->marcarProcessado($id);

        $this->assertSame(0, $this->fila->pendentes());
        $this->assertSame([], $this->fila->reivindicar(10));
    }

    // ------------------------------------------------------- estados de entrega

    #[Test]
    public function aplica_o_estado_na_mensagem_e_na_acao(): void
    {
        [$acao] = $this->mensagemEAcao('wamid.X1');

        $this->assertTrue($this->fila->aplicarStatus(new Status('wamid.X1', 'delivered')));

        $this->assertSame('entregue', DB::table('conversa_mensagem')->where('wamid', 'wamid.X1')->value('status'));
        $this->assertSame('entregue', DB::table('acao')->where('id', $acao)->value('status'));
    }

    /**
     * 🔴 SÓ AVANÇA.
     *
     * O webhook chega fora de ordem — `read` pode vir antes de `delivered` — e aplicar
     * cegamente faria a tela REGREDIR de "lido" para "entregue". O operador veria o
     * estado andar para trás e concluiria que o sistema está errado; pior, uma resposta
     * já lida pareceria não lida.
     */
    #[Test]
    public function estado_que_regride_e_recusado(): void
    {
        [$acao] = $this->mensagemEAcao('wamid.X1');

        $this->fila->aplicarStatus(new Status('wamid.X1', 'read'));
        $this->fila->aplicarStatus(new Status('wamid.X1', 'delivered'));   // atrasado

        $this->assertSame('lido', DB::table('conversa_mensagem')->where('wamid', 'wamid.X1')->value('status'));
        $this->assertSame('lido', DB::table('acao')->where('id', $acao)->value('status'));
    }

    /**
     * As duas colunas usam palavras DIFERENTES para o mesmo estado: `played` vira
     * `reproduzido` na mensagem e `tocado` na Ação. A regra vive num lugar só (`Estados`)
     * exatamente porque essa divergência de vocabulário é convite a erro.
     */
    #[Test]
    public function played_vira_reproduzido_na_mensagem_e_tocado_na_acao(): void
    {
        [$acao] = $this->mensagemEAcao('wamid.X1');

        $this->assertTrue($this->fila->aplicarStatus(new Status('wamid.X1', 'played')));

        $this->assertSame('reproduzido', DB::table('conversa_mensagem')->where('wamid', 'wamid.X1')->value('status'));
        $this->assertSame('tocado', DB::table('acao')->where('id', $acao)->value('status'));
    }

    /** Estado que a Meta inventar depois devolve `false`, para o chamador registrar. */
    #[Test]
    public function estado_desconhecido_devolve_falso_sem_gravar(): void
    {
        $this->mensagemEAcao('wamid.X1');

        $this->assertFalse($this->fila->aplicarStatus(new Status('wamid.X1', 'teletransportado')));
        $this->assertSame('enviado', DB::table('conversa_mensagem')->where('wamid', 'wamid.X1')->value('status'));
    }

    #[Test]
    public function falha_guarda_o_codigo_e_o_texto_da_meta(): void
    {
        [$acao] = $this->mensagemEAcao('wamid.X1');

        $this->fila->aplicarStatus(new Status('wamid.X1', 'failed', 131049, 'Limite do destinatário'));

        $m = (array) DB::table('conversa_mensagem')->where('wamid', 'wamid.X1')->first();
        $this->assertSame('falhou', $m['status']);
        $this->assertSame(131049, (int) $m['erro_codigo']);

        $this->assertStringContainsString('Limite do destinatário', (string) DB::table('acao')->where('id', $acao)->value('resposta'));
    }

    /** `wamid` que não existe aqui não cria linha: o estado é de mensagem que não é nossa. */
    #[Test]
    public function estado_de_wamid_desconhecido_nao_cria_nada(): void
    {
        $this->assertTrue($this->fila->aplicarStatus(new Status('wamid.NUNCA_VISTO', 'delivered')));

        $this->assertSame(0, DB::table('conversa_mensagem')->count());
        $this->assertSame(0, DB::table('acao')->count());
    }

    // ------------------------------------------------------------------- entrada

    /**
     * 🔴 `ultima_entrada_em` É A JANELA DE 24 HORAS.
     *
     * Dentro dela, texto livre é ilimitado e não custa por mensagem; fora, só template
     * pago. Errar este carimbo é errar o custo de operação e a possibilidade de responder.
     */
    #[Test]
    public function registrar_entrada_cria_a_conversa_e_abre_a_janela(): void
    {
        $canonico = $this->fila->registrarEntrada(
            new Entrada('wamid.E1', '5511987654321', 'text', 'quero saber mais')
        );

        $this->assertSame('5511987654321', $canonico);

        $c = (array) DB::table('conversa')->where('telefone', '5511987654321')->first();
        $this->assertNotNull($c['ultima_entrada_em']);

        $m = (array) DB::table('conversa_mensagem')->where('wamid', 'wamid.E1')->first();
        $this->assertSame('entrada', $m['direcao']);
        $this->assertSame('quero saber mais', $m['texto']);
        $this->assertSame((int) $c['id'], (int) $m['conversa_id']);
    }

    /**
     * Segunda mensagem MOVE a janela e não cria outra Conversa.
     *
     * Duas Conversas para o mesmo número dividiriam o histórico em duas caixas, e a
     * janela de cada uma contaria de um momento diferente.
     */
    #[Test]
    public function segunda_entrada_move_a_janela_sem_duplicar_a_conversa(): void
    {
        $this->fila->registrarEntrada(new Entrada('wamid.E1', '5511987654321', 'text', 'oi'));

        DB::table('conversa')->where('telefone', '5511987654321')
            ->update(['ultima_entrada_em' => now()->subHours(20)]);
        $antes = (string) DB::table('conversa')->where('telefone', '5511987654321')->value('ultima_entrada_em');

        $this->fila->registrarEntrada(new Entrada('wamid.E2', '5511987654321', 'text', 'ainda aí?'));

        $depois = (string) DB::table('conversa')->where('telefone', '5511987654321')->value('ultima_entrada_em');

        $this->assertSame(1, DB::table('conversa')->count());
        $this->assertSame(2, DB::table('conversa_mensagem')->count());
        $this->assertGreaterThan($antes, $depois, 'a janela de 24 h tem que reabrir');
    }

    /** Reprocessar o mesmo evento é no-op: a Meta reentrega quando não recebe 200. */
    #[Test]
    public function reprocessar_a_mesma_entrada_nao_duplica_a_mensagem(): void
    {
        $e = new Entrada('wamid.E1', '5511987654321', 'text', 'oi');

        $this->fila->registrarEntrada($e);
        $this->fila->registrarEntrada($e);

        $this->assertSame(1, DB::table('conversa_mensagem')->count());
    }

    #[Test]
    public function entrada_vincula_o_lead_quando_ele_existe(): void
    {
        $campanha = (int) DB::table('campanha')->insertGetId(['nome' => 'C', 'tipo' => 'opt_in', 'status' => 1]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 3,
        ]);

        $this->fila->registrarEntrada(new Entrada('wamid.E1', '5511987654321', 'text', 'oi'));

        $this->assertSame($lead, (int) DB::table('conversa')->where('telefone', '5511987654321')->value('lead_id'));
    }

    /**
     * Sem Lead a Conversa vale igual.
     *
     * A pessoa pode escrever sem nunca ter sido importada — encaminhada por alguém, ou
     * respondendo a outra campanha. Exigir o Lead descartaria a mensagem.
     */
    #[Test]
    public function entrada_sem_lead_ainda_cria_a_conversa(): void
    {
        $this->fila->registrarEntrada(new Entrada('wamid.E1', '5511987654321', 'text', 'oi'));

        $c = (array) DB::table('conversa')->where('telefone', '5511987654321')->first();
        $this->assertNull($c['lead_id']);
        $this->assertSame(1, DB::table('conversa_mensagem')->count());
    }

    /**
     * O número da Meta é normalizado de todo jeito.
     *
     * Ela manda dígitos com país, que já é o nosso canônico. Se um dia vier com `+`, a
     * Conversa não pode virar uma linha nova em paralelo à que já existe — seriam duas
     * caixas para a mesma pessoa, e o bloqueio por igualdade exata deixaria de casar.
     */
    #[Test]
    public function numero_com_mais_e_normalizado_para_a_mesma_conversa(): void
    {
        $this->fila->registrarEntrada(new Entrada('wamid.E1', '5511987654321', 'text', 'oi'));
        $this->fila->registrarEntrada(new Entrada('wamid.E2', '+55 11 98765-4321', 'text', 'de novo'));

        $this->assertSame(1, DB::table('conversa')->count());
        $this->assertSame(2, DB::table('conversa_mensagem')->count());
    }

    /**
     * 🔴 A Meta manda celular antigo SEM o nono dígito. Em produção eram 463 de 480
     * entradas. A resposta tem que cair na MESMA Conversa do Disparo (13 dígitos) e
     * vincular o Lead — antes caía numa Conversa órfã de 12 dígitos, e a janela de 24
     * horas da Conversa do Disparo nunca abria.
     */
    #[Test]
    public function wa_id_sem_o_nono_digito_cai_na_conversa_do_lead(): void
    {
        $campanha = (int) DB::table('campanha')->insertGetId(['nome' => 'C', 'tipo' => 'opt_in', 'status' => 1]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 3,
        ]);

        $canonico = $this->fila->registrarEntrada(new Entrada('wamid.E1', '551187654321', 'text', 'oi'));

        $this->assertSame('5511987654321', $canonico);
        $this->assertSame(1, DB::table('conversa')->count());
        $this->assertSame($lead, (int) DB::table('conversa')->value('lead_id'));
    }

    // ------------------------------------------------------------------- opt-out

    /**
     * 🔴 O "PARAR" de quem tem celular antigo tem que impedir a discagem do Lead de 13
     * dígitos. Antes o `bloqueio` recebia os 12 dígitos crus, e o dialer — que compara
     * por igualdade — seguia discando.
     */
    #[Test]
    public function opt_out_de_wa_id_sem_o_nono_digito_bloqueia_o_lead(): void
    {
        $telefone = $this->fila->registrarEntrada(new Entrada('wamid.E1', '551187654321', 'text', 'PARAR'));
        $this->fila->bloquear($telefone);

        $this->assertTrue((new Fila(DB::connection()->getPdo()))->bloqueado('5511987654321'));
    }

    /** O que o "SAIR" promete não é a linha em `bloqueio`: é a próxima chamada não sair. */
    #[Test]
    public function bloquear_impede_a_discagem(): void
    {
        $this->fila->bloquear('5511987654321');

        $b = (array) DB::table('bloqueio')->where('telefone', '5511987654321')->first();
        $this->assertSame('opt_out', $b['origem']);

        $this->assertTrue((new Fila(DB::connection()->getPdo()))->bloqueado('5511987654321'));
    }

    #[Test]
    public function bloquear_duas_vezes_e_no_op(): void
    {
        $this->fila->bloquear('5511987654321');
        $this->fila->bloquear('5511987654321');

        $this->assertSame(1, DB::table('bloqueio')->count());
    }

    // ---------------------------------------------------------------- auxiliares

    /** `id_externo` é a chave de idempotência contra a reentrega da Meta. */
    private function evento(string $payload): int
    {
        return (int) DB::table('evento_whatsapp')->insertGetId([
            'payload' => $payload,
            'id_externo' => 'ext-' . md5($payload),
        ]);
    }

    /**
     * Uma mensagem de saída `enviado` e a Ação correspondente, ligadas pelo mesmo wamid.
     *
     * @return array{0:int, 1:int} id da Ação, id da mensagem
     */
    private function mensagemEAcao(string $wamid): array
    {
        $campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 1,
            'canal_opt_in' => 'whatsapp', 'template_nome' => 'tpl',
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 3,
        ]);
        $ligacao = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha,
            'numero_tentativa' => 1, 'iniciada_em' => now(), 'desfecho' => 'opt_in',
        ]);
        $acao = (int) DB::table('acao')->insertGetId([
            'tipo' => 'disparo', 'referencia_id' => $ligacao,
            'status' => 'enviado', 'wamid' => $wamid,
        ]);
        $conversa = (int) DB::table('conversa')->insertGetId([
            'telefone' => '5511987654321', 'lead_id' => $lead,
        ]);
        $mensagem = (int) DB::table('conversa_mensagem')->insertGetId([
            'conversa_id' => $conversa, 'wamid' => $wamid, 'direcao' => 'saida',
            'tipo' => 'template', 'status' => 'enviado',
        ]);

        return [$acao, $mensagem];
    }
}
