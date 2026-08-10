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
        $d = (new Politica())->decidir($codigo, 'CONGESTION', false);

        $this->assertSame($acao, $d->acao, $apelido);
        $this->assertSame($protecao, $d->contaParaProtecao, $apelido);
    }

    #[Test]
    public function numero_inexistente_aposenta_em_vez_de_gastar_as_tentativas(): void
    {
        // Não atribuído nunca vai funcionar. Gastar max_tentativas nele é
        // desperdício em TODA rodada da Campanha.
        $d = (new Politica())->decidir('SIP 404 Not Found', 'CHANUNAVAIL', false);

        $this->assertSame(Acao::Aposentar, $d->acao);
        $this->assertSame('numero_inexistente', $d->motivo);
    }

    #[Test]
    public function o_503_CONSOME_a_tentativa(): void
    {
        // A formulação anterior do projeto era "erro de conta não consome
        // tentativa". A medição derrubou: 503 também vem para número ruim, então
        // isentá-lo faria número ruim ser rediscado para sempre.
        $d = (new Politica())->decidir('SIP 503 Service Unavailable', 'CONGESTION', false);

        $this->assertTrue($d->consomeTentativa);
        $this->assertTrue($d->contaParaProtecao);
        $this->assertSame(Acao::Retentar, $d->acao);
    }

    #[Test]
    public function atendida_e_finalizada_porque_o_fastagi_gravou_o_desfecho(): void
    {
        foreach ([['SIP 200 OK', 'ANSWER', false], [null, 'ANSWER', false], [null, null, true]] as [$c, $ds, $at]) {
            $this->assertSame(Acao::Finalizado, (new Politica())->decidir($c, $ds, $at)->acao);
        }
    }

    #[Test]
    public function codigo_desconhecido_retenta_e_NAO_pausa_a_campanha(): void
    {
        // Contar desconhecido arriscaria pausar por condição nova e inofensiva.
        $d = (new Politica())->decidir('SIP 599 Coisa Nova', 'CONGESTION', false);

        $this->assertSame(Acao::Retentar, $d->acao);
        $this->assertFalse($d->contaParaProtecao);
        $this->assertSame('desconhecido', $d->motivo);
    }

    #[Test]
    public function codigo_ausente_nao_derruba_a_decisao(): void
    {
        $d = (new Politica())->decidir(null, 'CONGESTION', false);

        $this->assertSame(Acao::Retentar, $d->acao);
        $this->assertFalse($d->contaParaProtecao);
    }

    #[Test]
    public function extrai_o_numero_de_formatos_diferentes_de_codigo(): void
    {
        foreach (['SIP 404 Not Found', '404', 'SIP/2.0 404 Not Found'] as $forma) {
            $this->assertSame(
                Acao::Aposentar,
                (new Politica())->decidir($forma, null, false)->acao,
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
        $this->assertNull((new Politica())->decidir('200', 'ANSWER', true)->desfecho);
        $this->assertNull((new Politica())->decidir(null, null, true)->desfecho);
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
                (new Politica())->decidir((string) $codigo, null, false)->desfecho,
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
                $d = $p->decidir($codigo, null, $atendeu)->desfecho;
                if ($d !== null) {
                    $this->assertContains($d, $doEnum, "codigo {$codigo}");
                }
            }
        }
    }
}
