<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\DuracaoMaxima;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DuracaoMaximaTest extends TestCase
{
    private DuracaoMaxima $conta;

    protected function setUp(): void
    {
        $this->conta = new DuracaoMaxima();
    }

    /**
     * 🔴 O TOQUE CONTA.
     *
     * A Ligação é criada ANTES de originar, e `iniciada_em` é daquele instante — então a
     * janela da reconciliação começa a correr no toque, não no atendimento. Esquecer isso
     * subestima o total em quase meio minuto, que é exatamente a margem que decide se uma
     * Campanha passa ou não do limite.
     */
    #[Test]
    public function o_toque_entra_na_conta(): void
    {
        $com = $this->conta->optIn(timeoutToqueSeg: 28, tentativasUra: 1);
        $sem = $this->conta->optIn(timeoutToqueSeg: 0, tentativasUra: 1);

        $this->assertSame(28, $com - $sem);
    }

    /**
     * 🔴 A MENSAGEM TOCA EM CADA TENTATIVA.
     *
     * `lerDigitos($audio, 1, 8000)` vira `GET DATA <arquivo> 8000 1`: o Asterisk toca o
     * arquivo e só ENTÃO espera pelo dígito. Quem não digita ouve a mensagem inteira de
     * novo. Contar o áudio uma vez só subestimaria a chamada pelo fator de tentativas — e
     * é a pessoa que não entende de primeira que estica a ligação.
     */
    #[Test]
    public function a_mensagem_multiplica_pelas_tentativas_da_ura(): void
    {
        $uma = $this->conta->optIn(timeoutToqueSeg: 0, mensagemSeg: 30.0, tentativasUra: 1);
        $tres = $this->conta->optIn(timeoutToqueSeg: 0, mensagemSeg: 30.0, tentativasUra: 3);

        // Duas tentativas a mais: 2 × (30 s de áudio + 8 s de espera).
        $this->assertSame(2 * (30 + 8), $tres - $uma);
    }

    /** O "não entendi" toca ENTRE tentativas: uma vez menos que o número delas. */
    #[Test]
    public function o_nao_entendi_toca_uma_vez_menos_que_as_tentativas(): void
    {
        $tres = $this->conta->optIn(timeoutToqueSeg: 0, tentativasUra: 3, naoEntendiSeg: 4.0);
        $semAviso = $this->conta->optIn(timeoutToqueSeg: 0, tentativasUra: 3);

        $this->assertSame(2 * 4, $tres - $semAviso);

        // Com uma tentativa só, ele nunca toca.
        $this->assertSame(
            $this->conta->optIn(timeoutToqueSeg: 0, tentativasUra: 1),
            $this->conta->optIn(timeoutToqueSeg: 0, tentativasUra: 1, naoEntendiSeg: 4.0),
        );
    }

    #[Test]
    public function um_opt_in_tipico_cabe_com_folga_no_limite_padrao(): void
    {
        // 28 s de toque, abertura de 1 s, mensagem de 20 s, duas tentativas, aviso de 3 s.
        $pior = $this->conta->optIn(
            timeoutToqueSeg: 28,
            aberturaSeg: 1.0,
            mensagemSeg: 20.0,
            tentativasUra: 2,
            naoEntendiSeg: 3.0,
            confirmacaoSeg: 5.0,
        );

        $this->assertLessThan(300, $pior, 'opt-in comum não deveria encostar no limite');
    }

    /**
     * 🔴 O CASO QUE JUSTIFICA A CLASSE.
     *
     * Cinco campos, duas tentativas cada, prompt de 20 s e espera de 20 s — todos valores
     * que o formulário aceita — passam do `limite_ligacao_seg` padrão de 300 s. Não é
     * configuração adversarial: é uma Campanha de recadastramento comum.
     *
     * Passar do limite significa que a reconciliação declara morta uma chamada viva,
     * libera o canal e disca de novo para quem está na linha digitando.
     */
    #[Test]
    public function captura_com_cinco_campos_comuns_passa_do_limite_padrao(): void
    {
        $campos = array_fill(0, 5, ['tentativas' => 2, 'timeoutSeg' => 20, 'promptSeg' => 20.0]);

        $pior = $this->conta->captura(28, 1.0, $campos);

        // 28 + 1 + 7 + 5 × 2 × (20 + 20) = 436
        $this->assertSame(436, $pior);
        $this->assertGreaterThan(300, $pior);
    }

    /** O prompt de cada campo também multiplica pelas tentativas daquele campo. */
    #[Test]
    public function o_prompt_do_campo_multiplica_pelas_tentativas_do_campo(): void
    {
        $uma = $this->conta->captura(0, 0.0, [['tentativas' => 1, 'timeoutSeg' => 10, 'promptSeg' => 6.0]]);
        $tres = $this->conta->captura(0, 0.0, [['tentativas' => 3, 'timeoutSeg' => 10, 'promptSeg' => 6.0]]);

        $this->assertSame(2 * (6 + 10), $tres - $uma);
    }

    /**
     * A captura NÃO toca o áudio de mensagem.
     *
     * `Fluxo::captura` pergunta campo a campo, com o prompt de cada um. Somar a mensagem
     * aqui inflaria a conta e recusaria Campanha que caberia no limite — recusa injusta é
     * defeito, mesmo sendo do lado conservador.
     */
    #[Test]
    public function captura_sem_campos_e_so_o_preambulo(): void
    {
        // 28 de toque + 1 de abertura + 7 de classificação.
        $this->assertSame(36, $this->conta->captura(28, 1.0, []));
    }

    /** Tentativas zero ou negativas contam como uma: o `Fluxo` usa `max(1, ...)`. */
    #[Test]
    public function tentativas_invalidas_contam_como_uma(): void
    {
        $this->assertSame(
            $this->conta->optIn(timeoutToqueSeg: 0, mensagemSeg: 10.0, tentativasUra: 1),
            $this->conta->optIn(timeoutToqueSeg: 0, mensagemSeg: 10.0, tentativasUra: 0),
        );

        $this->assertSame(
            $this->conta->captura(0, 0.0, [['tentativas' => 1, 'timeoutSeg' => 5, 'promptSeg' => 2.0]]),
            $this->conta->captura(0, 0.0, [['tentativas' => 0, 'timeoutSeg' => 5, 'promptSeg' => 2.0]]),
        );
    }

    /** Arredonda para CIMA: meio segundo a mais é conservador, meio a menos é furo. */
    #[Test]
    public function arredonda_para_cima(): void
    {
        $this->assertSame(
            36,
            $this->conta->captura(28, 0.5, []),
            '28 + 0,5 + 7 = 35,5 tem que virar 36',
        );
    }
}
