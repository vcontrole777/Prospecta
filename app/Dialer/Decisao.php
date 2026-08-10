<?php

declare(strict_types=1);

namespace App\Dialer;

final class Decisao
{
    public function __construct(
        public readonly Acao $acao,
        public readonly bool $consomeTentativa,
        /** Se este resultado conta para a proteção que pausa a Campanha. */
        public readonly bool $contaParaProtecao,
        public readonly string $motivo,
        /**
         * Desfecho a gravar na Ligação, ou `null` quando o dialer NÃO é o dono
         * desta coluna.
         *
         * 🔴 `null` para chamada ATENDIDA, e isso não é omissão. O `fastagi`
         * roda dentro da chamada atendida e é ele que sabe o desfecho de
         * verdade — `opt_in`, `maquina`, `captura_concluida`. Os dois reagem ao
         * MESMO hangup, então se o dialer escrevesse aqui haveria dois donos da
         * coluna numa corrida, e o dialer sobrescreveria `opt_in` com um
         * desfecho grosso. Perderia exatamente a linha pela qual o produto
         * existe, sem erro nenhum aparecendo.
         *
         * Quando não foi atendida o `fastagi` nunca rodou, então não há corrida:
         * ali o dialer é o único que pode fechar a Ligação.
         *
         * Um dos valores do enum `ligacao.desfecho`.
         */
        public readonly ?string $desfecho = null,
    ) {
    }
}
