<?php

declare(strict_types=1);

namespace App\Worker;

use App\Dominio\CaminhoDeGravacao;
use PDO;

/**
 * Apaga as Campanhas que o painel marcou com `apagar_pedido_em`.
 *
 * 🔴 FORA DO CLIQUE, EM LOTES, e não por gosto. Medido nesta base em 19/11/2026: a maior
 * Campanha tinha 444 mil Leads, 340 mil Ligações e 120 mil gravações no disco. Isso não
 * cabe nos 30 s de uma requisição do painel, e um `DELETE` só, numa transação só, seguraria
 * trava e desfazimento por minutos enquanto outra Campanha disca no mesmo banco. O painel
 * só marca; quem apaga é `bin/apagar-campanhas.php`, a cada minuto, pelo systemd.
 *
 * 🔴 SEM TRANSAÇÃO, e a ORDEM é o que garante a convergência. Cada passo é um comando
 * atômico, e morrer em qualquer ponto deixa um estado que a próxima volta retoma:
 *
 *   1. o ARQUIVO da gravação, depois a linha — a ordem do `bin/expurgo.php`;
 *   2. o Disparo pendente ANTES da Ligação — `FilaDeAcoes` reivindica com `JOIN ligacao`,
 *      e o Disparo órfão ficaria `pendente` para sempre, invisível;
 *   3. as Ligações, depois os Leads;
 *   4. a Campanha POR ÚLTIMO. Enquanto ela existe, a marca existe e o trabalho é retomado.
 *      Apagá-la antes deixaria Ligações sem dono e sem ninguém que soubesse delas.
 *
 * 🔴 GRAVAÇÃO QUE NÃO SAI DO DISCO SEGURA A CAMPANHA. A linha da Ligação fica, a Campanha
 * fica, e `apagar_erro` diz por quê na tela. Apagar a linha deixaria o arquivo fora do
 * alcance de qualquer expurgo, que só conhece o que está no banco.
 *
 * O que NÃO se apaga: `bloqueio`. Opt-out é global e permanente, vale para toda Campanha,
 * e a FK dele para `ligacao` é `nullOnDelete` exatamente para sobreviver a isto.
 */
final class ApagadorDeCampanha
{
    private const LOTE_LIGACOES = 1000;

    private const LOTE_LEADS = 5000;

    /** @var callable(string, string, array<string, bool|float|int|string|null>): void */
    private $log;

    /**
     * @param  callable(string, string, array<string, bool|float|int|string|null>): void  $log
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $gravacaoDir,
        callable $log,
    ) {
        $this->log = $log;
    }

    /**
     * Uma volta sobre todas as Campanhas marcadas.
     *
     * @return array{apagadas:int, aguardando:int, com_erro:int}
     */
    public function executar(): array
    {
        $resumo = ['apagadas' => 0, 'aguardando' => 0, 'com_erro' => 0];

        $st = $this->pdo->query(
            'SELECT id FROM campanha WHERE apagar_pedido_em IS NOT NULL AND status <> 1 ORDER BY id'
        );

        foreach ($st === false ? [] : $st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $resultado = $this->apagar(is_numeric($id) ? (int) $id : 0);
            $resumo[$resultado]++;
        }

        return $resumo;
    }

