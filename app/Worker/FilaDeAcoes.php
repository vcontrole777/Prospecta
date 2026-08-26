<?php

declare(strict_types=1);

namespace App\Worker;

use App\Seguranca\Cofre;
use PDO;

/**
 * A fila de Ações pendentes. Mesma forma da `Dialer\Fila`.
 */
final class FilaDeAcoes
{
    public function __construct(
        private readonly PDO $pdo,
        /**
         * Decifra `integracao.credenciais`. Opcional para o caso de instalação que
         * ainda tenha credencial em claro de antes da cifra — ver `credenciaisWhatsapp`.
         */
        private readonly ?Cofre $cofre = null,
    ) {
    }

    /**
     * Reivindica Ações de Disparo pendentes.
     *
     * 🔴 `FOR UPDATE OF a`, não `FOR UPDATE` seco.
     *
     * A consulta junta `acao` com `ligacao`, `leads` e `campanha`. Um `FOR UPDATE`
     * sem `OF` travaria as linhas de TODAS as tabelas da junção — inclusive as de
     * `leads`, que é exatamente onde o dialer faz o seu próprio
     * `FOR UPDATE SKIP LOCKED` para montar a fila de discagem. O worker passaria a
     * competir com o dialer por linha de Lead, e a discagem engasgaria em função do
     * volume de mensagens. `OF a` restringe a trava ao que é nosso.
     *
     * 🔴 `tentativas` é incrementado NA reivindicação e comitado ANTES do envio.
     *
     * Mesma disciplina do `try` no dialer, e aqui o motivo é mais forte: se o envio
     * der certo e a gravação do resultado falhar, a linha volta a ser pendente e a
     * mensagem sai de novo. Incrementar antes limita quantas duplicatas isso pode
     * gerar ao teto de tentativas, em vez de deixar um laço infinito mandando
     * mensagem paga para o mesmo Lead.
     *
     * 🔴 O CANAL É PARÂMETRO, e não mais um filtro fixo em `whatsapp`.
     *
     * A consulta trazia só WhatsApp porque o SMS não tinha remetente — o comentário
     * abaixo explicava que reivindicar sem poder enviar gastaria uma tentativa por
     * volta até o registro morrer por esgotamento. Agora `Worker\Sms` existe, e o
     * que decide é a Campanha.
     *
     * Continua sendo UMA reivindicação por canal, e não um `OR`: o worker monta um
     * remetente diferente para cada um, e misturar os dois no mesmo lote obrigaria a
     * escolher o remetente por linha dentro do laço de envio.
     *
     * @param 'whatsapp'|'sms' $canal
     * @return list<array<string,mixed>>
     */
    public function reivindicar(int $quantas, string $canal = 'whatsapp'): array
    {
        if ($quantas < 1) {
            return [];
        }

        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                "SELECT a.id, a.tentativas, a.referencia_id,
                        le.telefone, le.dados_extras,
                        c.id AS campanha_id, c.canal_opt_in,
                        c.template_nome, c.template_idioma, c.template_mapa,
                        c.texto_mensagem, c.texto_sms,
                        -- Token da imagem do cabecalho, quando o template pede uma.
                        -- LEFT JOIN: a esmagadora maioria das Campanhas nao tem, e
                        -- INNER aqui esconderia todas elas da fila.
                        img.token AS imagem_token
                   FROM acao a
                   JOIN ligacao l  ON l.id  = a.referencia_id
                   JOIN leads   le ON le.id = l.lead_id
                   JOIN campanha c ON c.id  = l.campanha_id
                   LEFT JOIN imagem img ON img.id = c.imagem_id
                  WHERE a.tipo = 'disparo' AND a.status = 'pendente'
                    -- 🔴 UM CANAL POR VEZ. Cada um tem remetente próprio — token da
                    -- Meta de um lado, chave do httpSMS e número do aparelho do
                    -- outro —, e um lote misto obrigaria a escolher o remetente
                    -- linha a linha dentro do laço de envio.
                    AND c.canal_opt_in = :canal
                  ORDER BY a.id
                  LIMIT {$quantas}
                  FOR UPDATE OF a SKIP LOCKED"
            );
            $st->execute(['canal' => $canal === 'sms' ? 'sms' : 'whatsapp']);

            /** @var list<array<string,mixed>> $linhas */
            $linhas = array_values(array_filter($st->fetchAll(), 'is_array'));

            if ($linhas === []) {
                $this->pdo->commit();

                return [];
            }

            $ids = array_map(static fn (array $l): int => self::numero($l['id'] ?? null), $linhas);
            $lista = implode(',', array_filter($ids));

            if ($lista !== '') {
                $this->pdo->exec("UPDATE acao SET tentativas = tentativas + 1 WHERE id IN ({$lista})");
            }

            $this->pdo->commit();

