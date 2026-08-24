<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\PosAtendimento;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O Pós-atendimento, sem banco, sem Asterisk, sem HTTP — que é o que o ADR-0006
 * exige de uma classe compartilhada entre o painel e o handler.
 */
final class PosAtendimentoTest extends TestCase
{
    /**
     * 🔴 A ORDEM É CONTRATO COM O ASTERISK, e este teste é a única coisa que a segura.
     *
     * `AMD()` lê os nove por posição. Trocar duas não dá erro nem log: dá classificação
     * errada em silêncio. A lista abaixo é escrita à mão de propósito — repetir o
     * contrato de forma independente é o que faz o teste valer alguma coisa; derivá-lo
     * da mesma fonte que ele confere não afirmaria nada.
     */
    #[Test]
    public function a_ordem_posicional_e_a_do_amd_do_asterisk(): void
    {
        $colunas = array_map(fn ($p): string => $p->coluna, PosAtendimento::parametros());

        $this->assertSame([
            'pos_silencio_inicial_ms',          // 1 initialSilence
            'pos_saudacao_max_ms',              // 2 greeting
            'pos_silencio_apos_saudacao_ms',    // 3 afterGreetingSilence
            'pos_analise_total_ms',             // 4 totalAnalysisTime
            'pos_palavra_min_ms',               // 5 minimumWordLength
            'pos_silencio_entre_palavras_ms',   // 6 betweenWordsSilence
            'pos_palavras_max',                 // 7 maximumNumberOfWords
            'pos_limiar_silencio',              // 8 silenceThreshold
            'pos_palavra_max_ms',               // 9 maximumWordLength
        ], $colunas);
    }

    #[Test]
    public function os_argumentos_saem_na_ordem_declarada(): void
    {
        $this->assertSame(
            ['6000', '4000', '800', '5000', '100', '50', '3', '256', '5000'],
            PosAtendimento::padrao()->argumentos(),
        );
    }

    /**
     * A linha de `configuracao` pode não existir: o `LEFT JOIN` do handler traz tudo
     * nulo, e uma instância recém-migrada é exatamente esse caso.
     *
     * A montagem anterior devolvia `AMD "0,0,0,0,0,0,0,0,0"` neste caminho — o fallback
     * existia e era código morto, porque o helper que ela consultava nunca devolvia
     * `null`. O PHPStan acusou a comparação; este teste é o que impede a volta.
     */
    #[Test]
    public function linha_ausente_cai_no_conjunto_de_fabrica(): void
    {
        $this->assertSame(
            PosAtendimento::padrao()->argumentos(),
            PosAtendimento::deLinha([])->argumentos(),
        );
    }

    #[Test]
    public function coluna_nula_ou_lixo_cai_no_default_do_parametro(): void
    {
        $pos = PosAtendimento::deLinha([
            'pos_silencio_inicial_ms' => null,
            'pos_saudacao_max_ms' => 'nada disso',
            'pos_palavras_max' => 0,
            'pos_limiar_silencio' => 300,
        ]);

        $this->assertSame(6000, $pos->valor('pos_silencio_inicial_ms'));
        $this->assertSame(4000, $pos->valor('pos_saudacao_max_ms'));
        // Zero não é valor válido para nenhum dos nove — o menor mínimo é 1 —, então
        // zero significa "a coluna não disse nada", e não "o operador pediu zero".
        $this->assertSame(3, $pos->valor('pos_palavras_max'));
        $this->assertSame(300, $pos->valor('pos_limiar_silencio'));
    }

    #[Test]
    public function numero_em_texto_conta_como_valor(): void
    {
        // O PDO devolve coluna numérica como string quando o driver não é emulado.
        $pos = PosAtendimento::deLinha(['pos_analise_total_ms' => '9000']);

        $this->assertSame(9000, $pos->valor('pos_analise_total_ms'));
    }

    #[Test]
    public function criterio_que_pede_mais_que_o_orcamento_e_denunciado(): void
    {
        $pos = PosAtendimento::padrao()->com([
            'pos_analise_total_ms' => 5000,
            'pos_silencio_inicial_ms' => 6000,
            'pos_saudacao_max_ms' => 1000,
        ]);

        $this->assertSame(['pos_silencio_inicial_ms' => 'silêncio inicial'], $pos->criteriosMortos());
    }

