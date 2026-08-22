<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Dominio\CaminhoDeGravacao;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Serve a gravação de uma Ligação para o player do painel.
 *
 * 🔴 CONVERTE, NÃO ENTREGA O ARQUIVO CRU. O `MixMonitor` grava `.gsm` — escolha de
 * espaço, e boa: 200 canais em WAV são 41 GB/dia contra 4,3 GB em GSM. Mas **nenhum
 * navegador toca GSM cru**; o arquivo nem tem cabeçalho (`file` diz só "data"). Um
 * `X-Accel-Redirect` do arquivo original — que é para o que o `location
 * /gravacao-interna/` do nginx existe — entregaria um download que o operador não
 * consegue ouvir. Por isso aqui passa pelo `sox`.
 *
 * O custo é pequeno e medido: 13,8 s de áudio convertem em 0,02 s. É ouvir chamada
 * pontualmente, não servir mídia em escala.
 *
 * 🔴 O ID É O ÚNICO PARÂMETRO, e o caminho é DERIVADO dele — nunca recebido. Aceitar
 * caminho do navegador seria travessia de diretório: `?f=../../etc/asterisk/pjsip-rota.conf`
 * leria a senha da Rota. O nome do arquivo vem do id inteiro da Ligação (ADR-0003), e
 * `CaminhoDeGravacao::contido` confirma que o resultado não escapou da base — cinto e
 * suspensório, porque a consequência de errar aqui é ler qualquer arquivo do servidor.
 */
final class Gravacao extends Controller
{
    /** Chamada mais longa que o produto admite; acima disso é arquivo suspeito. */
    private const TIMEOUT_CONVERSAO_SEG = 30;

    public function __invoke(int $ligacao): BinaryFileResponse
    {
        $caminho = DB::table('ligacao')->where('id', $ligacao)->value('gravacao');

        if (! is_string($caminho) || trim($caminho) === '') {
            abort(404);
        }

        // MESMA fonte que a tela de Resultados usa para dizer se o arquivo existe. Duas
        // fontes divergiriam na primeira mudança, e o sintoma seria a tela oferecer o
        // botão de um arquivo que o controller não encontra.
        // `config()` devolve mixed; conferir o tipo em vez de forçar o cast é o que faz
        // configuração ausente virar 404 em vez de string vazia disfarçada de caminho.
        $configurado = config('fastagi.gravacao_dir');
        $base = is_string($configurado) ? rtrim($configurado, '/') : '';

        if ($base === '') {
            abort(404);
        }

        if (! (new CaminhoDeGravacao())->contido($base, $caminho)) {
            // Não é 404: é a coluna apontando para fora da base, o que só acontece por
            // adulteração ou por configuração trocada. Registrar é o que torna isso
            // visível — 404 silencioso pareceria arquivo apagado.
            Log::warning('gravação fora do diretório base', ['ligacao' => $ligacao, 'caminho' => $caminho]);
            abort(404);
        }

        if (! is_file($caminho) || ! is_readable($caminho)) {
            // Duas causas com a mesma resposta HTTP, mas a tela já as distingue antes de
            // oferecer o botão: "não está no disco" (expurgada) e "não consigo ver"
            // (php-fpm fora do grupo asterisk).
            abort(404);
        }

        $wav = tempnam(sys_get_temp_dir(), 'grav_') . '.wav';

        try {
            // `-t gsm -r 8000 -c 1` na ENTRADA é obrigatório: GSM cru não tem cabeçalho,
            // então não há nada no arquivo dizendo a taxa. Sem isto o sox recusa a abrir.
            (new Process([
                'sox',
                '-t', 'gsm', '-r', '8000', '-c', '1', $caminho,
                '-t', 'wav', '-r', '8000', '-c', '1', '-b', '16', $wav,
            ], timeout: self::TIMEOUT_CONVERSAO_SEG))->mustRun();
        } catch (ProcessFailedException $e) {
            @unlink($wav);
            Log::warning('falha ao converter gravação', [
                'ligacao' => $ligacao,
                'erro' => $e->getMessage(),
            ]);
            abort(500, 'Não consegui converter esta gravação.');
        }

        /*
         * `response()->file()` e não `streamDownload`: ele põe `Content-Length` e trata
         * `Range`, que é o que faz a barra de progresso do player funcionar. Sem Range o
         * áudio toca, mas arrastar para o meio da conversa não vai a lugar nenhum — e
         * ouvir chamada é justamente pular para o trecho que interessa.
         *
         * `deleteFileAfterSend` limpa o temporário. Sem isso o /tmp enche de WAV, que é
         * 10x o tamanho do GSM que existe justamente para não ocupar espaço.
         */
        $resposta = response()
            ->file($wav, [
                'Content-Type' => 'audio/wav',
                'Content-Disposition' => 'inline; filename="ligacao-' . $ligacao . '.wav"',
            ])
            ->deleteFileAfterSend();

        /*
         * 🔴 `setPrivate()` DEPOIS de montar a resposta, não como cabeçalho no array.
         *
         * MEDIDO: passando `Cache-Control` junto dos outros cabeçalhos, o Symfony ainda
         * assim marca a resposta como `public` e o header sai `no-store, public` — as
         * duas coisas ao mesmo tempo. O `no-store` prevalece na prática, mas gravação de
         * conversa marcada como `public` é contradição que não deve existir num
         * cabeçalho que alguém vai auditar.
         */
        $resposta->setPrivate();
        $resposta->headers->addCacheControlDirective('no-store');

        return $resposta;
    }
}
