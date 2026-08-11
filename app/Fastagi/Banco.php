<?php

declare(strict_types=1);

namespace App\Fastagi;

use PDO;

/**
 * FASES 1, 2 e 4. PDO direto, sem ORM — ADR-0001 exige bootstrap mínimo.
 *
 * Toda consulta usa parâmetro VINCULADO. ADR-0003: valor externo é hostil, e
 * escape manual não conta como defesa — o protótipo em C do MagnusBilling tem
 * uma função de escape que a própria análise deles registra como quebrada sob
 * NO_BACKSLASH_ESCAPES e em charset multibyte.
 *
 * Note `leads` no plural: LEAD é palavra reservada no MySQL 8.0+ e aqui as
 * consultas são escritas à mão, sem o acento grave que um ORM colocaria.
 */
final class Banco
{
    private ?PDO $pdo = null;

    /**
     * @param array{dsn:string,usuario:string,senha:string} $conf
     * @param Registro|null $log opcional: o `Banco` é usado em teste sem log, e a
     *        ausência dele não deve mudar o comportamento das consultas — só silenciar
     *        o aviso de configuração ausente.
     */
    public function __construct(
        private array $conf,
        private ?Registro $log = null,
    ) {
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->conf['dsn'], $this->conf['usuario'], $this->conf['senha'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }

    /**
     * FASE 2. Solta a conexão antes da fase longa.
     *
     * Sem isto, cada Ligação segura uma conexão pela duração inteira, ociosa em
     * ~95% do tempo — em 200 canais são 200 conexões contra um max_connections
     * padrão de 151. Foi a melhor decisão de engenharia do módulo em C do
     * MagnusBilling, e o caminho interpretado deles não a tem.
     */
    public function desconectar(): void
    {
        $this->pdo = null;
    }

    /** FASE 1: lê tudo de uma vez. */
    public function carregar(int $ligacaoId, string $gravacaoDir, string $audioDir): Dados
    {
        $sql = <<<'SQL'
            SELECT  c.tipo, c.tecla_opt_in, c.tecla_opt_out, c.tentativas_ura,
                    am.arquivo  AS audio_mensagem,
                    ai.arquivo  AS audio_opt_in,
                    ao.arquivo  AS audio_opt_out,
                    an.arquivo  AS audio_nao_entendi,
                    ag.arquivo  AS audio_mensagem_generica,
                    g.gravacao_ativa
            FROM ligacao l
            JOIN campanha c        ON c.id = l.campanha_id
            LEFT JOIN audio am     ON am.id = c.audio_mensagem_id
            LEFT JOIN audio ai     ON ai.id = c.audio_confirma_opt_in_id
            LEFT JOIN audio ao     ON ao.id = c.audio_confirma_opt_out_id
            LEFT JOIN audio an     ON an.id = c.audio_nao_entendi_id
            -- 🔴 LEFT JOIN, não CROSS JOIN.
            --
            -- `configuracao` é linha única e uma migration a semeia. Mas CROSS JOIN
            -- com a tabela VAZIA devolve ZERO linhas, e então esta consulta falhava
            -- para TODA chamada de uma instância recém-migrada — lançando "Ligação
            -- não encontrada" sobre uma Ligação que existe.
            --
            -- Com LEFT JOIN, linha ausente degrada para `gravacao_ativa` nulo em vez
            -- de matar a chamada. A chamada acontece; o que se perde é a gravação, e
            -- a ausência é registrada nomeando a causa. É a ordem certa de
            -- prioridade: a chamada é o produto.
            LEFT JOIN configuracao g ON g.id = 1
            -- Recurso GLOBAL de mensagem, da `configuracao`. A coluna existia e era
            -- órfã, enquanto o código apontava para um `prospecta/mensagem` fixo que
            -- ninguém instalava.
            --
            -- 🔴 DEPOIS de `configuracao g`, e a ordem é obrigatória: junção só pode
            -- referenciar alias já introduzido. Escrevi antes e o MySQL recusou com
            -- "Unknown column 'g.audio_mensagem_generica_id' in 'on clause'" — o que
            -- derrubaria TODA chamada, e o teste pegou.
            LEFT JOIN audio ag ON ag.id = g.audio_mensagem_generica_id
            WHERE l.id = :id
            LIMIT 1
        SQL;

        $st = $this->pdo()->prepare($sql);
        $st->execute(['id' => $ligacaoId]);
        $linha = $st->fetch();

        if (! is_array($linha)) {
            // Agora esta mensagem é verdadeira: com LEFT JOIN, zero linhas significa
            // que a Ligação de fato não existe.
            throw new \RuntimeException("Ligação {$ligacaoId} não encontrada");
        }

        // A configuração global está ausente? Não impede a chamada, mas precisa
        // aparecer com o nome do problema — senão o sintoma seria "a gravação parou
        // de funcionar" sem ninguém saber por quê.
        if (($linha['gravacao_ativa'] ?? null) === null) {
            $this->log?->linha(
                'configuracao_ausente',
                'a linha única de `configuracao` não existe; seguindo sem gravação',
                ['ligacao' => $ligacaoId],
            );
        }

        return new Dados(
            ligacaoId: $ligacaoId,
            tipo: self::texto($linha['tipo'] ?? null) === 'captura' ? 'captura' : 'opt_in',
            audioAbertura: 'prospecta/abertura',
            // Áudio da Campanha, com recurso na configuração global. `null` quando não
            // há nenhum dos dois — e o `Fluxo` recusa a chamada com causa nomeada, em
            // vez de discar e tocar silêncio.
            audioMensagem: $this->caminho($audioDir, self::texto($linha['audio_mensagem'] ?? null))
                ?? $this->caminho($audioDir, self::texto($linha['audio_mensagem_generica'] ?? null)),
            audioConfirmaOptIn: $this->caminho($audioDir, self::texto($linha['audio_opt_in'] ?? null)),
            audioConfirmaOptOut: $this->caminho($audioDir, self::texto($linha['audio_opt_out'] ?? null)),
            // 🔴 Recurso no áudio de SISTEMA. Ele é instalado pelo provisionamento e
            // NENHUMA linha de código o usava — peso morto que o playbook exigia.
            //
            // Sem recurso, a URA repetia a mensagem sem explicar nada: a pessoa ouvia a
            // mesma coisa de novo e não sabia por quê. "Não entendi, vamos tentar de
            // novo" é o que o audios/README.md descreve como a intenção do arquivo.
            audioNaoEntendi: $this->caminho($audioDir, self::texto($linha['audio_nao_entendi'] ?? null))
                ?? $audioDir . '/naoentendi',
            teclaOptIn: self::texto($linha['tecla_opt_in'] ?? null),
            teclaOptOut: self::texto($linha['tecla_opt_out'] ?? null),
            tentativasUra: self::numero($linha['tentativas_ura'] ?? null),
            gravacaoAtiva: self::numero($linha['gravacao_ativa'] ?? null) === 1,
            gravacaoDir: $gravacaoDir,
            campos: $this->campos($ligacaoId, $audioDir),
        );
    }

    /**
     * @return list<array{nome:string,tamanho:int,tipo:string,audio:?string,obrigatorio:bool,tentativas:int,timeoutSeg:int}>
     */
    private function campos(int $ligacaoId, string $audioDir): array
    {
        $sql = <<<'SQL'
            SELECT cd.nome, cd.tamanho, cd.tipo, a.arquivo,
                   cc.obrigatorio, cc.tentativas, cc.timeout_seg
            FROM ligacao l
            JOIN campanha_campo cc  ON cc.campanha_id = l.campanha_id
            JOIN campo_definicao cd ON cd.id = cc.campo_definicao_id
            LEFT JOIN audio a       ON a.id = cc.audio_prompt_id
            WHERE l.id = :id
            ORDER BY cc.ordem
        SQL;

        $st = $this->pdo()->prepare($sql);
        $st->execute(['id' => $ligacaoId]);

        $saida = [];
        foreach ($st->fetchAll() as $l) {
            if (! is_array($l)) {
                continue;
            }
            $nome = self::texto($l['nome'] ?? null);
            if ($nome === null) {
                continue;   // campo sem nome não tem como virar chave em respostas
            }
            $saida[] = [
                'nome' => $nome,
                'tamanho' => self::numero($l['tamanho'] ?? null),
                'tipo' => self::texto($l['tipo'] ?? null) ?? 'numero',
                // 🔴 `null` quando não há prompt, e NÃO um caminho fabricado.
                //
                // Antes era `'prospecta/' . pathinfo(... ?? 'campo')`, que produzia
                // `prospecta/campo` — arquivo que `audios_sistema` nunca instalou. O
                // `GET DATA` então tocava NADA e a pessoa ouvia silêncio até o timeout,
                // errava a "validação" por não ter digitado, e o campo era descartado.
                // Mesma classe do `prospecta/mensagem` que já foi consertado.
                'audio' => $this->caminho($audioDir, self::texto($l['arquivo'] ?? null)),
                'obrigatorio' => self::numero($l['obrigatorio'] ?? null) === 1,
                'tentativas' => self::numero($l['tentativas'] ?? null),
                'timeoutSeg' => self::numero($l['timeout_seg'] ?? null),
            ];
        }

        return $saida;
    }

    /**
     * FASE 4: UMA transação.
     *
     * ADR-0002: gravar a tecla e criar a linha de `acao` tem que ser atômico.
     * Sem isso, "a Ação dispara no instante da tecla" é retórica — o processo
     * pode morrer entre as duas escritas e o Lead que apertou fica sem Disparo.
     */
    public function gravar(int $ligacaoId, Resultado $r): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $st = $pdo->prepare(<<<'SQL'
                UPDATE ligacao SET
                    atendida_em   = COALESCE(atendida_em, :atendida_em),
                    encerrada_em  = NOW(),
                    desfecho      = :desfecho,
                    tecla_digitada= :tecla,
                    respostas     = :respostas,
                    amd_status    = :amd_status,
                    amd_cause     = :amd_cause,
                    gravacao      = :gravacao,
                    duracao_seg   = TIMESTAMPDIFF(SECOND, iniciada_em, NOW()),
                    duracao_falada_seg = CASE WHEN :tem_atendida = 1
                        THEN TIMESTAMPDIFF(SECOND, COALESCE(atendida_em, NOW()), NOW()) ELSE NULL END
                WHERE id = :id
            SQL);
            $st->execute([
                'atendida_em' => $r->atendidaEm !== null ? date('Y-m-d H:i:s', $r->atendidaEm) : null,
                'desfecho' => $r->desfecho,
                'tecla' => $r->teclaDigitada,
                'respostas' => $r->respostas === [] ? null : json_encode($r->respostas, JSON_UNESCAPED_UNICODE),
                'amd_status' => $r->amdStatus,
                'amd_cause' => $r->amdCause,
                'gravacao' => $r->gravacao,
                'tem_atendida' => $r->atendida ? 1 : 0,
                'id' => $ligacaoId,
            ]);

            // Ação de Disparo no opt-in. O UNIQUE(tipo, referencia_id) dá a
            // idempotência no ESQUEMA; ON DUPLICATE KEY é o no-op que a torna
            // reentrante sem engolir outros erros, ao contrário de INSERT IGNORE.
            if ($r->desfecho === 'opt_in') {
                $pdo->prepare(<<<'SQL'
                    INSERT INTO acao (tipo, referencia_id, status, criado_em)
                    VALUES ('disparo', :ref, 'pendente', NOW())
                    ON DUPLICATE KEY UPDATE id = id
                SQL)->execute(['ref' => $ligacaoId]);
            }

            // Opt-out entra na lista de bloqueio GLOBAL: quem pediu para não ser
            // contatado pediu ao negócio, não à Campanha.
            if ($r->desfecho === 'opt_out') {
                $pdo->prepare(<<<'SQL'
                    INSERT INTO bloqueio (telefone, ligacao_id, origem, criado_em)
                    SELECT le.telefone, l.id, 'opt_out', NOW()
                    FROM ligacao l JOIN leads le ON le.id = l.lead_id
                    WHERE l.id = :id
                    ON DUPLICATE KEY UPDATE telefone = bloqueio.telefone
                SQL)->execute(['id' => $ligacaoId]);
            }

            // Captura concluída gera Ação de consulta, processada pelo worker
            // FORA da Ligação (ADR-0002).
            if (in_array($r->desfecho, ['captura_concluida', 'captura_parcial'], true) && $r->respostas !== []) {
                $pdo->prepare(<<<'SQL'
                    INSERT INTO acao (tipo, referencia_id, payload, status, criado_em)
                    VALUES ('evento_captura_concluida', :ref, :payload, 'pendente', NOW())
                    ON DUPLICATE KEY UPDATE id = id
                SQL)->execute([
                    'ref' => $ligacaoId,
                    'payload' => json_encode($r->respostas, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Narrowing HONESTO de `mixed` vindo do PDO: trata o caso não-escalar em
     * vez de calar o verificador com cast. O PHPStan nível 9 recusa acessar
     * offset de mixed, e está certo — dado inesperado do banco estouraria em
     * runtime, dentro da chamada.
     */
    private static function texto(mixed $v): ?string
    {
        return is_scalar($v) ? (string) $v : null;
    }

    private static function numero(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private function caminho(string $dir, ?string $arquivo): ?string
    {
        if ($arquivo === null || $arquivo === '') {
            return null;
        }

        // Sem extensão: o Asterisk escolhe o formato. E o nome passa por lista
        // de permissão porque vai para ARGUMENTO DE APLICAÇÃO — quinto contexto
        // hostil do ADR-0003.
        $nome = pathinfo($arquivo, PATHINFO_FILENAME);

        return preg_match('/^[A-Za-z0-9._-]+$/', $nome) === 1
            ? rtrim($dir, '/') . '/' . $nome
            : null;
    }
}
