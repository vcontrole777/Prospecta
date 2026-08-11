<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Audio\Alaw;
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
     * Põe a Campanha no ar. O dialer só olha `status = 1`.
     *
     * 🔴 RECUSA quando a Campanha não pode funcionar, e as três checagens são a
     * diferença entre "discou" e "discou e não fez nada":
     *
     * · sem Lead disponível — o dialer não acha nada, marca a Campanha como
     *   concluída na mesma volta, e o operador vê "concluída" um segundo depois de
     *   clicar em iniciar. Parece defeito, e a causa verdadeira não aparece.
     * · sem áudio de mensagem — liga para o Lead e não fala nada. Medido que
     *   `Playback` em arquivo ausente devolve FAILED e o dialplan CONTINUA.
     * · WhatsApp sem template — o Lead aperta 1, a Ação é gravada e o worker a
     *   recusa com `campanha_sem_template_ou_lead_sem_telefone`. O opt-in que o
     *   cliente pagou para conseguir morre depois de dar certo.
     */
    public function iniciar(int $id): void
    {
        $linha = DB::table('campanha')->where('id', $id)->first();
        if ($linha === null) {
            return;
        }
        $c = (array) $linha;

        $faltas = [];

        // Status 1 é disponível e 4 é aguardando a próxima rodada — as duas contam,
        // porque o dialer abre a rodada seguinte sozinho.
        $disponiveis = DB::table('leads')
            ->where('campanha_id', $id)
            ->whereIn('status', [1, 4])
            ->count();

        if ($disponiveis === 0) {
            $faltas[] = 'nenhum Lead disponível — importe a lista antes';
        }

        $ehCaptura = ($c['tipo'] ?? null) === 'captura';

        if ($ehCaptura) {
            // 🔴 Captura NÃO usa o áudio de mensagem — `Fluxo::captura` pergunta campo a
            // campo, com o prompt de cada um. Exigir a mensagem aqui recusaria uma
            // Campanha perfeitamente configurada.
            foreach ($this->faltasDeCaptura($id) as $falta) {
                $faltas[] = $falta;
            }
        } elseif (($c['audio_mensagem_id'] ?? null) === null) {
            $faltas[] = 'áudio da mensagem não escolhido — a chamada sairia muda';
        } else {
            // 🔴 A LINHA NO BANCO NÃO É PROVA de que o arquivo existe e serve.
            //
            // O audios/README.md exige verificação na ATIVAÇÃO, com causa nomeada, e
            // não só no cadastro: entre cadastrar e iniciar, o arquivo pode ter sido
            // removido do disco, restaurado de backup errado ou sobrescrito. O
            // sintoma seria `PLAYBACKSTATUS=FAILED` com o dialplan CONTINUANDO —
            // chamada muda, contada como atendida, sem erro visível.
            $falta = $this->conferirAudio(self::inteiro($c['audio_mensagem_id']));
            if ($falta !== null) {
                $faltas[] = $falta;
            }
        }

        // Só opt-in gera Ação de disparo. Captura não gera Ação nenhuma: o dado fica em
        // `ligacao.respostas`, e o caminho de leitura é a tela de Resultados.
        if (! $ehCaptura && ($c['canal_opt_in'] ?? null) === 'whatsapp' && ($c['template_nome'] ?? null) === null) {
            $faltas[] = 'nome do template ausente — o opt-in não viraria mensagem';
        }

        if ($faltas !== []) {
            session()->flash('recusa', 'Não iniciei: ' . implode('; ', $faltas) . '.');

            return;
        }

        // `COALESCE` preserva a primeira ativação: retomar uma Campanha pausada não
        // deveria reescrever quando ela começou.
        DB::table('campanha')
            ->where('id', $id)
            ->whereIn('status', [0, 2])
            ->update([
                'status' => 1,
                'iniciada_em' => DB::raw('COALESCE(iniciada_em, NOW())'),
            ]);

        session()->flash('recado', 'Campanha no ar.');
    }

    /**
     * Faltas de uma Campanha de captura.
     *
     * Sem campo, ela liga, não pergunta nada e encerra. Com campo sem prompt no disco, a
     * pessoa ouve SILÊNCIO até o timeout — e o `Fluxo` recusa a captura inteira com
     * `AUDIO_NAO_CONFIGURADO`, o que é melhor que coletar meio formulário, mas pior que
     * nunca ter discado.
     *
     * @return list<string>
     */
    private function faltasDeCaptura(int $campanhaId): array
    {
        $campos = DB::select(
            'SELECT cd.nome, cc.audio_prompt_id
               FROM campanha_campo cc
               JOIN campo_definicao cd ON cd.id = cc.campo_definicao_id
              WHERE cc.campanha_id = ?
              ORDER BY cc.ordem',
            [$campanhaId],
        );

        if ($campos === []) {
            return ['nenhum campo a coletar — a Campanha ligaria e não perguntaria nada'];
        }

        $faltas = [];
        foreach ($campos as $linha) {
            $cc = (array) $linha;
            $nome = self::texto($cc['nome'] ?? null);
            $audioId = $cc['audio_prompt_id'] ?? null;

            if (! is_numeric($audioId)) {
                $faltas[] = sprintf('o campo "%s" não tem áudio de pergunta', $nome);
                continue;
            }

            $falta = $this->conferirAudio((int) $audioId);
            if ($falta !== null) {
                $faltas[] = sprintf('no campo "%s": %s', $nome, $falta);
            }
        }

        return $faltas;
    }

    /**
     * Confere o áudio no disco. `null` quando está tudo certo; a falta descrita
     * quando não.
     *
     * As duas causas são as que o audios/README.md nomeia:
     *
     * · `AUDIO_MISSING` — a linha existe e o arquivo não
     * · `AUDIO_INCOMPATIBLE` — o arquivo existe e o tamanho não corresponde à
     *   duração gravada. A 8 kHz mono A-law são 8000 bytes por segundo, exatos, e
     *   num formato sem cabeçalho essa é a única verificação que existe. Pega
     *   arquivo truncado e arquivo trocado por outro em formato diferente.
     */
    private function conferirAudio(int $audioId): ?string
    {
        $linha = DB::table('audio')->where('id', $audioId)->first(['nome', 'arquivo', 'duracao_seg']);
        if ($linha === null) {
            return 'o áudio da mensagem não existe mais no cadastro (AUDIO_MISSING)';
        }

        $a = (array) $linha;
        $nome = self::texto($a['nome'] ?? null);
        $arquivo = self::texto($a['arquivo'] ?? null);

        $diretorio = self::texto(config('prospecta.audio_path'));
        $caminho = $diretorio . '/' . $arquivo;

        if ($arquivo === '' || ! is_file($caminho)) {
            return sprintf('o arquivo do áudio "%s" não está no disco (AUDIO_MISSING)', $nome);
        }

        $bytes = (int) filesize($caminho);
        $duracao = self::inteiro($a['duracao_seg'] ?? null);

        // Tolerância de 1 s: `duracao_seg` é inteiro arredondado para cima no
        // cadastro, então a diferença legítima é sempre menor que um segundo.
        if ($duracao > 0 && ! (new Alaw())->tamanhoConfere($bytes, (float) $duracao, 1.0 / $duracao)) {
            return sprintf(
                'o arquivo do áudio "%s" tem %d bytes, incompatível com %d s a 8 kHz mono (AUDIO_INCOMPATIBLE)',
                $nome,
                $bytes,
                $duracao,
            );
        }

        return null;
    }

    /**
     * Pausa. O `where` no status evita pausar Campanha já concluída, o que a
     * ressuscitaria como pausável e faria o dialer reavaliá-la sem motivo.
     */
    public function pausar(int $id): void
    {
        DB::table('campanha')->where('id', $id)->where('status', 1)->update(['status' => 2]);

        session()->flash('recado', 'Campanha pausada.');
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
