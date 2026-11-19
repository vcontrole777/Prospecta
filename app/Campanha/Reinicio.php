<?php

declare(strict_types=1);

namespace App\Campanha;

use App\Dominio\CaminhoDeGravacao;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reinicia as Campanhas que o painel marcou com `reiniciar_pedido_em`: devolve os números à
 * fila, APAGA o histórico e as gravações, e põe no ar.
 *
 * 🔴 FORA DO CLIQUE, EM LOTES. O reinício rodava inteiro dentro da requisição do painel.
 * Medido nesta base em 19/11/2026: a maior Campanha tinha 340 mil Ligações, 120 mil
 * gravações e 444 mil Leads. Isso não cabe nos 30 s do php-fpm. O PHP morreria no meio do
 * laço de gravações, com parte dos arquivos apagada e NENHUMA linha mexida, e o operador
 * veria um erro 500 sem saber o que aconteceu. Quem roda isto é
 * `php artisan prospecta:campanhas-reiniciar`, a cada minuto, pelo systemd.
 *
 * 🔴 SÓ OPT-IN E OPT-OUT FICAM DE FORA, e essa é a regra inteira.
 *
 * `opt_out` é obrigatório: a pessoa pediu para não ser contatada. O dialer já a barraria
 * pela Lista de bloqueio (`Dialer\Fila` consulta na discagem), mas repor o Lead só para ele
 * ser descartado adiante esconde a intenção no código e infla a fila com trabalho que não
 * existe.
 *
 * `opt_in` fica de fora porque já converteu: ligar de novo para quem disse sim é gastar
 * canal com quem já está do outro lado do funil — e, para o Lead, é a empresa que não
 * percebeu que ele já respondeu.
 *
 * `captura_concluida` e `captura_parcial` VOLTAM: reiniciar é "rodar a Campanha de novo", e
 * nessa leitura recadastramento é para todo mundo menos quem converteu ou recusou.
 *
 * A fonte da verdade sobre Opt-in é `leads.opt_in_em`, NÃO `ligacao.desfecho`: o histórico
 * é apagado logo abaixo, e ler a prova de uma tabela que este mesmo método esvazia faria o
 * segundo reinício rediscar quem o primeiro poupou. Ver a migration `leads_guarda_opt_in`.
 *
 * `leads.status`: 1 disponível · 2 reivindicado (em voo) · 3 terminal · 4 aguardando a
 * próxima rodada. Reiniciar leva 1, 3 e 4 para disponível com o `try` zerado. O 1 entra de
 * propósito: um Lead disponível pode estar no MEIO do ciclo, com `try` já em 2 de 3.
 *
 * 🔴 NÃO TOCA EM STATUS 2, nem no Lead nem na Ligação dele. Aquele Lead está com uma
 * Ligação EM CURSO — o fastagi ainda vai gravar o desfecho. Devolvê-lo faria o mesmo número
 * ser discado enquanto a pessoa está na linha.
 *
 * 🔴 PÕE NO AR PELAS GUARDAS de `Ativacao`, não por atalho. Recusa vira `reiniciar_aviso`
 * na tela, com a fila já reposta e a Campanha pausada.
 *
 * 🔴 SEM TRANSAÇÃO ÚNICA, e a ordem converge. Cada lote é atômico; morrer no meio deixa a
 * marca no lugar, e a próxima volta retoma de onde parou — refazer um lote já feito não
 * muda nada. A marca só sai depois que tudo foi apagado e reposto.
 */
final class Reinicio
{
    private const LOTE_LIGACOES = 1000;

    private const LOTE_LEADS = 5000;

    /**
     * Uma volta sobre todas as Campanhas marcadas.
     *
     * @return array{reiniciadas:int, com_erro:int}
     */
    public function executar(): array
    {
        $resumo = ['reiniciadas' => 0, 'com_erro' => 0];

        $ids = DB::table('campanha')
            ->whereNotNull('reiniciar_pedido_em')
            ->whereNull('apagar_pedido_em')
            ->where('status', '<>', 1)
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            $ok = $this->reiniciar(is_numeric($id) ? (int) $id : 0);
            $resumo[$ok ? 'reiniciadas' : 'com_erro']++;
        }

