<?php

declare(strict_types=1);

namespace App\Importacao;

/**
 * Leitor de CSV tolerante ao que sai de planilha de verdade.
 *
 * Fluxo INJETADO, como o `Agi` e o `Ami`: dá para testar sem upload, sem disco e
 * sem framework.
 *
 * 🔴 As duas detecções abaixo não são conveniência. O Excel em português exporta
 * CSV com `;` e em cp1252, e as duas suposições contrárias falham de formas que
 * chegam ao Lead:
 *
 *   · delimitador errado → o arquivo inteiro vira UMA coluna, e nenhum telefone é
 *     encontrado. O operador vê "0 importados" num arquivo visivelmente cheio.
 *   · codificação errada → "João" vira "JoÃ£o" no banco, e daí vai para dentro do
 *     template do WhatsApp. O Lead recebe uma mensagem com o próprio nome
 *     corrompido, que é pior que não receber nada.
 */
final class Csv
{
    /** @var list<string> */
    private const DELIMITADORES = [';', ',', "\t", '|'];

    /**
     * Lê o começo do arquivo e decide delimitador e codificação.
     *
     * @return array{delimitador:string, codificacao:string}
     */
    public function detectar(string $amostra): array
    {
        // BOM de UTF-8. Se ficar, o primeiro nome de coluna vem com três bytes
        // invisíveis na frente e NUNCA casa com o nome que o operador escolheu.
        $amostra = $this->semBom($amostra);

        $primeiraLinha = strtok($amostra, "\r\n");
        if ($primeiraLinha === false) {
            $primeiraLinha = '';
        }

        $melhor = ',';
        $maior = 0;
        foreach (self::DELIMITADORES as $d) {
            $n = substr_count($primeiraLinha, $d);
            if ($n > $maior) {
                $maior = $n;
                $melhor = $d;
            }
        }

        return [
            'delimitador' => $melhor,
            // `mb_check_encoding` como teste POSITIVO de UTF-8, e cp1252 como
            // suposição de fallback: é o que o Excel pt-BR produz. Não existe
            // detecção confiável de cp1252, então o teste é "não é UTF-8 válido".
            'codificacao' => mb_check_encoding($amostra, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        ];
    }

    /**
     * Linhas do arquivo, já na codificação interna e sem BOM.
     *
     * Gerador de propósito: uma lista de 200 mil Leads não cabe confortavelmente em
     * memória, e carregar o arquivo inteiro para depois inserir em lote é o jeito
     * mais fácil de derrubar o php-fpm com um upload legítimo.
     *
     * @param resource $fluxo
     * @return \Generator<int, list<string>>
     */
    public function linhas($fluxo, string $delimitador, string $codificacao): \Generator
    {
        $primeira = true;

        // 🔴 `escape: ''` — explícito, e não só para calar a depreciação do PHP 8.4.
        //
        // O padrão do PHP é `\\`, que NÃO existe no CSV do RFC 4180. Com ele, um
        // campo como `C:\Users\maria` ou um endereço com barra invertida é mal
        // interpretado, e o estrago aparece na coluna seguinte — que pode ser a do
        // telefone. Planilha não escapa com barra invertida; ela usa aspas.
        while (($campos = fgetcsv($fluxo, 0, $delimitador, '"', '')) !== false) {
            // `fgetcsv` devolve [null] para linha vazia; não é fim de arquivo.
            if ($campos === [null]) {
                continue;
            }

            $saida = [];
            foreach ($campos as $c) {
                $valor = is_string($c) ? $c : '';

                if ($codificacao !== 'UTF-8') {
                    $convertido = mb_convert_encoding($valor, 'UTF-8', $codificacao);

                    // `false` é falha da conversão em si. Vira string vazia, e a
                    // perda é DELIBERADA: byte inválido não pode chegar ao MySQL,
                    // porque utf8mb4 o rejeita e a linha inteira se perde em vez
                    // de um campo só. Vazio na coluna de telefone cai na lista de
                    // rejeitados com motivo, que o operador vê; vazio numa coluna
                    // de substituição aparece como lacuna na mensagem.
                    $valor = $convertido === false ? '' : $convertido;
                }

                $saida[] = trim($valor);
            }

            // `fgetcsv` nunca devolve lista vazia aqui — `[null]` já foi filtrado
            // acima —, então não há checagem de vazio: ela seria código morto.
            if ($primeira) {
                $saida[0] = $this->semBom($saida[0]);
                $primeira = false;
            }

            yield $saida;
        }
    }

    private function semBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }
}
