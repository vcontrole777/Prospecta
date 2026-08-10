<?php

declare(strict_types=1);

namespace App\Worker;

final class TransporteCurl implements Transporte
{
    public function __construct(
        private readonly int $timeoutSeg = 15,
    ) {
    }

    /**
     * @param array<string,mixed> $corpo
     * @param array<string,string> $cabecalhos
     * @return array{http:int, corpo:string, erro:?string}
     */
    public function postJson(string $url, array $corpo, array $cabecalhos): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['http' => 0, 'corpo' => '', 'erro' => 'curl_init falhou'];
        }

        $linhas = ['Content-Type: application/json'];
        foreach ($cabecalhos as $k => $v) {
            $linhas[] = "{$k}: {$v}";
        }

        $json = json_encode($corpo, JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json === false ? '{}' : $json,
            CURLOPT_HTTPHEADER => $linhas,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeg,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Sem seguir redirecionamento: a API não redireciona, e seguir
            // reenviaria o corpo — com o token — para um destino que não é o dela.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $resposta = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_errno($ch) !== 0 ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'http' => $http,
            'corpo' => is_string($resposta) ? $resposta : '',
            'erro' => $erro,
        ];
    }
}
