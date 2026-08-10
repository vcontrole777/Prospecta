<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Ritmo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RitmoTest extends TestCase
{
    #[Test]
    public function preenche_os_canais_livres(): void
    {
        // Malha FECHADA: conta canal livre de verdade, não aplica taxa.
        $this->assertSame(13, (new Ritmo())->quantasOriginar(60, 47, 500));
    }

    #[Test]
    public function nunca_passa_do_contratado(): void
    {
        $this->assertSame(0, (new Ritmo())->quantasOriginar(60, 60, 500));
        $this->assertSame(0, (new Ritmo())->quantasOriginar(60, 99, 500));
    }

    #[Test]
    public function a_fila_curta_limita(): void
    {
        // Não há como originar mais que os Leads disponíveis.
        $this->assertSame(4, (new Ritmo())->quantasOriginar(60, 0, 4));
        $this->assertSame(0, (new Ritmo())->quantasOriginar(60, 0, 0));
    }

    #[Test]
    public function o_teto_de_CPS_da_conta_limita_a_volta(): void
    {
        // 200 canais livres, mas a conta aceita 5 chamadas por segundo. Estourar
        // volta como 503 e dispara a proteção — comportamento certo, mensagem
        // confusa. Melhor não estourar.
        $r = new Ritmo(cpsMaximo: 5);

        $this->assertSame(5, $r->quantasOriginar(200, 0, 500, 1.0));
        $this->assertSame(10, $r->quantasOriginar(200, 0, 500, 2.0));
    }

    #[Test]
    public function sem_teto_de_CPS_preenche_tudo_de_uma_vez(): void
    {
        $this->assertSame(200, (new Ritmo())->quantasOriginar(200, 0, 500));
    }

    #[Test]
    public function espaca_as_originacoes_dentro_da_volta(): void
    {
        // Entregar o lote no mesmo instante a operadora leria como pico de CPS.
        $r = new Ritmo();

        $this->assertSame(0, $r->espacamentoMicro(1));
        $this->assertSame(0, $r->espacamentoMicro(0));
        $this->assertSame(100_000, $r->espacamentoMicro(10, 1.0));
        $this->assertSame(20_000, $r->espacamentoMicro(50, 1.0));
    }

    #[Test]
    public function o_teto_de_CPS_nunca_zera_a_volta(): void
    {
        // Com intervalo curto o teto arredondaria para zero e o dialer travaria.
        $r = new Ritmo(cpsMaximo: 1);

        $this->assertGreaterThanOrEqual(1, $r->quantasOriginar(60, 0, 500, 0.2));
    }
}
