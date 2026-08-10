<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Saída HTTP, atrás de interface pelo MESMO motivo que o `Ami` e o `Agi` recebem
 * fluxos injetados: a regra de envio precisa ser testável sem rede, sem token e
 * sem mandar mensagem de verdade para telefone de verdade.
 */
interface Transporte
{
    /**
     * @param array<string,mixed> $corpo
     * @param array<string,string> $cabecalhos
     * @return array{http:int, corpo:string, erro:?string}
     */
    public function postJson(string $url, array $corpo, array $cabecalhos): array;
}
