<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\EmVoo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Chamadas no ar, agora.
 *
 * 🔴 É A TELA QUE O ADR-0008 CHAMOU DE PONTO DURO, e o custo dela vem inteiro de uma
 * frase: "`wire:poll` de 1 s sobre 200 Ligações ativas re-renderiza 200 linhas por
 * segundo". O ADR não a adiou por preguiça — decidiu que ela deixava de ser
 * pré-requisito e passava a ser escolha de quando pagar o custo. Este é o momento.
 *
 * 🔴 O QUE PAGA O CUSTO: separar o que muda A CADA SEGUNDO do que muda a cada minuto.
 *
 * Numa tabela ao vivo o campo que muda todo segundo é UM — a duração. O CONJUNTO de
 * linhas muda muito mais devagar: com 300 canais e ~30 s de chamada, entram e saem
 * ~10 linhas por segundo, e nenhuma das outras 290 mudou nada.
 *
 * Então a duração NÃO vem do servidor. O servidor manda o instante de início em
 * epoch, uma vez, e o relógio corre no navegador (ver a view). O `wire:poll` fica em
 * 3 s e cuida só do conjunto. O contador na tela continua andando de segundo em
 * segundo, e nenhum segundo custa uma requisição.
 *
 * Sobra a re-renderização do conjunto a cada 3 s. Com 300 linhas isso é da ordem de
 * dezenas de KB — grande, mas duas ordens de grandeza abaixo do que o ADR temia, e o
 * operador tem um botão para congelar a tela. Se um dia houver mil canais, o próximo
 * passo é o componente estreito que o ADR descreve; a linha de corte fica escrita
 * aqui para ninguém ter que redescobri-la.
 *
 * 🔴 A REGRA DO ADR QUE VALE ANTES DE TUDO: "antes de otimizar uma tela densa ao
 * vivo, verificar se a pergunta do operador não é agregada". Aqui ela é as DUAS
 * coisas, e por isso o resumo por Campanha vem primeiro e a tabela vem depois. Quem
 * abre esta tela quase sempre quer saber "estou usando o que contratei?", e essa
 * resposta são seis números que não dependem de linha nenhuma.
 */
final class AoVivo extends Component
{
    /**
     * Teto de linhas renderizadas.
     *
     * Acima do `PROSPECTA_CANAIS` de qualquer instância nossa hoje (300), então na
     * prática não corta. Existe para o dia em que houver linha órfã: Ligação sem
     * `encerrada_em` porque o `Hangup` se perdeu ficaria aberta para sempre, e sem
     * teto uma tela ao vivo viraria uma varredura da tabela inteira.
     */
    private const TETO_DE_LINHAS = 400;

    /** Congelar a tela para ler ou copiar uma linha sem ela fugir. */
    public bool $rodando = true;

    public function alternar(): void
    {
        $this->rodando = ! $this->rodando;
    }

    public function render(): View
    {
        $linhas = $this->emVoo();
        $total = $this->contar();
        $campanhas = $this->porCampanha();
        $contratado = $this->contratado();

        return view('livewire.ao-vivo', [
            'linhas' => $linhas,
            'total' => $total,
            // Sem corte silencioso: se a tabela mostra menos do que existe, a tela diz.
            'cortadas' => max(0, $total - count($linhas)),
            'campanhas' => $campanhas,
            'contratado' => $contratado,
            'ocupacao' => EmVoo::percentual($total, $contratado),
            'chamando' => count(array_filter($linhas, static fn (array $l): bool => $l['estado'] === EmVoo::CHAMANDO)),
            'naUra' => count(array_filter($linhas, static fn (array $l): bool => $l['estado'] === EmVoo::NA_URA)),
            // 🔴 O RELÓGIO DO SERVIDOR, mandado junto. O navegador calcula a duração
            // subtraindo do relógio DELE, e relógio de máquina de operador erra por
            // minutos com frequência — sem esta referência a tela mostraria durações
            // negativas ou infladas, e pareceria defeito do discador.
            'agora' => time(),
        ]);
    }

