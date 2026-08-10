<?php

declare(strict_types=1);

namespace App\Worker;

final class DecisaoDeEnvio
{
    public function __construct(
        /** Valor do enum `acao.status`. */
        public readonly string $status,
        public readonly bool $retentar,
        /**
         * O CANAL está quebrado, não esta Ação. O worker para a volta em vez de
         * percorrer a fila marcando tudo — token inválido erraria em todas.
         */
        public readonly bool $pararCanal,
        public readonly string $motivo,
    ) {
    }
}
