<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serve uma imagem da galeria. É a ÚNICA rota de arquivo aberta do produto.
 *
 * 🔴 PÚBLICA POR NECESSIDADE, não por descuido. Quem busca a imagem é a Meta,
 * para montar o cabeçalho do template, e ela não faz login. `/audios` e
 * `/gravacao` continuam atrás de `auth`; esta não pode.
 *
 * A proteção possível numa rota aberta é a URL não ser adivinhável, e é o que o
 * `token` de 32 caracteres faz. Com o id na URL — `/imagem/1`, `/imagem/2` — quem
 * descobrisse uma enumeraria a galeria inteira.
 *
 * O risco aceito está nomeado: a imagem vai para milhares de desconhecidos no
 * disparo de qualquer forma, então ela não é segredo. Gravação de chamada é, e por
 * isso não mudou de lugar.
 *
 * 🔴 O TOKEN É O ÚNICO PARÂMETRO, e o caminho é DERIVADO dele pelo banco — nunca
 * recebido. É a mesma disciplina do `Gravacao` e do ADR-0003: aceitar caminho do
 * navegador seria travessia de diretório num servidor que guarda a senha da Rota
 * em arquivo.
 */
final class Imagem extends Controller
{
    public function __invoke(string $token): BinaryFileResponse
    {
        // A rota já restringe o formato; conferir aqui de novo é barato e mantém o
        // controller correto se alguém afrouxar a rota depois.
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            abort(404);
        }

        $linha = DB::table('imagem')->where('token', $token)->first();
        if ($linha === null) {
            abort(404);
        }

        $arquivo = is_string($linha->arquivo ?? null) ? $linha->arquivo : '';
        $mime = is_string($linha->mime ?? null) ? $linha->mime : 'application/octet-stream';

        // `basename` porque o valor vem do banco e o banco pode ter sido adulterado.
        // Cinto e suspensório: a coluna é escrita a partir de um inteiro, então isto
        // não deveria mudar nada — e é exatamente por isso que é barato manter.
        $caminho = self::diretorio() . '/' . basename($arquivo);

        if ($arquivo === '' || ! is_file($caminho)) {
            abort(404);
        }

        return response()->file($caminho, [
            'Content-Type' => $mime,
            // A imagem de um template não muda: o mesmo token sempre devolve o mesmo
            // arquivo. Deixar a Meta e os intermediários guardarem evita uma busca
            // por disparo num envio de milhares.
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    /** Onde os arquivos moram. Fora de `public/`, servidos só por aqui. */
    public static function diretorio(): string
    {
        return storage_path('app/imagens');
    }
}
