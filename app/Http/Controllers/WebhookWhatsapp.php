<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Seguranca\Cofre;
use App\Whatsapp\Assinatura;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Entrada do webhook do WhatsApp.
 *
 * 🔴 GRAVA E DEVOLVE 200. NADA de processamento aqui.
 *
 * É o espelho de entrada do ADR-0002: assim como Ação não é requisição HTTP dentro
 * da chamada, evento não é processamento dentro do webhook. Duas razões, e as duas
 * são operacionais:
 *
 *   · a Meta REENTREGA em não-200 e em lentidão. Processar aqui significa que um
 *     banco lento, ou um erro numa Conversa específica, vira reentrega em laço —
 *     e a reentrega traz o mesmo evento, então o erro se repete.
 *   · o processamento precisa de retentativa própria e de ordem, e isso é trabalho
 *     do worker, que já tem as duas coisas.
 *
 * Controller e não componente Livewire: quem chama é máquina, não navegador.
 */
final class WebhookWhatsapp extends Controller
{
    public function __construct(
        private readonly Assinatura $assinatura = new Assinatura(),
    ) {
    }

    /**
     * Aperto de mão da Meta. Ela chama com `hub.mode=subscribe` e devolve o desafio.
     *
     * O `verify_token` é escolhido pelo operador ao cadastrar o webhook no painel da
     * Meta, e guardado cifrado junto das outras credenciais.
     */
    public function verificar(Request $request): Response
    {
        $token = $this->credencial('verify_token');

        $modo = $request->query('hub_mode');
        $enviado = $request->query('hub_verify_token');
        $desafio = $request->query('hub_challenge');

        // `hash_equals` também aqui: o token é comparado com valor que o chamador
        // controla, e o aperto de mão é público.
        if ($modo !== 'subscribe' || ! is_string($enviado) || $token === ''
            || ! hash_equals($token, $enviado)) {
            return new Response('', 403);
        }

        return new Response(is_string($desafio) ? $desafio : '', 200);
    }

    public function receber(Request $request): Response
    {
        // 🔴 O CORPO CRU, não `$request->all()`. A Meta assina os bytes que enviou;
        // JSON reserializado tem espaçamento e escape diferentes, e o HMAC falharia
        // para 100% dos pedidos legítimos.
        $corpo = $request->getContent();
        $appSecret = $this->credencial('app_secret');

        // `header()` pode devolver array quando o cabeçalho vem repetido. Cabeçalho
        // de assinatura repetido não é pedido legítimo, então tratar como ausente é a
        // leitura certa — e é o que `self::cabecalho` faz.
        $cabecalho = self::cabecalho($request->header('X-Hub-Signature-256'));

        if (! $this->assinatura->confere($corpo, $cabecalho, $appSecret)) {
            // 403 e NÃO 200: aqui a reentrega da Meta é desejável, porque a causa
            // provável é app secret errado no cadastro, e o evento não deve ser
            // perdido enquanto o operador conserta. Também não grava nada — a tabela
            // de eventos não é lugar para JSON de desconhecido.
            Log::warning('webhook do WhatsApp com assinatura inválida', [
                'tem_cabecalho' => $request->hasHeader('X-Hub-Signature-256'),
                'tem_app_secret' => $appSecret !== '',
                'bytes' => strlen($corpo),
            ]);

            return new Response('', 403);
        }

        if (json_decode($corpo, true) === null) {
            // Assinado e não é JSON: não é ataque, é a Meta mudando algo. Registra e
            // devolve 200, porque reentregar não vai fazer virar JSON.
            Log::warning('webhook do WhatsApp assinado mas sem JSON válido', ['bytes' => strlen($corpo)]);

            return new Response('', 200);
        }

        // Idempotência no ESQUEMA: `id_externo` é único e o id vem do resumo do
        // corpo. `insertOrIgnore` faz da reentrega um no-op — sem engolir outros
        // erros, ao contrário de um try/catch em volta de tudo.
        DB::table('evento_whatsapp')->insertOrIgnore([
            'id_externo' => $this->assinatura->identificador($corpo),
            'payload' => $corpo,
        ]);

        // 200 imediato. O worker consome o que está com `processado_em` nulo.
        return new Response('', 200);
    }

    /**
     * Um cabeçalho repetido chega como array. Assinatura duplicada não é pedido
     * legítimo, então vira `null` e o pedido é recusado — falhar fechado.
     */
    private static function cabecalho(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }

    /**
     * Uma credencial da integração de WhatsApp em vigor.
     *
     * Vazio quando não há integração, quando a credencial não abre, ou quando o campo
     * não foi preenchido — e nos três casos o efeito é o mesmo: a assinatura não
     * confere e o pedido é recusado. Falhar fechado é o comportamento certo num
     * endpoint público.
     */
    private function credencial(string $campo): string
    {
        $coluna = DB::table('configuracao as cfg')
            ->join('integracao as i', 'i.id', '=', 'cfg.integracao_whatsapp_id')
            ->where('cfg.id', 1)
            ->where('i.ativo', 1)
            ->value('i.credenciais');

        if (! is_string($coluna)) {
            return '';
        }

        try {
            $chave = config('app.key');
            $cred = (new Cofre(is_string($chave) ? $chave : ''))->decifrarDaColuna($coluna);
        } catch (RuntimeException $e) {
            Log::warning('credencial do WhatsApp ilegível no webhook', ['erro' => $e->getMessage()]);

            return '';
        }

        $v = $cred[$campo] ?? null;

        return is_string($v) ? $v : '';
    }
}
