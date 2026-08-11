<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use App\Worker\Transporte;

/**
 * Transporte de teste. É o análogo do `ArreioAgi` para o `fastagi`: a razão de a
 * saída HTTP estar atrás de interface é justamente esta — testar a regra de envio
 * sem rede, sem token e sem mandar mensagem para telefone de verdade.
 *
 * POST e GET são preparados de formas diferentes de propósito. O envio faz UMA chamada e
 * devolve sempre a mesma resposta, então basta o construtor. A descoberta faz uma sequência
 * — `debug_token`, depois um `phone_numbers` por conta — e cada passo precisa de resposta
 * própria; por isso `respostasGet` é uma FILA, consumida em ordem.
 */
final class TransporteFalso implements Transporte
{
    public ?string $url = null;

    /** @var array<string,mixed> */
    public array $corpo = [];

    /** @var array<string,string> */
    public array $cabecalhos = [];

    public int $chamadas = 0;

    /** @var list<array{http:int, corpo:string, erro:?string}> Respostas de GET, consumidas em ordem. */
    public array $respostasGet = [];

    /** @var list<string> URLs consultadas por GET, em ordem — é o que prova o escape do token. */
    public array $urlsGet = [];

    /**
     * Os três têm padrão para o dublê poder ser construído sem argumento nos testes que só
     * exercitam GET. Exigir a resposta de POST ali obrigaria a inventar um valor que o teste
     * não usa, e valor inventado no arreio é o que faz o teste passar por motivo errado.
     */
    public function __construct(
        private readonly int $http = 200,
        private readonly string $resposta = '{}',
        private readonly ?string $erro = null,
    ) {
    }

    /**
     * @param  array<string,string>  $cabecalhos
     * @return array{http:int, corpo:string, erro:?string}
     */
    public function getJson(string $url, array $cabecalhos): array
    {
        $this->urlsGet[] = $url;

        // Fila vazia devolve erro NOMEADO, não uma resposta vazia plausível: teste que
        // consulta mais vezes do que preparou tem que falhar dizendo isso, e não seguir com
        // um corpo em branco que o código interpretaria como "nenhum número".
        return array_shift($this->respostasGet)
            ?? ['http' => 0, 'corpo' => '', 'erro' => 'TransporteFalso: sem resposta de GET preparada'];
    }

    /**
     * @param  array<string,mixed>  $corpo
     * @param  array<string,string>  $cabecalhos
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
