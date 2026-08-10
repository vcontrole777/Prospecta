<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Protecao;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProtecaoTest extends TestCase
{
    #[Test]
    public function tronco_morto_pausa(): void
    {
        // 100% de erro de conta e zero atendimento: é a assinatura de tronco
        // morto, e é o caso que a proteção existe para pegar.
        $this->assertTrue((new Protecao())->devePausar(20, 20, 0));
    }

    #[Test]
    public function horario_de_almoco_NAO_pausa(): void
    {
        // O caso que derrubou a regra de sequência crua: ocupado devolve 503, e
        // muita gente ao telefone geraria uma sequência longa de 503 sem nada
        // estar errado. Uma atendida prova que o tronco funciona.
        $this->assertFalse((new Protecao())->devePausar(20, 18, 2));
    }

    #[Test]
    public function uma_unica_atendida_ja_desarma_a_protecao(): void
    {
        $this->assertFalse((new Protecao())->devePausar(50, 49, 1));
    }

    #[Test]
    public function amostra_pequena_nao_pausa(): void
    {
        // Não pausar Campanha por três chamadas.
        $this->assertFalse((new Protecao())->devePausar(3, 3, 0));
        $this->assertFalse((new Protecao())->devePausar(9, 9, 0));
        $this->assertTrue((new Protecao())->devePausar(10, 10, 0));
    }

    #[Test]
    public function taxa_abaixo_do_limite_nao_pausa(): void
    {
        // 80% de erro sem atendimento ainda pode ser lista ruim, não tronco.
        $this->assertFalse((new Protecao())->devePausar(100, 80, 0));
        $this->assertTrue((new Protecao())->devePausar(100, 90, 0));
    }

    #[Test]
    public function a_explicacao_nomeia_o_estouro_de_CPS_como_causa_possivel(): void
    {
        // Estouro de CPS da operadora também volta como 503. Sem isto o operador
        // lê "campanha pausada" sem saber que o motivo pode ser RITMO.
        $texto = (new Protecao())->explicacao(20, 20);

        $this->assertStringContainsString('20 de 20', $texto);
        $this->assertStringContainsString('100%', $texto);
        $this->assertStringContainsString('chamadas por segundo', $texto);
    }

    #[Test]
    public function zero_tentativas_nao_divide_por_zero(): void
    {
        $this->assertFalse((new Protecao())->devePausar(0, 0, 0));
        $this->assertStringContainsString('0 de 0', (new Protecao())->explicacao(0, 0));
    }
}