            return $linhas;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reivindica respostas de texto escritas pelo operador.
     *
     * Consulta SEPARADA da de `disparo`, e não um `OR` na mesma: o disparo junta
     * `ligacao`, `leads` e `campanha` para achar telefone e template. Resposta de texto
     * não tem Ligação — nasce de alguém digitando numa Conversa —, então o mesmo SELECT
     * precisaria de LEFT JOIN e de ramos condicionais para as duas formas. Duas
     * consultas simples são mais fáceis de ler que uma que serve a dois donos.
     *
     * `referencia_id` é a MENSAGEM (`conversa_mensagem`), não a Conversa. A tabela `acao`
     * tem `unique(tipo, referencia_id)` para dar idempotência pelo esquema; apontar para
     * a Conversa faria a segunda resposta do mesmo diálogo colidir. Uma mensagem tem um
     * envio — é essa a unicidade que faz sentido.
     *
     * Texto e telefone vêm do BANCO, não de payload escrito pela tela: deixar as duas
     * fontes divergirem permitiria mandar um texto enquanto a Conversa mostra outro.
     *
     * `ultima_entrada_em` vem junto porque o worker RECONFERE a janela no instante do
     * envio. A tela já verificou, mas entre digitar e o worker pegar a Ação pode passar
     * tempo, e a Meta recusa texto fora da janela com 131047.
     *
     * @return list<array<string,mixed>>
     */
    public function reivindicarRespostas(int $quantas): array
    {
        $quantas = max(1, $quantas);

        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                "SELECT a.id, a.tentativas, a.referencia_id,
                        m.texto, m.conversa_id, m.botao_texto, m.botao_url,
                        c.telefone, c.ultima_entrada_em
                   FROM acao a
                   JOIN conversa_mensagem m ON m.id = a.referencia_id
                   JOIN conversa c          ON c.id = m.conversa_id
                  WHERE a.tipo = 'resposta_texto' AND a.status = 'pendente'
                  ORDER BY a.id
                  LIMIT {$quantas}
                  FOR UPDATE OF a SKIP LOCKED"
            );
            $st->execute();

            /** @var list<array<string,mixed>> $linhas */
            $linhas = array_values(array_filter($st->fetchAll(), 'is_array'));

            if ($linhas === []) {
                $this->pdo->commit();

                return [];
            }

            $ids = array_map(static fn (array $l): int => self::numero($l['id'] ?? null), $linhas);
            $lista = implode(',', array_filter($ids));

            if ($lista !== '') {
                $this->pdo->exec("UPDATE acao SET tentativas = tentativas + 1 WHERE id IN ({$lista})");
            }

            $this->pdo->commit();

            return $linhas;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Grava o resultado.
     *
     * `processado_em` só é preenchido em estado TERMINAL: uma Ação que voltou para
     * `pendente` não foi processada, e marcá-la faria a tela mentir sobre quando o
     * envio aconteceu.
     */
    public function aplicar(int $acaoId, DecisaoDeEnvio $d, Envio $e): void
    {
        $terminal = $d->status !== 'pendente';

        $st = $this->pdo->prepare(
            'UPDATE acao
                SET status = :s,
                    wamid = COALESCE(:w, wamid),
                    resposta = :r,
                    processado_em = CASE WHEN :terminal = 1 THEN NOW() ELSE processado_em END
              WHERE id = :id'
        );

        // `error_user_msg` primeiro: é texto que a Meta escreveu PARA o usuário
        // final, e mostrá-lo é melhor que parafrasear — evita a tela dizer "erro"
        // quando a plataforma já explicou o quê. O `fbtrace_id` vai junto porque é
        // o que um ticket de suporte com a Meta exige.
        $partes = array_filter([
            $d->motivo,
            $e->mensagemDoUsuario ?? $e->mensagem,
            $e->codigo !== null ? "codigo={$e->codigo}" : null,
            $e->fbtrace !== null ? "fbtrace={$e->fbtrace}" : null,
        ]);

        $st->execute([
            's' => $d->status,
            'w' => $e->wamid,
            'r' => implode(' | ', $partes),
            'terminal' => $terminal ? 1 : 0,
            'id' => $acaoId,
        ]);
    }

    /**
     * O Disparo deve sair pela Marketing Messages API?
     *
     * 🔴 LIDO A CADA VOLTA, junto das credenciais, e não na partida. É chave para
     * um problema de PLATAFORMA — a Meta aceitando a mensagem e desistindo de
     * entregar —, e voltar atrás precisa ser um clique na tela, não um restart.
     */
    public function marketingApiAtiva(): bool
    {
        $st = $this->pdo->query('SELECT marketing_api_ativa FROM configuracao WHERE id = 1');
        if ($st === false) {
            return false;
        }

        return (bool) $st->fetchColumn();
    }

