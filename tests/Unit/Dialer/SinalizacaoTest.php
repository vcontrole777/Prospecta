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
     * existe desde antes e nunca produziu um código.
     *
     * O teste NÃO consagra o comportamento como desejável: ele o torna visível. Tirar o
     * recurso é seguro — a medição de 24/08/2026 não achou uma Ligação sequer em que ele
     * tivesse mudado a decisão — mas é mexer sem ganho, e por isso ficou.
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

    /**
     * Dois campos afirmam atendimento, e são os DOIS que valem.
     *
     * `Reason: 4` é `AST_CONTROL_ANSWER`. Medido em 24/08/2026: as 2.387 ocorrências
     * correspondem a 2.387 Ligações com `atendida_em` preenchido, sem exceção.
     * Enquanto ele ficou de fora, essas 2.387 — 6,6% de tudo o que foi discado em
     * 5 h 24 — eram marcadas para retentativa depois de terem sido atendidas.
     */
    #[Test]
    public function dialstatus_ANSWER_e_reason_4_afirmam_atendimento(): void
    {
        $this->assertTrue(Sinalizacao::doEvento(['DialStatus' => 'ANSWER'])->atendeu());
        $this->assertTrue(Sinalizacao::doEvento(['Reason' => '4'])->atendeu(), 'AST_CONTROL_ANSWER');
        $this->assertFalse(Sinalizacao::doEvento(['DialStatus' => 'CONGESTION'])->atendeu());
        $this->assertFalse(Sinalizacao::doEvento(['Reason' => '3'])->atendeu(), 'tocando não é atendido');
        $this->assertFalse(Sinalizacao::doEvento(['Reason' => '0'])->atendeu());
        $this->assertFalse(Sinalizacao::doEvento([])->atendeu());
    }

    /**
     * 🔴 `Reason` É `AST_CONTROL`, e não Q.850 nem SIP.
     *
     * Os três vocabulários usam números pequenos e plausíveis, e a confusão é
     * silenciosa: `3` é `RINGING` aqui e "No route to destination" no Q.850. Este
     * teste existe para que trocar um pelo outro quebre.
     */
    #[Test]
    public function o_reason_vira_numero_de_controle(): void
    {
        $this->assertSame(0, Sinalizacao::doEvento(['Reason' => '0'])->controleDeOriginacao());
        $this->assertSame(3, Sinalizacao::doEvento(['Reason' => '3'])->controleDeOriginacao());
        $this->assertSame(4, Sinalizacao::doEvento(['Reason' => '4'])->controleDeOriginacao());

        $this->assertSame(Sinalizacao::CONTROLE_NENHUM, 0);
        $this->assertSame(Sinalizacao::CONTROLE_TOCANDO, 3);
        $this->assertSame(Sinalizacao::CONTROLE_ATENDEU, 4);
    }

    /**
     * `Hangup` não traz `Reason` — nas 970 medidas em 24/08/2026, nenhuma trazia.
     *
     * Importa porque o `Hangup` chega DEPOIS do `OriginateResponse` para a mesma
     * Ligação: se ele produzisse um controle qualquer, sobrescreveria a decisão já
     * tomada pelo evento mais rico.
     */
    #[Test]
    public function evento_sem_reason_nao_tem_controle(): void
    {
        $semReason = Sinalizacao::doEvento(['Event' => 'Hangup', 'Cause' => '19']);
        $this->assertNull($semReason->controleDeOriginacao());

        // Campo presente mas não numérico também é ausência, não zero — zero É uma
        // condição medida (`nem chegou a tocar`) e não pode ser o balde do lixo.
        $this->assertNull(Sinalizacao::doEvento(['Reason' => 'CONGESTION'])->controleDeOriginacao());
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
