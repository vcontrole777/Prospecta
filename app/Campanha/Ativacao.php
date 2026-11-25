<?php

declare(strict_types=1);

namespace App\Campanha;

use App\Audio\Alaw;
use App\Dominio\DuracaoMaxima;
use App\Dominio\SituacaoDoArquivo;
use Illuminate\Support\Facades\DB;

/**
 * As guardas de ativação: o que precisa estar no lugar para a Campanha ir ao ar.
 *
 * Saiu do componente de Campanhas porque agora tem DOIS chamadores: o botão "iniciar" e o
 * fim do reinício em segundo plano (`App\Campanha\Reinicio`). Um caminho paralelo que
 * pulasse as guardas seria a forma mais cara de descobrir que elas importam.
 *
 * Não fala com a sessão nem com a tela: devolve as faltas, e quem chamou decide como
 * mostrá-las.
 */
final class Ativacao
{
    /**
     * O que impede a Campanha de ir ao ar. Lista vazia: pode ir. `null`: ela não existe.
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
     *
     * @return list<string>|null
     */
    public function faltas(int $id): ?array
    {
        $linha = DB::table('campanha')->where('id', $id)->first();
        if ($linha === null) {
            return null;
        }
        $c = (array) $linha;

        // Marcas de trabalho em segundo plano vêm antes de tudo e SOZINHAS: enquanto o
        // apagamento ou o reinício roda, as outras faltas descrevem um estado que está
        // mudando, e listá-las junto mandaria o operador consertar o que não está quebrado.
        if (($c['apagar_pedido_em'] ?? null) !== null) {
            return ['esta Campanha está sendo apagada'];
        }

        if (($c['reiniciar_pedido_em'] ?? null) !== null) {
            return ['esta Campanha está sendo reiniciada, e vai ao ar sozinha quando terminar'];
        }

        $faltas = [];

        // Status 1 é disponível e 4 é aguardando a próxima rodada — as duas contam,
        // porque o dialer abre a rodada seguinte sozinho.
        //
        // `exists()` e não `count()`: a pergunta é "tem algum?", e contar uma lista de
        // 1,77 milhão de Leads levou minutos com o banco ocupado (25/11/2026).
        $temDisponivel = DB::table('leads')
            ->where('campanha_id', $id)
            ->whereIn('status', [1, 4])
            ->exists();

        if (! $temDisponivel) {
            $faltas[] = 'nenhum Lead disponível — importe a lista antes';
        }

        $ehCaptura = ($c['tipo'] ?? null) === 'captura';

        if ($ehCaptura) {
            foreach ($this->faltasDeCaptura($id) as $falta) {
                $faltas[] = $falta;
            }
        }

        /*
         * 🔴 A MENSAGEM É EXIGIDA NOS DOIS TIPOS, e isto MUDOU.
         *
         * Havia aqui uma exceção para captura, com a justificativa de que `Fluxo::captura`
         * perguntava campo a campo sem tocar a Mensagem. Era verdade e deixou de ser: a
         * captura passou a exigir o mesmo portão do opt_in — Mensagem, tecla, e só então
         * as perguntas —, porque pedir CPF a quem não ouviu a oferta é pedir documento sem
         * autorização, e na prática ninguém digita.
         *
         * Manter a exceção deixaria a ativação aprovar uma Campanha que aborta na primeira
         * ligação com `AUDIO_NAO_CONFIGURADO`: erro longe da causa, que é justamente o que
         * esta tela existe para evitar.
         */
        if (($c['audio_mensagem_id'] ?? null) === null) {
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

        $longa = $this->faltaDeDuracao($id, $c, $ehCaptura);
        if ($longa !== null) {
            $faltas[] = $longa;
        }

        return $faltas;
    }

    /**
     * Põe no ar. O dialer só olha `status = 1`. Devolve se pôs.
     *
     * Condicional no próprio UPDATE: as marcas de apagar e de reiniciar são conferidas de
     * novo aqui, e não só em `faltas()`, porque entre ler e gravar outro clique pode tê-las
     * posto.
     */
    public function ativar(int $id): bool
    {
        // `COALESCE` preserva a primeira ativação: retomar uma Campanha pausada não
        // deveria reescrever quando ela começou.
        return DB::table('campanha')
            ->where('id', $id)
            ->whereIn('status', [0, 2])
            ->whereNull('apagar_pedido_em')
            ->whereNull('reiniciar_pedido_em')
            ->update([
                'status' => 1,
                'iniciada_em' => DB::raw('COALESCE(iniciada_em, NOW())'),
            ]) > 0;
    }

    /**
     * 🔴 A CHAMADA CABE DENTRO DA JANELA DA RECONCILIAÇÃO?
     *
     * `Fila::reconciliar` encerra Ligação aberta há mais de `limite_ligacao_seg` e devolve
     * o Lead à Fila, porque a única prova que ela tem de que o evento de AMI se perdeu é o
     * tempo. Chamada VIVA que passa do limite é declarada morta, e então:
     *
     * · o canal é dado como livre e o dialer origina um a mais do que o contratado;
     * · o Lead volta à Fila e é discado DE NOVO, para quem está na linha naquele instante;
     * · a tentativa é gasta duas vezes pela mesma conversa.
     *
     * O `fastagi`, ao terminar, sobrescreve o desfecho — então o dado se conserta e a
     * ligação duplicada não. Sem sintoma no banco, com sintoma para a pessoa.
     *
     * A conta é alcançável com valores que o próprio formulário aceita: cinco campos com
     * duas tentativas, prompt de 20 s e espera de 20 s passam de 400 segundos. Não é caso
     * adversarial — é uma Campanha de recadastramento comum.
     *
     * Recusar AQUI é onde o operador pode agir: ele vê quanto a chamada pode durar e o que
     * mudar. Descobrir isso pelo comportamento seria descobrir por reclamação de cliente.
     *
     * @param  array<string,mixed>  $c
     */
    private function faltaDeDuracao(int $id, array $c, bool $ehCaptura): ?string
    {
        $limite = self::inteiro(config('dialer.limite_ligacao_seg')) ?: 300;
        $toque = self::inteiro(config('dialer.timeout_toque_seg')) ?: 28;

        $conta = new DuracaoMaxima();
        $abertura = $this->duracaoDaAbertura();

        // 🔴 O PORTÃO VALE NOS DOIS TIPOS, e a captura não o passava.
        //
        // `Fluxo::captura` chama `portao()` antes das perguntas desde que pedir CPF sem
        // Opt-in foi reconhecido como pedir documento sem autorização. Aqui a captura
        // seguia chamando `DuracaoMaxima::captura` só com os campos, e o custo do portão
        // — mensagem × tentativas da URA, "não entendi" entre elas, confirmação — não
        // entrava na conta que decide se a Campanha pode ir ao ar.
        $mensagem = $this->duracaoDoAudio(self::inteiro($c['audio_mensagem_id'] ?? null));
        $tentativasUra = max(1, self::inteiro($c['tentativas_ura'] ?? null));
        $naoEntendi = $this->duracaoDoAudio(self::inteiro($c['audio_nao_entendi_id'] ?? null));

        // `max` das duas confirmações: qual delas toca depende da tecla, e a conta é do
        // PIOR caso. A de Opt-in é a que continua para os campos, mas errar para cima é o
        // lado seguro — subestimar deixa passar a Campanha que estoura a reconciliação.
        $confirmacao = max(
            $this->duracaoDoAudio(self::inteiro($c['audio_confirma_opt_in_id'] ?? null)),
            $this->duracaoDoAudio(self::inteiro($c['audio_confirma_opt_out_id'] ?? null)),
        );

        if ($ehCaptura) {
            $pior = $conta->captura(
                timeoutToqueSeg: $toque,
                aberturaSeg: $abertura,
                campos: $this->camposParaConta($id),
                mensagemSeg: $mensagem,
                tentativasUra: $tentativasUra,
                naoEntendiSeg: $naoEntendi,
                confirmacaoSeg: $confirmacao,
            );
        } else {
            $pior = $conta->optIn(
                timeoutToqueSeg: $toque,
                aberturaSeg: $abertura,
                mensagemSeg: $mensagem,
                tentativasUra: $tentativasUra,
                naoEntendiSeg: $naoEntendi,
                confirmacaoSeg: $confirmacao,
            );
        }

        if ($pior <= $limite) {
            return null;
        }

        return sprintf(
            'a chamada pode durar até %d s e a reconciliação encerra em %d s — '
            . 'ela discaria de novo para quem ainda está na linha. '
            . 'Reduza %s, ou aumente PROSPECTA_LIMITE_LIGACAO_SEG',
            $pior,
            $limite,
            $ehCaptura
                ? 'os campos, as tentativas ou a espera de cada um'
                : 'as tentativas na URA ou a duração do áudio',
        );
    }

    /**
     * @return list<array{tentativas:int, timeoutSeg:int, promptSeg:float}>
     */
    private function camposParaConta(int $campanhaId): array
    {
        $saida = [];

        foreach (DB::select(
            'SELECT cc.tentativas, cc.timeout_seg, a.duracao_seg
               FROM campanha_campo cc
               LEFT JOIN audio a ON a.id = cc.audio_prompt_id
              WHERE cc.campanha_id = ?
              ORDER BY cc.ordem, cc.id',
            [$campanhaId],
        ) as $linha) {
            $cc = (array) $linha;
            $saida[] = [
                'tentativas' => max(1, self::inteiro($cc['tentativas'] ?? null)),
                'timeoutSeg' => max(1, self::inteiro($cc['timeout_seg'] ?? null)),
                'promptSeg' => (float) self::inteiro($cc['duracao_seg'] ?? null),
            ];
        }

        return $saida;
    }

    /**
     * Duração do áudio de abertura, DO DISCO.
     *
     * `Banco::carregar` passa `prospecta/abertura` fixo: a abertura não é escolhida por
     * Campanha e não tem linha em `audio`. Então não há duração gravada para consultar —
     * ela sai do tamanho do arquivo, que num `.alaw` é exato: 8000 bytes por segundo.
     *
     * Ausente, cai no limite que o `audios/README.md` impõe (~1 s). Assumir zero
     * subestimaria a conta, e esta conta erra para cima de propósito.
     */
    private function duracaoDaAbertura(): float
    {
        $arquivo = self::texto(config('prospecta.audio_path')) . '/abertura.alaw';

        if (! is_file($arquivo)) {
            return self::ABERTURA_MAXIMA_SEG;
        }

        return (new Alaw())->duracaoSeg((int) filesize($arquivo));
    }

    /** O limite duro da abertura, do `audios/README.md`: "~1 s, não mais". */
    private const ABERTURA_MAXIMA_SEG = 1.5;

    /**
     * Duração gravada do áudio, ou zero.
     *
     * Zero quando não há áudio é o certo para ESTA conta: áudio ausente não toca, então
     * não consome tempo. A falta dele é recusada por outra guarda, com a causa dela.
     */
    private function duracaoDoAudio(int $audioId): float
    {
        if ($audioId === 0) {
            return 0.0;
        }

        return (float) self::inteiro(DB::table('audio')->where('id', $audioId)->value('duracao_seg'));
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

        if ($arquivo === '') {
            return sprintf('o áudio "%s" não tem arquivo no cadastro (AUDIO_MISSING)', $nome);
        }

        // 🔴 "NÃO CONSIGO LER" NÃO É "NÃO EXISTE", e aqui a diferença bloqueia o operador.
        //
        // Medido nesta máquina: os áudios são `root:asterisk` modo 0640 e o php-fpm roda
        // como `prospecta`. Sem o grupo `asterisk` nos workers EM EXECUÇÃO — grupo
        // suplementar é fixado no `exec`, então adesão concedida depois de o serviço subir
        // não vale —, `is_file()` devolve false para arquivo presente.
        //
        // O efeito não era cosmético: esta função roda na ATIVAÇÃO, então toda Campanha era
        // recusada com "o arquivo não está no disco", acusando ausência de um arquivo que
        // estava lá. O operador não tinha como chegar na causa a partir dessa frase.
        //
        // A checagem do diretório vem PRIMEIRO porque ela explica o resto: sem travessia,
        // nada que se afirme sobre os arquivos dentro dele vale.
        if (! $this->diretorioDeAudioAcessivel($diretorio)) {
            return sprintf(
                'não consigo ler %s — o usuário do php-fpm precisa estar no grupo do '
                . 'diretório de áudios, e o serviço precisa ser reiniciado depois de '
                . 'entrar no grupo (AUDIO_SEM_ACESSO)',
                $diretorio,
            );
        }

        if (! is_file($caminho)) {
            return sprintf('o arquivo do áudio "%s" não está no disco (AUDIO_MISSING)', $nome);
        }

        // Arquivo presente e ilegível é caso próprio: o diretório abre, este arquivo não.
        // O `Playback` do Asterisk roda como `asterisk` e talvez consiga — então isto é
        // aviso sobre a nossa conferência, não prova de que a chamada sairia muda.
        if (! is_readable($caminho)) {
            return sprintf(
                'o arquivo do áudio "%s" existe e não posso lê-lo, então não consigo '
                . 'conferir o formato (AUDIO_SEM_ACESSO)',
                $nome,
            );
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
     * O painel atravessa o diretório de áudios?
     *
     * Diretório INEXISTENTE responde `true` de propósito: aí o problema é outro — não há
     * áudio nenhum instalado —, e a falta de cada arquivo é dita uma por uma, com a causa
     * dela. Confundir "não instalado" com "sem permissão" mandaria o operador mexer em
     * grupo de usuário quando o que falta é copiar arquivo.
     */
    private function diretorioDeAudioAcessivel(string $diretorio): bool
    {
        return (new SituacaoDoArquivo())->diretorioAcessivel($diretorio);
    }

    private static function inteiro(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
