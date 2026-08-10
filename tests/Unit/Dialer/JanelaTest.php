<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Janela;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JanelaTest extends TestCase
{
    /**
     * 🔴 Sem configuração, LIBERA. Fechar por padrão faria a discadora parar no
     * instante em que esta classe entrasse — sem ninguém mudar nada e sem sinal.
     */
    #[Test]
    public function sem_configuracao_esta_aberta(): void
    {
        $j = new Janela();

        foreach (['2026-08-10 03:00:00', '2026-08-09 23:59:00', '2026-08-15 12:00:00'] as $t) {
            $this->assertTrue($j->aberta(new DateTimeImmutable($t)), $t);
        }
    }

    #[Test]
    public function janela_normal_de_comercio(): void
    {
        $j = new Janela('09:00', '18:00');

        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-10 08:59:00')), 'um minuto antes');
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 09:00:00')), 'na abertura');
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 17:59:00')));
        // O fim é EXCLUSIVO: às 18:00 já não se origina. Inclusivo faria uma chamada
        // começar às 18:00 e a pessoa atender às 18:00:40.
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-10 18:00:00')), 'no fechamento');
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-10 03:00:00')), 'madrugada');
    }

    /**
     * 🔴 Janela que ATRAVESSA A MEIA-NOITE. Sem tratar este caso, a comparação simples
     * devolve falso o tempo todo e a Campanha nunca disca — com a configuração
     * parecendo correta na tela.
     */
    #[Test]
    public function janela_que_atravessa_a_meia_noite(): void
    {
        $j = new Janela('20:00', '02:00');

        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 20:00:00')));
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 23:59:00')));
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-11 00:30:00')), 'depois da meia-noite');
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-11 01:59:00')));
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-11 02:00:00')));
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-11 12:00:00')));
    }

    /**
     * 🔴 ISO-8601: 1 é segunda e 7 é domingo. O `w` do PHP começa no domingo com 0, e
     * confundir os dois desloca a semana inteira em um dia — erro que passa por seis
     * dias e aparece no sétimo.
     */
    #[Test]
    public function dias_da_semana_em_iso(): void
    {
        // Seg a sex. 2026-08-10 é uma segunda.
        $j = new Janela('00:00', '23:59', [1, 2, 3, 4, 5]);

        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 10:00:00')), 'segunda');
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-14 10:00:00')), 'sexta');
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-15 10:00:00')), 'sábado');
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-16 10:00:00')), 'domingo');

        // Só domingo, para provar que 7 é domingo e não sábado.
        $so = new Janela('00:00', '23:59', [7]);
        $this->assertTrue($so->aberta(new DateTimeImmutable('2026-08-16 10:00:00')), '7 é domingo');
        $this->assertFalse($so->aberta(new DateTimeImmutable('2026-08-15 10:00:00')), '7 não é sábado');
    }

    #[Test]
    public function dia_e_hora_tem_que_valer_os_dois(): void
    {
        $j = new Janela('09:00', '18:00', [1, 2, 3, 4, 5]);

        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-15 10:00:00')), 'sábado no horário');
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-10 03:00:00')), 'segunda fora do horário');
        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 10:00:00')), 'os dois valem');
    }

    #[Test]
    public function hora_ilegivel_ou_impossivel_libera_em_vez_de_travar(): void
    {
        foreach (['', 'meio-dia', '25:00', '09:99', 'abc'] as $ruim) {
            $this->assertTrue(
                (new Janela($ruim, '18:00'))->aberta(new DateTimeImmutable('2026-08-10 03:00:00')),
                "início: {$ruim}",
            );
        }

        // Início igual ao fim é configuração sem sentido; liberar é melhor que uma
        // Campanha que nunca disca sem explicação.
        $this->assertTrue((new Janela('09:00', '09:00'))->aberta(new DateTimeImmutable('2026-08-10 03:00:00')));
    }

    #[Test]
    public function aceita_hora_com_segundos_como_o_mysql_devolve(): void
    {
        $j = new Janela('09:00:00', '18:00:00');

        $this->assertTrue($j->aberta(new DateTimeImmutable('2026-08-10 10:00:00')));
        $this->assertFalse($j->aberta(new DateTimeImmutable('2026-08-10 03:00:00')));
    }

    #[Test]
    public function descreve_para_o_log_e_para_a_tela(): void
    {
        $this->assertSame('sem restrição de horário', (new Janela())->descricao());
        $this->assertSame('09:00 às 18:00, todos os dias', (new Janela('09:00:00', '18:00:00'))->descricao());
        $this->assertSame('09:00 às 18:00, seg, ter, qua, qui, sex', (new Janela('09:00', '18:00', [1, 2, 3, 4, 5]))->descricao());
    }
}
