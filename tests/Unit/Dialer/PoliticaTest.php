<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Acao;
use App\Dialer\Politica;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Esta suíte É a tabela medida em 2026-08-09 contra tronco real. Cada caso
 * corresponde a uma chamada que aconteceu.
 */
final class PoliticaTest extends TestCase
{
    /** @return list<array{string,Acao,bool,string}> codigo, ação, conta p/ proteção, apelido */
    public static function medidos(): array
    {
        return [
            ['SIP 404 Not Found',            Acao::Aposentar,  false, 'número inexistente é PERMANENTE'],
            ['SIP 480 Temporarily Unavailable', Acao::Retentar, false, 'desligado é TEMPORÁRIO'],
            ['SIP 486 Busy Here',            Acao::RecuoLongo, false, '486 é NÃO PERTURBE nesta rota'],
            ['SIP 487 Request Terminated',   Acao::Retentar,   false, '487 é NOSSO CANCEL'],
            ['SIP 503 Service Unavailable',  Acao::Retentar,   true,  '503 pode ser da conta'],
        ];
    }

    #[Test]
    #[DataProvider('medidos')]
    public function decide_conforme_a_tabela_medida(string $codigo, Acao $acao, bool $protecao, string $apelido): void
    {
        $d = (new Politica())->decidir(self::evento($codigo, 'CONGESTION'), false);

        $this->assertSame($acao, $d->acao, $apelido);
        $this->assertSame($protecao, $d->contaParaProtecao, $apelido);
    }

    #[Test]
    public function numero_inexistente_aposenta_em_vez_de_gastar_as_tentativas(): void
    {
        // Não atribuído nunca vai funcionar. Gastar max_tentativas nele é
        // desperdício em TODA rodada da Campanha.
        $d = (new Politica())->decidir(self::evento('SIP 404 Not Found', 'CHANUNAVAIL'), false);

        $this->assertSame(Acao::Aposentar, $d->acao);
        $this->assertSame('numero_inexistente', $d->motivo);
    }

    #[Test]
    public function o_503_CONSOME_a_tentativa(): void
    {
        // A formulação anterior do projeto era "erro de conta não consome
        // tentativa". A medição derrubou: 503 também vem para número ruim, então
        // isentá-lo faria número ruim ser rediscado para sempre.
        $d = (new Politica())->decidir(self::evento('SIP 503 Service Unavailable', 'CONGESTION'), false);

        $this->assertTrue($d->consomeTentativa);
        $this->assertTrue($d->contaParaProtecao);
        $this->assertSame(Acao::Retentar, $d->acao);
    }

    #[Test]
    public function atendida_e_finalizada_porque_o_fastagi_gravou_o_desfecho(): void
    {
        foreach ([['SIP 200 OK', 'ANSWER', false], [null, 'ANSWER', false], [null, null, true]] as [$c, $ds, $at]) {
            $this->assertSame(Acao::Finalizado, (new Politica())->decidir(self::evento($c, $ds), $at)->acao);
        }
    }

    #[Test]
    public function codigo_desconhecido_retenta_e_NAO_pausa_a_campanha(): void
    {
        // Contar desconhecido arriscaria pausar por condição nova e inofensiva.
        $d = (new Politica())->decidir(self::evento('SIP 599 Coisa Nova', 'CONGESTION'), false);

        $this->assertSame(Acao::Retentar, $d->acao);
        $this->assertFalse($d->contaParaProtecao);
        $this->assertSame('desconhecido', $d->motivo);
    }

    #[Test]
    public function codigo_ausente_nao_derruba_a_decisao(): void
    {
        $d = (new Politica())->decidir(self::evento(null, 'CONGESTION'), false);

        $this->assertSame(Acao::Retentar, $d->acao);
        $this->assertFalse($d->contaParaProtecao);
    }

    #[Test]
    public function extrai_o_numero_de_formatos_diferentes_de_codigo(): void
    {
        foreach (['SIP 404 Not Found', '404', 'SIP/2.0 404 Not Found'] as $forma) {
            $this->assertSame(
                Acao::Aposentar,
                (new Politica())->decidir(self::evento($forma, null), false)->acao,
                "forma: {$forma}",
            );
        }
    }

    /**
     * O desfecho de chamada ATENDIDA tem que ser `null`: o `fastagi` é o dono da
     * coluna. Este teste é a trava contra o dialer voltar a escrever ali e
     * sobrescrever `opt_in` numa corrida pelo mesmo hangup.
     */
    #[Test]
    public function chamada_atendida_nao_recebe_desfecho_do_dialer(): void
    {
        $this->assertNull((new Politica())->decidir(self::evento('200', 'ANSWER'), true)->desfecho);
        $this->assertNull((new Politica())->decidir(self::evento(null, null), true)->desfecho);
    }

