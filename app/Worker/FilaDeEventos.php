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
        // 🔴 `tentarWaId`, NÃO `tentar`. A Meta manda celular antigo SEM o nono dígito
        // (12 dígitos), e `tentar` recusa esse formato — caía no fallback cru, a resposta
        // abria uma Conversa órfã em paralelo à do Disparo, e o opt-out ia para o
        // `bloqueio` com 12 dígitos, que o dialer nunca casa com o Lead de 13.
        //
        // O fallback cru continua para quem não é número brasileiro: a Conversa vale
        // mesmo assim, e não há Lead para casar.
        $canonico = $this->telefone->tentarWaId($e->de) ?? preg_replace('/\D/', '', $e->de) ?? '';
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
     * Registra uma mensagem que NÓS enviamos, para ela aparecer na Conversa.
     *
     * 🔴 ISTO FALTAVA, e o buraco era visível na tela: `aplicarStatus` só faz
     * `UPDATE conversa_mensagem ... WHERE wamid`, e a linha nunca era criada. Resultado:
     * a caixa de entrada mostrava o que o Lead escrevia e NADA do que foi enviado a ele
     * — nem os Disparos de template. O operador via metade do diálogo e tinha que
     * adivinhar a outra, e o `status` de entrega não tinha onde pousar.
     *
     * Chamado depois do envio ACEITO, quando já existe `wamid`. Sem `wamid` não há como
     * o webhook de status casar depois, então gravar antes seria criar linha órfã.
     *
     * `direcao = 'saida'` e `status = 'enviado'`: é o que a Meta acabou de confirmar. Os
     * estados seguintes — entregue, lido — chegam por webhook e o `aplicarStatus` os
     * aplica, respeitando a regra de só avançar.
     *
     * Cria a `conversa` quando não existe: um Disparo pode ser a PRIMEIRA mensagem do
     * diálogo, e nesse caso não houve entrada nenhuma para tê-la criado. Note que
     * `ultima_entrada_em` NÃO é tocado aqui — a janela de 24 h corre pela última
     * mensagem DELE, não pela nossa, e mexer nisso faria a tela mostrar janela aberta
     * onde não há.
     */
    public function registrarSaida(
        string $telefone,
        string $wamid,
        string $texto,
        ?string $templateNome = null,
    ): void {
        $canonico = $this->telefone->canonico($telefone);

        $lead = $this->pdo->prepare('SELECT id FROM leads WHERE telefone = :t ORDER BY id LIMIT 1');
        $lead->execute(['t' => $canonico]);
        $leadId = $lead->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO conversa (telefone, lead_id, criado_em)
             VALUES (:t, :l, NOW())
             ON DUPLICATE KEY UPDATE lead_id = COALESCE(conversa.lead_id, VALUES(lead_id))'
        )->execute(['t' => $canonico, 'l' => is_numeric($leadId) ? (int) $leadId : null]);

        $busca = $this->pdo->prepare('SELECT id FROM conversa WHERE telefone = :t');
        $busca->execute(['t' => $canonico]);
        $id = $busca->fetchColumn();

        if ($id === false) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO conversa_mensagem
                    (conversa_id, wamid, direcao, tipo, texto, template_nome, status, criado_em)
             VALUES (:c, :w, \'saida\', :tp, :tx, :tn, \'enviado\', NOW())
             ON DUPLICATE KEY UPDATE id = id'
        )->execute([
            'c' => (int) $id,
            'w' => $wamid,
            'tp' => $templateNome === null ? 'text' : 'template',
            'tx' => $texto === '' ? null : $texto,
            'tn' => $templateNome,
        ]);
    }

    /**
     * Fecha o ciclo de uma mensagem de saída que a tela já criou.
     *
     * A resposta do operador nasce em `conversa_mensagem` com `wamid` e `status` nulos —
     * é o que a faz aparecer na conversa imediatamente, em vez de sumir até o worker
     * rodar. Aqui ela recebe o resultado do envio.
     *
     * 🔴 O `wamid` é o que importa. Sem ele os webhooks de entrega não têm como casar
     * (`aplicarStatus` procura por `wamid`), e a mensagem ficaria em "enviado" para
     * sempre mesmo depois de lida.
     *
     * Não avança status já terminal: se o webhook de `delivered` chegar antes de esta
     * gravação acontecer — improvável, mas a Meta é rápida —, sobrescrever com `enviado`
     * faria a tela regredir. É a mesma regra de só-avançar do `aplicarStatus`.
     */
    public function confirmarSaida(int $mensagemId, ?string $wamid, bool $enviada): void
    {
        if ($mensagemId <= 0) {
            return;
        }

        if (! $enviada) {
            $this->pdo->prepare(
                "UPDATE conversa_mensagem SET status = 'falhou' WHERE id = :id AND status IS NULL"
            )->execute(['id' => $mensagemId]);

            return;
        }

        if ($wamid === null) {
            return;
        }

        $this->pdo->prepare(
            "UPDATE conversa_mensagem
                SET wamid = :w, status = 'enviado'
              WHERE id = :id AND status IS NULL"
        )->execute(['w' => $wamid, 'id' => $mensagemId]);
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
