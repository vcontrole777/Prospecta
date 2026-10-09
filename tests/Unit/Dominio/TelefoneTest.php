<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\ErroDeDominio;
use App\Dominio\Telefone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A fronteira onde tudo entra. Errar aqui vira chamada errada depois.
 */
final class TelefoneTest extends TestCase
{
    #[Test]
    #[DataProvider('aceitos')]
    public function normaliza_para_o_canonico(string $bruto, string $esperado, string $porque): void
    {
        $this->assertSame($esperado, (new Telefone())->canonico($bruto), $porque);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function aceitos(): iterable
    {
        yield 'já canônico móvel' => ['5511987654321', '5511987654321', 'o caso normal'];
        yield 'já canônico fixo' => ['551134567890', '551134567890', 'fixo de 8 dígitos'];

        yield 'com + do exterior' => ['+5511987654321', '5511987654321', 'o + é pontuação'];
        yield 'com 00 internacional' => ['005511987654321', '5511987654321', '00 é prefixo de discagem'];
        yield 'com máscara' => ['(11) 98765-4321', '5511987654321', 'o que sai do Excel'];
        yield 'com espaços e pontos' => [' 11.98765.4321 ', '5511987654321', 'planilha suja'];

        yield 'sem país, móvel' => ['11987654321', '5511987654321', '11 dígitos é DDD + móvel'];
        yield 'sem país, fixo' => ['1134567890', '551134567890', '10 dígitos é DDD + fixo'];

        yield 'com 0 de operadora' => ['011987654321', '5511987654321', '0 é prefixo interurbano'];

        // 🔴 DDD 55 EXISTE — Santa Maria, RS. Ler aquele 55 como código de país
        // deixaria 9 dígitos, que não é telefone nenhum. É a armadilha mais fácil
        // de introduzir numa normalização brasileira.
        yield 'DDD 55 não é o país' => ['55987654321', '5555987654321', 'DDD 55 é Santa Maria'];
        yield 'DDD 55 fixo' => ['5534567890', '555534567890', 'idem, fixo'];
    }

    #[Test]
    #[DataProvider('recusados')]
    public function recusa_o_que_viraria_chamada_perdida(string $bruto, string $porque): void
    {
        $this->expectException(ErroDeDominio::class);
        (new Telefone())->canonico($bruto);
    }

    /** @return iterable<string, array{string, string}> */
    public static function recusados(): iterable
    {
        yield 'vazio' => ['', 'nada a discar'];
        yield 'só pontuação' => ['(  ) -', 'idem'];
        yield 'curto demais' => ['987654321', 'sem DDD não há como adivinhar de onde é'];
        yield 'longo demais' => ['5511987654321234', 'não é telefone brasileiro'];

        // Medido contra tronco real: DDD inexistente devolve 503, que CONTA para a
        // proteção — uma planilha cheia deles pausaria a Campanha inteira.
        yield 'DDD 20 não existe' => ['5520987654321', 'DDD inexistente vira 503'];
        yield 'DDD 23 não existe' => ['5523987654321', 'idem'];
        yield 'DDD 52 não existe' => ['5552987654321', 'idem'];
        yield 'DDD 90 não existe' => ['5590987654321', 'idem'];
        yield 'DDD 10 não existe' => ['5510987654321', 'a faixa começa em 11'];

        // Móvel de 9 dígitos que não começa com 9 é fixo com dígito sobrando —
        // digitação ou coluna deslocada. Discar aquilo é 404 garantido, e 404
        // aposenta o Lead permanentemente.
        yield 'móvel sem o nono dígito 9' => ['5511887654321', '9 dígitos começando em 8'];
        yield 'móvel começando em 3' => ['5511387654321', 'idem'];

        // Fixo começa em 2 a 5. Começar em 9 é móvel sem o nono dígito, que não
        // completa desde a migração de 2016.
        yield 'fixo começando em 9' => ['551194567890', '8 dígitos começando em 9'];
        yield 'fixo começando em 1' => ['551114567890', 'idem'];

        yield 'país que não é o Brasil' => ['3512345678901', '13 dígitos sem 55 na frente'];
    }

    /** `tentar` devolve null em vez de lançar — é o que o laço de importação usa. */
    #[Test]
    public function tentar_nao_lanca(): void
    {
        $t = new Telefone();

        $this->assertSame('5511987654321', $t->tentar('(11) 98765-4321'));
        $this->assertNull($t->tentar('nada disso'));
    }

    /**
     * 🔴 A Meta identifica celular antigo SEM o nono dígito: o `wa_id` de quem
     * registrou o WhatsApp antes de 2016 tem 12 dígitos. Em produção, 463 de 480
     * mensagens recebidas vieram assim. Gravado cru, o opt-out vai para o
     * `bloqueio` com 12 dígitos e o dialer, que compara por igualdade com o Lead
     * de 13, continua discando.
     */
    #[Test]
    #[DataProvider('dosWaId')]
    public function de_wa_id_devolve_o_canonico_do_lead(string $waId, string $esperado, string $porque): void
    {
        $this->assertSame($esperado, (new Telefone())->deWaId($waId), $porque);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function dosWaId(): iterable
    {
        yield 'celular antigo sem o 9' => ['551187654321', '5511987654321', 'o formato que a Meta manda'];
        yield 'celular antigo começando em 9' => ['556298485352', '5562998485352', 'idem, outro DDD'];
        yield 'celular antigo começando em 6' => ['557166543210', '5571966543210', 'faixa 6 também era móvel'];
        yield 'já com o 9' => ['5511987654321', '5511987654321', 'quem registrou depois de 2016'];
        yield 'fixo fica fixo' => ['551134567890', '551134567890', 'WhatsApp Business em linha fixa'];
        yield 'com +' => ['+551187654321', '5511987654321', 'se um dia vier com +'];
    }

    #[Test]
    public function de_wa_id_recusa_o_que_nao_e_brasileiro(): void
    {
        $t = new Telefone();

        // 12 dígitos de outro país não podem ganhar um 9 e virar número brasileiro.
        $this->assertNull($t->tentarWaId('351912345678'));
        $this->assertNull($t->tentarWaId('552087654321'), 'DDD 20 não existe');
        $this->assertNull($t->tentarWaId(''));
    }

    /** A normalização na entrada é o que torna isto substring em vez de parser. */
    #[Test]
    public function ddd_e_tipo_saem_por_posicao(): void
    {
        $t = new Telefone();

        $this->assertSame('11', $t->ddd('5511987654321'));
        $this->assertSame('62', $t->ddd('5562984853523'));
        $this->assertTrue($t->movel('5511987654321'));
        $this->assertFalse($t->movel('551134567890'));
    }
}
