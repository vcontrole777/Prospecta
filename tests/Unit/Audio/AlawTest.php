<?php

declare(strict_types=1);

namespace Tests\Unit\Audio;

use App\Audio\Alaw;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A invariante que substitui o `soxi`. Num formato sem cabeçalho, o tamanho é a
 * única coisa verificável — e é suficiente, porque erro de formato erra por
 * fatores, não por bytes.
 */
final class AlawTest extends TestCase
{
    #[Test]
    public function oito_mil_bytes_e_um_segundo(): void
    {
        $a = new Alaw();

        $this->assertSame(1.0, $a->duracaoSeg(8000));
        $this->assertSame(2.5, $a->duracaoSeg(20000));
        $this->assertSame(8000, $a->tamanhoEsperado(1.0));
        $this->assertSame(24000, $a->tamanhoEsperado(3.0));
    }

    /**
     * 🔴 O caso que a checagem existe para pegar. Medido: 2 s convertidos sem
     * `-r 8000 -c 1` saíram com 176400 bytes onde 16000 eram esperados — 11×.
     */
    #[Test]
    public function recusa_o_tamanho_de_44k_estereo(): void
    {
        $a = new Alaw();

        $this->assertTrue($a->tamanhoConfere(16000, 2.0), '8 kHz mono: exato');
        $this->assertFalse($a->tamanhoConfere(176400, 2.0), '44,1 kHz estéreo: 11x');
        $this->assertFalse($a->tamanhoConfere(32000, 2.0), '16 kHz: 2x');
        $this->assertFalse($a->tamanhoConfere(32000, 2.0), 'mono 16 kHz ou estéreo 8 kHz');
    }

    /** Arredondamento de quadro do reamostrador muda o total em dezenas de bytes. */
    #[Test]
    public function tolera_arredondamento_de_quadro(): void
    {
        $a = new Alaw();

        $this->assertTrue($a->tamanhoConfere(16040, 2.0), '40 bytes a mais passa');
        $this->assertTrue($a->tamanhoConfere(15960, 2.0), '40 bytes a menos passa');
        $this->assertFalse($a->tamanhoConfere(8000, 2.0), 'metade do arquivo NÃO passa');
    }

    #[Test]
    public function duracao_zero_nao_confere_com_nada(): void
    {
        $this->assertFalse((new Alaw())->tamanhoConfere(8000, 0.0));
    }

    /**
     * `-t al` é obrigatório: sem ele o sox recusa a extensão `.alaw` com
     * "no handler for file extension". Este teste é a trava contra alguém remover.
     */
    #[Test]
    public function o_comando_declara_o_tipo_al(): void
    {
        $args = (new Alaw())->argumentosSox('/tmp/a.wav', '/tmp/b.alaw');

        $this->assertSame(['sox', '/tmp/a.wav', '-r', '8000', '-c', '1', '-t', 'al', '/tmp/b.alaw'], $args);

        // A asserção acima já é a afirmação que importa: ARRAY de argumentos, nunca
        // string única. Sem shell no caminho, o contexto shell do ADR-0003 fecha por
        // construção — não há escape para esquecer.
    }
}
