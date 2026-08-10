<?php

declare(strict_types=1);

namespace App\Dialer;

use PDO;

/**
 * A Fila de discagem. PDO direto, parâmetro sempre vinculado.
 *
 * Note `leads` no plural: LEAD é palavra reservada no MySQL 8.0+, e aqui as
 * consultas são escritas à mão, sem o acento grave que um ORM colocaria.
 */
final class Fila
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * 🔴 REIVINDICAÇÃO ATÔMICA, com `FOR UPDATE SKIP LOCKED`.
     *
     * E `try` é incrementado ANTES de originar. O MagnusBilling reserva o lote
     * inteiro e só depois valida cada número: os que falham na validação ficam
     * com tentativa gasta sem nunca ter sido discados, e como o limite deles é
     * duas tentativas, **duas passagens de Campanha mal configurada queimam a
     * agenda inteira, permanentemente**.
     *
     * @return list<array{id:int,telefone:string,tentativa:int}>
     */
    public function reivindicar(int $campanhaId, int $quantas): array
    {
        if ($quantas < 1) {
            return [];
        }

        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare(
                'SELECT id, telefone, try FROM leads
                 WHERE campanha_id = :c AND status = 1
                 ORDER BY id LIMIT ' . (int) $quantas . ' FOR UPDATE SKIP LOCKED'
            );
            $st->execute(['c' => $campanhaId]);

            $lote = [];
            foreach ($st->fetchAll() as $l) {
                if (! is_array($l)) {
                    continue;
                }
                $id = self::numero($l['id'] ?? null);
                $tel = self::texto($l['telefone'] ?? null);
                if ($id === 0 || $tel === null) {
                    continue;
                }
                $lote[] = ['id' => $id, 'telefone' => $tel, 'tentativa' => self::numero($l['try'] ?? null) + 1];
            }

            if ($lote !== []) {
                $ids = implode(',', array_map(static fn (array $l): int => $l['id'], $lote));
                $this->pdo->exec("UPDATE leads SET status = 2, try = try + 1 WHERE id IN ({$ids})");
            }

            $this->pdo->commit();

            return $lote;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * A lista de bloqueio é verificada NA DISCAGEM, não só na importação —
     * alguém pode entrar nela depois de o arquivo ser carregado.
     */
    public function bloqueado(string $telefone): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM bloqueio WHERE telefone = :t LIMIT 1');
        $st->execute(['t' => $telefone]);

        return $st->fetchColumn() !== false;
    }

    /**
     * 🔴 Cria a Ligação ANTES de originar. Linha sem chamada é reconciliável;
     * chamada sem linha é invisível. E o `id` precisa existir para virar
     * `ChannelId`.
     */
    public function criarLigacao(int $leadId, int $campanhaId, int $tentativa, ?string $bina): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ligacao (lead_id, campanha_id, numero_tentativa, bina, iniciada_em)
             VALUES (:l, :c, :t, :b, NOW())'
        );
        $st->execute(['l' => $leadId, 'c' => $campanhaId, 't' => $tentativa, 'b' => $bina]);

        return (int) $this->pdo->lastInsertId();
    }

    /** Canais realmente ocupados por esta Campanha. */
    public function contarOcupados(int $campanhaId): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ligacao WHERE campanha_id = :c AND encerrada_em IS NULL'
        );
        $st->execute(['c' => $campanhaId]);

        return self::numero($st->fetchColumn());
    }

    public function contarDisponiveis(int $campanhaId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM leads WHERE campanha_id = :c AND status = 1');
        $st->execute(['c' => $campanhaId]);

        return self::numero($st->fetchColumn());
    }

    /**
     * Leads REIVINDICADOS e ainda em voo (status 2).
     *
     * 🔴 Encontrado em teste de integração: sem esta contagem a Campanha era
     * marcada como CONCLUÍDA enquanto quatro chamadas estavam em andamento —
     * porque "nenhum disponível" e "nenhum aguardando rodada" era verdade, mas
     * havia gente em voo. Em produção isso apareceria como Campanha terminada
     * que continua discando, e resultado chegando para Campanha concluída.
     */
    public function contarEmProcessamento(int $campanhaId): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM leads WHERE campanha_id = :c AND status = 2');
        $st->execute(['c' => $campanhaId]);

        return self::numero($st->fetchColumn());
    }

    /**
     * 🔴 TODO caminho de saída grava status TERMINAL. `continue` sem escrita
     * deixa a linha reivindicada (status 2) e nunca mais discada — no
     * MagnusBilling a falha de seleção de tronco sai por `continue` seco, e
     * esses números ficam em "discando" para sempre.
     *
     * 🔴 E só `UPDATE` de coluna, nunca gravação de model completo: objeto
     * carregado antes da reivindicação reverteria a reivindicação. No Yii deles
     * o `save()` desfaz o `try + 1` de um `updateAll` anterior.
     */
    public function aplicar(int $leadId, Decisao $d, int $maxTentativas, int $tentativaAtual): void
    {
        $status = match ($d->acao) {
            // 3 finalizado: aposentado ou atendido. Não volta para a Fila.
            Acao::Aposentar, Acao::Finalizado => 3,
            // 4 aguardando rodada, se ainda tem tentativa. Senão, finalizado.
            Acao::Retentar, Acao::RecuoLongo => $tentativaAtual >= $maxTentativas ? 3 : 4,
        };

        $st = $this->pdo->prepare('UPDATE leads SET status = :s WHERE id = :id');
        $st->execute(['s' => $status, 'id' => $leadId]);
    }

    /** Devolve o Lead à Fila sem gastar a tentativa — usado quando NÃO discamos. */
    public function devolverSemGastar(int $leadId): void
    {
        $st = $this->pdo->prepare('UPDATE leads SET status = 1, try = GREATEST(try - 1, 0) WHERE id = :id');
        $st->execute(['id' => $leadId]);
    }

    /** Marca como finalizado sem discar — Lead na lista de bloqueio. */
    public function encerrarSemDiscar(int $leadId): void
    {
        $st = $this->pdo->prepare('UPDATE leads SET status = 3, try = GREATEST(try - 1, 0) WHERE id = :id');
        $st->execute(['id' => $leadId]);
    }

    /** Sorteia uma BINA do pool cujo DDD casa com o do Lead. */
    public function binaDoDdd(string $telefone): ?string
    {
        // ORDER BY RAND() é seguro AQUI porque a tabela é pequena — dezenas ou
        // centenas de linhas. É o mesmo ORDER BY RAND() que no MagnusBilling é
        // problema, porque lá roda sobre milhões de linhas de telefone.
        $st = $this->pdo->prepare(
            'SELECT numero FROM bina WHERE ativo = 1 AND ddd = SUBSTRING(:t, 3, 2) ORDER BY RAND() LIMIT 1'
        );
        $st->execute(['t' => $telefone]);
        $v = $st->fetchColumn();

        return $v === false ? null : self::texto($v);
    }

    public function pausarCampanha(int $campanhaId): void
    {
        $st = $this->pdo->prepare('UPDATE campanha SET status = 2 WHERE id = :id AND status = 1');
        $st->execute(['id' => $campanhaId]);
    }

    /** Abre a próxima rodada: quem estava aguardando volta para a Fila. */
    public function abrirProximaRodada(int $campanhaId): int
    {
        $st = $this->pdo->prepare('UPDATE leads SET status = 1 WHERE campanha_id = :c AND status = 4');
        $st->execute(['c' => $campanhaId]);
        $voltaram = $st->rowCount();

        if ($voltaram > 0) {
            $this->pdo->prepare('UPDATE campanha SET rodada_atual = rodada_atual + 1 WHERE id = :c')
                ->execute(['c' => $campanhaId]);
        }

        return $voltaram;
    }

    /**
     * Reconciliação. Evento de AMI se perde — reconexão, reinício do PBX,
     * processo morto no momento errado. Sem esta varredura a Ligação fica presa
     * para sempre, e cada uma prende um canal na contabilidade. NÃO é caso de
     * borda: é operação normal ao longo de semanas.
     */
    public function reconciliar(int $limiteSeg = 300): int
    {
        $st = $this->pdo->prepare(
            "UPDATE ligacao SET encerrada_em = NOW(), desfecho = COALESCE(desfecho, 'erro')
             WHERE encerrada_em IS NULL AND iniciada_em < NOW() - INTERVAL :s SECOND"
        );
        $st->bindValue('s', $limiteSeg, PDO::PARAM_INT);
        $st->execute();

        return $st->rowCount();
    }

    /**
     * O Horário de Trabalho configurado. GLOBAL, como a gravação — não é por
     * Campanha.
     *
     * As três colunas existiam e nada as lia: o produto prometia horário e discava 24
     * horas por dia, sete dias por semana. Coluna nula devolve janela sem restrição,
     * que é o comportamento de antes — a restrição passa a existir só quando alguém a
     * configura.
     */
    public function janelaDeTrabalho(): Janela
    {
        $st = $this->pdo->query('SELECT horario_inicio, horario_fim, dias_semana FROM configuracao WHERE id = 1');
        if ($st === false) {
            return new Janela();
        }

        $linha = $st->fetch();
        if (! is_array($linha)) {
            return new Janela();
        }

        $dias = null;
        $json = $linha['dias_semana'] ?? null;
        if (is_string($json)) {
            $lido = json_decode($json, true);
            if (is_array($lido)) {
                $dias = [];
                foreach ($lido as $d) {
                    if (is_numeric($d) && (int) $d >= 1 && (int) $d <= 7) {
                        $dias[] = (int) $d;
                    }
                }
            }
        }

        return new Janela(
            is_string($linha['horario_inicio'] ?? null) ? $linha['horario_inicio'] : null,
            is_string($linha['horario_fim'] ?? null) ? $linha['horario_fim'] : null,
            $dias,
        );
    }

    /** @return list<array{id:int,contratado:int,max_tentativas:int,bina:?string,bina_regra:string,timeout_toque:int}> */
    public function campanhasAtivas(int $canaisContratados): array
    {
        $st = $this->pdo->query(
            'SELECT id, max_tentativas, bina, bina_regra, chamadas_simultaneas
             FROM campanha WHERE status = 1 ORDER BY id'
        );
        if ($st === false) {
            return [];
        }

        $saida = [];
        foreach ($st->fetchAll() as $c) {
            if (! is_array($c)) {
                continue;
            }
            $saida[] = [
                'id' => self::numero($c['id'] ?? null),
                // O contratado da INSTÂNCIA é o teto; a Campanha pode pedir menos.
                'contratado' => min($canaisContratados, max(1, self::numero($c['chamadas_simultaneas'] ?? null))),
                'max_tentativas' => max(1, self::numero($c['max_tentativas'] ?? null)),
                'bina' => self::texto($c['bina'] ?? null),
                'bina_regra' => self::texto($c['bina_regra'] ?? null) ?? 'unica',
                // Timeout de toque: 25-30 s é a faixa recomendada. Medido que o
                // 487 é NOSSO CANCEL, então este número define a ocupação de
                // canal do não atendido — e é decisão nossa, não da operadora.
                'timeout_toque' => 28,
            ];
        }

        return $saida;
    }

    private static function texto(mixed $v): ?string
    {
        return is_scalar($v) ? (string) $v : null;
    }

    private static function numero(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
