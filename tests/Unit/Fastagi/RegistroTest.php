<?php

declare(strict_types=1);

namespace Tests\Unit\Fastagi;

use App\Fastagi\Registro;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegistroTest extends TestCase
{
    #[Test]
    public function usa_o_id_do_processo_quando_o_extra_nao_traz_um(): void
    {
        $d = $this->registrar(77, 'gravacao', 'iniciada', ['arquivo' => 'x.gsm']);

        $this->assertSame(77, $d['ligacao']);
        $this->assertSame('gravacao', $d['evento']);
        $this->assertSame('x.gsm', $d['arquivo']);
    }

    /**
     * 🔴 A regressão que motivou o conserto.
     *
     * `['ligacao' => $this->ligacaoId] + $extra` mantém o valor da ESQUERDA em
     * chave repetida, então um `extra['ligacao']` era descartado em silêncio. No
     * `bin/expurgo.php`, que percorre muitas Ligações num processo sem id fixo, a
     * linha de recusa saía com `"ligacao": null` — perdendo o único dado pelo qual
     * aquela linha existe.
     */
    #[Test]
    public function o_extra_vence_o_id_do_processo(): void
    {
        $this->assertSame(5, $this->registrar(null, 'recusado', 'fora do diretório', ['ligacao' => 5])['ligacao']);

        // E vence também quando o processo TEM id — um processo que fala de outra
        // Ligação precisa poder dizer isso.
        $this->assertSame(5, $this->registrar(77, 'recusado', 'fora do diretório', ['ligacao' => 5])['ligacao']);
    }

    /**
     * `evento` e `mensagem` são a identidade da linha. Sobrescrevíveis, um `extra`
     * descuidado renomearia o evento e a linha sumiria de qualquer busca no log.
     */
    #[Test]
    public function evento_e_mensagem_nao_sao_sobrescreviveis(): void
    {
        $d = $this->registrar(1, 'erro', 'falhou', ['evento' => 'ok', 'mensagem' => 'tudo bem']);

        $this->assertSame('erro', $d['evento']);
        $this->assertSame('falhou', $d['mensagem']);
    }

    #[Test]
    public function escreve_uma_linha_de_json_por_registro(): void
    {
        $fluxo = fopen('php://memory', 'r+');
        self::assertIsResource($fluxo);

        $r = new Registro($fluxo);
        $r->linha('a', 'primeira');
        $r->linha('b', 'segunda');

        rewind($fluxo);
        $bruto = (string) stream_get_contents($fluxo);
        fclose($fluxo);

        $linhas = array_values(array_filter(explode("\n", $bruto)));
        $this->assertCount(2, $linhas);
        foreach ($linhas as $l) {
            $this->assertIsArray(json_decode($l, true), "não é JSON válido: {$l}");
        }
    }

    /** Acentos legíveis e barras sem escape — o log é lido por pessoa. */
    #[Test]
    public function nao_escapa_acento_nem_barra(): void
    {
        $fluxo = fopen('php://memory', 'r+');
        self::assertIsResource($fluxo);

        (new Registro($fluxo))->linha('recusado', 'fora do diretório', ['arquivo' => '/var/spool/x.gsm']);

        rewind($fluxo);
        $bruto = (string) stream_get_contents($fluxo);
        fclose($fluxo);

        $this->assertStringContainsString('diretório', $bruto);
        $this->assertStringContainsString('/var/spool/x.gsm', $bruto);
    }

    /**
     * @param array<string,scalar|null> $extra
     * @return array<string,mixed>
     */
    private function registrar(?int $idDoProcesso, string $evento, string $mensagem, array $extra = []): array
    {
        $fluxo = fopen('php://memory', 'r+');
        self::assertIsResource($fluxo);

        (new Registro($fluxo, $idDoProcesso))->linha($evento, $mensagem, $extra);

        rewind($fluxo);
        $bruto = (string) stream_get_contents($fluxo);
        fclose($fluxo);

        $d = json_decode(trim($bruto), true);
        self::assertIsArray($d);

        /** @var array<string,mixed> $d */
        return $d;
    }
}