    /**
     * @return 'apagadas'|'aguardando'|'com_erro'
     */
    public function apagar(int $id): string
    {
        // 🔴 LIGAÇÃO EM CURSO ESPERA. O fastagi ainda vai gravar o desfecho dela; apagar a
        // linha agora faria aquele `UPDATE` acertar zero linhas em silêncio. A Campanha
        // está fora do ar, então não nasce Ligação nova, e a reconciliação do dialer fecha
        // a que ficou presa. Na próxima volta, isto passa.
        if ($this->contar('SELECT COUNT(*) FROM ligacao WHERE campanha_id = ? AND encerrada_em IS NULL', $id) > 0) {
            ($this->log)('aguardando', 'Campanha marcada para apagar ainda tem ligação em curso', ['campanha' => $id]);

            return 'aguardando';
        }

        $base = rtrim($this->gravacaoDir, '/');
        $comGravacao = $this->contar('SELECT COUNT(*) FROM ligacao WHERE campanha_id = ? AND gravacao IS NOT NULL', $id);

        // A mesma guarda do expurgo, pela mesma razão: sem atravessar o diretório,
        // `is_file()` diz `false` para tudo, cada gravação passa por "já ausente" e as
        // linhas somem com os arquivos ainda no disco.
        if ($comGravacao > 0) {
            $falha = match (true) {
                $base === '' => 'PROSPECTA_GRAVACAO_DIR não está configurado',
                is_dir($base) && (! is_readable($base) || ! is_executable($base))
                    => sprintf('sem acesso a %s — o serviço precisa estar no grupo do diretório', $base),
                default => null,
            };

            if ($falha !== null) {
                $this->falhar($id, 'Não consegui apagar as gravações: ' . $falha . '.');

                return 'com_erro';
            }
        }

        $caminho = new CaminhoDeGravacao();
        $retidas = 0;
        $ligacoes = 0;
        $gravacoes = 0;
        $ultimo = 0;

        $busca = $this->pdo->prepare(
            'SELECT id, gravacao FROM ligacao WHERE campanha_id = ? AND id > ? ORDER BY id LIMIT ' . self::LOTE_LIGACOES
        );

        // Cursor por id, e não "os próximos mil": a Ligação retida continua na tabela, e
        // sem cursor o mesmo lote voltaria para sempre.
        do {
            $busca->execute([$id, $ultimo]);
            $lote = $busca->fetchAll(PDO::FETCH_ASSOC);

            $apagar = [];
            foreach ($lote as $linha) {
                $ligacao = is_numeric($linha['id'] ?? null) ? (int) $linha['id'] : 0;
                $arquivo = is_string($linha['gravacao'] ?? null) ? $linha['gravacao'] : '';
                $ultimo = max($ultimo, $ligacao);

                if ($arquivo !== '') {
                    if (! $caminho->contido($base, $arquivo)) {
                        $retidas++;
                        ($this->log)('recusado', 'gravação fora do diretório base; mantenho a Ligação', [
                            'campanha' => $id, 'ligacao' => $ligacao, 'arquivo' => $arquivo,
                        ]);
                        continue;
                    }

                    if (is_file($arquivo)) {
                        if (! unlink($arquivo)) {
                            $retidas++;
                            ($this->log)('erro', 'não consegui apagar a gravação; mantenho a Ligação', [
                                'campanha' => $id, 'ligacao' => $ligacao, 'arquivo' => $arquivo,
                            ]);
                            continue;
                        }
                        $gravacoes++;
                    }
                }

                $apagar[] = $ligacao;
            }

            if ($apagar !== []) {
                $marcas = implode(',', array_fill(0, count($apagar), '?'));

                $this->pdo->prepare("DELETE FROM acao WHERE tipo = 'disparo' AND referencia_id IN ($marcas)")
                    ->execute($apagar);

                $del = $this->pdo->prepare("DELETE FROM ligacao WHERE id IN ($marcas)");
                $del->execute($apagar);
                $ligacoes += $del->rowCount();
            }
        } while (count($lote) === self::LOTE_LIGACOES);

        $leads = 0;
        $delLeads = $this->pdo->prepare('DELETE FROM leads WHERE campanha_id = ? LIMIT ' . self::LOTE_LEADS);
        do {
            $delLeads->execute([$id]);
            $leads += $delLeads->rowCount();
        } while ($delLeads->rowCount() === self::LOTE_LEADS);

        if ($retidas > 0) {
            $this->falhar($id, sprintf(
                'Apaguei o resto, mas %d gravação(ões) não saíram do disco e as ligações delas foram mantidas. '
                . 'O motivo de cada uma está no log do serviço prospecta-apagar-campanhas.',
                $retidas,
            ));

            return 'com_erro';
        }

        // `campanha_campo` sai junto, pela FK em cascata. O `status <> 1` é a última
        // guarda: o painel não deixa iniciar Campanha marcada, mas a recusa mora aqui.
        $this->pdo->prepare('DELETE FROM campanha WHERE id = ? AND status <> 1')->execute([$id]);

        ($this->log)('apagada', 'Campanha apagada', [
            'campanha' => $id, 'ligacoes' => $ligacoes, 'gravacoes' => $gravacoes, 'leads' => $leads,
        ]);

        return 'apagadas';
    }

    private function falhar(int $id, string $motivo): void
    {
        $this->pdo->prepare('UPDATE campanha SET apagar_erro = ? WHERE id = ?')->execute([$motivo, $id]);
        ($this->log)('erro', $motivo, ['campanha' => $id]);
    }

    private function contar(string $sql, int $id): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute([$id]);
        $n = $st->fetchColumn();

        return is_numeric($n) ? (int) $n : 0;
    }
}
