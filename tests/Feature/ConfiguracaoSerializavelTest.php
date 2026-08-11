<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 🔴 TODA CONFIGURAÇÃO TEM QUE SOBREVIVER A `config:cache`.
 *
 * O deploy roda `artisan optimize`, que serializa a configuração inteira com
 * `var_export`. Valor não serializável — closure, objeto, recurso — faz o comando FALHAR,
 * e sem o cache o painel falha na primeira requisição.
 *
 * Aconteceu: `config/ambiente.php` devolvia uma closure. O Laravel carrega todo arquivo em
 * `config/` como configuração, então o leitor de ambiente dos processos de chamada virou
 * uma chave de configuração com uma closure dentro. O playbook morreu em "the value at
 * 'ambiente' is non-serializable".
 *
 * 🔴 E A SUÍTE NÃO PEGAVA. Nenhum teste roda `config:cache`, então esta classe inteira de
 * quebra de implantação era invisível — PHPStan também não vê, porque não é erro de tipo.
 * Foi encontrado IMPLANTANDO.
 *
 * Este teste confere a propriedade sem rodar o comando: `var_export` sobre a configuração
 * é exatamente o que o `ConfigCacheCommand` faz, e falhar aqui é o mesmo defeito.
 */
final class ConfiguracaoSerializavelTest extends TestCase
{
    #[Test]
    public function nenhuma_configuracao_tem_valor_nao_serializavel(): void
    {
        $problemas = [];

        foreach (config()->all() as $chave => $valor) {
            foreach ($this->naoSerializaveis($valor, (string) $chave) as $caminho) {
                $problemas[] = $caminho;
            }
        }

        $this->assertSame([], $problemas, sprintf(
            "Estes valores de configuração não sobrevivem a `artisan config:cache`:\n  %s\n\n"
            . 'Arquivo que devolve closure não pode viver em `config/` — o Laravel carrega '
            . 'tudo lá como configuração. Ver `bootstrap/ambiente.php`.',
            implode("\n  ", $problemas),
        ));
    }

    /**
     * O `var_export` da configuração inteira, que é o que o comando faz de verdade.
     *
     * A varredura acima nomeia QUAL chave está errada; esta afirma a propriedade toda,
     * inclusive para tipos que eu não pensei em enumerar.
     */
    #[Test]
    public function a_configuracao_inteira_passa_por_var_export(): void
    {
        $exportada = var_export(config()->all(), true);

        // `var_export` de closure produz `\Closure::__set_state(...)`, que não existe — é
        // exatamente a mensagem que o deploy deu.
        $this->assertStringNotContainsString('__set_state', $exportada);

        // E o resultado tem que voltar a ser PHP válido: é assim que o cache é lido.
        $this->assertIsArray(eval('return ' . $exportada . ';'));
    }

    /**
     * @return list<string> caminhos das chaves com valor não serializável
     */
    private function naoSerializaveis(mixed $valor, string $caminho): array
    {
        if ($valor instanceof \Closure) {
            return [$caminho . ' (Closure)'];
        }

        if (is_resource($valor)) {
            return [$caminho . ' (resource)'];
        }

        if (is_object($valor) && ! method_exists($valor, '__set_state')) {
            // Objeto sem `__set_state` não volta do `var_export`. Enum é a exceção que o
            // próprio PHP exporta, e o `is_object` não a alcança porque enum case exporta
            // como `\Enum::CASO`.
            if (! ($valor instanceof \UnitEnum)) {
                return [$caminho . ' (' . $valor::class . ')'];
            }
        }

        if (! is_array($valor)) {
            return [];
        }

        $saida = [];
        foreach ($valor as $chave => $item) {
            foreach ($this->naoSerializaveis($item, $caminho . '.' . $chave) as $p) {
                $saida[] = $p;
            }
        }

        return $saida;
    }
}
