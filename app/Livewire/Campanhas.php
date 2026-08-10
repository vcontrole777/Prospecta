<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Home do painel: uma linha por Campanha, com contadores ao vivo.
 *
 * 🔴 AGREGADO, não linha-a-linha. O ADR-0008 marcou a tela de chamadas ao vivo
 * como o ponto duro do produto: `wire:poll` de 1 s sobre 200 Ligações ativas
 * re-renderiza 200 linhas por segundo. Esta tela não resolve esse problema — ela
 * NÃO O TEM. O que o operador acompanha de minuto a minuto são ~8 números por
 * Campanha, e isso é uma consulta agregada barata, independente do número de
 * canais contratados.
 *
 * A tabela de Ligações ao vivo é outra tela, e é lá que o problema mora.
 */
final class Campanhas extends Component
{
    public function render(): View
    {
        return view('livewire.campanhas', ['campanhas' => $this->resumo()]);
    }

    /**
     * @return list<array<string,int|string|null>>
     */
    private function resumo(): array
    {
        // 🔴 DUAS consultas de propósito, não uma com dois LEFT JOIN.
        //
        // Juntar `leads` E `ligacao` no mesmo GROUP BY faz produto cartesiano —
        // cada Lead multiplicado por cada Ligação da mesma Campanha — e TODA
        // contagem sai inflada pelo fator do outro lado. O sintoma é a pior
        // espécie: uma tela plausível, com números errados por um múltiplo que
        // muda conforme a Campanha avança. Ninguém desconfia de um total que
        // apenas parece alto.
        $porCampanha = DB::select(
            'SELECT c.id, c.nome, c.tipo, c.status, c.bina_regra, c.rodada_atual,
                    c.max_tentativas, c.chamadas_simultaneas,
                    COUNT(l.id)                    AS leads,
                    COALESCE(SUM(l.status = 1), 0) AS disponiveis,
                    COALESCE(SUM(l.status = 2), 0) AS em_voo,
                    COALESCE(SUM(l.status = 4), 0) AS aguardando,
                    COALESCE(SUM(l.status = 3), 0) AS finalizados
               FROM campanha c
               LEFT JOIN leads l ON l.campanha_id = c.id
              GROUP BY c.id
              ORDER BY c.status = 1 DESC, c.id DESC'
        );

        $ligacoes = [];
        foreach (DB::select(
            "SELECT campanha_id,
                    COUNT(*)                                  AS ligacoes,
                    COALESCE(SUM(atendida_em IS NOT NULL), 0) AS atendidas,
                    COALESCE(SUM(desfecho = 'opt_in'), 0)     AS opt_in,
                    COALESCE(SUM(desfecho = 'maquina'), 0)    AS maquina,
                    COALESCE(SUM(desfecho = 'erro'), 0)       AS erros
               FROM ligacao
              GROUP BY campanha_id"
        ) as $linha) {
            $l = (array) $linha;
            $ligacoes[self::inteiro($l['campanha_id'] ?? null)] = $l;
        }

        $saida = [];
        foreach ($porCampanha as $linha) {
            $c = (array) $linha;
            $id = self::inteiro($c['id'] ?? null);
            $g = $ligacoes[$id] ?? [];

            $discadas = self::inteiro($g['ligacoes'] ?? null);
            $atendidas = self::inteiro($g['atendidas'] ?? null);

            $saida[] = [
                'id' => $id,
                'nome' => self::texto($c['nome'] ?? null),
                'tipo' => self::texto($c['tipo'] ?? null),
                'status' => self::inteiro($c['status'] ?? null),
                'bina_regra' => self::texto($c['bina_regra'] ?? null),
                'rodada' => self::inteiro($c['rodada_atual'] ?? null),
                'max_tentativas' => self::inteiro($c['max_tentativas'] ?? null),
                'simultaneas' => self::inteiro($c['chamadas_simultaneas'] ?? null),
                'leads' => self::inteiro($c['leads'] ?? null),
                'disponiveis' => self::inteiro($c['disponiveis'] ?? null),
                'em_voo' => self::inteiro($c['em_voo'] ?? null),
                'aguardando' => self::inteiro($c['aguardando'] ?? null),
                'finalizados' => self::inteiro($c['finalizados'] ?? null),
                'discadas' => $discadas,
                'atendidas' => $atendidas,
                'opt_in' => self::inteiro($g['opt_in'] ?? null),
                'maquina' => self::inteiro($g['maquina'] ?? null),
                'erros' => self::inteiro($g['erros'] ?? null),
                // Taxa de atendimento em pontos percentuais inteiros. `null`
                // quando não houve discagem: 0% e "ainda não discou" são coisas
                // diferentes, e mostrar 0% numa Campanha recém-criada faria a
                // tela dizer que a lista é ruim antes de qualquer chamada.
                'taxa' => $discadas > 0 ? (int) round($atendidas / $discadas * 100) : null,
            ];
        }

        return $saida;
    }

    /**
     * `DB::select` devolve `array` de `stdClass` sem tipo por campo, e o driver
     * do MySQL entrega COUNT/SUM como string. Estreitar é honesto; `(int)` cru
     * sobre `mixed` esconderia o dia em que a consulta mudar de forma.
     */
    private static function inteiro(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
