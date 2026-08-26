<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Ritmo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O teto da INSTÂNCIA, que não existia.
 *
 * 🔴 `PROSPECTA_CANAIS` ERA CHAMADO DE "CONTRATADO DA INSTÂNCIA" E NÃO ERA.
 *
 * `quantasOriginar` roda uma vez POR CAMPANHA, com os ocupados daquela Campanha. O
 * teto era aplicado dentro do laço, então cada Campanha ativa ganhava o contratado
 * INTEIRO — com três no ar e 100 contratados, o produto segurava até 300 canais do
 * tronco sem ninguém ter pedido.
 *
 * Medido em 26/08/2026: 60 contratados com duas Campanhas deram pico de 117
 * simultâneas; 100 com três deram 170 — e o `503` da rota saltou de 0,66% para
 * 8,95% enquanto o atendimento caía de 44,9% para 19,0%. Tronco recusando é o
 * sintoma de um teto que não era teto.
 */
final class RitmoInstanciaTest extends TestCase
{
    /**
     * 🔴 O DEFEITO, escrito como teste.
     *
     * Três Campanhas, 100 contratados, ninguém em voo. Sem orçamento cada uma pede
     * 100 — 300 no total. Com orçamento, as três somadas não passam de 100.
     */
    #[Test]
    public function sem_orcamento_cada_campanha_pede_o_contratado_inteiro(): void
    {
        $r = new Ritmo();

        $semTeto = 0;
        for ($i = 0; $i < 3; $i++) {
            $semTeto += $r->quantasOriginar(100, 0, 5000);
        }
        $this->assertSame(300, $semTeto, 'é o defeito: 3 x 100 canais do tronco');

        // Com o orçamento descontado a cada Campanha, a soma respeita a instância.
        $orcamento = 100;
        $comTeto = 0;
        for ($i = 0; $i < 3; $i++) {
            $q = $r->quantasOriginar(100, 0, 5000, 1.0, $orcamento);
            $comTeto += $q;
            $orcamento -= $q;
        }
        $this->assertSame(100, $comTeto);
    }

    /**
     * 🔴 OS DOIS TETOS VALEM AO MESMO TEMPO.
     *
     * O da Campanha protege a Campanha; o da instância protege o tronco. Uma
     * Campanha que pediu 20 simultâneas continua limitada a 20 mesmo com a instância
     * inteira ociosa — senão "chamadas simultâneas" na tela deixaria de significar
     * alguma coisa.
     */
    #[Test]
    public function o_teto_da_campanha_continua_valendo_dentro_do_orcamento(): void
    {
        $this->assertSame(20, (new Ritmo())->quantasOriginar(20, 0, 5000, 1.0, 500));
    }

    /** E o orçamento vence quando é ele o menor. */
    #[Test]
    public function o_orcamento_vence_quando_e_o_menor(): void
    {
        $this->assertSame(7, (new Ritmo())->quantasOriginar(100, 0, 5000, 1.0, 7));
    }

    /** Instância cheia não origina nada, mesmo com a Campanha vazia e Lead de sobra. */
    #[Test]
    public function orcamento_zerado_nao_origina(): void
    {
        $r = new Ritmo();

        $this->assertSame(0, $r->quantasOriginar(100, 0, 5000, 1.0, 0));
        // Negativo é instância JÁ estourada — acontece quando uma Campanha foi
        // pausada com chamadas em voo e o contratado foi baixado depois.
        $this->assertSame(0, $r->quantasOriginar(100, 0, 5000, 1.0, -30));
    }

    /**
     * Nulo desliga o teto de instância, e é o que mantém o comportamento antigo
     * para quem chama sem o parâmetro — os testes que já existiam, entre eles.
     */
    #[Test]
    public function nulo_desliga_o_teto_de_instancia(): void
    {
        $this->assertSame(100, (new Ritmo())->quantasOriginar(100, 0, 5000, 1.0, null));
    }

    /** O CPS continua sendo o último teto, depois de campanha e instância. */
    #[Test]
    public function o_cps_ainda_limita_por_cima_de_tudo(): void
    {
        $r = new Ritmo(cpsMaximo: 5);

        $this->assertSame(5, $r->quantasOriginar(100, 0, 5000, 1.0, 80));
        // E não RESSUSCITA o que os outros já zeraram: `max(1, ...)` do CPS vale
        // sobre o teto da volta, não sobre um orçamento esgotado.
        $this->assertSame(0, $r->quantasOriginar(100, 0, 5000, 1.0, 0));
    }
}
