<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\Cpf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CpfTest extends TestCase
{
    /** @return list<array{string}> */
    public static function validos(): array
    {
        return [['52998224725'], ['11144477735'], ['12345678909']];
    }

    /** @return list<array{string,string}> */
    public static function invalidos(): array
    {
        return [
            ['00000000000', 'dígito repetido — é o que sai de quem apertou zero até o timeout'],
            ['11111111111', 'dígito repetido, passa pelo módulo 11 mas não é CPF'],
            ['99999999999', 'dígito repetido'],
            ['52998224724', 'segundo dígito verificador errado'],
            ['52998224715', 'primeiro dígito verificador errado'],
            ['5299822472',  'dez dígitos'],
            ['529982247250', 'doze dígitos'],
            ['529.982.247-25', 'com pontuação: não vem de DTMF, e mascarar esconderia erro de caminho'],
            ['', 'vazio'],
        ];
    }

    #[Test]
    #[DataProvider('validos')]
    public function aceita_cpf_valido(string $cpf): void
    {
        $this->assertTrue((new Cpf())->valido($cpf));
    }

    #[Test]
    #[DataProvider('invalidos')]
    public function recusa_cpf_invalido(string $cpf, string $porque): void
    {
        $this->assertFalse((new Cpf())->valido($cpf), $porque);
    }

    #[Test]
    public function recusa_todas_as_onze_sequencias_de_digito_repetido(): void
    {
        $c = new Cpf();
        for ($d = 0; $d <= 9; $d++) {
            $this->assertFalse($c->valido(str_repeat((string) $d, 11)), "sequência de {$d}");
        }
    }
}
