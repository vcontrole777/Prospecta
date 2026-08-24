<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Um dos nove parâmetros do Pós-atendimento, com tudo que se sabe sobre ele.
 *
 * Existe para que nome, rótulo, default e limites viajem JUNTOS. Antes cada um
 * desses fatos morava num lugar diferente — o default na migration e mais duas
 * vezes no código, o limite na regra de validação e outra vez no atributo `max`
 * do input, o rótulo em duas constantes —, e nada obrigava os lugares a
 * concordarem. Um parâmetro novo eram sete edições, e esquecer uma não dava
 * erro: dava um campo que a tela mostra e o classificador ignora.
 */
final class ParametroDoPos
{
    public function __construct(
        /** Nome da coluna em `configuracao`, que é também a propriedade na tela. */
        public readonly string $coluna,
        /** Como o operador chama isto. Usado na tela e nas mensagens de recusa. */
        public readonly string $rotulo,
        /** O valor quando a coluna não disse nada. Igual ao default da migration. */
        public readonly int $padrao,
        public readonly int $minimo,
        /** Teto do tipo da coluna: 65535 no SMALLINT, 255 no TINYINT. */
        public readonly int $maximo,
        /** `ms` na maioria; vazio nos dois que são contagem, não tempo. */
        public readonly string $unidade = 'ms',
        /**
         * O relógio deste critério corre DENTRO do orçamento de `pos_analise_total_ms`?
         *
         * Quando corre, pedir tempo igual ou maior que o orçamento faz o critério
         * nunca ser alcançado — em silêncio. Ver `PosAtendimento::criteriosMortos()`.
         */
        public readonly bool $dentroDaAnalise = false,
    ) {
    }
}