    #[Test]
    public function desfecho_por_codigo_sip_medido(): void
    {
        $esperado = [
            '404' => 'invalido',      // não atribuído
            '480' => 'nao_atendeu',   // desligado ou fora de área
            '486' => 'nao_atendeu',   // não perturbe
            '487' => 'nao_atendeu',   // nosso CANCEL por timeout
            '503' => 'erro',          // circuito ou conta, NÃO o Lead
            '403' => 'erro',          // conta
            '599' => 'erro',          // desconhecido
        ];

        foreach ($esperado as $codigo => $desfecho) {
            $this->assertSame(
                $desfecho,
                (new Politica())->decidir(self::evento((string) $codigo, null), false)->desfecho,
                "codigo {$codigo}",
            );
        }
    }

    /**
     * Todo desfecho que a Politica produz precisa existir no enum da coluna.
     * Valor fora do enum viraria erro de SQL só em produção, e só no código SIP
     * menos comum — que é onde ninguém está olhando.
     */
    #[Test]
    public function todo_desfecho_produzido_existe_no_enum_da_coluna(): void
    {
        $doEnum = [
            'opt_in', 'opt_out', 'sem_resposta', 'maquina', 'nao_atendeu',
            'invalido', 'erro', 'captura_concluida', 'captura_parcial',
        ];

        $p = new Politica();
        foreach ([null, '200', '403', '404', '480', '486', '487', '503', '599'] as $codigo) {
            foreach ([true, false] as $atendeu) {
                $d = $p->decidir(self::evento($codigo, null), $atendeu)->desfecho;
                if ($d !== null) {
                    $this->assertContains($d, $doEnum, "codigo {$codigo}");
                }
            }
        }

        // E o mesmo pelos caminhos do `Reason`, que não passam por código SIP nenhum.
        // Um `Reason` novo do Asterisk cairia no `desconhecido`, mas o dia em que um
        // deles virar regra, a trava tem que já estar cobrindo esta porta.
        foreach (['0', '3', '4', '5', '8', 'lixo'] as $reason) {
            foreach ([true, false] as $atendeu) {
                $d = $p->decidir(['Reason' => $reason], $atendeu)->desfecho;
                if ($d !== null) {
                    $this->assertContains($d, $doEnum, "reason {$reason}");
                }
            }
        }
    }
    /**
     * Monta o evento AMI mínimo a partir do par que esta suíte sempre falou.
     *
     * A assinatura de `decidir` passou a receber o evento inteiro; a tabela medida
     * continua sendo expressa como código e `DialStatus`, que é como ela foi medida.
     *
     * @return array<string,mixed>
     */
    private static function evento(?string $codigoSip, ?string $dialStatus): array
    {
        $ev = [];
        if ($codigoSip !== null) {
            // `TechCause` é a fonte primária, e existe a partir do Asterisk 22.7.0.
            $ev['TechCause'] = $codigoSip;
        }
        if ($dialStatus !== null) {
            $ev['DialStatus'] = $dialStatus;
        }

        return $ev;
    }

    /**
     * `Hangup` SEM `TechCause` continua caindo em `desconhecido`, e isso está certo.
     *
     * Quadro real capturado em 22/08/2026, originando um canal `Local` para um
     * contexto que não atende: `Cause: 19` e `Cause-txt: User alerting, no answer`,
     * sem `TechCause`. O recurso para `Cause-txt` não salva, porque
     * `codigoNumerico()` procura três dígitos seguidos e a prosa não tem nenhum.
     *
     * 🔴 O Q.850 NÃO É MAPEADO AQUI DE PROPÓSITO, e a medição de 24/08/2026 é o
     * motivo. Contra tronco real, `Cause: 19` veio acompanhado de `TechCause` com
     * `480` nas 424 ocorrências — ou seja, esta forma pobre não é o que a rota
     * produz. Mapear `19` seria escrever regra a partir de um quadro de laboratório
     * em vez da distribuição medida, que é exatamente o erro que a medição existiu
     * para evitar.
     */
    #[Test]
    public function hangup_sem_TechCause_cai_em_desconhecido_e_o_q850_fica_guardado(): void
    {
        $d = (new Politica())->decidir([
            'Event' => 'Hangup',
            'Cause' => '19',
            'Cause-txt' => 'User alerting, no answer',
        ], false);

        $this->assertSame('desconhecido', $d->motivo);
        $this->assertSame('erro', $d->desfecho);

        // Afirmado, e não navegado com `?->`: a decisão SEMPRE carrega o que leu, e um
        // `?->` aqui deixaria o teste passar em silêncio se um dia parasse de carregar.
        $sinal = $d->sinal;
        $this->assertNotNull($sinal);
        $this->assertSame(19, $sinal->q850);
        $this->assertNull($sinal->codigoNumerico(), 'prosa não tem três dígitos');
        $this->assertNull($sinal->controleDeOriginacao(), 'Hangup não traz Reason');
    }

