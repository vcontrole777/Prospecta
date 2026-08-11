<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Audio\AudioRecusado;
use App\Audio\Conversor;
use App\Dominio\SituacaoDoArquivo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Cadastro de áudio.
 *
 * 🔴 O áudio pertence à INSTÂNCIA, não à Campanha.
 *
 * A tabela `audio` é global e várias Campanhas apontam para a mesma linha — o que
 * está certo, porque "confirmação de opt-in" é o mesmo áudio em toda Campanha.
 *
 * A consequência é a regra de remoção: **desativa, nunca apaga**. Apagar a linha
 * deixaria a Campanha apontando para um `audio_mensagem_id` inexistente, e o
 * sintoma seria chamada muda — `Playback` em arquivo ausente devolve
 * `PLAYBACKSTATUS=FAILED` e o dialplan CONTINUA. O arquivo em disco também fica:
 * uma Campanha antiga concluída ainda referencia o que aconteceu.
 */
final class Audios extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $arquivo = null;

    public string $nome = '';

    public function render(): View
    {
        return view('livewire.audios', ['audios' => $this->lista()]);
    }

    public function salvar(): void
    {
        $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            // `extensions` e não `mimes`: o tipo detectado para áudio varia com o
            // sistema de quem envia, e o `sox` é quem decide de verdade se dá para
            // ler — a recusa dele vira `AUDIO_ILEGIVEL` com a mensagem original.
            'arquivo' => ['required', 'file', 'max:20480', 'extensions:wav,mp3,ogg,m4a,aac,flac'],
        ], [
            'arquivo.extensions' => 'Envie WAV, MP3, OGG, M4A, AAC ou FLAC.',
            'arquivo.max' => 'O arquivo passa de 20 MB.',
        ]);

        $origem = $this->arquivo?->getRealPath();
        if ($origem === null) {
            $this->addError('arquivo', 'O arquivo temporário expirou. Envie de novo.');

            return;
        }

        $diretorio = self::texto(config('prospecta.audio_path'));

        if (! is_dir($diretorio) || ! is_writable($diretorio)) {
            // Diagnóstico específico em vez de "erro ao salvar". Isto é permissão de
            // implantação: o diretório é do usuário `asterisk` e a aplicação escreve
            // nele pelo grupo, com setgid. Ver o bloco Base do provisionamento.
            $this->addError('arquivo', sprintf(
                'Não consigo escrever em %s. É permissão de implantação: o usuário da '
                . 'aplicação precisa estar no grupo asterisk e o diretório em 2770.',
                $diretorio,
            ));

            return;
        }

        // 🔴 Nome de arquivo derivado de um INTEIRO, nunca do nome que o operador
        // digitou. Fecha o contexto "caminho de arquivo" do ADR-0003 por construção:
        // não há como um nome com `../` ou com espaço chegar ao disco. O nome legível
        // vive na coluna `nome`, que é onde ele deve viver.
        $id = (int) DB::table('audio')->insertGetId([
            'nome' => trim($this->nome),
            'arquivo' => 'pendente',
            'ativo' => 0,
        ]);

        $base = $id . '.alaw';
        $destino = $diretorio . '/' . $base;

        try {
            $duracao = (new Conversor())->converter(
                $origem,
                $destino,
                self::numero(config('prospecta.audio_limite_seg')),
            );
        } catch (AudioRecusado $e) {
            // A linha nasceu inativa e é removida na recusa: ela nunca esteve
            // disponível para nenhuma Campanha, então apagar aqui não deixa
            // referência pendurada. É o único caso em que apagar é certo.
            DB::table('audio')->where('id', $id)->delete();

            $this->addError('arquivo', $e->getMessage());

            return;
        }

        DB::table('audio')->where('id', $id)->update([
            'arquivo' => $base,
            // `duracao_seg` é inteiro na coluna; arredonda para cima para não
            // reportar 0 s num áudio curto de verdade.
            'duracao_seg' => max(1, (int) ceil($duracao)),
            // Só agora fica disponível: entre o INSERT e aqui o áudio existia sem
            // arquivo verificado, e uma Campanha que o escolhesse nesse instante
            // sairia muda.
            'ativo' => 1,
        ]);

        $this->reset(['arquivo', 'nome']);
        session()->flash('recado', 'Áudio cadastrado.');
    }

    /**
     * Desativa. NÃO apaga — ver o comentário da classe.
     */
    public function desativar(int $id): void
    {
        $emUso = $this->campanhasQueUsam($id);

        DB::table('audio')->where('id', $id)->update(['ativo' => 0]);

        session()->flash('recado', $emUso > 0
            ? "Áudio desativado. {$emUso} Campanha(s) ainda o referenciam e continuam funcionando; "
                . 'ele só deixa de aparecer para escolha nova.'
            : 'Áudio desativado.');
    }

    public function reativar(int $id): void
    {
        DB::table('audio')->where('id', $id)->update(['ativo' => 1]);

        session()->flash('recado', 'Áudio reativado.');
    }

    /**
     * `config()` devolve `mixed`, e converter `mixed` esconderia o dia em que a
     * chave sumir do arquivo de configuração. Diretório vazio cai na checagem de
     * `is_dir` acima, que já diz o que está errado com nome e tudo.
     */
    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private static function numero(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    private function campanhasQueUsam(int $id): int
    {
        return DB::table('campanha')
            ->where('audio_mensagem_id', $id)
            ->orWhere('audio_confirma_opt_in_id', $id)
            ->orWhere('audio_confirma_opt_out_id', $id)
            ->orWhere('audio_nao_entendi_id', $id)
            ->count();
    }

    /** @return list<array<string,int|string|bool|null>> */
    private function lista(): array
    {
        $saida = [];

        $situacao = new SituacaoDoArquivo();

        foreach (DB::select(
            'SELECT a.id, a.nome, a.arquivo, a.duracao_seg, a.ativo,
                    (SELECT COUNT(*) FROM campanha c
                      WHERE c.audio_mensagem_id = a.id
                         OR c.audio_confirma_opt_in_id = a.id
                         OR c.audio_confirma_opt_out_id = a.id
                         OR c.audio_nao_entendi_id = a.id) AS em_uso
               FROM audio a
              ORDER BY a.ativo DESC, a.nome'
        ) as $linha) {
            $a = (array) $linha;
            $arquivo = is_string($a['arquivo'] ?? null) ? $a['arquivo'] : '';
            $caminho = self::texto(config('prospecta.audio_path')) . '/' . $arquivo;

            $saida[] = [
                'id' => is_numeric($a['id'] ?? null) ? (int) $a['id'] : 0,
                'nome' => is_string($a['nome'] ?? null) ? $a['nome'] : '',
                'arquivo' => $arquivo,
                'duracao' => is_numeric($a['duracao_seg'] ?? null) ? (int) $a['duracao_seg'] : 0,
                'ativo' => (bool) ($a['ativo'] ?? false),
                'em_uso' => is_numeric($a['em_uso'] ?? null) ? (int) $a['em_uso'] : 0,
                // 🔴 O arquivo existe MESMO? A linha no banco não é prova. Sem esta
                // coluna, um áudio removido do disco por fora apareceria como
                // disponível e a Campanha sairia muda.
                //
                // TRÊS estados, não dois: `is_file() === false` também responde `false`
                // quando o painel não atravessa o diretório — e os áudios são
                // `root:asterisk` modo 0640. Dizer "arquivo ausente" em vermelho por
                // cegueira acusaria falha onde há configuração, em TODA linha de uma vez,
                // e ensinaria o operador a ignorar a linha vermelha que um dia estará
                // certa. A regra vive em `SituacaoDoArquivo`, num lugar só.
                'situacao' => $situacao->de($arquivo === '' ? '' : $caminho),
            ];
        }

        return $saida;
    }
}
