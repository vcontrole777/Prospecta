<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * Argumento de aplicação Asterisk fora da lista de permissão.
 *
 * ADR-0003: valor externo é hostil em cinco contextos, e argumento de aplicação
 * é o mais esquecido deles. Recusar é melhor que escapar: no MagnusBilling
 * existe uma função de escape que a própria análise deles registra como quebrada
 * sob NO_BACKSLASH_ESCAPES e em charset multibyte.
 */
final class ArgumentoRecusado extends \RuntimeException
{
    public function __construct(string $aplicacao, string $argumento)
    {
        parent::__construct("Argumento recusado para {$aplicacao}: " . json_encode($argumento));
    }
}