    /**
     * 🔴 A CORREÇÃO DE 48% DAS LIGAÇÕES, e ela cabe em duas linhas de tabela.
     *
     * Medido em 24/08/2026 sobre 8.829 Ligações de campanha real: 4.237 delas —
     * 96,9% de tudo o que virava `desconhecido` — chegam por `OriginateResponse`
     * sem código SIP nenhum, só com `Reason`. Nenhuma das 4.237 tinha
     * `atendida_em` preenchido.
     *
     * A ação NÃO muda: continua `Retentar`, consumindo tentativa, fora da proteção
     * — exatamente como o `487`, que é a mesma condição por outro evento. O que
     * muda é o desfecho deixar de dizer `erro` ao operador.
     *
     * @return list<array{string,string,string,string}> Reason, motivo, desfecho, apelido
     */
    public static function origincacoesSemCodigoSip(): array
    {
        return [
            ['3', 'nao_atendeu',        'nao_atendeu', 'tocou ~50 s e ninguém atendeu (2.036 medidas)'],
            ['0', 'sem_sinal_de_toque', 'nao_atendeu', 'nem tocou; morreu no nosso timeout de 28 s (2.201)'],
        ];
    }

    #[Test]
    #[DataProvider('origincacoesSemCodigoSip')]
    public function originate_response_sem_codigo_sip_decide_pelo_reason(
        string $reason,
        string $motivo,
        string $desfecho,
        string $apelido,
    ): void {
        $d = (new Politica())->decidir([
            'Event' => 'OriginateResponse',
            'Response' => 'Failure',
            'Reason' => $reason,
        ], false);

        $this->assertSame($motivo, $d->motivo, $apelido);
        $this->assertSame($desfecho, $d->desfecho, $apelido);

        // O comportamento do discador é o do 487 — só o rótulo mudou.
        $this->assertSame(Acao::Retentar, $d->acao, $apelido);
        $this->assertTrue($d->consomeTentativa, $apelido);
        $this->assertFalse($d->contaParaProtecao, $apelido);
    }

    /**
     * Os dois grupos ficam SEPARÁVEIS no log, e isso é o ponto.
     *
     * A medição não conseguiu decidir se `Reason: 0` é "não atendeu" ou "número que
     * não completa": o discriminador é a retentativa, e a Campanha está em
     * `rodada_atual` 0. Motivos distintos é o que permite responder essa pergunta
     * quando uma rodada reabrir, sem precisar mexer no código para voltar a medir.
     */
    #[Test]
    public function tocou_e_nem_tocou_nao_se_confundem_no_relatorio(): void
    {
        $p = new Politica();
        $tocou = $p->decidir(['Reason' => '3'], false);
        $nemTocou = $p->decidir(['Reason' => '0'], false);

        $this->assertNotSame($tocou->motivo, $nemTocou->motivo);
        $this->assertSame($tocou->desfecho, $nemTocou->desfecho, 'o desfecho é o mesmo; o motivo é que separa');
    }

    /**
     * `Reason: 4` é atendimento afirmado, e ignorá-lo tinha custo medido.
     *
     * Em 24/08/2026 as 3.155 ocorrências de `Reason: 4` correspondem a 3.155
     * Ligações com `atendida_em` — sem exceção. Antes deste caso, 45 delas por dia
     * de campanha caíam em `desconhecido` e eram marcadas para RETENTATIVA: o
     * discador ligaria de novo para quem já tinha atendido.
     */
    #[Test]
    public function reason_4_e_atendimento_mesmo_sem_DialStatus(): void
    {
        $d = (new Politica())->decidir([
            'Event' => 'OriginateResponse',
            'Response' => 'Success',
            'Reason' => '4',
        ], false);

        $this->assertSame(Acao::Finalizado, $d->acao);
        $this->assertSame('atendida', $d->motivo);
        $this->assertNull($d->desfecho, 'atendida: o fastagi é o dono da coluna');
    }

    /** A decisão devolve o que leu, para o chamador gravar sem re-extrair. */
    #[Test]
    public function a_decisao_carrega_a_sinalizacao_que_leu(): void
    {
        $d = (new Politica())->decidir([
            'TechCause' => 'SIP 486 Busy Here',
            'Cause-txt' => 'User busy',
            'Cause' => '17',
        ], false);

        $sinal = $d->sinal;
        $this->assertNotNull($sinal);
        $this->assertSame('SIP 486 Busy Here', $sinal->codigoSip);
        $this->assertSame('User busy', $sinal->hangupCause, 'é o que vai para ligacao.hangup_cause');
        $this->assertSame(17, $sinal->q850);
    }
}
