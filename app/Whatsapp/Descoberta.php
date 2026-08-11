<?php

declare(strict_types=1);

namespace App\Whatsapp;

use App\Worker\Transporte;

/**
 * Descobre o Phone Number ID a partir do token, em vez de pedir ao operador.
 *
 * 🔴 PERGUNTAR MENOS É PERGUNTAR MENOS COISA ERRADA.
 *
 * A tela pedia cinco valores para o WhatsApp: token, Phone Number ID, versão da API, App
 * Secret e Verify Token. Três deles não precisavam ser perguntados:
 *
 * · o **Phone Number ID** o próprio token conhece, e é o que esta classe busca. É um número
 *   de 15 dígitos copiado à mão de outro painel — o tipo de campo que erra sem avisar, e o
 *   erro aparece só na primeira mensagem que não sai, como `Unsupported get request`;
 * · o **Verify Token** é escolhido pelo operador e repetido na Meta. Não há nada a escolher:
 *   o painel gera e mostra para copiar;
 * · a **versão da API** tem padrão e quase nunca muda.
 *
 * Sobra o token e o App Secret, que são segredos do painel da Meta e não podem ser
 * derivados de nada.
 *
 * 🔴 DOIS PASSOS, PORQUE A META NÃO DÁ O NÚMERO DIRETO DO TOKEN.
 *
 * 1. `debug_token` devolve `granular_scopes`, e é lá que estão os `target_ids` do escopo
 *    `whatsapp_business_messaging` — as contas (WABA) que o token alcança.
 * 2. `{waba}/phone_numbers` devolve os números daquela conta, com id e o número exibido.
 *
 * Quando há um número só, está resolvido. Com vários, o operador escolhe de uma LISTA com o
 * número visível — o que é uma pergunta melhor que digitar um id, porque ele reconhece o
 * número e não reconhece o id.
 *
 * Falhando, devolve o motivo que a Meta deu e a tela cai no campo manual. Automatizar não
 * pode virar beco sem saída: token com escopo estreito é caso real, e o operador precisa
 * conseguir seguir.
 */
final class Descoberta
{
    public function __construct(
        private readonly Transporte $transporte,
        private readonly string $versaoApi = 'v21.0',
    ) {
    }

    /**
     * @return array{ok:bool, numeros:list<array{id:string, numero:string, nome:string}>, erro:?string}
     */
    public function numeros(string $token): array
    {
        if (trim($token) === '') {
            return ['ok' => false, 'numeros' => [], 'erro' => 'Sem token não há o que consultar.'];
        }

        $contas = $this->contas($token);
        if ($contas['erro'] !== null) {
            return ['ok' => false, 'numeros' => [], 'erro' => $contas['erro']];
        }

        if ($contas['ids'] === []) {
            return [
                'ok' => false,
                'numeros' => [],
                'erro' => 'O token não dá acesso a nenhuma conta de WhatsApp Business. '
                    . 'Confira se ele tem a permissão whatsapp_business_messaging.',
            ];
        }

        $numeros = [];
        $ultimoErro = null;

        foreach ($contas['ids'] as $waba) {
            $r = $this->numerosDaConta($token, $waba);

            if ($r['erro'] !== null) {
                // Não aborta: um token pode alcançar várias contas e falhar em uma. Guardar o
                // erro e seguir é o que faz o operador com duas contas conseguir usar a que
                // funciona, em vez de travar pela outra.
                $ultimoErro = $r['erro'];
                continue;
            }

            foreach ($r['numeros'] as $n) {
                $numeros[] = $n;
            }
        }

        if ($numeros === []) {
            return [
                'ok' => false,
                'numeros' => [],
                'erro' => $ultimoErro ?? 'Nenhum número encontrado nas contas que o token alcança.',
            ];
        }

        return ['ok' => true, 'numeros' => $numeros, 'erro' => null];
    }

