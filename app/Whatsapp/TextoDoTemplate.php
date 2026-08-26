<?php

declare(strict_types=1);

namespace App\Whatsapp;

/**
 * Monta o texto que o Lead REALMENTE recebeu, a partir do corpo aprovado e dos
 * parâmetros enviados. PURA.
 *
 * 🔴 EXISTE PARA A CAIXA DE ENTRADA PARAR DE MOSTRAR `(template)`.
 *
 * O worker gravava string vazia como texto do Disparo — ele tem os parâmetros
 * resolvidos, mas nunca teve o corpo: quem fala com a Meta para isso é a tela.
 * Medido em 26/08/2026: das 243 mensagens da base, 213 eram `saida/template` sem
 * texto. O operador via que ALGO foi enviado e não o quê.
 *
 * 🔴 O QUE SAI DAQUI É HISTÓRICO, e por isso é gravado e não recalculado. Template
 * aprovado pode ser editado e reaprovado na Meta; reconstruir na hora de exibir
 * mostraria o corpo de HOJE no lugar do que a pessoa recebeu.
 */
final class TextoDoTemplate
{
    /**
     * @param string $corpo o corpo aprovado, com `{{1}}` ou `{{nome}}`
     * @param list<string> $valores os parâmetros enviados, NA ORDEM
     * @param list<string> $nomes os nomes, na mesma ordem. Vazio = posicional.
     */
    public function montar(string $corpo, array $valores, array $nomes = []): string
    {
        if (trim($corpo) === '') {
            /*
             * Sem corpo guardado — Campanha criada antes desta coluna existir. O que
             * sobra é melhor que vazio: os parâmetros dizem PARA QUEM a mensagem foi
             * personalizada, e é o que o operador procura ao abrir a conversa.
             */
            return $valores === [] ? '' : implode(' · ', $valores);
        }

        $de = [];
        $para = [];

        foreach ($valores as $i => $valor) {
            // Posicional quando não há nome: a chave é a POSIÇÃO, base 1.
            $chave = $nomes[$i] ?? (string) ($i + 1);
            $de[] = '/\{\{\s*' . preg_quote($chave, '/') . '\s*\}\}/';
            /*
             * `$0` e afins dentro do VALOR seriam lidos como referência de captura
             * pelo `preg_replace` — um Lead chamado "R$ 1" viraria lixo. `\\0`
             * escapado no substituto é o que impede isso.
             */
            $para[] = str_replace(['\\', '$'], ['\\\\', '\\$'], $valor);
        }

        $r = $de === [] ? $corpo : preg_replace($de, $para, $corpo);

        return is_string($r) ? $r : $corpo;
    }
}
