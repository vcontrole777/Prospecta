<?php

declare(strict_types=1);

namespace App\Worker;

use App\Sms\StatusSms;
use App\Whatsapp\Estados;
use PDO;

/**
 * Consumo de `evento_sms`. É a outra metade do webhook do httpSMS.
 *
 * 🔴 CLASSE PRÓPRIA, e não `FilaDeEventos` estendida. As duas guardam "corpo cru
 * esperando processamento", e a semelhança tenta — mas o que elas fazem com o
 * evento diverge: a do WhatsApp cria Conversa, grava mensagem de entrada, aplica
 * opt-out e mexe em `conversa_mensagem`; esta só carimba status em `acao`. É o mesmo
 * argumento que separa `reivindicar` de `reivindicarRespostas`: duas consultas
 * simples se leem melhor que uma que serve a dois donos.
 *
 * 🔴 REUSA `Whatsapp\Estados`, e não é gambiarra: `avanca()` opera no vocabulário do
 * PRODUTO — `enviado`, `entregue`, `falhou` —, não no da Meta. A regra que ele
 * carrega é sobre webhook chegar FORA DE ORDEM, e isso vale igual aqui: o httpSMS
 * pode entregar `delivered` antes de `sent`, e aplicar cegamente faria a Ação
 * regredir. Duplicar a regra é que faria as duas divergirem.
 */
final class FilaDeEventosSms
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Estados $estados = new Estados(),
    ) {
    }

    /**
     * Os próximos eventos não processados.
     *
     * @return list<array{id:int, payload:string}>
     */
    public function proximos(int $quantas): array
    {
        $quantas = max(1, $quantas);

        $st = $this->pdo->query(
            "SELECT id, payload FROM evento_sms
              WHERE processado_em IS NULL
              ORDER BY id LIMIT {$quantas}"
        );
        if ($st === false) {
            return [];
        }

        $saida = [];
        foreach ($st->fetchAll() as $linha) {
            if (! is_array($linha)) {
                continue;
            }
            $id = $linha['id'] ?? null;
            $payload = $linha['payload'] ?? null;
            if (is_numeric($id) && is_string($payload)) {
                $saida[] = ['id' => (int) $id, 'payload' => $payload];
            }
        }

        return $saida;
    }

    /**
     * Aplica um estado de entrega na Ação correspondente.
     *
     * 🔴 CASA PELO `wamid`, que no SMS guarda o id do httpSMS. É o mesmo mecanismo
     * do WhatsApp, e é o que faz um evento forjado para id desconhecido não fazer
     * nada: só se aplica a mensagem que NÓS mandamos e cujo id já está gravado.
     *
     * @return bool se algo mudou — o chamador usa para o log não mentir
     */
    public function aplicar(StatusSms $s): bool
    {
        $atual = $this->pdo->prepare('SELECT status FROM acao WHERE wamid = :w');
        $atual->execute(['w' => $s->id]);
        $antes = $atual->fetchColumn();

        if ($antes === false) {
            // Nenhuma Ação com este id: evento de mensagem que não é nossa, ou
            // chegou antes de o envio ter gravado o id. Não é erro.
            return false;
        }

        if (! $this->estados->avanca(is_string($antes) ? $antes : null, $s->status)) {
            return false;
        }

        $st = $this->pdo->prepare(
            'UPDATE acao SET status = :s, resposta = COALESCE(:r, resposta) WHERE wamid = :w'
        );
        $st->execute([
            's' => $s->status,
            // O `error_message` do httpSMS — `MOBILE_APP_INACTIVE` e afins. É o que
            // diz ao operador que o problema é o APARELHO, não o número do Lead.
            'r' => $s->erro === null ? null : 'httpsms | ' . $s->erro,
            'w' => $s->id,
        ]);

        return $st->rowCount() > 0;
    }

    public function marcarProcessado(int $id): void
    {
        $this->pdo->prepare('UPDATE evento_sms SET processado_em = NOW() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function pendentes(): int
    {
        $st = $this->pdo->query('SELECT COUNT(*) FROM evento_sms WHERE processado_em IS NULL');

        return $st === false ? 0 : (int) $st->fetchColumn();
    }
}
