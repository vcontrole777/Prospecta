<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Http\Controllers\Imagem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Galeria de imagens para o cabeçalho de template.
 *
 * 🔴 EXISTE PORQUE DOIS TEMPLATES APROVADOS ERAM INENVIÁVEIS. Template com
 * `HEADER format=IMAGE` exige a imagem em todo envio, e o produto não tinha onde
 * guardar uma. Ver a migration `galeria_de_imagens_e_cabecalho_de_template`.
 *
 * 🔴 GUARDA O ARQUIVO, NÃO O `media_id` DA META. O id que o upload da Meta devolve
 * expira e é preso ao número que subiu — uma galeria feita dele quebraria sozinha,
 * e quebraria no envio. Guardando o arquivo e servindo por URL, a mesma imagem
 * serve qualquer número, inclusive o de outra instância.
 */
final class Imagens extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $arquivo = null;

    public string $nome = '';

    /**
     * O que a Meta aceita como cabeçalho de imagem. Fora disto ela recusa o envio
     * com erro de mídia, que chegaria ao operador como "falhou" sem causa.
     *
     * @var array<string,string> extensão => mime
     */
    private const TIPOS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    public function render(): View
    {
        return view('livewire.imagens', ['imagens' => $this->lista()]);
    }

    public function salvar(): void
    {
        $this->validate([
            'nome' => ['required', 'string', 'max:255'],
            /*
             * `extensions` e não `mimes`, pelo mesmo motivo da tela de Áudios: o tipo
             * detectado varia com o sistema de quem envia. O teto de 5 MB é o da
             * própria Meta para imagem de template — arquivo maior é recusado por
             * ela, e recusar aqui dá a causa em vez de "falhou".
             */
            'arquivo' => ['required', 'file', 'max:5120', 'extensions:jpg,jpeg,png'],
        ], [
            'arquivo.extensions' => 'A Meta aceita JPG ou PNG no cabeçalho de template.',
            'arquivo.max' => 'O arquivo passa de 5 MB, que é o teto da Meta.',
            'nome.required' => 'Dê um nome para você reconhecer a imagem depois.',
        ]);

        $origem = $this->arquivo?->getRealPath();
        $extensao = strtolower((string) $this->arquivo?->getClientOriginalExtension());

        if ($origem === null || ! isset(self::TIPOS[$extensao])) {
            $this->addError('arquivo', 'O arquivo temporário expirou. Envie de novo.');

            return;
        }

        $diretorio = Imagem::diretorio();
        if (! is_dir($diretorio) && ! mkdir($diretorio, 0o750, true) && ! is_dir($diretorio)) {
            $this->addError('arquivo', "Não consigo criar {$diretorio}. É permissão de implantação.");

            return;
        }

        /*
         * 🔴 NOME DE ARQUIVO DERIVADO DO ID, e token separado para a URL.
         *
         * O id resolve o caminho em disco sem que o nome digitado pelo operador
         * chegue perto dele — fecha o contexto "caminho de arquivo" do ADR-0003 por
         * construção, igual à tela de Áudios. O token existe porque a rota que serve
         * é PÚBLICA: com o id na URL, quem visse uma imagem enumeraria a galeria.
         *
         * `random_bytes` e não `uniqid`/`mt_rand`: URL adivinhável é a única falha
         * possível numa rota sem login.
         */
        $token = bin2hex(random_bytes(16));

        $id = (int) DB::table('imagem')->insertGetId([
            'nome' => trim($this->nome),
            'token' => $token,
            'arquivo' => 'pendente',
            'mime' => self::TIPOS[$extensao],
            'bytes' => 0,
            'criado_em' => now(),
        ]);

        $base = $id . '.' . ($extensao === 'jpeg' ? 'jpg' : $extensao);
        $destino = $diretorio . '/' . $base;

        if (! copy($origem, $destino)) {
            // A linha já nasceu; sem o arquivo ela é lixo que apareceria na galeria
            // como imagem quebrada. Apagar aqui deixa o estado consistente.
            DB::table('imagem')->where('id', $id)->delete();
            $this->addError('arquivo', 'Não consegui gravar o arquivo. Tente de novo.');

            return;
        }

        DB::table('imagem')->where('id', $id)->update([
            'arquivo' => $base,
            'bytes' => (int) filesize($destino),
        ]);

        $this->reset('nome', 'arquivo');
        session()->flash('recado', 'Imagem salva.');
    }

    /**
     * Apaga a imagem e o arquivo.
     *
     * A Campanha que a usava fica com `imagem_id` nulo — `nullOnDelete` na migration
     * — e volta a ser cobrada pela tela. Melhor que impedir o apagamento: o operador
     * veria "não posso" sem saber quem usa.
     */
    public function apagar(int $id): void
    {
        $linha = DB::table('imagem')->where('id', $id)->first();
        if ($linha === null) {
            return;
        }

        $arquivo = is_string($linha->arquivo ?? null) ? basename($linha->arquivo) : '';
        $caminho = Imagem::diretorio() . '/' . $arquivo;
        if ($arquivo !== '' && is_file($caminho)) {
            @unlink($caminho);
        }

        $usada = DB::table('campanha')->where('imagem_id', $id)->count();
        DB::table('imagem')->where('id', $id)->delete();

        session()->flash('recado', $usada > 0
            ? "Imagem apagada. {$usada} Campanha(s) ficaram sem cabeçalho e precisam de outra."
            : 'Imagem apagada.');
    }

    /** @return list<array<string,mixed>> */
    private function lista(): array
    {
        $saida = [];
        foreach (DB::table('imagem')->orderByDesc('id')->get() as $i) {
            $saida[] = [
                'id' => (int) $i->id,
                'nome' => (string) $i->nome,
                'url' => route('imagem', ['token' => $i->token]),
                'kb' => (int) round(((int) $i->bytes) / 1024),
                'usada' => (int) DB::table('campanha')->where('imagem_id', $i->id)->count(),
            ];
        }

        return $saida;
    }
}
