<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\Bina;
use App\Dominio\ErroDeDominio;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BinaTest extends TestCase
{
    private const MOVEL = '5511987654321';   // 13 dígitos
    private const FIXO  = '551133334444';    // 12 dígitos

    private Bina $b;

    protected function setUp(): void
    {
        $this->b = new Bina();
    }

    // ---- unica -----------------------------------------------------------

    #[Test]
    public function unica_devolve_a_bina_da_campanha(): void
    {
        $this->assertSame('5562984853523', $this->b->derivar('unica', self::MOVEL, '5562984853523'));
    }

    #[Test]
    public function unica_sem_bina_devolve_null_para_o_campo_ser_OMITIDO(): void
    {
        // null significa "não pedir BINA nenhuma", e o chamador OMITE o campo no
        // Originate. Nunca string vazia: vazio pode sair como chamada anônima,
        // que tem atendimento perto de zero e causa invisível.
        $this->assertNull($this->b->derivar('unica', self::MOVEL, null));
        $this->assertNull($this->b->derivar('unica', self::MOVEL, ''));
    }

    // ---- lista -----------------------------------------------------------

    #[Test]
    public function lista_usa_o_candidato_sorteado_pelo_chamador(): void
    {
        $this->assertSame(
            '5562911112222',
            $this->b->derivar('lista', self::MOVEL, '5511000000000', '5562911112222'),
        );
    }

    #[Test]
    public function lista_sem_candidato_cai_na_bina_da_campanha_e_NAO_no_espelho(): void
    {
        // Cair no espelho trocaria em silêncio "números reais do operador, com
        // retorno funcionando" por "números de terceiros, com retorno quebrado".
        // A implementação de referência em AEL cai no espelho; divergimos.
        $r = $this->b->derivar('lista', self::MOVEL, '5511000000000', null);

        $this->assertSame('5511000000000', $r);
        $this->assertNotSame(self::MOVEL, $r);
    }

    #[Test]
    public function lista_sem_candidato_e_sem_bina_devolve_null(): void
    {
        $this->assertNull($this->b->derivar('lista', self::MOVEL, null, null));
    }

    // ---- espelho ---------------------------------------------------------

    #[Test]
    public function espelho_preserva_tudo_menos_os_quatro_ultimos(): void
    {
        $r = $this->b->derivar('espelho', self::MOVEL);

        $this->assertNotNull($r);
        $this->assertSame('551198765', substr($r, 0, -4));
        $this->assertSame(13, strlen($r));
    }

    #[Test]
    public function espelho_generaliza_para_fixo_sem_caso_especial(): void
    {
        // "Tudo menos os 4 últimos" vale para móvel de 13 e fixo de 12.
        $r = $this->b->derivar('espelho', self::FIXO);

        $this->assertNotNull($r);
        $this->assertSame('55113333', substr($r, 0, -4));
        $this->assertSame(12, strlen($r));
    }

    #[Test]
    public function espelho_e_DETERMINISTICO(): void
    {
        // Mesmo Lead, mesma BINA em toda tentativa. Aleatório daria três números
        // diferentes para quem foi chamado três vezes — mais estranho, não menos.
        $a = $this->b->derivar('espelho', self::MOVEL);
        $c = $this->b->derivar('espelho', self::MOVEL);

        $this->assertSame($a, $c);
    }

    #[Test]
    public function espelho_nunca_devolve_o_proprio_numero_do_destino(): void
    {
        // Seria o Lead vendo o PRÓPRIO número ligando para ele. E foi medido que
        // From == To faz a rota tratar a chamada diferente.
        for ($i = 0; $i < 400; $i++) {
            $tel = '55119' . str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            $this->assertNotSame($tel, $this->b->derivar('espelho', $tel), "colidiu em {$tel}");
        }
    }

    #[Test]
    public function espelho_usa_a_faixa_completa_de_quatro_digitos(): void
    {
        // A referência em AEL usa RAND(8111,9999) e RAND(1111,9999) — pisos
        // inconsistentes entre macros, o que revela que o piso existe só para
        // garantir 4 dígitos. Zerando à esquerda temos 10 mil valores.
        $vistos = [];
        for ($i = 0; $i < 500; $i++) {
            $r = $this->b->derivar('espelho', '55119' . str_pad((string) $i, 8, '0', STR_PAD_LEFT));
            $this->assertNotNull($r);
            $vistos[substr($r, -4)] = true;
        }
        $comZeroInicial = array_filter(array_keys($vistos), fn (string $q): bool => $q[0] === '0');

        $this->assertNotEmpty($comZeroInicial, 'nenhum quarteto começou com zero — a faixa está truncada');
    }

    // ---- espelho_fixo ----------------------------------------------------

    #[Test]
    public function espelho_fixo_preserva_pais_e_ddd_e_gera_fixo_de_oito(): void
    {
        $r = $this->b->derivar('espelho_fixo', self::MOVEL);

        $this->assertNotNull($r);
        $this->assertSame('5511', substr($r, 0, 4), 'país + DDD do Lead');
        $this->assertSame(12, strlen($r), 'país + DDD + fixo de 8');
    }

    #[Test]
    public function espelho_fixo_gera_prefixo_em_faixa_plausivel_de_fixo(): void
    {
        // 3224-4711 cobre fixos iniciando em 3 e 4, confirmado contra a
        // implementação de referência em AEL.
        for ($i = 0; $i < 300; $i++) {
            $r = $this->b->derivar('espelho_fixo', '55629' . str_pad((string) $i, 8, '0', STR_PAD_LEFT));
            $this->assertNotNull($r);
            $prefixo = (int) substr($r, 4, 4);
            $this->assertGreaterThanOrEqual(3224, $prefixo);
            $this->assertLessThanOrEqual(4711, $prefixo);
        }
    }

    // ---- invariantes -----------------------------------------------------

    #[Test]
    public function nenhuma_regra_devolve_o_sinal_de_mais(): void
    {
        // MEDIDO: com o `+`, a rota prefixa `00` e o aparelho exibe
        // `+005562984853523`. Pior que a BINA não funcionar, porque um número
        // APARECE e nada no sistema indica erro.
        foreach (['unica', 'lista', 'espelho', 'espelho_fixo'] as $regra) {
            $r = $this->b->derivar($regra, self::MOVEL, '+5562984853523', '+5562911112222');
            $this->assertNotNull($r);
            $this->assertStringNotContainsString('+', $r, "regra {$regra}");
            $this->assertMatchesRegularExpression('/^[0-9]+$/', $r, "regra {$regra}");
        }
    }

    #[Test]
    public function telefone_fora_do_formato_canonico_falha(): void
    {
        foreach (['+5511987654321', '11987654321', '5511 98765-4321', ''] as $ruim) {
            try {
                $this->b->derivar('espelho', $ruim);
                $this->fail('devia ter falhado para ' . json_encode($ruim));
            } catch (ErroDeDominio $e) {
                $this->assertSame('TELEFONE_INVALIDO', $e->causa);
            }
        }
    }

    #[Test]
    public function regra_desconhecida_falha_com_causa_nomeada(): void
    {
        try {
            $this->b->derivar('fixa', self::MOVEL);   // o nome antigo, que foi renomeado
            $this->fail('devia ter falhado');
        } catch (ErroDeDominio $e) {
            $this->assertSame('REGRA_BINA_DESCONHECIDA', $e->causa);
        }
    }
}
