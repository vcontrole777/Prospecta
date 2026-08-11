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

    /**
     * GET com resposta JSON.
     *
     * Existe para a DESCOBERTA de configuração: a tela de Integrações usa o token do
     * operador para achar o Phone Number ID em vez de pedir que ele o copie do painel da
     * Meta. Perguntar menos é perguntar menos coisa errada — ID digitado à mão erra, e o
     * erro só aparece na primeira mensagem que não sai.
     *
     * @param  array<string,string>  $cabecalhos
     * @return array{http:int, corpo:string, erro:?string}
     */
    public function getJson(string $url, array $cabecalhos): array;
}
