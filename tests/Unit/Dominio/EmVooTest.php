<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\EmVoo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** O vocabulário da tela de chamadas ao vivo. */
final class EmVooTest extends TestCase
{
    #[Test]
    public function sem_atendimento_esta_chamando(): void
    {
        $this->assertSame(EmVoo::CHAMANDO, EmVoo::estado(null));
        // String vazia é o que alguns drivers devolvem no lugar de NULL; tratar como
        // "atendida" poria a Ligação na URA sem ninguém ter atendido.
        $this->assertSame(EmVoo::CHAMANDO, EmVoo::estado(''));
        $this->assertSame(EmVoo::CHAMANDO, EmVoo::estado('   '));
    }

    #[Test]
    public function com_atendimento_esta_na_ura(): void
    {
        $this->assertSame(EmVoo::NA_URA, EmVoo::estado('2026-08-26 14:53:26'));
    }

    #[Test]
    public function percentual_do_teto(): void
    {
        $this->assertSame(50, EmVoo::percentual(30, 60));
        $this->assertSame(100, EmVoo::percentual(60, 60));
        $this->assertSame(0, EmVoo::percentual(0, 60));
    }

    /**
     * 🔴 PASSAR DO TETO NÃO É LIMITADO A 100%.
     *
     * Foi o defeito de 26/08/2026: cada Campanha ganhava o contratado inteiro, e três
     * somavam 300 canais num tronco de 100. Limitar em 100% na tela teria escondido
     * justamente o número que denuncia isso — o operador via 100%, que parece o
     * normal, e o sintoma só aparecia como 503 do lado da operadora.
     */
    #[Test]
    public function acima_do_teto_passa_de_cem(): void
    {
        $this->assertSame(300, EmVoo::percentual(300, 100));
        $this->assertSame(195, EmVoo::percentual(117, 60));
    }

    /**
     * Teto desconhecido é `null`, e não zero.
     *
     * Um "0%" em cima de 200 chamadas no ar é pior que um traço: parece tranquilizador
     * e é falso. Zero também protege da divisão por zero.
     */
    #[Test]
    public function sem_teto_nao_ha_percentual(): void
    {
        $this->assertNull(EmVoo::percentual(200, 0));
        $this->assertNull(EmVoo::percentual(0, 0));
        $this->assertNull(EmVoo::percentual(10, -5));
    }

    /** Arredonda, para a coluna não ficar com casa decimal que ninguém lê. */
    #[Test]
    public function arredonda(): void
    {
        $this->assertSame(33, EmVoo::percentual(1, 3));
        $this->assertSame(67, EmVoo::percentual(2, 3));
    }
}
