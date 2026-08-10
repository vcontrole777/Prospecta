<?php

declare(strict_types=1);

namespace App\Worker;

use App\Dominio\Telefone;
use App\Whatsapp\Entrada;
use App\Whatsapp\Estados;
use App\Whatsapp\Status;
use PDO;

/**
 * Consumo de `evento_whatsapp`. É a outra metade do webhook.
 *
 * 🔴 TODO EFEITO AQUI É IDEMPOTENTE, e isso é o que permite marcar `processado_em`
 * DEPOIS de aplicar.
 *
 * Marcar antes tornaria uma queda no meio um evento perdido em silêncio. Marcando
 * depois, uma queda faz o evento ser reprocessado — e reprocessar não pode causar
 * dano. Cada efeito abaixo é idempotente por uma razão do ESQUEMA, não por cuidado
 * no código:
 *
 *   · `conversa.telefone` é único        → upsert
 *   · `conversa_mensagem.wamid` é único  → insertOrIgnore
 *   · `bloqueio.telefone` é a chave      → insertOrIgnore
 *   · estado só avança                   → `Estados::avanca`
 */
final class FilaDeEventos
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Estados $estados = new Estados(),
        private readonly Telefone $telefone = new Telefone(),
    ) {
    }

    /**
     * Eventos ainda não processados, mais antigos primeiro.
     *
     * Ordem por `id` importa: os estados de uma mensagem chegam em sequência, e
     * processar fora de ordem faria a checagem de avanço rejeitar o estado certo.
     * (Ela protege contra a Meta entregar fora de ordem; não é desculpa para nós
     * embaralharmos.)
     *
     * @return list<array{id:int, payload:string}>
     */
    public function reivindicar(int $quantas): array
    {
        if ($quantas < 1) {
            return [];
        }

        $st = $this->pdo->query(
            'SELECT id, payload FROM evento_whatsapp
              WHERE processado_em IS NULL
              ORDER BY id
              LIMIT ' . $quantas
        );
        if ($st === false) {
            return [];
        }

        $saida = [];
        foreach ($st->fetchAll() as $linha) {
            if (! is_array($linha)) {
                continue;
            }
            $id = is_numeric($linha['id'] ?? null) ? (int) $linha['id'] : 0;
            $payload = is_string($linha['payload'] ?? null) ? $linha['payload'] : '';

            if ($id > 0 && $payload !== '') {
                $saida[] = ['id' => $id, 'payload' => $payload];
            }
        }

        return $saida;
    }

    /**
     * Aplica um estado de entrega em `conversa_mensagem` e na `acao` correspondente.
     *
     * 🔴 SÓ AVANÇA. Webhook chega fora de ordem — `read` pode vir antes de
     * `delivered` — e aplicar cegamente faria a tela regredir de "lido" para
     * "entregue". A regra vive em `Estados`, num lugar só, porque as duas colunas
     * usam palavras DIFERENTES para o mesmo estado (`reproduzido` e `tocado`).
     *
     * @return bool false quando o estado é desconhecido, para o chamador registrar
     */
    public function aplicarStatus(Status $s): bool
    {
        if (! $this->estados->conhecido($s->estado)) {
            return false;
        }

        $paraMensagem = $this->estados->paraMensagem($s->estado);
        $paraAcao = $this->estados->paraAcao($s->estado);

        if ($paraMensagem !== null) {
            $atual = $this->pdo->prepare('SELECT status FROM conversa_mensagem WHERE wamid = :w');
            $atual->execute(['w' => $s->wamid]);
            $antes = $atual->fetchColumn();

            if ($antes !== false && $this->estados->avanca(is_string($antes) ? $antes : null, $paraMensagem)) {
                $this->pdo->prepare(
                    'UPDATE conversa_mensagem SET status = :s, erro_codigo = COALESCE(:c, erro_codigo)
                      WHERE wamid = :w'
                )->execute(['s' => $paraMensagem, 'c' => $s->erroCodigo, 'w' => $s->wamid]);
            }
        }

        if ($paraAcao !== null) {
            $atual = $this->pdo->prepare('SELECT status FROM acao WHERE wamid = :w');
            $atual->execute(['w' => $s->wamid]);
            $antes = $atual->fetchColumn();

            if ($antes !== false && $this->estados->avanca(is_string($antes) ? $antes : null, $paraAcao)) {
                // `resposta` recebe o texto que a META escreveu para o usuário final,
                // quando houver. Melhor que parafrasear: a tela mostra a explicação
                // dela em vez de dizer "erro".
                $this->pdo->prepare(
                    "UPDATE acao
                        SET status = :s,
                            resposta = CASE WHEN :t IS NULL THEN resposta
                                            ELSE CONCAT(COALESCE(resposta, ''), ' | ', :t2) END
                      WHERE wamid = :w"
                )->execute([
                    's' => $paraAcao,
                    't' => $s->erroTexto,
                    't2' => $s->erroTexto,
                    'w' => $s->wamid,
                ]);
            }
        }

        return true;
    }

    /**
     * Registra mensagem de entrada e devolve o telefone canônico.
     *
     * 🔴 `ultima_entrada_em` É A JANELA DE 24 HORAS. Dentro dela, texto livre é
     * ilimitado e não custa por mensagem; fora, só template pago. Errar este carimbo
     * é errar o custo de operação e a possibilidade de responder.
     */
    public function registrarEntrada(Entrada $e): string
    {
        // A Meta manda só dígitos com país, que já é o nosso canônico. Normalizar de
        // todo jeito: se um dia vier com `+` ou com nono dígito ausente, a Conversa
        // não pode virar uma linha nova em paralelo à que já existe.
        $canonico = $this->telefone->tentar($e->de) ?? preg_replace('/\D/', '', $e->de) ?? '';
        if ($canonico === '') {
            return '';
        }

        // Vincula ao Lead quando existe. Não é obrigatório: pessoa pode escrever sem
        // ter sido importada, e a Conversa vale mesmo assim.
        $lead = $this->pdo->prepare('SELECT id FROM leads WHERE telefone = :t ORDER BY id LIMIT 1');
        $lead->execute(['t' => $canonico]);
        $leadId = $lead->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO conversa (telefone, lead_id, ultima_entrada_em, criado_em)
             VALUES (:t, :l, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                 ultima_entrada_em = NOW(),
                 lead_id = COALESCE(conversa.lead_id, VALUES(lead_id))'
        )->execute(['t' => $canonico, 'l' => is_numeric($leadId) ? (int) $leadId : null]);

        $conversaId = $this->pdo->prepare('SELECT id FROM conversa WHERE telefone = :t');
        $conversaId->execute(['t' => $canonico]);
        $id = $conversaId->fetchColumn();

        if (! is_numeric($id)) {
            return $canonico;
        }

        // `wamid` é único: reprocessar o mesmo evento é no-op, sem engolir outros
        // erros como um INSERT IGNORE faria.
        $this->pdo->prepare(
            'INSERT INTO conversa_mensagem (conversa_id, wamid, direcao, tipo, texto, criado_em)
             VALUES (:c, :w, \'entrada\', :tp, :tx, NOW())
             ON DUPLICATE KEY UPDATE id = id'
        )->execute([
            'c' => (int) $id,
            'w' => $e->wamid,
            'tp' => mb_substr($e->tipo, 0, 30),
            'tx' => $e->texto === '' ? null : $e->texto,
        ]);

        return $canonico;
    }

    /**
     * Põe o telefone na lista de bloqueio GLOBAL.
     *
     * Global porque quem pediu para não ser contatado pediu ao NEGÓCIO, não à
     * Campanha — aplicar por Campanha faria a próxima voltar a ligar.
     */
    public function bloquear(string $telefone): void
    {
        $this->pdo->prepare(
            "INSERT INTO bloqueio (telefone, origem, criado_em)
             VALUES (:t, 'opt_out', NOW())
             ON DUPLICATE KEY UPDATE telefone = bloqueio.telefone"
        )->execute(['t' => $telefone]);
    }

    public function marcarProcessado(int $id): void
    {
        $this->pdo->prepare('UPDATE evento_whatsapp SET processado_em = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /** Quantos eventos esperam. Para o log dizer que há acúmulo. */
    public function pendentes(): int
    {
        $st = $this->pdo->query('SELECT COUNT(*) FROM evento_whatsapp WHERE processado_em IS NULL');

        return $st === false ? 0 : (is_numeric($v = $st->fetchColumn()) ? (int) $v : 0);
    }
}
