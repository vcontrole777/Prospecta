<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Traduz o resultado de um envio em decisão sobre a Ação. PURA.
 *
 * Mesma forma da `Dialer\Politica`, e pelo mesmo motivo: a regra que decide
 * retentativa é onde os erros caros moram, e ela precisa ser testável sem rede.
 *
 * 🔴 A PREMISSA QUE GOVERNA TUDO AQUI: a Cloud API não tem chave de
 * idempotência. Não existe campo que faça a Meta reconhecer "esta é a mesma
 * mensagem que você já me mandou" — `biz_opaque_callback_data` só volta no
 * webhook, não deduplica.
 *
 * Então uma retentativa depois de um timeout PODE enviar a mensagem duas vezes:
 * o tempo esgotou depois de a requisição chegar, e nós não temos como saber. O
 * Lead recebe duas mensagens e o cliente paga duas.
 *
 * Por isso retenta-se apenas quando há razão POSITIVA para crer que nada foi
 * enviado:
 *   · `is_transient: true` — a plataforma pedindo para retentar
 *   · falha de conexão antes de qualquer resposta
 *   · 5xx, que é o servidor dizendo que não processou
 *
 * Erro 4xx sem `is_transient` NÃO é retentado: a requisição foi recebida e
 * recusada, e insistir só multiplica a recusa.
 */
final class PoliticaDeEnvio
{
    /**
     * A Meta decidiu não entregar esta mensagem agora — limite por usuário ou
     * restrição por qualidade. NÃO é falha técnica, e retentar não resolve.
     *
     * Tem status próprio no enum porque a tela precisa distinguir "a plataforma
     * recusou entregar" de "deu erro": no primeiro caso não há nada a consertar,
     * e tratar como erro faria o operador procurar defeito onde não tem.
     */
    private const LIMITE_DESTINATARIO = 131049;

    /**
     * Erros do CANAL, não da mensagem. Todos exigem ação do operador ou do
     * cliente junto à Meta, e nenhum melhora com retentativa.
     *
     * `190` é token inválido ou expirado — e o que a documentação chama de token
     * "permanente" é, no texto oficial, "de longa duração", então isto acontece.
     * `200` e `1349174` são o aplicativo sem permissão na conta de negócio.
     */
    private const CODIGOS_DE_CANAL = [190, 200, 1349174];

    public function decidir(Envio $e, int $tentativas, int $maxTentativas): DecisaoDeEnvio
    {
        if ($e->aceito()) {
            return new DecisaoDeEnvio('enviado', false, false, 'aceito');
        }

        if ($e->codigo === self::LIMITE_DESTINATARIO) {
            return new DecisaoDeEnvio('limite_destinatario', false, false, 'limite_ou_qualidade');
        }

        if ($e->codigo !== null && in_array($e->codigo, self::CODIGOS_DE_CANAL, true)) {
            // `pausado` e para o canal: com token inválido TODA Ação da fila
            // falharia igual, e percorrer a fila marcando tudo destruiria a
            // informação de quais ainda estavam pendentes.
            return new DecisaoDeEnvio('pausado', false, true, 'credencial_ou_permissao');
        }

        $podeSerDuplicata = ! $e->semResposta && ! $e->transitorio && $e->http >= 400 && $e->http < 500;

        if ($podeSerDuplicata) {
            // 4xx sem `is_transient`: recebido e recusado. Retentar multiplicaria
            // a recusa sem chance de sucesso.
            return new DecisaoDeEnvio('falhou', false, false, 'recusado_definitivamente');
        }

        $vaiRetentar = $e->semResposta || $e->transitorio || $e->http >= 500;

        if ($vaiRetentar && $tentativas < $maxTentativas) {
            return new DecisaoDeEnvio('pendente', true, false, $e->semResposta ? 'sem_resposta' : 'transitorio');
        }

        if ($vaiRetentar) {
            return new DecisaoDeEnvio('falhou', false, false, 'esgotou_tentativas');
        }

        // Resposta 2xx sem `wamid`, ou forma que não reconhecemos. NÃO retenta: se
        // veio 2xx a mensagem provavelmente saiu, e retentar duplicaria. O texto
        // bruto vai para `acao.resposta`, que é como isto é investigado.
        return new DecisaoDeEnvio('falhou', false, false, 'resposta_nao_reconhecida');
    }
}
