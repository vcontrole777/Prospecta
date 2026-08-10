<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Support\Facades\DB;

/**
 * Cria áudio de teste COM arquivo no disco.
 *
 * Existe porque a guarda de ativação confere o arquivo, não só a linha — então uma
 * fixture que insere em `audio` e não escreve nada é uma Campanha que não pode
 * iniciar, e com razão.
 *
 * Escreve os bytes direto em vez de chamar o `sox`: a 8 kHz mono A-law são 8000
 * bytes por segundo, exatos, e é o tamanho que a verificação olha. Chamar o `sox`
 * para gerar seno aqui só deixaria o teste mais lento.
 */
trait CriaAudio
{
    private ?string $dirDeAudio = null;

    protected function audioComArquivo(string $nome = 'Mensagem', int $segundos = 1): int
    {
        $dir = $this->diretorioDeAudio();

        $id = (int) DB::table('audio')->insertGetId([
            'nome' => $nome,
            'arquivo' => 'pendente',
            'duracao_seg' => $segundos,
            'ativo' => 1,
        ]);

        $arquivo = $id . '.alaw';
        // 0x55 é silêncio em A-law. O conteúdo é irrelevante; o TAMANHO não é.
        file_put_contents($dir . '/' . $arquivo, str_repeat("\x55", 8000 * $segundos));

        DB::table('audio')->where('id', $id)->update(['arquivo' => $arquivo]);

        return $id;
    }

    protected function diretorioDeAudio(): string
    {
        if ($this->dirDeAudio === null) {
            $this->dirDeAudio = sys_get_temp_dir() . '/prospecta-audio-' . getmypid() . '-' . spl_object_id($this);
            if (! is_dir($this->dirDeAudio)) {
                mkdir($this->dirDeAudio, 0o770, true);
            }
            config(['prospecta.audio_path' => $this->dirDeAudio]);
        }

        return $this->dirDeAudio;
    }

    protected function limparAudios(): void
    {
        if ($this->dirDeAudio === null) {
            return;
        }
        foreach (glob($this->dirDeAudio . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dirDeAudio);
        $this->dirDeAudio = null;
    }
}
