<?php

declare(strict_types=1);

namespace App\Whatsapp;

/**
 * Detecção de pedido de descadastro em mensagem de entrada. PURA.
 *
 * 🔴 CONSERVADORA POR CONSTRUÇÃO, e a razão é a assimetria do erro:
 *
 *   · falso POSITIVO bloqueia o Lead na lista GLOBAL, para sempre, em toda Campanha
 *     — e a lista de bloqueio existe justamente para não ter volta
 *   · falso NEGATIVO custa o Lead repetir, ou apertar a tecla de opt-out na próxima
 *     chamada, que é o caminho principal de qualquer forma
 *
 * Então o casamento é sobre a MENSAGEM INTEIRA normalizada, não sobre conter a
 * palavra. "Pode parar de me ligar" casa; "vou parar no mercado antes" não. Um
 * `str_contains('parar')` bloquearia os dois — e o segundo é um Lead perdido sem
 * ninguém saber.
 *
 * O produto não tem obrigação de conformidade aqui: o tratamento da lista é de quem
 * envia. Isto é cortesia operacional, e é o que evita reclamação.
 */
final class OptOut
{
    /**
     * Mensagem INTEIRA, normalizada, que significa descadastro.
     *
     * "para" e "pare" estão aqui e são seguros porque o casamento é da mensagem
     * inteira: ninguém manda só a preposição "para" numa conversa.
     *
     * @var list<string>
     */
    private const EXATAS = [
        'sair', 'saír', 'parar', 'pare', 'para', 'cancelar', 'cancela', 'cancelado',
        'descadastrar', 'descadastre', 'remover', 'remova', 'excluir', 'exclua',
        'nao quero', 'nao quero mais', 'nao me ligue', 'nao me ligue mais',
        'nao perturbe', 'me tira', 'me tire', 'me remova', 'me exclua',
        'sair da lista', 'tirar da lista', 'me tira da lista', 'me tire da lista',
        'para de me ligar', 'pare de me ligar', 'pode parar', 'pode parar de me ligar',
        'nao envie mais', 'nao mande mais', 'chega',
        'stop', 'unsubscribe', 'opt out', 'optout', 'remove',
    ];

    /**
     * `payload` de botão que significa descadastro.
     *
     * Botão é sinal FORTE e não precisa de heurística: o operador escolheu aquele
     * payload ao criar o template, então casamento por prefixo é seguro aqui.
     *
     * @var list<string>
     */
    private const BOTOES = ['opt_out', 'optout', 'sair', 'cancelar', 'parar', 'nao_quero'];

    public function pediu(Entrada $e): bool
    {
        if ($e->botao !== null && $this->botaoPede($e->botao)) {
            return true;
        }

        return in_array($this->normalizar($e->texto), self::EXATAS, true);
    }

    private function botaoPede(string $payload): bool
    {
        $p = $this->normalizar($payload);

        foreach (self::BOTOES as $b) {
            if ($p === $b || str_starts_with($p, $b)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Caixa baixa, sem acento, sem pontuação, espaços colapsados.
     *
     * Sem acento porque teclado de celular erra e muita gente escreve sem. Sem
     * pontuação porque "PARAR!" e "parar." são o mesmo pedido, e exigir a forma exata
     * transformaria cortesia em pegadinha.
     */
    private function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto));

        $t = strtr($t, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'è' => 'e',
            'í' => 'i', 'î' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        $t = (string) preg_replace('/[^a-z0-9_ ]+/u', '', $t);
        $t = (string) preg_replace('/\s+/', ' ', $t);

        return trim($t);
    }
}
