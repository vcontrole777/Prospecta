<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Audio\Alaw;
use App\Dominio\CaminhoDeGravacao;
use App\Dominio\DuracaoMaxima;
use App\Dominio\SituacaoDoArquivo;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

/**
 * Home do painel: uma linha por Campanha, com contadores ao vivo.
 *
 * 🔴 AGREGADO, não linha-a-linha. O ADR-0008 marcou a tela de chamadas ao vivo
 * como o ponto duro do produto: `wire:poll` de 1 s sobre 200 Ligações ativas
 * re-renderiza 200 linhas por segundo. Esta tela não resolve esse problema — ela
 * NÃO O TEM. O que o operador acompanha de minuto a minuto são ~8 números por
 * Campanha, e isso é uma consulta agregada barata, independente do número de
 * canais contratados.
 *
 * A tabela de Ligações ao vivo é outra tela, e é lá que o problema mora.
 */
final class Campanhas extends Component
{
    public function render(): View
    {
        return view('livewire.campanhas', ['campanhas' => $this->resumo()]);
    }

    /**
     * Põe a Campanha no ar. O dialer só olha `status = 1`.
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
     */
    public function iniciar(int $id): void
    {
        $linha = DB::table('campanha')->where('id', $id)->first();
        if ($linha === null) {
            return;
        }
        $c = (array) $linha;

        $faltas = [];

        // Status 1 é disponível e 4 é aguardando a próxima rodada — as duas contam,
        // porque o dialer abre a rodada seguinte sozinho.
        $disponiveis = DB::table('leads')
            ->where('campanha_id', $id)
            ->whereIn('status', [1, 4])
            ->count();

        if ($disponiveis === 0) {
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

        if ($faltas !== []) {
            session()->flash('recusa', 'Não iniciei: ' . implode('; ', $faltas) . '.');

            return;
        }

        // `COALESCE` preserva a primeira ativação: retomar uma Campanha pausada não
        // deveria reescrever quando ela começou.
        DB::table('campanha')
            ->where('id', $id)
            ->whereIn('status', [0, 2])
            ->update([
                'status' => 1,
                'iniciada_em' => DB::raw('COALESCE(iniciada_em, NOW())'),
            ]);

        session()->flash('recado', 'Campanha no ar.');
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

    /**
     * Campanha do zero: devolve TODOS os números à fila, APAGA o histórico e põe no ar.
     *
     * 🔴 SÓ OPT-IN E OPT-OUT FICAM DE FORA, e essa é a regra inteira.
     *
     * `opt_out` é obrigatório: a pessoa pediu para não ser contatada. O dialer já a
     * barraria pela Lista de bloqueio (`Dialer\Fila` consulta na discagem), mas repor o
     * Lead só para ele ser descartado adiante esconde a intenção no código e infla a fila
     * com trabalho que não existe.
     *
     * `opt_in` fica de fora porque já converteu: ligar de novo para quem disse sim é gastar
     * canal com quem já está do outro lado do funil — e, para o Lead, é a empresa que não
     * percebeu que ele já respondeu.
     *
     * 🔴 `captura_concluida` e `captura_parcial` VOLTAM, e isto MUDOU. Antes ficavam de
     * fora com o argumento de que "atendeu e falou" não é "não conseguiu falar". Reiniciar
     * deixou de ser "recuperar quem escapou" e passou a ser "rodar a Campanha de novo", e
     * nessa leitura recadastramento é para todo mundo menos quem converteu ou recusou.
     *
     * A fonte da verdade sobre Opt-in é `leads.opt_in_em`, NÃO `ligacao.desfecho`: o
     * histórico é apagado logo abaixo, e ler a prova de uma tabela que este mesmo método
     * esvazia faria o segundo reinício rediscar quem o primeiro poupou. Ver a migration
     * `leads_guarda_opt_in`. O Opt-out não tem esse problema — `bloqueio` é global e
     * permanente — e cobre também o Opt-out que veio por WhatsApp, sem Ligação nenhuma.
     *
     * `leads.status`: 1 disponível · 2 reivindicado (em voo) · 3 terminal · 4 aguardando a
     * próxima rodada. Reiniciar leva 1, 3 e 4 para disponível com o `try` zerado.
     *
     * O 1 entra na conta de propósito, e não é redundância: um Lead disponível pode estar
     * no MEIO do ciclo de tentativas, devolvido à fila pelo dialer com `try` já em 2 de 3.
     * Deixá-lo intacto faria a rodada nova começar com ele quase esgotado, enquanto os
     * vizinhos recomeçam com o crédito cheio — mesma Campanha, dois comportamentos, e a
     * diferença invisível na tela.
     *
     * 🔴 NÃO TOCA EM STATUS 2, nem no Lead nem na Ligação dele. Aquele Lead está com uma
     * Ligação EM CURSO — o dialer o reivindicou e o fastagi ainda vai gravar o desfecho.
     * Devolvê-lo faria o mesmo número ser discado enquanto a pessoa está na linha, e apagar
     * a linha da Ligação faria o `UPDATE` do fastagi acertar zero linhas em silêncio,
     * perdendo o desfecho de uma conversa que aconteceu.
     *
     * 🔴 APAGA O HISTÓRICO, e isto MUDOU — era o contrário, com o argumento de que o
     * histórico é o produto. Passa a ser decisão do operador: reiniciar é começar de novo,
     * com os contadores da tela em zero. É IRREVERSÍVEL e leva junto desfechos, dados
     * capturados e GRAVAÇÕES. Quem precisa do que foi apurado exporta antes, na tela de
     * Resultados; o texto de confirmação diz isso.
     *
     * 🔴 PÕE NO AR SOZINHO, e isto MUDOU. Passa pelo `iniciar()` normal, não por um atalho:
     * as guardas de ativação — áudio no disco, template, duração que cabe na reconciliação
     * — são o que separa "discou" de "discou e não fez nada", e um caminho paralelo que as
     * pulasse seria a forma mais cara de descobrir isso. Recusa vira recado na tela, com a
     * fila já reposta.
     */
    public function reiniciar(int $id): void
    {
        $atual = DB::table('campanha')->where('id', $id)->value('status');
        if ($atual === null) {
            return;
        }

        // 🔴 RECUSA COM A CAMPANHA NO AR. O botão já não aparece nesse estado, mas a
        // guarda mora aqui: repor a fila enquanto o dialer trabalha faria a mesma pessoa
        // receber duas ligações da mesma rodada, e agora há apagamento no meio.
        if (self::inteiro($atual) === 1) {
            session()->flash('recusa', 'Não reiniciei: a Campanha está no ar. Pause antes.');

            return;
        }

        // Carimba o Opt-in no Lead ANTES de apagar a Ligação que o prova. É idempotente e
        // cobre o histórico que existe hoje; daqui para a frente quem carimba é o fastagi.
        DB::update(<<<'SQL'
            UPDATE leads le
              JOIN ligacao l ON l.lead_id = le.id
               SET le.opt_in_em = COALESCE(le.opt_in_em, l.encerrada_em, l.iniciada_em)
             WHERE le.campanha_id = ? AND l.desfecho = 'opt_in'
        SQL, [$id]);

        $gravacoes = $this->apagarGravacoes($id);
        if ($gravacoes === null) {
            return;   // diretório cego: a recusa já foi para a tela, nada foi tocado
        }

        [$apagadas, $retidas] = $gravacoes;

        // Contadores por referência, e não pelo retorno da transação: `DB::transaction`
        // devolve `mixed`, e ler `$contas['repostos']` de um mixed é exatamente o tipo
        // solto que a análise estática cobra — com razão, porque o recado da tela sai daqui.
        $repostos = 0;
        $ligacoes = 0;
        $disparos = 0;

        DB::transaction(function () use ($id, $retidas, &$repostos, &$ligacoes, &$disparos): void {
            // 🔴 As Ações ANTES das Ligações: a subconsulta lê `ligacao`, e apagar a
            // Ligação primeiro deixaria o Disparo pendente órfão. `FilaDeAcoes` reivindica
            // com `JOIN ligacao` — sem a Ligação, a linha some da fila e fica `pendente`
            // para sempre, invisível, exatamente como o `evento_captura_concluida` que a
            // migration de `acao` conta como lição aprendida.
            $disparos = DB::table('acao')
                ->where('tipo', 'disparo')
                ->where('status', 'pendente')
                ->whereIn('referencia_id', $this->ligacoesAApagar($id, $retidas)->select('id'))
                ->count();

            DB::table('acao')
                ->where('tipo', 'disparo')
                ->whereIn('referencia_id', $this->ligacoesAApagar($id, $retidas)->select('id'))
                ->delete();

            $ligacoes = $this->ligacoesAApagar($id, $retidas)->delete();

            $repostos = DB::table('leads')
                ->where('campanha_id', $id)
                ->whereIn('status', [1, 3, 4])
                ->whereNull('opt_in_em')
                ->whereNotExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('bloqueio as b')
                        ->whereColumn('b.telefone', 'leads.telefone');
                })
                ->update(['status' => 1, 'try' => 0]);

            // `rodada_atual` e `iniciada_em` fazem parte do histórico: deixá-los seria a
            // tela dizer "rodada 4, começou em março" ao lado de zero ligações.
            DB::table('campanha')->where('id', $id)->update([
                'rodada_atual' => 0,
                'iniciada_em' => null,
            ]);

            // Concluída volta a PAUSADA, não direto ao ar: quem põe no ar é o `iniciar()`
            // logo abaixo, com as guardas. E se ele recusar, a Campanha fica num estado que
            // a tela sabe oferecer — sem isso ela ficaria concluída, sem histórico e sem
            // botão nenhum, encalhada.
            DB::table('campanha')->where('id', $id)->where('status', 3)->update(['status' => 2]);
        });

        // Recusa velha, de outro clique, ainda está na sessão nesta volta. Sem o
        // `forget` ela seria lida abaixo como se fosse a resposta do `iniciar()` de agora.
        session()->forget('recusa');
        session()->forget('recado');

        $this->iniciar($id);

        $resumo = sprintf(
            'Reiniciei: %d número(s) de volta na fila, %d ligação(ões) e %d gravação(ões) apagadas.',
            $repostos,
            $ligacoes,
            $apagadas,
        );

        if ($disparos > 0) {
            $resumo .= sprintf(
                ' %d disparo(s) de WhatsApp ainda pendentes foram descartados junto com as Ligações.',
                $disparos,
            );
        }

        if ($retidas !== []) {
            $resumo .= sprintf(
                ' %d ligação(ões) foram MANTIDAS porque não consegui apagar a gravação delas do'
                . ' disco — apagar a linha deixaria o arquivo lá para sempre, fora do alcance do'
                . ' expurgo.',
                count($retidas),
            );
        }

        $recusa = session()->get('recusa');

        if (is_string($recusa) && $recusa !== '') {
            session()->flash('recusa', $resumo . ' ' . $recusa);
        } else {
            session()->flash('recado', $resumo . ' Campanha no ar.');
        }

        $this->dispatch(
            'campanha-reiniciada',
            repostos: $repostos,
            ligacoes: $ligacoes,
            gravacoes: $apagadas,
        );
    }

