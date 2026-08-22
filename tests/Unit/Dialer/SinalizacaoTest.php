<?php

declare(strict_types=1);

namespace Tests\Unit\Dialer;

use App\Dialer\Sinalizacao;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A leitura do evento AMI, que até agora vivia solta num laço de `bin/dialer.php`
 * e por isso nunca teve um teste.
 *
 * Estes casos são quadros REAIS, capturados em 22/08/2026 contra a rota.
 */
final class SinalizacaoTest extends TestCase
{
    #[Test]
    public function techcause_tem_prioridade_sobre_cause_txt(): void
    {
        // `TechCause` existe a partir do Asterisk 22.7.0 — o piso de versão do projeto.
        $s = Sinalizacao::doEvento([
            'TechCause' => 'SIP 503 Service Unavailable',
            'Cause-txt' => 'Normal Clearing',
        ]);

        $this->assertSame('SIP 503 Service Unavailable', $s->codigoSip);
        $this->assertSame(503, $s->codigoNumerico());
    }

    /** `hangup_cause` guarda o `Cause-txt` cru, mesmo quando o código veio do `TechCause`. */
    #[Test]
    public function hangup_cause_e_sempre_o_cause_txt(): void
    {
        $s = Sinalizacao::doEvento([
            'TechCause' => 'SIP 480 Temporarily Unavailable',
            'Cause-txt' => 'Subscriber absent',
        ]);

        $this->assertSame('Subscriber absent', $s->hangupCause);
    }

    /**
     * 🔴 O RECURSO PARA `Cause-txt` É INÚTIL POR CONSTRUÇÃO, e este teste fixa isso.
     *
     * `Cause-txt` é prosa; `codigoNumerico()` procura três dígitos seguidos. O recurso
     * existe desde antes e nunca produziu um código. É por aqui que 20.713 Ligações —
     * 77,3% de todas as não atendidas — viram `desconhecido`.
     *
     * O teste NÃO consagra o comportamento como desejável: ele o torna visível. Quando o
     * Q.850 virar regra, este é o teste que muda.
     */
    #[Test]
    public function o_recurso_para_cause_txt_nunca_produz_codigo(): void
    {
        $s = Sinalizacao::doEvento(['Cause-txt' => 'User alerting, no answer']);

        $this->assertSame('User alerting, no answer', $s->codigoSip, 'o texto entra');
        $this->assertNull($s->codigoNumerico(), 'e não vira código nenhum');
    }

    #[Test]
    public function le_o_q850_que_ninguem_lia(): void
    {
        // Medido originando um canal `Local` para um contexto que não atende.
        $s = Sinalizacao::doEvento([
            'Event' => 'Hangup',
            'Cause' => '19',
            'Cause-txt' => 'User alerting, no answer',
        ]);

        $this->assertSame(19, $s->q850);
    }

    #[Test]
    public function dialstatus_recorre_ao_reason(): void
    {
        $this->assertSame('ANSWER', Sinalizacao::doEvento(['DialStatus' => 'ANSWER'])->dialStatus);
        $this->assertSame('3', Sinalizacao::doEvento(['Reason' => '3'])->dialStatus);
        $this->assertSame(
            'CONGESTION',
            Sinalizacao::doEvento(['DialStatus' => 'CONGESTION', 'Reason' => '3'])->dialStatus,
            'o DialStatus vence quando os dois vêm',
        );
    }

    #[Test]
    public function so_o_dialstatus_ANSWER_afirma_atendimento(): void
    {
        $this->assertTrue(Sinalizacao::doEvento(['DialStatus' => 'ANSWER'])->atendeu());
        $this->assertFalse(Sinalizacao::doEvento(['DialStatus' => 'CONGESTION'])->atendeu());
        $this->assertFalse(Sinalizacao::doEvento([])->atendeu());
    }

    #[Test]
    public function evento_vazio_nao_derruba_nada(): void
    {
        $s = Sinalizacao::doEvento([]);

        $this->assertNull($s->codigoSip);
        $this->assertNull($s->hangupCause);
        $this->assertNull($s->q850);
        $this->assertNull($s->reason);
        $this->assertNull($s->dialStatus);
        $this->assertNull($s->codigoNumerico());
    }

    /**
     * Campo presente e VAZIO conta como ausente.
     *
     * O AMI manda chave com valor vazio, e `''` gravado em `codigo_sip` seria pior que
     * `NULL`: a coluna deixaria de ser nula e o `WHERE codigo_sip IS NULL` que preserva
     * o PRIMEIRO evento pararia de funcionar — o `Hangup`, que chega depois e é mais
     * pobre, sobrescreveria o `DialEnd`.
     */
    #[Test]
    public function campo_vazio_conta_como_ausente(): void
    {
        $s = Sinalizacao::doEvento(['TechCause' => '', 'Cause-txt' => '', 'Cause' => '']);

        $this->assertNull($s->codigoSip);
        $this->assertNull($s->hangupCause);
        $this->assertNull($s->q850);
    }

    /** O Q.850 chega como string do AMI; lixo não vira zero. */
    #[Test]
    public function q850_nao_numerico_e_nulo_em_vez_de_zero(): void
    {
        $this->assertNull(Sinalizacao::doEvento(['Cause' => 'nada disso'])->q850);
        $this->assertSame(0, Sinalizacao::doEvento(['Cause' => '0'])->q850);
    }

    #[Test]
    public function extrai_o_numero_de_formatos_diferentes(): void
    {
        foreach (['SIP 404 Not Found', '404', 'SIP/2.0 404 Not Found'] as $forma) {
            $this->assertSame(404, Sinalizacao::doEvento(['TechCause' => $forma])->codigoNumerico(), $forma);
        }
    }
}
