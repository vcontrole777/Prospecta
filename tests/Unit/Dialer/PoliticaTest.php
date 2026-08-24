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
     * 🔴 O CAMINHO DOS 77,3%, agora exercitado por teste.
     *
     * Quadro real capturado em 22/08/2026, originando um canal `Local` para um
     * contexto que não atende. O `Hangup` traz `Cause: 19` e
     * `Cause-txt: User alerting, no answer` — e NENHUM `TechCause`.
     *
     * O recurso para `Cause-txt` não salva: `codigoNumerico()` procura três dígitos
     * seguidos e a prosa não tem nenhum. A Ligação vira `desconhecido` com desfecho
     * `erro`, quando foi o nosso próprio cancelamento por não atender.
     *
     * O teste fixa o comportamento de HOJE, sem mudá-lo. O Q.850 que conserta isto
     * já chega e está no `sinal` — vira regra quando a distribuição real da rota for
     * colhida.
     */
    #[Test]
    public function hangup_sem_TechCause_ainda_cai_em_desconhecido_e_o_q850_fica_guardado(): void
    {
        $d = (new Politica())->decidir([
            'Event' => 'Hangup',
            'Cause' => '19',
            'Cause-txt' => 'User alerting, no answer',
        ], false);

        $this->assertSame('desconhecido', $d->motivo, 'é assim que os 77,3% se perdem');
        $this->assertSame('erro', $d->desfecho);

        // Afirmado, e não navegado com `?->`: a decisão SEMPRE carrega o que leu, e um
        // `?->` aqui deixaria o teste passar em silêncio se um dia parasse de carregar.
        $sinal = $d->sinal;
        $this->assertNotNull($sinal);
        $this->assertSame(19, $sinal->q850, 'o dado que conserta isso já chegou');
        $this->assertNull($sinal->codigoNumerico(), 'prosa não tem três dígitos');
    }

    /** O `OriginateResponse` medido no mesmo dia traz `Reason: 3` e nenhum código SIP. */
    #[Test]
    public function originate_response_guarda_o_reason(): void
    {
        $d = (new Politica())->decidir([
            'Event' => 'OriginateResponse',
            'Response' => 'Failure',
            'Reason' => '3',
        ], false);

        $sinal = $d->sinal;
        $this->assertNotNull($sinal);
        $this->assertSame('3', $sinal->reason);
        $this->assertSame('desconhecido', $d->motivo);
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
