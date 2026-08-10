<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\CaminhoDeGravacao;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contenção do caminho que o expurgo APAGA. Errar aqui é um serviço removendo
 * arquivo fora do diretório de gravação.
 */
final class CaminhoDeGravacaoTest extends TestCase
{
    private const BASE = '/var/spool/asterisk/gravacoes';

    #[Test]
    #[DataProvider('aceitos')]
    public function aceita_caminho_dentro_do_diretorio(string $caminho, string $porque): void
    {
        $this->assertTrue((new CaminhoDeGravacao())->contido(self::BASE, $caminho), $porque);
    }

    /** @return iterable<string, array{string, string}> */
    public static function aceitos(): iterable
    {
        yield 'arquivo direto' => [self::BASE . '/1234.gsm', 'o caso normal'];
        yield 'subdiretorio' => [self::BASE . '/2026/08/1234.gsm', 'particionamento por data é plausível'];
        yield 'barra dupla' => [self::BASE . '//1234.gsm', 'concatenação descuidada não é ataque'];
        yield 'ponto simples' => [self::BASE . '/./1234.gsm', '"." é ruído, não subida'];
    }

    #[Test]
    #[DataProvider('recusados')]
    public function recusa_caminho_perigoso_ou_de_fora(string $caminho, string $porque): void
    {
        $this->assertFalse((new CaminhoDeGravacao())->contido(self::BASE, $caminho), $porque);
    }

    /** @return iterable<string, array{string, string}> */
    public static function recusados(): iterable
    {
        yield 'outro diretorio' => ['/etc/asterisk/pjsip.conf', 'o caso que a classe existe para impedir'];

        // 🔴 A ARMADILHA DE PREFIXO. Comparar só o começo da string, sem exigir a
        // barra, aceitaria este caminho: "/…/gravacoes" é prefixo de
        // "/…/gravacoesx". Um diretório vizinho com nome parecido — backup,
        // gravacoes-antigas — seria apagado.
        yield 'vizinho com nome parecido' => [self::BASE . 'x/1234.gsm', 'prefixo não é contenção'];
        yield 'vizinho com sufixo' => [self::BASE . '-backup/1234.gsm', 'idem, com hífen'];

        yield 'subida' => [self::BASE . '/../../../etc/passwd', '".." é recusado, não resolvido'];
        yield 'subida no meio' => [self::BASE . '/2026/../../etc/passwd', 'idem, mais fundo'];
        yield 'subida que volta' => [self::BASE . '/../gravacoes/1234.gsm', 'inofensivo, mas recusar é mais simples que contar segmentos'];

        yield 'o proprio diretorio' => [self::BASE, 'o diretório não é um arquivo a apagar'];
        yield 'diretorio com barra' => [self::BASE . '/', 'idem'];

        yield 'relativo' => ['1234.gsm', 'sem âncora, o significado depende do cwd do serviço'];
        yield 'relativo com ponto' => ['./1234.gsm', 'idem'];

        yield 'byte nulo' => [self::BASE . "/1234.gsm\0/../../etc/passwd", 'byte nulo trunca o caminho no C'];
        yield 'vazio' => ['', 'nada a apagar'];
    }

    /**
     * A base também é entrada. Base relativa ou com subida não pode virar uma
     * contenção que aceita tudo.
     */
    #[Test]
    public function recusa_quando_a_propria_base_e_invalida(): void
    {
        $c = new CaminhoDeGravacao();

        $this->assertFalse($c->contido('gravacoes', '/var/spool/asterisk/gravacoes/1.gsm'), 'base relativa');
        $this->assertFalse($c->contido('/var/../etc', '/etc/passwd'), 'base com subida');
        $this->assertFalse($c->contido('', '/qualquer/coisa'), 'base vazia');
        $this->assertFalse($c->contido("/var\0/x", '/var/x/1.gsm'), 'base com byte nulo');
    }
}