        return $resumo;
    }

    public function reiniciar(int $id): bool
    {
        // Carimba o Opt-in no Lead ANTES de apagar a Ligação que o prova. Idempotente.
        DB::update(<<<'SQL'
            UPDATE leads le
              JOIN ligacao l ON l.lead_id = le.id
               SET le.opt_in_em = COALESCE(le.opt_in_em, l.encerrada_em, l.iniciada_em)
             WHERE le.campanha_id = ? AND l.desfecho = 'opt_in'
        SQL, [$id]);

        $base = $this->baseDeGravacao();
        $recusa = $this->recusaDoDiretorio($id, $base);

        // Diretório cego: NADA foi tocado, e a marca sai — o operador conserta a permissão
        // e clica de novo. Manter a marca deixaria a Campanha "reiniciando" para sempre,
        // tentando a cada minuto, sem botão para desistir.
        if ($recusa !== null) {
            $this->concluir($id, 'Não reiniciei: ' . $recusa);
            Log::warning('reinício recusado', ['campanha' => $id, 'motivo' => $recusa]);

            return false;
        }

        [$ligacoes, $gravacoes, $disparos, $retidas] = $this->apagarHistorico($id, $base);
        $repostos = $this->reporLeads($id);

        // `rodada_atual` e `iniciada_em` são histórico: deixá-los seria a tela dizer
        // "rodada 4, começou em março" ao lado de zero ligações. Concluída volta a PAUSADA,
        // não direto ao ar: quem põe no ar é a `Ativacao`, com as guardas.
        DB::table('campanha')->where('id', $id)->update(['rodada_atual' => 0, 'iniciada_em' => null]);
        DB::table('campanha')->where('id', $id)->where('status', 3)->update(['status' => 2]);

        $resumo = sprintf(
            'Reiniciada em %s: %d número(s) de volta na fila, %d ligação(ões) e %d gravação(ões) apagadas.',
            now()->format('d/m H:i'),
            $repostos,
            $ligacoes,
            $gravacoes,
        );

        if ($disparos > 0) {
            $resumo .= sprintf(
                ' %d disparo(s) de WhatsApp ainda pendentes foram descartados junto com as Ligações.',
                $disparos,
            );
        }

        if ($retidas > 0) {
            $resumo .= sprintf(
                ' %d ligação(ões) foram MANTIDAS porque não consegui apagar a gravação delas do'
                . ' disco — apagar a linha deixaria o arquivo lá para sempre, fora do alcance do'
                . ' expurgo.',
                $retidas,
            );
        }

        // A marca sai ANTES de ativar: a `Ativacao` recusa Campanha marcada, de propósito.
        $this->concluir($id, $resumo);

        $ativacao = new Ativacao();
        $faltas = $ativacao->faltas($id) ?? [];

        // `ativar` falso com a Campanha no ar é o operador que clicou "iniciar" no instante
        // entre a marca sair e esta linha: o resultado é o mesmo, e o recado tem que dizer.
        if ($faltas === [] && ($ativacao->ativar($id) || $this->noAr($id))) {
            DB::table('campanha')->where('id', $id)->update(['reiniciar_aviso' => $resumo . ' Campanha no ar.']);
        } else {
            $porque = $faltas === [] ? 'o estado mudou enquanto eu reiniciava' : implode('; ', $faltas);
            DB::table('campanha')->where('id', $id)->update([
                'reiniciar_aviso' => $resumo . ' Não pus no ar: ' . $porque . '.',
            ]);
        }

        Log::info('Campanha reiniciada', [
            'campanha' => $id, 'repostos' => $repostos, 'ligacoes' => $ligacoes,
            'gravacoes' => $gravacoes, 'retidas' => $retidas,
        ]);

        return true;
    }

    /**
     * Apaga Ligações, Disparos e gravações, em lotes, com cursor por id.
     *
     * Cursor e não "os próximos mil": a Ligação retida continua na tabela, e sem cursor o
     * mesmo lote voltaria para sempre.
     *
     * 🔴 O ARQUIVO PRIMEIRO, A LINHA DEPOIS — a ordem do `bin/expurgo.php`. Nesta ordem,
     * morrer no meio deixa linha apontando para arquivo ausente, que a próxima volta limpa.
     * Na inversa deixa arquivo sem linha: vazamento permanente, invisível.
     *
     * 🔴 O Disparo ANTES da Ligação: `FilaDeAcoes` reivindica com `JOIN ligacao`, e o
     * Disparo órfão ficaria `pendente` para sempre, invisível.
     *
     * @return array{0:int, 1:int, 2:int, 3:int} [ligações, gravações, disparos pendentes, retidas]
     */
    private function apagarHistorico(int $id, string $base): array
    {
        $caminho = new CaminhoDeGravacao();
        $ligacoes = 0;
        $gravacoes = 0;
        $disparos = 0;
        $retidas = 0;
        $ultimo = 0;

        do {
            $lote = $this->ligacoesAApagar($id)
                ->where('id', '>', $ultimo)
                ->orderBy('id')
                ->limit(self::LOTE_LIGACOES)
                ->get(['id', 'gravacao']);

            $apagar = [];
            foreach ($lote as $linha) {
                $l = (array) $linha;
                $ligacao = is_numeric($l['id'] ?? null) ? (int) $l['id'] : 0;
                $arquivo = is_string($l['gravacao'] ?? null) ? $l['gravacao'] : '';
                $ultimo = max($ultimo, $ligacao);

                if ($arquivo !== '') {
                    // Contenção antes de apagar (ADR-0003). Hoje o caminho é derivado de um
                    // inteiro pelo nosso código; a checagem existe para que continue assim.
                    if (! $caminho->contido($base, $arquivo)) {
                        $retidas++;
                        Log::warning('gravação fora do diretório base; não apago e mantenho a Ligação', [
                            'ligacao' => $ligacao, 'caminho' => $arquivo,
                        ]);

                        continue;
                    }

                    // Ausente é caso normal, não anomalia: o expurgo já passou por aqui.
                    if (is_file($arquivo)) {
                        if (! unlink($arquivo)) {
                            $retidas++;
                            Log::warning('não consegui apagar a gravação; mantenho a Ligação', [
                                'ligacao' => $ligacao, 'caminho' => $arquivo,
                            ]);

                            continue;
                        }
                        $gravacoes++;
                    }
                }

                $apagar[] = $ligacao;
            }

            if ($apagar !== []) {
                $disparos += DB::table('acao')
                    ->where('tipo', 'disparo')
                    ->where('status', 'pendente')
                    ->whereIn('referencia_id', $apagar)
                    ->count();

                DB::table('acao')->where('tipo', 'disparo')->whereIn('referencia_id', $apagar)->delete();
                $ligacoes += DB::table('ligacao')->whereIn('id', $apagar)->delete();
            }
        } while ($lote->count() === self::LOTE_LIGACOES);

        return [$ligacoes, $gravacoes, $disparos, $retidas];
    }

    /**
     * Devolve os Leads à fila, em lotes por id.
     *
     * Sem cursor, um `UPDATE ... LIMIT` reencontraria a cada lote os já repostos — que
     * continuam com status 1 —, e a volta ficaria quadrática numa lista de 444 mil.
     */
    private function reporLeads(int $id): int
    {
        $repostos = 0;
        $ultimo = 0;

        do {
            $ids = DB::table('leads')
                ->where('campanha_id', $id)
                ->where('id', '>', $ultimo)
                ->orderBy('id')
                ->limit(self::LOTE_LEADS)
                ->pluck('id')
                ->map(fn ($v): int => is_numeric($v) ? (int) $v : 0)
                ->all();

            if ($ids === []) {
                break;
            }

            $ultimo = max($ids);

            $repostos += DB::table('leads')
                ->whereIn('id', $ids)
                ->whereIn('status', [1, 3, 4])
                ->whereNull('opt_in_em')
                ->whereNotExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('bloqueio as b')
                        ->whereColumn('b.telefone', 'leads.telefone');
                })
                ->update(['status' => 1, 'try' => 0]);
        } while (count($ids) === self::LOTE_LEADS);

        return $repostos;
    }

    /**
     * As Ligações que o reinício apaga: tudo da Campanha MENOS as de Lead em voo.
     *
     * `whereNotExists` e não `whereNotIn('lead_id', ...)`: Ligação com `lead_id` NULL
     * existe (a FK é `nullOnDelete`), e `NULL NOT IN (...)` é NULL, que o SQL trata como
     * falso — ela escaparia do apagamento sem ninguém notar.
     */
    private function ligacoesAApagar(int $id): Builder
    {
        return DB::table('ligacao')
            ->where('campanha_id', $id)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('leads as le')
                    ->whereColumn('le.id', 'ligacao.lead_id')
                    ->where('le.status', 2);
            });
    }

    /**
     * 🔴 RECUSA SE NÃO ATRAVESSAR O DIRETÓRIO. Sem permissão, `is_file()` responde `false`
     * para TODO arquivo, cada gravação passa por "já ausente" e as linhas somem com os
     * arquivos ainda no disco, para sempre. A condição é GLOBAL, então recuso a volta
     * inteira. Diretório INEXISTENTE não é anomalia: é instância sem gravação ligada.
     */
    private function recusaDoDiretorio(int $id, string $base): ?string
    {
        if (! $this->ligacoesAApagar($id)->whereNotNull('gravacao')->exists()) {
            return null;
        }

        if ($base === '') {
            return 'PROSPECTA_GRAVACAO_DIR não está configurado e esta Campanha tem gravações — '
                . 'eu apagaria as linhas e deixaria os arquivos no disco.';
        }

        if (is_dir($base) && (! is_readable($base) || ! is_executable($base))) {
            return sprintf(
                'sem acesso a %s, e esta Campanha tem gravações. Eu apagaria as linhas sem '
                . 'conseguir apagar os arquivos, e eles ficariam no disco para sempre. O usuário '
                . 'do serviço precisa estar no grupo do diretório.',
                $base,
            );
        }

        return null;
    }

    private function concluir(int $id, string $aviso): void
    {
        DB::table('campanha')->where('id', $id)->update([
            'reiniciar_pedido_em' => null,
            'reiniciar_aviso' => $aviso,
        ]);
    }

    private function noAr(int $id): bool
    {
        return DB::table('campanha')->where('id', $id)->where('status', 1)->exists();
    }

    private function baseDeGravacao(): string
    {
        $configurado = config('fastagi.gravacao_dir');

        return is_string($configurado) ? rtrim($configurado, '/') : '';
    }
}