    /**
     * As contas (WABA) que o token alcança, via `debug_token`.
     *
     * O token é usado como `input_token` E como `access_token`: inspecionar o próprio token
     * é permitido, e evita exigir do operador o token de app, que é outro segredo.
     *
     * @return array{ids:list<string>, erro:?string}
     */
    private function contas(string $token): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/debug_token?input_token=%s&access_token=%s',
            $this->versaoApi,
            urlencode($token),
            urlencode($token),
        );

        $r = $this->transporte->getJson($url, []);
        $dados = $this->json($r);

        if ($dados['erro'] !== null) {
            return ['ids' => [], 'erro' => $dados['erro']];
        }

        $corpo = $dados['corpo'];
        $data = is_array($corpo['data'] ?? null) ? $corpo['data'] : [];

        // `is_valid: false` é a resposta para token expirado ou revogado, e vem com HTTP 200
        // — então precisa ser lida do CORPO. Tratar 200 como sucesso aqui deixaria o
        // operador com "nenhum número encontrado" quando a causa é token morto.
        if (($data['is_valid'] ?? null) === false) {
            return [
                'ids' => [],
                'erro' => 'A Meta diz que este token não é válido — provavelmente expirou. '
                    . 'Gere um novo no painel dela.',
            ];
        }

        $ids = [];
        foreach (is_array($data['granular_scopes'] ?? null) ? $data['granular_scopes'] : [] as $escopo) {
            if (! is_array($escopo)) {
                continue;
            }
            if (($escopo['scope'] ?? null) !== 'whatsapp_business_messaging') {
                continue;
            }
            foreach (is_array($escopo['target_ids'] ?? null) ? $escopo['target_ids'] : [] as $id) {
                if (is_string($id) || is_int($id)) {
                    $ids[] = (string) $id;
                }
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'erro' => null];
    }

    /**
     * @return array{numeros:list<array{id:string, numero:string, nome:string}>, erro:?string}
     */
    private function numerosDaConta(string $token, string $waba): array
    {
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/phone_numbers?fields=id,display_phone_number,verified_name&access_token=%s',
            $this->versaoApi,
            urlencode($waba),
            urlencode($token),
        );

        $r = $this->transporte->getJson($url, []);
        $dados = $this->json($r);

        if ($dados['erro'] !== null) {
            return ['numeros' => [], 'erro' => $dados['erro']];
        }

        $numeros = [];
        foreach (is_array($dados['corpo']['data'] ?? null) ? $dados['corpo']['data'] : [] as $n) {
            if (! is_array($n) || ! is_string($n['id'] ?? null)) {
                continue;
            }

            $numeros[] = [
                'id' => $n['id'],
                'numero' => is_string($n['display_phone_number'] ?? null) ? $n['display_phone_number'] : '',
                'nome' => is_string($n['verified_name'] ?? null) ? $n['verified_name'] : '',
            ];
        }

        return ['numeros' => $numeros, 'erro' => null];
    }

    /**
     * Decodifica, e traduz a falha para algo que o operador possa agir.
     *
     * 🔴 A MENSAGEM DA META VEM PRIMEIRO, quando existe. Ela escreve em português para o
     * usuário final e explica o que nós parafrasearíamos pior. O `fbtrace_id` vai junto
     * porque é o que um ticket com a Meta exige.
     *
     * @param  array{http:int, corpo:string, erro:?string}  $r
     * @return array{corpo:array<string,mixed>, erro:?string}
     */
    private function json(array $r): array
    {
        if ($r['erro'] !== null) {
            return ['corpo' => [], 'erro' => 'Não consegui falar com a Meta: ' . $r['erro']];
        }

        $corpo = json_decode($r['corpo'], true);
        $corpo = is_array($corpo) ? $corpo : [];

        $erroApi = is_array($corpo['error'] ?? null) ? $corpo['error'] : null;

        if ($erroApi !== null) {
            $partes = array_filter([
                is_string($erroApi['error_user_msg'] ?? null) ? $erroApi['error_user_msg'] : null,
                is_string($erroApi['message'] ?? null) ? $erroApi['message'] : null,
                is_string($erroApi['fbtrace_id'] ?? null) ? 'fbtrace=' . $erroApi['fbtrace_id'] : null,
            ]);

            return ['corpo' => [], 'erro' => implode(' | ', $partes) ?: 'A Meta recusou a consulta.'];
        }

        if ($r['http'] < 200 || $r['http'] >= 300) {
            return ['corpo' => [], 'erro' => sprintf('A Meta respondeu HTTP %d.', $r['http'])];
        }

        return ['corpo' => $corpo, 'erro' => null];
    }
}
