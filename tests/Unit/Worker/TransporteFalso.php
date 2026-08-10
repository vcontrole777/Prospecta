<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use App\Worker\Transporte;

/**
 * Transporte de teste. É o análogo do `ArreioAgi` para o `fastagi`: a razão de a
 * saída HTTP estar atrás de interface é justamente esta — testar a regra de envio
 * sem rede, sem token e sem mandar mensagem para telefone de verdade.
 */
final class TransporteFalso implements Transporte
{
    public ?string $url = null;

    /** @var array<string,mixed> */
    public array $corpo = [];

    /** @var array<string,string> */
    public array $cabecalhos = [];

    public int $chamadas = 0;

    public function __construct(
        private readonly int $http,
        private readonly string $resposta,
        private readonly ?string $erro = null,
    ) {
    }

    /**
     * @param array<string,mixed> $corpo
     * @param array<string,string> $cabecalhos
     * @return array{http:int, corpo:string, erro:?string}
     */
    public function postJson(string $url, array $corpo, array $cabecalhos): array
    {
        $this->chamadas++;
        $this->url = $url;
        $this->corpo = $corpo;
        $this->cabecalhos = $cabecalhos;

        return ['http' => $this->http, 'corpo' => $this->resposta, 'erro' => $this->erro];
    }
}
