<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Seguranca\Cofre;
use App\Sms\Assinatura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recebe os eventos do httpSMS. Público, como o do WhatsApp e pelo mesmo motivo.
 *
 * 🔴 GUARDA E SAI, sem interpretar nada. O httpSMS dá **5 segundos** de timeout e
 * reentrega até quatro vezes em cima de 5XX — processar aqui dentro transformaria
 * uma consulta lenta em avalanche de reentrega. Quem interpreta é o worker, na
 * volta seguinte. Mesma disciplina do ADR-0002.
 *
 * 🔴 A ASSINATURA É JWT, NÃO HMAC DO CORPO. Ver `Sms\Assinatura`: o token prova que
 * quem chamou conhece a chave, mas não amarra o corpo. O que fecha o resto é o
 * caminho adiante — o `id` do CloudEvent dá idempotência, e um status só é aplicado
 * a uma mensagem cujo id já está gravado numa Ação nossa.
 */
final class WebhookSms extends Controller
{
    public function __construct(private readonly Assinatura $assinatura = new Assinatura())
    {
    }

    public function __invoke(Request $request): Response
    {
        $chave = $this->chaveDeAssinatura();

        /*
         * FALHA FECHADA. Sem chave cadastrada, sem token, ou token que não confere: o
         * pedido é recusado. Numa rota pública, "na dúvida aceita" é o mesmo que não
         * ter proteção.
         */
        if (! $this->assinatura->confere(self::cabecalho($request->header('Authorization')), $chave, time())) {
            Log::warning('webhook do httpSMS com assinatura inválida', [
                'evento' => self::cabecalho($request->header('X-Event-Type')),
                'tem_chave' => $chave !== '',
            ]);

            return new Response('', 401);
        }

        $corpo = $request->getContent();
        $evento = json_decode($corpo, true);

        if (! is_array($evento)) {
            return new Response('', 400);
        }

        $id = $evento['id'] ?? null;
        if (! is_string($id) || trim($id) === '') {
            // CloudEvent sem `id` não tem como ser deduplicado, e aceitar seria abrir
            // caminho para a reentrega processar o mesmo evento quatro vezes.
            return new Response('', 400);
        }

        // `insertOrIgnore`: a reentrega do httpSMS traz o MESMO `id`, e o índice único
        // faz o repetido ser descartado pelo esquema, sem código de deduplicação.
        DB::table('evento_sms')->insertOrIgnore([
            'id_externo' => trim($id),
            'payload' => $corpo,
        ]);

        // 200 imediato. O worker consome o que está com `processado_em` nulo.
        return new Response('', 200);
    }

    /**
     * A chave de assinatura da integração de SMS em vigor.
     *
     * Vazia quando não há integração, quando a credencial não abre, ou quando o campo
     * não foi preenchido — e nos três casos o efeito é o mesmo: a assinatura não
     * confere e o pedido é recusado.
     */
    private function chaveDeAssinatura(): string
    {
        $coluna = DB::table('configuracao as cfg')
            ->join('integracao as i', 'i.id', '=', 'cfg.integracao_sms_id')
            ->where('cfg.id', 1)
            ->where('i.ativo', 1)
            ->value('i.credenciais');

        if (! is_string($coluna)) {
            return '';
        }

        try {
            $chaveApp = config('app.key');
            $cred = (new Cofre(is_string($chaveApp) ? $chaveApp : ''))->decifrarDaColuna($coluna);
        } catch (RuntimeException $e) {
            Log::warning('credencial de SMS ilegível no webhook', ['erro' => $e->getMessage()]);

            return '';
        }

        $v = $cred['signing_key'] ?? null;

        return is_string($v) ? $v : '';
    }

    /** Cabeçalho repetido chega como array; assinatura duplicada não é pedido legítimo. */
    private static function cabecalho(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