    /**
     * As Ligações que o reinício apaga.
     *
     * Tudo da Campanha MENOS as de Lead em voo — ver o 🔴 de `reiniciar` — e menos as que
     * ficaram retidas por gravação que não pôde ser apagada.
     *
     * `whereNotExists` e não `whereNotIn('lead_id', ...)`: Ligação com `lead_id` NULL
     * existe (o Lead foi apagado, e a FK é `nullOnDelete`), e `NULL NOT IN (...)` é NULL,
     * que o SQL trata como falso — ela escaparia do apagamento sem ninguém notar.
     *
     * @param  list<int>  $retidas
     */
    private function ligacoesAApagar(int $campanhaId, array $retidas): \Illuminate\Database\Query\Builder
    {
        return DB::table('ligacao')
            ->where('campanha_id', $campanhaId)
            ->whereNotIn('id', $retidas)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('leads as le')
                    ->whereColumn('le.id', 'ligacao.lead_id')
                    ->where('le.status', 2);
            });
    }

    /**
     * Apaga do disco as gravações que vão embora com o histórico.
     *
     * 🔴 O ARQUIVO PRIMEIRO, A LINHA DEPOIS — a mesma ordem do `bin/expurgo.php`, e pela
     * mesma razão. Nesta ordem, morrer no meio deixa linha apontando para arquivo ausente,
     * que o expurgo reencontra e limpa. Na ordem inversa deixa arquivo no disco sem
     * nenhuma linha apontando para ele: vazamento permanente, invisível, que só um `find`
     * manual acharia. Num produto de uma VPS por cliente, o que enche é o disco — e é o
     * mesmo disco em que o Asterisk grava.
     *
     * Por isso o que falhar aqui RETÉM a Ligação em vez de seguir adiante: manter a linha é
     * o que mantém o arquivo alcançável.
     *
     * @return array{0:int, 1:list<int>}|null  [apagadas, ligações retidas]; `null` quando
     *                                          recusei mexer em qualquer coisa
     */
    private function apagarGravacoes(int $campanhaId): ?array
    {
        $configurado = config('fastagi.gravacao_dir');
        $base = is_string($configurado) ? rtrim($configurado, '/') : '';

        $comGravacao = $this->ligacoesAApagar($campanhaId, [])->whereNotNull('gravacao')->count();

        if ($comGravacao === 0) {
            return [0, []];
        }

        /*
         * 🔴 ABORTA SE NÃO ATRAVESSAR O DIRETÓRIO, e é a guarda que impede o pior caso.
         *
         * Sem ela: sem permissão no diretório, `is_file()` responde `false` para TODO
         * arquivo; o laço abaixo conta cada um como "já ausente", nada é apagado e as
         * linhas somem mesmo assim. Toda gravação da Campanha ficaria no disco sem linha
         * apontando para ela, em silêncio e para sempre — o expurgo só conhece o que está
         * no banco. Medido nesta máquina: o diretório é `asterisk:asterisk` modo 2770 e o
         * php-fpm roda como `prospecta`; basta a adesão ao grupo faltar.
         *
         * A condição é GLOBAL — se vale, toda decisão desta passada estaria errada do mesmo
         * jeito —, então recuso a operação inteira em vez de pular linha a linha. Diretório
         * INEXISTENTE não é anomalia: é instância sem gravação ligada, e aí os caminhos no
         * banco só apontam para o que não existe mais.
         */
        if ($base === '') {
            session()->flash('recusa', 'Não reiniciei: PROSPECTA_GRAVACAO_DIR não está configurado e '
                . 'esta Campanha tem gravações — eu apagaria as linhas e deixaria os arquivos no disco.');

            return null;
        }

        if (is_dir($base) && (! is_readable($base) || ! is_executable($base))) {
            session()->flash('recusa', sprintf(
                'Não reiniciei: sem acesso a %s, e esta Campanha tem gravações. Eu apagaria as '
                . 'linhas sem conseguir apagar os arquivos, e eles ficariam no disco para sempre. '
                . 'O usuário do painel precisa estar no grupo do diretório.',
                $base,
            ));

            return null;
        }

        $caminho = new CaminhoDeGravacao();
        $apagadas = 0;
        $retidas = [];

        $this->ligacoesAApagar($campanhaId, [])
            ->whereNotNull('gravacao')
            ->select('id', 'gravacao')
            ->orderBy('id')
            ->chunk(500, function ($linhas) use (&$apagadas, &$retidas, $caminho, $base): void {
                foreach ($linhas as $linha) {
                    $l = (array) $linha;
                    $ligacao = self::inteiro($l['id'] ?? null);
                    $arquivo = self::texto($l['gravacao'] ?? null);

                    if ($ligacao === 0 || $arquivo === '') {
                        continue;
                    }

                    // Contenção antes de apagar (ADR-0003). Hoje o caminho é derivado de um
                    // inteiro pelo nosso código e não pode escapar; a checagem existe para
                    // que continue assim quando isso mudar — uma linha com
                    // `/etc/asterisk/pjsip.conf` na coluna bastaria.
                    if (! $caminho->contido($base, $arquivo)) {
                        $retidas[] = $ligacao;
                        Log::warning('gravação fora do diretório base; não apago e mantenho a Ligação', [
                            'ligacao' => $ligacao,
                            'caminho' => $arquivo,
                        ]);
                        continue;
                    }

                    // Ausente é caso normal, não anomalia: o expurgo já passou por aqui.
                    if (! is_file($arquivo)) {
                        continue;
                    }

                    if (! unlink($arquivo)) {
                        $retidas[] = $ligacao;
                        Log::warning('não consegui apagar a gravação; mantenho a Ligação', [
                            'ligacao' => $ligacao,
                            'caminho' => $arquivo,
                        ]);
                        continue;
                    }

                    $apagadas++;
                }
            });

        return [$apagadas, $retidas];
    }

    /**
     * Pausa. O `where` no status evita pausar Campanha já concluída, o que a
     * ressuscitaria como pausável e faria o dialer reavaliá-la sem motivo.
     */
    public function pausar(int $id): void
    {
        DB::table('campanha')->where('id', $id)->where('status', 1)->update(['status' => 2]);

        session()->flash('recado', 'Campanha pausada.');
    }

    /**
     * @return list<array<string,int|string|null>>
     */
    private function resumo(): array
    {
        // 🔴 DUAS consultas de propósito, não uma com dois LEFT JOIN.
        //
        // Juntar `leads` E `ligacao` no mesmo GROUP BY faz produto cartesiano —
        // cada Lead multiplicado por cada Ligação da mesma Campanha — e TODA
        // contagem sai inflada pelo fator do outro lado. O sintoma é a pior
        // espécie: uma tela plausível, com números errados por um múltiplo que
        // muda conforme a Campanha avança. Ninguém desconfia de um total que
        // apenas parece alto.
        $porCampanha = DB::select(
            'SELECT c.id, c.nome, c.tipo, c.status, c.bina_regra, c.rodada_atual,
                    c.max_tentativas, c.chamadas_simultaneas,
                    COUNT(l.id)                    AS leads,
                    COALESCE(SUM(l.status = 1), 0) AS disponiveis,
                    COALESCE(SUM(l.status = 2), 0) AS em_voo,
                    COALESCE(SUM(l.status = 4), 0) AS aguardando,
                    COALESCE(SUM(l.status = 3), 0) AS finalizados
               FROM campanha c
               LEFT JOIN leads l ON l.campanha_id = c.id
              GROUP BY c.id
              ORDER BY c.status = 1 DESC, c.id DESC'
        );

        $ligacoes = [];
        foreach (DB::select(
            "SELECT campanha_id,
                    COUNT(*)                                  AS ligacoes,
                    COALESCE(SUM(atendida_em IS NOT NULL), 0) AS atendidas,
                    COALESCE(SUM(desfecho = 'opt_in'), 0)     AS opt_in,
                    COALESCE(SUM(desfecho = 'maquina'), 0)    AS maquina,
                    COALESCE(SUM(desfecho = 'erro'), 0)       AS erros
               FROM ligacao
              GROUP BY campanha_id"
        ) as $linha) {
            $l = (array) $linha;
            $ligacoes[self::inteiro($l['campanha_id'] ?? null)] = $l;
        }

        $saida = [];
        foreach ($porCampanha as $linha) {
            $c = (array) $linha;
            $id = self::inteiro($c['id'] ?? null);
            $g = $ligacoes[$id] ?? [];

            $discadas = self::inteiro($g['ligacoes'] ?? null);
            $atendidas = self::inteiro($g['atendidas'] ?? null);

            $saida[] = [
                'id' => $id,
                'nome' => self::texto($c['nome'] ?? null),
                'tipo' => self::texto($c['tipo'] ?? null),
                'status' => self::inteiro($c['status'] ?? null),
                'bina_regra' => self::texto($c['bina_regra'] ?? null),
                'rodada' => self::inteiro($c['rodada_atual'] ?? null),
                'max_tentativas' => self::inteiro($c['max_tentativas'] ?? null),
                'simultaneas' => self::inteiro($c['chamadas_simultaneas'] ?? null),
                'leads' => self::inteiro($c['leads'] ?? null),
                'disponiveis' => self::inteiro($c['disponiveis'] ?? null),
                'em_voo' => self::inteiro($c['em_voo'] ?? null),
                'aguardando' => self::inteiro($c['aguardando'] ?? null),
                'finalizados' => self::inteiro($c['finalizados'] ?? null),
                'discadas' => $discadas,
                'atendidas' => $atendidas,
                'opt_in' => self::inteiro($g['opt_in'] ?? null),
                'maquina' => self::inteiro($g['maquina'] ?? null),
                'erros' => self::inteiro($g['erros'] ?? null),
                // Taxa de atendimento em pontos percentuais inteiros. `null`
                // quando não houve discagem: 0% e "ainda não discou" são coisas
                // diferentes, e mostrar 0% numa Campanha recém-criada faria a
                // tela dizer que a lista é ruim antes de qualquer chamada.
                'taxa' => $discadas > 0 ? (int) round($atendidas / $discadas * 100) : null,
            ];
        }

        return $saida;
    }

    /**
     * `DB::select` devolve `array` de `stdClass` sem tipo por campo, e o driver
     * do MySQL entrega COUNT/SUM como string. Estreitar é honesto; `(int)` cru
     * sobre `mixed` esconderia o dia em que a consulta mudar de forma.
     */
    private static function inteiro(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