    /**
     * As Ligações abertas.
     *
     * 🔴 `encerrada_em IS NULL` é a definição de "no ar" em todo o produto — é a
     * mesma que `Fila::contarOcupadosNaInstancia()` usa para orçar canal. Tem que ser
     * a mesma: se esta tela contasse diferente do que o dialer orça, o operador veria
     * um número e o discador obedeceria a outro.
     *
     * @return list<array<string,mixed>>
     */
    private function emVoo(): array
    {
        $linhas = DB::select(
            'SELECT l.id, l.numero_tentativa, l.bina,
                    UNIX_TIMESTAMP(l.iniciada_em) AS inicio,
                    UNIX_TIMESTAMP(l.atendida_em) AS atendimento,
                    l.atendida_em,
                    le.telefone,
                    c.nome AS campanha
             FROM ligacao l
             LEFT JOIN leads    le ON le.id = l.lead_id
             LEFT JOIN campanha c  ON c.id  = l.campanha_id
             WHERE l.encerrada_em IS NULL
             /* Atendidas primeiro: são poucas, duram muito e são as únicas em que
                alguém está de fato ouvindo. Dentro de cada grupo, a mais VELHA no
                topo — no grupo que chama, a mais velha é a mais perto do timeout.
                A ordem é estável de propósito: linha que não mudou não muda de
                lugar, e tabela que reordena sozinha a cada 3 s não se consegue ler. */
             ORDER BY (l.atendida_em IS NULL), l.iniciada_em
             LIMIT ' . self::TETO_DE_LINHAS
        );

        $saida = [];
        foreach ($linhas as $linha) {
            $l = (array) $linha;
            $saida[] = [
                'id' => (int) $l['id'],
                'telefone' => is_string($l['telefone'] ?? null) ? $l['telefone'] : '—',
                'campanha' => is_string($l['campanha'] ?? null) ? $l['campanha'] : '—',
                'tentativa' => (int) ($l['numero_tentativa'] ?? 1),
                'bina' => is_string($l['bina'] ?? null) ? $l['bina'] : '—',
                'inicio' => (int) ($l['inicio'] ?? 0),
                'atendimento' => is_numeric($l['atendimento'] ?? null) ? (int) $l['atendimento'] : null,
                'estado' => EmVoo::estado(is_string($l['atendida_em'] ?? null) ? $l['atendida_em'] : null),
            ];
        }

        return $saida;
    }

    /** O total real, que pode ser maior que o renderizado. */
    private function contar(): int
    {
        return (int) DB::table('ligacao')->whereNull('encerrada_em')->count();
    }

    /**
     * Ocupação por Campanha.
     *
     * 🔴 INCLUI PAUSADA COM CHAMADA NO AR, e é o motivo de esta consulta existir
     * separada da tabela. Pausar não desliga o que já está no ar: as chamadas seguem
     * ocupando canal do tronco até desligarem. Quem pausa uma Campanha para "aliviar"
     * precisa VER que o alívio ainda não chegou — senão pausa a segunda, e a terceira.
     *
     * É a mesma verdade que o docblock de `Fila::contarOcupadosNaInstancia()` nomeia;
     * aqui ela vira coluna na tela.
     *
     * UM join só. Juntar `leads` aqui multiplicaria cada Ligação por cada Lead da
     * Campanha — a armadilha que o ADR-0008 registra na seção de agregados.
     *
     * @return list<array<string,mixed>>
     */
    private function porCampanha(): array
    {
        $linhas = DB::select(
            'SELECT c.id, c.nome, c.status, c.chamadas_simultaneas,
                    COUNT(l.id) AS em_voo
             FROM campanha c
             LEFT JOIN ligacao l ON l.campanha_id = c.id AND l.encerrada_em IS NULL
             GROUP BY c.id, c.nome, c.status, c.chamadas_simultaneas
             HAVING em_voo > 0 OR c.status = 1
             ORDER BY em_voo DESC, c.nome'
        );

        $saida = [];
        foreach ($linhas as $linha) {
            $l = (array) $linha;
            $emVoo = (int) ($l['em_voo'] ?? 0);
            $teto = (int) ($l['chamadas_simultaneas'] ?? 0);

            $saida[] = [
                'id' => (int) $l['id'],
                'nome' => is_string($l['nome'] ?? null) ? $l['nome'] : '—',
                'status' => (int) ($l['status'] ?? 0),
                'emVoo' => $emVoo,
                'teto' => $teto,
                'percentual' => EmVoo::percentual($emVoo, $teto),
            ];
        }

        return $saida;
    }

    /**
     * O teto da instância.
     *
     * Vem do `PROSPECTA_CANAIS`, a MESMA fonte que o `bin/dialer.php` lê para orçar —
     * ler de outro lugar seria a regra duplicada que este projeto já viu divergir.
     * Desde 26/08/2026 ele é teto da INSTÂNCIA, não por Campanha.
     */
    private function contratado(): int
    {
        $v = config('dialer.canais_contratados');

        return is_numeric($v) ? (int) $v : 0;
    }
}