    /** Empatar com o orçamento é o mesmo que estourá-lo: o relógio para ao ATINGIR. */
    #[Test]
    public function empatar_com_a_analise_total_ja_e_morto(): void
    {
        $pos = PosAtendimento::padrao()->com([
            'pos_analise_total_ms' => 5000,
            'pos_silencio_inicial_ms' => 5000,
            'pos_saudacao_max_ms' => 1000,
        ]);

        $this->assertArrayHasKey('pos_silencio_inicial_ms', $pos->criteriosMortos());
    }

    /**
     * `palavra máxima` fica FORA da conta, e não é esquecimento.
     *
     * O `amd.conf` de fábrica do Asterisk entrega `maximum_word_length` e
     * `total_analysis_time` iguais em 5000. Ela não é um prazo a vencer: é o tamanho a
     * partir do qual uma elocução conta como máquina. Recusar 5000/5000 seria recusar o
     * padrão do fornecedor.
     */
    #[Test]
    public function palavra_maxima_nao_entra_na_conta_da_analise(): void
    {
        $pos = PosAtendimento::padrao()->com([
            'pos_analise_total_ms' => 5000,
            'pos_palavra_max_ms' => 60000,
            'pos_silencio_inicial_ms' => 1000,
            'pos_saudacao_max_ms' => 1000,
        ]);

        $this->assertSame([], $pos->criteriosMortos());
        $this->assertSame(['silêncio inicial', 'saudação máxima'], PosAtendimento::rotulosDentroDaAnalise());
    }

    /**
     * 🔴 ESTE TESTE FIXA UM DEFEITO CONHECIDO, e falhar é o comportamento desejado.
     *
     * O conjunto de fábrica — que é o default das colunas da migration — nasce com
     * `silêncio inicial` em 6000 contra `análise total` em 5000. É a combinação exata
     * que foi medida em 21/08/2026: `INITIALSILENCE` ZERO vezes em 8.406 classificações,
     * 54,6% das Ligações atendidas sem decisão, 12,7 horas de tronco. O `amd.conf` do
     * Asterisk usa 2500 aqui; a migration escolheu 6000.
     *
     * Ou seja: toda instância nova nasce com o critério morto, e a tela passa a denunciá-lo
     * na primeira visita.
     *
     * Se alguém corrigir o default, ESTE TESTE QUEBRA — de propósito. A correção mexe no
     * comportamento do classificador em toda instalação e precisa ser uma decisão tomada,
     * com migration que conserte as linhas já gravadas, não um efeito colateral.
     */
    #[Test]
    public function o_conjunto_de_fabrica_nasce_com_um_criterio_morto(): void
    {
        $this->assertSame(
            ['pos_silencio_inicial_ms' => 'silêncio inicial'],
            PosAtendimento::padrao()->criteriosMortos(),
            'o default da migration ainda é 6000 de silêncio inicial contra 5000 de análise total',
        );
    }

    #[Test]
    public function com_troca_so_o_que_foi_pedido(): void
    {
        $pos = PosAtendimento::padrao()->com(['pos_palavras_max' => 7]);

        $this->assertSame(7, $pos->valor('pos_palavras_max'));
        $this->assertSame(6000, $pos->valor('pos_silencio_inicial_ms'));
        $this->assertSame(9, count($pos->paraColunas()));
    }

    #[Test]
    public function a_etiqueta_da_tela_traz_a_unidade_so_onde_ela_existe(): void
    {
        $porColuna = [];
        foreach (PosAtendimento::parametros() as $p) {
            $porColuna[$p->coluna] = PosAtendimento::etiqueta($p);
        }

        $this->assertSame('Análise total (ms)', $porColuna['pos_analise_total_ms']);
        $this->assertSame('Máximo de palavras', $porColuna['pos_palavras_max']);
        $this->assertSame('Limiar de silêncio', $porColuna['pos_limiar_silencio']);
    }
}
