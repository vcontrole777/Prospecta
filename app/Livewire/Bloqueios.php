<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\Telefone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista de bloqueio: quem não deve ser contatado.
 *
 * 🔴 A TELA EXISTE PORQUE FALTAVA O CANAL MANUAL.
 *
 * `bloqueio.origem` sempre teve dois valores no enum, `opt_out` e `manual`, e nada
 * escrevia `manual`. Os dois escritores automáticos são a tecla 9 na URA e o "SAIR" no
 * WhatsApp; o caso que sobrava é o mais comum de todos — a pessoa liga de volta, ou fala
 * com alguém, e pede para não ser mais chamada. Não havia onde registrar, então o pedido
 * dependia de alguém lembrar.
 *
 * 🔴 E O CANAL DE DESFAZER, que é o outro lado da mesma moeda: número entrava na lista e
 * não saía nunca. Um dígito errado numa colagem tirava da Campanha um Lead que nunca
 * pediu nada, permanentemente e sem sintoma.
 *
 * A lista é GLOBAL, não por Campanha: quem pediu para não ser contatado pediu ao
 * negócio. Aplicar por Campanha faria a próxima voltar a ligar.
 */
final class Bloqueios extends Component
{
    use WithPagination;

    /** Cola de números, um por linha ou separados por vírgula. */
    public string $entrada = '';

    #[Url(as: 'q', except: '')]
    public string $busca = '';

    #[Url(as: 'o', except: '')]
    public string $origem = '';

    public int $adicionados = 0;

    public int $repetidos = 0;

    /** @var list<string> */
    public array $recusados = [];

    /** @var array<string,string> */
    public const ORIGENS = [
        'opt_out' => 'Pediu na chamada ou no WhatsApp',
        'manual' => 'Registrado à mão',
    ];

    public function render(): View
    {
        return view('livewire.bloqueios', [
            'bloqueios' => $this->pagina(),
            'porOrigem' => $this->porOrigem(),
            'total' => DB::table('bloqueio')->count(),
        ]);
    }

    public function updatedBusca(): void
    {
        $this->resetPage();
    }

    public function updatedOrigem(): void
    {
        $this->resetPage();
    }

    public function adicionar(): void
    {
        $this->validate(
            ['entrada' => ['required', 'string', 'max:100000']],
            ['entrada.required' => 'Cole ao menos um número.'],
        );

        $t = new Telefone();

        $this->adicionados = 0;
        $this->repetidos = 0;
        $this->recusados = [];

        // Um por linha, por vírgula ou por ponto e vírgula — é o que sai de planilha, de
        // bloco de notas e de mensagem colada. Mesma tolerância da lista de BINA.
        $pedacos = preg_split('/[\r\n,;]+/', $this->entrada) ?: [];

        $novos = [];
        foreach ($pedacos as $bruto) {
            $bruto = trim($bruto);
            if ($bruto === '') {
                continue;
            }

            // 🔴 MESMA normalização da importação de lista e do dialer.
            //
            // O bloqueio é conferido por igualdade exata de texto: `SELECT 1 FROM
            // bloqueio WHERE telefone = :t`. Guardar `(11) 98765-4321` aqui enquanto o
            // Lead está gravado como `5511987654321` faria o bloqueio existir na tela e
            // não valer na discagem — o pior resultado possível para esta tela, porque o
            // operador teria confirmação visual de algo que não acontece.
            $canonico = $t->tentar($bruto);

            if ($canonico === null) {
                if (count($this->recusados) < 20) {
                    $this->recusados[] = $bruto;
                }
                continue;
            }

            $novos[$canonico] = true;   // dedup dentro da própria colagem
        }

        foreach (array_keys($novos) as $numero) {
            // `telefone` é a chave primária. `insertOrIgnore` faz o repetido ser no-op
            // sem engolir outro erro — e repetido aqui é comum: o operador cola de novo
            // uma lista que já tem gente dentro.
            $inseriu = DB::table('bloqueio')->insertOrIgnore([
                'telefone' => $numero,
                'origem' => 'manual',
            ]);

            if ($inseriu > 0) {
                $this->adicionados++;
            } else {
                $this->repetidos++;
            }
        }

        $this->entrada = '';
    }

    /**
     * Tira da lista.
     *
     * NÃO devolve o Lead à Fila, e é decisão: o dialer aposentou aquele Lead com
     * `encerrarSemDiscar` quando encontrou o bloqueio, e ressuscitá-lo daqui duplicaria
     * a regra de retentativa num segundo lugar. Quem quer discar de novo para um número
     * desbloqueado o importa na próxima lista, que é explícito.
     */
    public function remover(string $telefone): void
    {
        DB::table('bloqueio')->where('telefone', $telefone)->delete();

        session()->flash('recado', $telefone . ' saiu da lista de bloqueio.');
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    private function pagina(): LengthAwarePaginator
    {
        $q = DB::table('bloqueio as b')
            // LEFT JOIN: bloqueio de origem `manual` não tem Ligação, e bloqueio de
            // `opt_out` no WhatsApp também não — só a tecla 9 na URA grava `ligacao_id`.
            // Com JOIN simples a tela mostraria apenas os da URA.
            ->leftJoin('ligacao as g', 'g.id', '=', 'b.ligacao_id')
            ->leftJoin('leads as l', 'l.id', '=', 'g.lead_id')
            ->leftJoin('campanha as c', 'c.id', '=', 'g.campanha_id')
            ->select([
                'b.telefone', 'b.origem', 'b.criado_em', 'b.ligacao_id',
                'c.nome as campanha', 'l.id as lead_id',
            ]);

        if ($this->busca !== '') {
            // Só dígitos: o operador digita `(11) 98765` e o banco guarda
            // `5511987654321`. Buscar o texto cru não acharia nada, e a tela diria que o
            // número não está bloqueado quando está — resposta errada para a única
            // pergunta que esta busca existe para responder.
            $digitos = preg_replace('/\D+/', '', $this->busca) ?? '';
            if ($digitos !== '') {
                $q->where('b.telefone', 'like', '%' . $digitos . '%');
            }
        }

        if ($this->origem !== '' && array_key_exists($this->origem, self::ORIGENS)) {
            $q->where('b.origem', $this->origem);
        }

        /** @var LengthAwarePaginator<int, object> */
        return $q->orderByDesc('b.criado_em')->orderBy('b.telefone')->paginate(50);
    }

    /** @return array<string,int> */
    private function porOrigem(): array
    {
        $saida = [];

        foreach (DB::table('bloqueio')
            ->select('origem', DB::raw('COUNT(*) as quantos'))
            ->groupBy('origem')
            ->get() as $linha) {
            $l = (array) $linha;
            $chave = $l['origem'] ?? null;
            if (is_string($chave)) {
                $saida[$chave] = is_numeric($l['quantos'] ?? null) ? (int) $l['quantos'] : 0;
            }
        }

        return $saida;
    }
}
