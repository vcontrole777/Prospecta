<?php

declare(strict_types=1);

namespace Tests\Unit\Whatsapp;

use App\Whatsapp\JanelaDeAtendimento;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contar isto errado não dá erro — dá mensagem recusada pela plataforma, ou custo que
 * ninguém previu.
 */
final class JanelaDeAtendimentoTest extends TestCase
{
    private const AGORA = '2026-08-10 18:00:00';

    #[Test]
    public function nunca_escreveu_significa_fechada(): void
    {
        $j = new JanelaDeAtendimento(null);

        $this->assertFalse($j->aberta($this->agora()));
        $this->assertSame(0, $j->segundosRestantes($this->agora()));
        $this->assertSame('fechada', $j->restante($this->agora()));
    }

    #[Test]
    public function dentro_das_24_horas_esta_aberta(): void
    {
        foreach (['-1 minute', '-1 hour', '-12 hours', '-23 hours -59 minutes'] as $quando) {
            $j = new JanelaDeAtendimento($this->agora()->modify($quando));
            $this->assertTrue($j->aberta($this->agora()), $quando);
        }
    }

    #[Test]
    public function depois_de_24_horas_esta_fechada(): void
    {
        foreach (['-24 hours', '-24 hours -1 second', '-3 days'] as $quando) {
            $j = new JanelaDeAtendimento($this->agora()->modify($quando));
            $this->assertFalse($j->aberta($this->agora()), $quando);
            $this->assertSame('fechada', $j->restante($this->agora()), $quando);
        }
    }

    #[Test]
    public function conta_o_restante(): void
    {
        $agora = $this->agora();

        $this->assertSame('23 h 00 min', (new JanelaDeAtendimento($agora->modify('-1 hour')))->restante($agora));
        $this->assertSame('12 h 30 min', (new JanelaDeAtendimento($agora->modify('-11 hours -30 minutes')))->restante($agora));
        // Abaixo de uma hora mostra minuto — é quando a decisão fica urgente de fato.
        $this->assertSame('30 min', (new JanelaDeAtendimento($agora->modify('-23 hours -30 minutes')))->restante($agora));
        // Nunca "0 min" com a janela aberta: leria como fechada.
        $this->assertSame('1 min', (new JanelaDeAtendimento($agora->modify('-23 hours -59 minutes -30 seconds')))->restante($agora));
    }

    /**
     * 🔴 Entrada no FUTURO devolve a janela cheia, não número negativo.
     *
     * Acontece com relógio dessincronizado entre banco e aplicação. Um "-3600 segundos
     * restantes" na tela leria como FECHADA quando a janela está aberta — e o operador
     * deixaria de responder de graça.
     */
    #[Test]
    public function entrada_no_futuro_nao_vira_negativo(): void
    {
        $j = new JanelaDeAtendimento($this->agora()->modify('+2 hours'));

        $this->assertTrue($j->aberta($this->agora()));
        $this->assertSame(24 * 3600, $j->segundosRestantes($this->agora()));
    }

    #[Test]
    public function acabando_avisa_nas_ultimas_horas(): void
    {
        $agora = $this->agora();

        $this->assertFalse((new JanelaDeAtendimento($agora->modify('-1 hour')))->acabando($agora), '23 h restantes');
        $this->assertTrue((new JanelaDeAtendimento($agora->modify('-23 hours')))->acabando($agora), '1 h restante');
        $this->assertFalse((new JanelaDeAtendimento($agora->modify('-25 hours')))->acabando($agora), 'já fechada não é "acabando"');
    }

    private function agora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AGORA);
    }
}