    /**
     * Credenciais da integração de WhatsApp em vigor.
     *
     * Vem de `configuracao.integracao_whatsapp_id`, e não de "a primeira ativa":
     * pode haver mais de uma cadastrada, e qual está em uso é escolha do operador,
     * não ordem de inserção.
     *
     * @return array{token:string, numero_id:string, versao_api:string}|null
     */
    public function credenciaisWhatsapp(): ?array
    {
        $st = $this->pdo->query(
            'SELECT i.credenciais
               FROM configuracao cfg
               JOIN integracao i ON i.id = cfg.integracao_whatsapp_id
              WHERE cfg.id = 1 AND i.ativo = 1 AND i.tipo = \'whatsapp\''
        );
        if ($st === false) {
            return null;
        }

        $linha = $st->fetch();
        if (! is_array($linha)) {
            return null;
        }

        $guardado = self::texto($linha['credenciais'] ?? null);

        // A convenção do envelope vive no `Cofre`, não aqui — painel e worker usam o
        // mesmo par de métodos. Sem `Cofre` injetado, lê como claro (caso de teste e
        // de instalação anterior à cifra).
        $cred = $this->cofre !== null
            ? $this->cofre->decifrarDaColuna($guardado)
            : json_decode($guardado, true);

        if (! is_array($cred) || $cred === []) {
            return null;
        }

        $token = self::texto($cred['token'] ?? null);
        $numero = self::texto($cred['phone_number_id'] ?? null);

        if ($token === '' || $numero === '') {
            return null;
        }

        return [
            'token' => $token,
            'numero_id' => $numero,
            'versao_api' => self::texto($cred['versao_api'] ?? null) ?: 'v21.0',
        ];
    }

    /**
     * Credenciais da integração de SMS em vigor.
     *
     * Mesma forma de `credenciaisWhatsapp`, e a duplicação é DELIBERADA: extrair um
     * "carrega credencial do tipo X" pareceria economia, mas os dois conferem campos
     * DIFERENTES — token e `phone_number_id` de um lado, chave e remetente do outro
     * —, e a conferência é justamente o que impede um envio nascer condenado. Um
     * método genérico devolveria o array cru e empurraria a checagem para o
     * chamador, que é onde ela some.
     *
     * @return array{api_key:string, remetente:string}|null
     */
    public function credenciaisSms(): ?array
    {
        $st = $this->pdo->query(
            'SELECT i.credenciais
               FROM configuracao cfg
               JOIN integracao i ON i.id = cfg.integracao_sms_id
              WHERE cfg.id = 1 AND i.ativo = 1 AND i.tipo = \'sms\''
        );
        if ($st === false) {
            return null;
        }

        $linha = $st->fetch();
        if (! is_array($linha)) {
            return null;
        }

        $guardado = self::texto($linha['credenciais'] ?? null);
        $cred = $this->cofre !== null
            ? $this->cofre->decifrarDaColuna($guardado)
            : json_decode($guardado, true);

        if (! is_array($cred) || $cred === []) {
            return null;
        }

        $chave = self::texto($cred['api_key'] ?? null);
        $remetente = self::texto($cred['remetente'] ?? null);

        // Sem remetente o httpSMS não sabe qual aparelho seu deve mandar, e recusa
        // com um 422 sobre formato. Recusar aqui deixa a Ação esperando em vez de
        // gastar tentativa contra uma configuração incompleta.
        if ($chave === '' || $remetente === '') {
            return null;
        }

        return ['api_key' => $chave, 'remetente' => $remetente];
    }

    /**
     * Ações pendentes de canal que ainda não tem remetente. Não é erro — é
     * trabalho esperando por código — mas precisa aparecer no log, senão uma
     * Campanha configurada para um canal desses pareceria simplesmente não fazer nada.
     *
     * 🔴 A LISTA É A DOS CANAIS QUE O WORKER SABE MANDAR, e ela cresceu: `sms` saiu
     * daqui quando `Worker\Sms` nasceu. Hoje a conta dá zero, e isso está certo —
     * ela é a guarda para o dia em que alguém acrescentar um canal ao enum sem
     * escrever o remetente, que é exatamente o que aconteceu com o SMS e ficou
     * invisível até este contador existir.
     */
    public function contarAguardandoCanal(): int
    {
        $st = $this->pdo->query(
            "SELECT COUNT(*)
               FROM acao a
               JOIN ligacao l  ON l.id = a.referencia_id
               JOIN campanha c ON c.id = l.campanha_id
              WHERE a.tipo = 'disparo' AND a.status = 'pendente'
                AND c.canal_opt_in NOT IN ('whatsapp', 'sms')"
        );

        return $st === false ? 0 : self::numero($st->fetchColumn());
    }

    /** Ações que sobraram em `pendente` com as tentativas esgotadas. */
    public function marcarEsgotadas(int $maxTentativas): int
    {
        $st = $this->pdo->prepare(
            "UPDATE acao
                SET status = 'falhou',
                    resposta = CONCAT(COALESCE(resposta, ''), ' | esgotou_tentativas'),
                    processado_em = NOW()
              WHERE tipo = 'disparo' AND status = 'pendente' AND tentativas >= :m"
        );
        $st->bindValue('m', $maxTentativas, PDO::PARAM_INT);
        $st->execute();

        return $st->rowCount();
    }

    private static function numero(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
