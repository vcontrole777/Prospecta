<?php

declare(strict_types=1);

namespace App\Whatsapp;

use App\Worker\Transporte;

/**
 * Lista os templates APROVADOS de uma WABA, para a tela de Campanha oferecer escolha
 * em vez de campo de texto.
 *
 * Por que isto existe: `template_nome` e `template_idioma` digitados à mão erram em
 * SILÊNCIO. Nome trocado, idioma `pt-BR` em vez de `pt_BR`, template ainda em
 * `PENDING` — nada disso falha ao salvar a Campanha. Falha lá na frente, no Disparo,
 * quando o Opt-in já aconteceu e a pessoa está esperando a mensagem. O erro fica a uma
 * distância enorme da causa.
 *
 * Escolher de uma lista elimina a classe inteira: o que está na lista existe, está
 * aprovado, e vem com o idioma correto colado.
 *
 * 🔴 SÓ `APPROVED`. Template em `PENDING`, `REJECTED` ou `PAUSED` não é entregue pela
 * Meta — oferecê-lo seria oferecer uma Campanha que não vai disparar.
 *
 * 🔴 FALHAR NÃO PODE VIRAR BECO SEM SAÍDA. Mesma disciplina do `Descoberta`: se a Meta
 * não responder, ou o token não alcançar a WABA, a tela cai no campo manual. Token com
 * escopo estreito é caso real, e o operador precisa conseguir seguir.
 */
final class TemplatesAprovados
{
    public function __construct(
        private readonly Transporte $transporte,
        private readonly string $versaoApi = 'v21.0',
    ) {
    }

    /**
     * @return array{ok:bool, templates:list<array{chave:string, nome:string, idioma:string, parametros:int, corpo:string, rotulo:string}>, erro:?string}
     */
    public function listar(string $token, string $waba): array
    {
        if (trim($token) === '') {
            return ['ok' => false, 'templates' => [], 'erro' => 'Sem token não há o que consultar.'];
        }

        if (trim($waba) === '') {
            return [
                'ok' => false,
                'templates' => [],
                'erro' => 'Sem a identificação da conta do WhatsApp Business não dá para listar '
                    . 'templates. Preencha-a na tela de Integrações.',
            ];
        }

        // `limit=200`: uma conta com mais templates que isso é caso que não vimos, e
        // paginar aqui seria código sem exercício. Se acontecer, o operador vê a lista
        // truncada e ainda tem o campo manual.
        $url = sprintf(
            'https://graph.facebook.com/%s/%s/message_templates'
                . '?fields=name,status,language,components&limit=200&access_token=%s',
            $this->versaoApi,
            urlencode(trim($waba)),
            urlencode($token),
        );

        $r = $this->transporte->getJson($url, []);

        if ($r['erro'] !== null) {
            return ['ok' => false, 'templates' => [], 'erro' => 'Não consegui falar com a Meta: ' . $r['erro']];
        }

        $corpo = json_decode($r['corpo'], true);
        $corpo = is_array($corpo) ? $corpo : [];

        $erroApi = is_array($corpo['error'] ?? null) ? $corpo['error'] : null;
        if ($erroApi !== null) {
            $partes = array_filter([
                is_string($erroApi['message'] ?? null) ? $erroApi['message'] : null,
                is_string($erroApi['fbtrace_id'] ?? null) ? 'fbtrace_id: ' . $erroApi['fbtrace_id'] : null,
            ]);

            return ['ok' => false, 'templates' => [], 'erro' => implode(' — ', $partes) ?: 'A Meta recusou a consulta.'];
        }

        $templates = [];
        $reprovados = 0;

        foreach (is_array($corpo['data'] ?? null) ? $corpo['data'] : [] as $t) {
            if (! is_array($t) || ! is_string($t['name'] ?? null)) {
                continue;
            }

            if (($t['status'] ?? null) !== 'APPROVED') {
                $reprovados++;

                continue;
            }

            $idioma = is_string($t['language'] ?? null) ? $t['language'] : '';
            $corpoTexto = self::corpo($t);

            $templates[] = [
                // Chave COMPOSTA: a Meta permite o mesmo `name` em idiomas diferentes,
                // e são templates distintos. O select precisa de um valor que os separe.
                'chave' => $t['name'] . '|' . $idioma,
                'nome' => $t['name'],
                'idioma' => $idioma,
                'parametros' => self::parametros($corpoTexto),
                'corpo' => $corpoTexto,
                'rotulo' => $t['name'] . ' (' . ($idioma !== '' ? $idioma : 'sem idioma') . ')',
            ];
        }

        // Lista vazia com templates existentes é informação DIFERENTE de conta sem
        // template nenhum, e o operador age diferente em cada caso: no primeiro ele
        // espera a aprovação, no segundo ele precisa criar.
        if ($templates === [] && $reprovados > 0) {
            return [
                'ok' => true,
                'templates' => [],
                'erro' => "Nenhum template aprovado. Há {$reprovados} aguardando aprovação ou "
                    . 'recusado(s) no gerenciador da Meta.',
            ];
        }

        return ['ok' => true, 'templates' => $templates, 'erro' => null];
    }

    /**
     * O texto do componente BODY, que é onde os parâmetros posicionais vivem.
     *
     * @param  array<string,mixed>  $t
     */
    private static function corpo(array $t): string
    {
        foreach (is_array($t['components'] ?? null) ? $t['components'] : [] as $c) {
            if (is_array($c) && ($c['type'] ?? null) === 'BODY' && is_string($c['text'] ?? null)) {
                return $c['text'];
            }
        }

        return '';
    }

    /**
     * Quantos parâmetros posicionais o corpo usa.
     *
     * Conta o MAIOR índice, não a quantidade de ocorrências: `{{1}} ... {{1}} ... {{3}}`
     * exige três parâmetros, porque a Meta os recebe por posição e uma lacuna manda o
     * valor no lugar errado. É a mesma regra que `CampanhaForm` já aplica ao montar o
     * `template_mapa`.
     */
    private static function parametros(string $corpo): int
    {
        if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $corpo, $m) === 0) {
            return 0;
        }

        $indices = array_map('intval', $m[1]);

        return $indices === [] ? 0 : max($indices);
    }
}
