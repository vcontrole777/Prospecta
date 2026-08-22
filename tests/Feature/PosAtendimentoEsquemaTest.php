<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Dominio\PosAtendimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A declaração de `PosAtendimento` e as colunas de `configuracao` dizem a mesma coisa?
 *
 * 🔴 A MIGRATION NÃO LÊ A DECLARAÇÃO, e isso é deliberado.
 *
 * Migration é registro histórico: ela descreve o que foi aplicado naquela data, e uma
 * que consultasse código vivo mudaria de significado toda vez que o código mudasse —
 * um banco reconstruído do zero deixaria de bater com um migrado incrementalmente.
 *
 * O que amarra os dois é este teste. Se alguém mexer no default ou no tipo de um lado
 * sem mexer no outro, quebra aqui — que é o sinal que faltava quando os nove parâmetros
 * eram soletrados em sete lugares sem nada conferindo.
 */
final class PosAtendimentoEsquemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,object> */
    private function colunas(): array
    {
        /** @var list<object> $linhas */
        $linhas = DB::select(
            'SELECT COLUMN_NAME, COLUMN_DEFAULT, COLUMN_TYPE
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['configuracao'],
        );

        $porNome = [];
        foreach ($linhas as $l) {
            /** @var array<string,mixed> $c */
            $c = (array) $l;
            $nome = $c['COLUMN_NAME'] ?? null;
            if (is_string($nome)) {
                $porNome[$nome] = $l;
            }
        }

        return $porNome;
    }

    #[Test]
    public function toda_a_declaracao_existe_como_coluna(): void
    {
        $colunas = $this->colunas();

        foreach (PosAtendimento::parametros() as $p) {
            $this->assertArrayHasKey(
                $p->coluna,
                $colunas,
                "a declaração tem `{$p->coluna}` e a tabela não",
            );
        }
    }

    #[Test]
    public function o_default_da_coluna_e_o_default_declarado(): void
    {
        $colunas = $this->colunas();

        foreach (PosAtendimento::parametros() as $p) {
            /** @var array<string,mixed> $c */
            $c = (array) $colunas[$p->coluna];
            $default = $c['COLUMN_DEFAULT'] ?? null;

            $this->assertSame(
                $p->padrao,
                is_numeric($default) ? (int) $default : null,
                "`{$p->coluna}`: a coluna e a declaração discordam do valor de fábrica",
            );
        }
    }

    /**
     * O `maximo` da declaração vira `max:` na validação e `max` no input. Se ele passar
     * do teto do tipo da coluna, o MySQL trunca em silêncio: o operador vê um número na
     * tela e o classificador recebe outro.
     */
    #[Test]
    public function o_maximo_declarado_cabe_no_tipo_da_coluna(): void
    {
        $tetoPorTipo = [
            'tinyint unsigned' => 255,
            'smallint unsigned' => 65535,
            'mediumint unsigned' => 16777215,
            'int unsigned' => 4294967295,
        ];

        $colunas = $this->colunas();

        foreach (PosAtendimento::parametros() as $p) {
            /** @var array<string,mixed> $c */
            $c = (array) $colunas[$p->coluna];
            $tipo = $c['COLUMN_TYPE'] ?? null;
            $this->assertIsString($tipo);

            $this->assertArrayHasKey(
                $tipo,
                $tetoPorTipo,
                "`{$p->coluna}` é `{$tipo}`, um tipo que este teste não sabe medir",
            );

            $this->assertSame(
                $tetoPorTipo[$tipo],
                $p->maximo,
                "`{$p->coluna}`: o máximo declarado não é o teto de `{$tipo}`",
            );
        }
    }
}
