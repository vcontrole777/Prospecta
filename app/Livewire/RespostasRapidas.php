<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\Placeholder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Cadastro das mensagens prontas que aparecem na caixa de entrada.
 *
 * 🔴 O TEXTO ACEITA PLACEHOLDER, e é a MESMA classe que o `fastagi` usa para montar
 * a Mensagem da chamada — `App\Dominio\Placeholder`. Duas implementações da mesma
 * substituição divergiriam, que é exatamente o contra-exemplo registrado no docblock
 * dela.
 *
 * Aqui só se guarda o texto. Quem resolve o Placeholder é a tela de Conversas, no
 * instante do uso, porque só lá existe um Lead para dar valor às colunas.
 */
final class RespostasRapidas extends Component
{
    public string $titulo = '';

    public string $texto = '';

    /** Edição no lugar: nulo é "estou criando", id é "estou editando esta". */
    public ?int $editando = null;

    public function render(): View
    {
        return view('livewire.respostas-rapidas', ['respostas' => $this->lista()]);
    }

    public function salvar(): void
    {
        $this->validate([
            'titulo' => ['required', 'string', 'max:60'],
            // 4000 é o mesmo teto de `Conversas::responder`. Guardar mais do que dá
            // para enviar seria oferecer ao operador o que não sai.
            'texto' => ['required', 'string', 'max:4000'],
        ], [
            'titulo.required' => 'Dê um nome curto — é o que vai escrito no botão.',
            'titulo.max' => 'Título muito longo para caber no botão: use até 60 caracteres.',
            'texto.required' => 'Escreva a mensagem.',
            'texto.max' => 'O WhatsApp corta texto acima de 4096 caracteres.',
        ]);

        if ($this->editando !== null) {
            DB::table('resposta_rapida')->where('id', $this->editando)->update([
                'titulo' => trim($this->titulo),
                'texto' => trim($this->texto),
            ]);
        } else {
            DB::table('resposta_rapida')->insert([
                'titulo' => trim($this->titulo),
                'texto' => trim($this->texto),
                // Nasce no fim da lista; reordenar é ato separado. `max()` devolve
                // `mixed` e é NULO na tabela vazia — conferir é o que faz a primeira
                // resposta nascer em 1 em vez de num cast silencioso de nulo.
                'ordem' => $this->proximaOrdem(),
                'ativo' => 1,
                'criado_em' => now(),
            ]);
        }

        $this->reset('titulo', 'texto', 'editando');
        session()->flash('recado', 'Resposta rápida salva.');
    }

    private function proximaOrdem(): int
    {
        $maior = DB::table('resposta_rapida')->max('ordem');

        return (is_numeric($maior) ? (int) $maior : 0) + 1;
    }

    public function editar(int $id): void
    {
        $linha = DB::table('resposta_rapida')->where('id', $id)->first();
        if ($linha === null) {
            return;
        }

        $this->editando = $id;
        $this->titulo = (string) $linha->titulo;
        $this->texto = (string) $linha->texto;
        $this->resetErrorBag();
    }

    public function cancelar(): void
    {
        $this->reset('titulo', 'texto', 'editando');
        $this->resetErrorBag();
    }

    /** Liga e desliga sem apagar: resposta sazonal volta, e redigitar é desperdício. */
    public function alternar(int $id): void
    {
        $atual = DB::table('resposta_rapida')->where('id', $id)->value('ativo');
        DB::table('resposta_rapida')->where('id', $id)->update(['ativo' => $atual ? 0 : 1]);
    }

    public function apagar(int $id): void
    {
        DB::table('resposta_rapida')->where('id', $id)->delete();

        if ($this->editando === $id) {
            $this->reset('titulo', 'texto', 'editando');
        }

        session()->flash('recado', 'Resposta rápida apagada.');
    }

    /**
     * Sobe uma posição. Troca a `ordem` com a vizinha de cima.
     *
     * Reordenar por arrastar exigiria JavaScript próprio; trocar com a vizinha resolve
     * o caso real — pôr a mais usada no começo — com um clique e sem código de tela.
     */
    public function subir(int $id): void
    {
        $atual = DB::table('resposta_rapida')->where('id', $id)->first();
        if ($atual === null) {
            return;
        }

        $acima = DB::table('resposta_rapida')
            ->where('ordem', '<', $atual->ordem)
            ->orderByDesc('ordem')
            ->first();

        if ($acima === null) {
            return;
        }

        DB::table('resposta_rapida')->where('id', $atual->id)->update(['ordem' => $acima->ordem]);
        DB::table('resposta_rapida')->where('id', $acima->id)->update(['ordem' => $atual->ordem]);
    }

    /**
     * As colunas que cada texto cita, para a tela avisar quais ele vai exigir.
     *
     * 🔴 AVISO, NÃO VALIDAÇÃO. Não dá para conferir aqui se a coluna existe: as
     * colunas vêm de `leads.dados_extras`, que é por Campanha e por importação, e uma
     * resposta rápida serve todas. Recusar `{Nome}` porque a Campanha da vez não o tem
     * proibiria o caso legítimo; avisar deixa a decisão com quem sabe.
     *
     * @return list<array<string,mixed>>
     */
    private function lista(): array
    {
        $placeholder = new Placeholder();
        $saida = [];

        foreach (DB::table('resposta_rapida')->orderBy('ordem')->orderBy('id')->get() as $r) {
            $saida[] = [
                'id' => (int) $r->id,
                'titulo' => (string) $r->titulo,
                'texto' => (string) $r->texto,
                'ativo' => (bool) $r->ativo,
                'colunas' => $placeholder->colunasCitadas((string) $r->texto),
            ];
        }

        return $saida;
    }
}
