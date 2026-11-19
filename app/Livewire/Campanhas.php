<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Campanha\Ativacao;
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
     * Põe a Campanha no ar, se as guardas deixarem. As guardas moram em
     * `App\Campanha\Ativacao`, que diz por que cada uma existe.
     */
    public function iniciar(int $id): void
    {
        $ativacao = new Ativacao();
        $faltas = $ativacao->faltas($id);

        if ($faltas === null) {
            return;
        }

        if ($faltas !== []) {
            session()->flash('recusa', 'Não iniciei: ' . implode('; ', $faltas) . '.');

            return;
        }

        $ativacao->ativar($id);

        // O recado do último reinício é sobre um estado que acabou de mudar.
        DB::table('campanha')->where('id', $id)->update(['reiniciar_aviso' => null]);

        session()->flash('recado', 'Campanha no ar.');
    }

    /**
     * Pede para reiniciar a Campanha do zero: devolve os números à fila, APAGA o histórico
     * e as gravações, e põe no ar.
     *
     * 🔴 SÓ MARCA, como o `apagar`. Quem reinicia é `php artisan prospecta:campanhas-reiniciar`,
     * a cada minuto, em lotes — ver `App\Campanha\Reinicio`, que diz o que fica de fora e
     * por quê. Fazia tudo dentro do clique, e a maior Campanha desta base (340 mil Ligações,
     * 120 mil gravações, 444 mil Leads) não cabe nos 30 s de uma requisição: o PHP morria
     * no meio, com parte das gravações apagadas e nenhuma linha.
     *
     * 🔴 RECUSA COM A CAMPANHA NO AR: repor a fila enquanto o dialer trabalha faria a mesma
     * pessoa receber duas ligações da mesma rodada. A guarda está no próprio UPDATE.
     */
    public function reiniciar(int $id): void
    {
        $nome = DB::table('campanha')->where('id', $id)->value('nome');
        if ($nome === null) {
            return;
        }

        if (DB::table('campanha')->where('id', $id)->whereNotNull('apagar_pedido_em')->exists()) {
            session()->flash('recusa', 'Não reiniciei: esta Campanha está sendo apagada.');

            return;
        }

        $marcadas = DB::table('campanha')
            ->where('id', $id)
            ->where('status', '<>', 1)
            ->whereNull('apagar_pedido_em')
            ->update(['reiniciar_pedido_em' => now(), 'reiniciar_aviso' => null]);

        if ($marcadas === 0) {
            session()->flash('recusa', 'Não reiniciei: a Campanha está no ar. Pause antes.');

            return;
        }

        session()->flash('recado', sprintf(
            'Reiniciando a Campanha "%s". Começa em até um minuto, e Campanha grande pode levar '
            . 'alguns minutos; ela vai ao ar sozinha quando terminar.',
            self::texto($nome),
        ));
    }

    /**
     * Pausa. O `where` no status evita pausar Campanha já concluída, o que a
     * ressuscitaria como pausável e faria o dialer reavaliá-la sem motivo.
     */
    public function pausar(int $id): void
    {
        DB::table('campanha')->where('id', $id)->where('status', 1)->update(['status' => 2, 'reiniciar_aviso' => null]);

        session()->flash('recado', 'Campanha pausada.');
    }

    /**
     * Pede para apagar a Campanha inteira: ela, os Leads, o histórico e as gravações.
     *
     * 🔴 SÓ MARCA. Quem apaga é `bin/apagar-campanhas.php`, em lotes, a cada minuto — uma
     * Campanha grande não cabe numa requisição. Ver `App\Worker\ApagadorDeCampanha`.
     * Enquanto a marca existe, `iniciar` e `reiniciar` recusam, e a tela mostra
     * "apagando".
     *
     * 🔴 RECUSA COM A CAMPANHA NO AR, como o `reiniciar`: apagar a fila de baixo do dialer
     * enquanto ele disca é pedir Ligação sem Lead. Pausar antes é um clique.
     *
     * A Lista de bloqueio NÃO é tocada: Opt-out vale para toda Campanha, para sempre.
     */
    public function apagar(int $id): void
    {
        $nome = DB::table('campanha')->where('id', $id)->value('nome');
        if ($nome === null) {
            return;
        }

        // Condicional no próprio UPDATE, e não ler-depois-escrever: entre ler o status e
        // gravar a marca, outro clique poderia pôr a Campanha no ar.
        $marcadas = DB::table('campanha')
            ->where('id', $id)
            ->where('status', '<>', 1)
            ->whereNull('reiniciar_pedido_em')
            ->update(['apagar_pedido_em' => now(), 'apagar_erro' => null]);

        if ($marcadas === 0) {
            session()->flash('recusa', 'Não apaguei: a Campanha está no ar ou sendo reiniciada.');

            return;
        }

        session()->flash('recado', sprintf(
            'A Campanha "%s" será apagada em segundos. Campanha grande pode levar alguns minutos; '
            . 'enquanto isso ela aparece como "apagando".',
            self::texto($nome),
        ));
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
                    c.apagar_pedido_em IS NOT NULL AS apagando, c.apagar_erro,
                    c.reiniciar_pedido_em IS NOT NULL AS reiniciando, c.reiniciar_aviso,
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
                'apagando' => self::inteiro($c['apagando'] ?? null),
                'apagar_erro' => is_string($c['apagar_erro'] ?? null) ? $c['apagar_erro'] : null,
                'reiniciando' => self::inteiro($c['reiniciando'] ?? null),
                'reiniciar_aviso' => is_string($c['reiniciar_aviso'] ?? null) ? $c['reiniciar_aviso'] : null,
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
