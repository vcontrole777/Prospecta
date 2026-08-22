<?php

declare(strict_types=1);

namespace App\Fastagi;

use App\Dominio\Cpf;

/**
 * FASE 3 do handler: toca áudio e coleta DTMF. Zero banco.
 *
 * É a fase LONGA — e é justamente por isso que a conexão de banco foi solta
 * antes dela (ADR-0001). Uma Ligação de 40 s não deve segurar conexão.
 *
 * Pura em relação ao banco de propósito: recebe `Dados` já carregado e devolve
 * `Resultado`. É o que torna o caminho mais complexo do produto testável sem
 * Asterisk e sem MySQL.
 */
final class Fluxo
{
    public function __construct(
        private Agi $agi,
        private Registro $log,
        private Cpf $cpf = new Cpf(),
    ) {
    }

    /**
     * Recebe o `Resultado` em vez de criar: se o Lead desligar no meio, o
     * chamador já tem o estado parcial e a FASE 4 grava o que se sabe. Criar
     * aqui perderia tudo na exceção — e o caso comum do produto é apertar a
     * tecla e desligar no mesmo instante.
     */
    public function executar(Dados $d, Resultado $r): Resultado
    {
        $this->agi->atender();
        $r->atendida = true;
        $r->atendidaEm = time();
        $this->log->linha('atendeu', 'canal atendido');

        // Gravação a partir do ANSWER. Caminho derivado do id INTEIRO da
        // Ligação — ADR-0003 fecha a classe de injeção por construção.
        // SEM a opção `b`: medido que ela significa "só gravar em ponte", e a
        // Ligação do produto nunca tem ponte. Com `b`, arquivo de 44 bytes.
        if ($d->gravacaoAtiva) {
            $arquivo = rtrim($d->gravacaoDir, '/') . '/' . $d->ligacaoId . '.gsm';
            $this->agi->executar('MixMonitor', $arquivo);
            $r->gravacao = $arquivo;
            $this->log->linha('gravacao', 'MixMonitor iniciado', ['arquivo' => $arquivo]);
        }

        // Abertura de ~1 s antes do AMD. Dois propósitos: elimina o ar morto que
        // o Lead ouviria durante a análise, e PROVOCA a fala de quem atendeu
        // calado — que é o modo de falha medido do classificador.
        if (! $this->tocar($d->audioAbertura, $r)) {
            return $r;
        }

        if (! $this->classificar($d, $r)) {
            return $r;
        }

        // O AMD declara humano ~800 ms depois da primeira palavra: a pessoa
        // ainda está dizendo "alô?" quando o áudio começaria.
        $this->agi->executar('WaitForSilence', '500', '1', '2');

        return $d->tipo === 'captura'
            ? $this->captura($d, $r)
            : $this->optIn($d, $r);
    }

    /**
     * @return bool false quando o fluxo deve terminar
     */
    private function classificar(Dados $d, Resultado $r): bool
    {
        /*
         * 🔴 DESLIGADO SEGUE COMO HUMANO, e deixa `amd_status` NULO.
         *
         * Nulo é o registro honesto de "não classificamos", e é distinto de `NOTSURE`,
         * que significa "classificamos e não deu para decidir". Gravar NOTSURE aqui
         * misturaria as duas coisas na mesma coluna e estragaria justamente a análise
         * que o classificador existe para alimentar — a comparação entre baldes.
         *
         * E o desfecho `maquina` deixa de ser possível enquanto isto estiver desligado.
         * É consequência esperada, não perda: sem classificar, não há como afirmar que
         * atendeu máquina, e afirmar assim mesmo seria inventar dado.
         */
        if (! $d->classificadorPosAtivo) {
            $this->log->linha('amd', 'Pós-atendimento desligado na configuração; seguindo como humano');

            return true;
        }

        $this->agi->executar('AMD', ...$d->pos->argumentos());
        $r->amdStatus = $this->agi->variavel('AMDSTATUS');
        $r->amdCause = $this->agi->variavel('AMDCAUSE');
        $this->log->linha('amd', 'classificado', ['status' => $r->amdStatus, 'causa' => $r->amdCause]);

        // HANGUP NÃO é humano: o Lead desligou durante a análise. O
        // MagnusBilling joga todo o não-MACHINE no ramo humano, grava
        // "atendida por humano" e toca áudio em canal morto.
        if ($r->amdStatus === 'HANGUP') {
            $r->desfecho = 'nao_atendeu';

            return false;
        }

        if ($r->amdStatus === 'MACHINE') {
            $r->desfecho = 'maquina';

            return false;
        }

        // NOTSURE segue como humano DE PROPÓSITO. Os dois erros não custam o
        // mesmo: falso-HUMANO gasta segundos de áudio numa secretária;
        // falso-MÁQUINA desliga na cara de quem atendeu, e é irreversível.
        return true;
    }

    private function optIn(Dados $d, Resultado $r): Resultado
    {
        $this->portao($d, $r);

        return $r;
    }

    /**
     * Toca a Mensagem e lê a tecla. É a URA de opt-in, e agora ela é COMPARTILHADA.
     *
     * 🔴 A CAPTURA TAMBÉM PASSA POR AQUI, e antes não passava.
     *
     * `captura()` ia direto ao prompt do primeiro campo: a pessoa atendia e ouvia "digite
     * seu CPF" sem nunca ter ouvido a oferta nem ter concordado com nada. Além de pedir
     * documento a quem não autorizou, isso arruína a taxa — ninguém digita CPF para uma
     * voz que não se apresentou.
     *
     * O fluxo certo, e o que este método impõe aos dois tipos: Mensagem → tecla de
     * Opt-in → só então as perguntas.
     *
     * Extraído em vez de copiado porque a cortesia do `naoentendi`, a contagem de
     * tentativas e o tratamento de Opt-out são regra sutil que já estava certa aqui.
     * Duplicar significaria consertar duas vezes daqui em diante.
     *
     * @return bool true quando houve Opt-in — o único caso em que a captura prossegue
     */
    private function portao(Dados $d, Resultado $r): bool
    {
        // 🔴 RECUSA em vez de discar mudo.
        //
        // `audioMensagem` é nulo quando a Campanha não tem áudio E a configuração global
        // não tem o genérico. Antes havia um `'prospecta/mensagem'` fixo no lugar — um
        // arquivo que o código referenciava e o provisionamento não instalava —, e o
        // efeito era `lerDigitos` sem prompt: a pessoa ouvia SILÊNCIO por 8 segundos e o
        // desfecho virava `sem_resposta`, indistinguível de quem não quis responder.
        //
        // A guarda do painel impede isso para quem passa pela tela; Campanha ativada por
        // SQL não passa. Causa nomeada aqui é o que torna o problema legível.
        if ($d->audioMensagem === null) {
            $this->log->linha('audio', 'AUDIO_NAO_CONFIGURADO', ['ligacao' => $d->ligacaoId]);
            $r->desfecho = 'erro';
            $r->erro = 'AUDIO_NAO_CONFIGURADO';

            return false;
        }

        for ($tentativa = 1; $tentativa <= max(1, $d->tentativasUra); $tentativa++) {
            $tecla = $this->agi->lerDigitos($d->audioMensagem, 1, 8000);
            $this->log->linha('tecla', 'dígito lido', ['tentativa' => $tentativa, 'tecla' => $tecla]);

            if ($tecla === '') {
                if ($tentativa < $d->tentativasUra && $d->audioNaoEntendi !== null) {
                    // Cortesia: a pessoa não digitou nada, e falhar a chamada por causa
                    // do aviso seria punir quem já estava tentando.
                    $this->tocarCortesia($d->audioNaoEntendi, $r);
                    continue;
                }
                $r->desfecho = 'sem_resposta';

                return false;
            }

            $r->teclaDigitada = $tecla;

            if ($d->teclaOptIn !== null && $tecla === $d->teclaOptIn) {
                $r->desfecho = 'opt_in';
                $this->tocarCortesia($d->audioConfirmaOptIn, $r);

                return true;
            }

            if ($d->teclaOptOut !== null && $tecla === $d->teclaOptOut) {
                $r->desfecho = 'opt_out';
                $this->tocarCortesia($d->audioConfirmaOptOut, $r);

                return false;
            }

            if ($tentativa < $d->tentativasUra) {
                $this->tocarCortesia($d->audioNaoEntendi, $r);
                continue;
            }

            $r->desfecho = 'invalido';

            return false;
        }

        $r->desfecho = 'sem_resposta';

        return false;
    }

    private function captura(Dados $d, Resultado $r): Resultado
    {
        // 🔴 CONFERE TODOS OS PROMPTS ANTES DE PERGUNTAR QUALQUER COISA.
        //
        // Campo sem áudio de prompt fazia o `GET DATA` tocar um caminho fabricado —
        // `prospecta/campo`, arquivo que ninguém instala — e a pessoa ouvia SILÊNCIO até
        // o timeout, "errava" por não ter digitado, e o campo era descartado. Mesma
        // classe do `prospecta/mensagem`.
        //
        // A conferência é ANTES do laço, e não campo a campo, para não coletar meio
        // formulário: perguntar o CPF, gravar, e só então descobrir que o segundo campo
        // não tem prompt deixaria a pessoa tendo digitado por nada.
        foreach ($d->campos as $campo) {
            if ($campo['audio'] === null) {
                $this->log->linha('audio', 'AUDIO_NAO_CONFIGURADO', ['campo' => $campo['nome']]);
                $r->desfecho = 'erro';
                $r->erro = 'AUDIO_NAO_CONFIGURADO';

                return $r;
            }
        }

        /*
         * 🔴 O PORTÃO VEM ANTES DAS PERGUNTAS, e depois da conferência dos prompts.
         *
         * Depois porque campo sem áudio tem que abortar sem gastar a Mensagem nem o
         * tempo da pessoa. Antes das perguntas porque pedir CPF a quem não ouviu a
         * oferta e não apertou nada é pedir documento sem autorização — e, na prática,
         * ninguém digita.
         *
         * Só `opt_in` prossegue. `opt_out`, `sem_resposta` e `invalido` já vêm com o
         * desfecho gravado pelo portão, e são desfecho final: quem pediu para não ser
         * contatado não passa a ser interrogado em seguida.
         */
        if (! $this->portao($d, $r)) {
            return $r;
        }

        /*
         * 🔴 SAI DO `opt_in` ASSIM QUE O PORTÃO ABRE, antes de perguntar qualquer coisa.
         *
         * O portão grava `opt_in` como valor TRANSITÓRIO, e quem o sobrescreve é o fim
         * deste método. Só que o fim não é alcançado quando o Lead desliga no meio: o
         * `lerCampo` lança `CanalMorto`, que sobe até o `fastagi.php` e vai direto para a
         * FASE 4 — corretamente, para não perder o que já se sabe, e por isso a correção
         * não é capturar a exceção aqui.
         *
         * O que se perdia era o resto: `Banco::gravar` decide TUDO por
         * `desfecho === 'opt_in'`, então cada desligamento no prompt do CPF gravava um
         * Desfecho que uma Campanha de captura não pode ter, criava uma Ação de Disparo
         * numa Campanha sem template — que nasce e morre em
         * `campanha_sem_template_ou_lead_sem_telefone` — e carimbava `leads.opt_in_em`,
         * aposentando para sempre quem não entregou dado nenhum. Medido em 21/08/2026:
         * 5 Leads aposentados para 1 captura boa.
         *
         * `captura_parcial` é o valor certo para o intervalo: o portão abriu, a coleta
         * começou e não terminou. É o mesmo desfecho que o campo obrigatório esgotado já
         * usa logo abaixo, e descreve o desligamento com a mesma honestidade.
         */
        $r->desfecho = 'captura_parcial';

        foreach ($d->campos as $campo) {
            // O prompt vai como argumento próprio: a conferência acima garantiu que
            // não é nulo, e passar explícito é o que torna a garantia legível.
            $valor = $this->lerCampo($campo, (string) $campo['audio'], $r);

            if ($valor === null) {
                if ($campo['obrigatorio']) {
                    $r->desfecho = 'captura_parcial';

                    return $r;
                }
                continue;   // opcional esgotado: pula
            }

            $r->respostas[$campo['nome']] = $valor;
        }

        /*
         * Sobrescreve o `opt_in` que o portão gravou: numa Campanha de captura o Opt-in é
         * ETAPA, não desfecho. O que interessa no relatório é se os dados vieram.
         *
         * `respostas === []` com o portão aprovado significa que a pessoa autorizou e
         * nenhum campo foi coletado — todos opcionais e esgotados. `sem_resposta`
         * descreve isso melhor que `captura_concluida` sobre um JSON vazio.
         */
        $r->desfecho = $r->respostas === [] ? 'sem_resposta' : 'captura_concluida';

        /*
         * 🔴 O "RECEBI, OBRIGADO" — e a captura terminava MUDA sem ele.
         *
         * Era o único caminho do fluxo sem retorno nenhum: o portão confirma a tecla, a
         * recusa confirma a recusa, a tecla errada tem o "não entendi". Quem digitava os
         * 11 dígitos certos ouvia silêncio e a linha caía. Do lado da pessoa isso é
         * indistinguível de ter dado errado — e ela acabou de entregar um documento.
         *
         * 🔴 DEPOIS do desfecho, e SÓ no sucesso. Dois motivos para a ordem:
         *
         * · `sem_resposta` não toca. Ninguém digitou nada; dizer "recebemos seus dados"
         *   seria afirmar o contrário do que aconteceu. `captura_parcial` também não
         *   chega aqui — sai com `return` lá em cima.
         * · gravar o desfecho ANTES de tocar é o que faz o dado sobreviver ao áudio. Se a
         *   pessoa desligar durante a confirmação — caso comum: ela já respondeu, já
         *   ouviu o "obrigado" começar —, o `Resultado` que a FASE 4 grava já tem
         *   `captura_concluida` e as respostas. Tocar primeiro arriscaria perder a coleta
         *   inteira por causa da cortesia que vem depois dela.
         *
         * `tocarCortesia` e não `tocar`: falha de arquivo aqui vai para o log e NÃO
         * contamina o desfecho. A pessoa entregou o CPF; o áudio que não tocou é problema
         * nosso, não dela.
         */
        if ($r->desfecho === 'captura_concluida') {
            $this->tocarCortesia($d->audioConfirmaCaptura, $r);
        }

        return $r;
    }

    /**
     * O `audio` do array é nullable, mas o prompt chega como argumento PRÓPRIO e
     * não-nulo: `captura()` já conferiu todos antes de perguntar qualquer coisa.
     *
     * @param array{nome:string,tamanho:int,tipo:string,audio:?string,obrigatorio:bool,tentativas:int,timeoutSeg:int} $campo
     */
    private function lerCampo(array $campo, string $audio, Resultado $r): ?string
    {
        for ($t = 1; $t <= max(1, $campo['tentativas']); $t++) {
            $valor = $this->agi->lerDigitos($audio, $campo['tamanho'], $campo['timeoutSeg'] * 1000);
            $this->log->linha('campo', 'coletado', [
                'campo' => $campo['nome'], 'tentativa' => $t, 'digitos' => strlen($valor),
            ]);

            // CPF: dígito verificador validado AQUI, antes de gastar rede.
            // Latência (o Lead está esperando), custo (consulta cobrada) e
            // diagnóstico: "digitou errado" e "documento não encontrado" passam
            // a ser coisas distintas.
            $ok = $campo['tipo'] === 'cpf'
                ? $this->cpf->valido($valor)
                : strlen($valor) === $campo['tamanho'];

            if ($ok) {
                return $valor;
            }

            /*
             * 🔴 GUARDA O QUE FOI DIGITADO E REPROVADO.
             *
             * Antes o valor era descartado aqui — nem o log o registrava, só o `strlen`.
             * O desfecho virava `captura_parcial` e a tela mostrava célula VAZIA: o
             * operador via que falhou e não tinha como saber se a pessoa digitou 5
             * dígitos e desistiu, errou o dígito verificador, ou digitou o CPF de outra
             * pessoa. Três problemas, três correções diferentes.
             *
             * Vazio não entra: "não digitou nada" já é o que a ausência significa, e
             * poluir a lista com strings vazias esconderia as tentativas reais.
             */
            if ($valor !== '') {
                $this->log->linha('campo_invalido', 'valor reprovado na validação', [
                    'campo' => $campo['nome'], 'tentativa' => $t,
                ]);
                $r->tentativasInvalidas[$campo['nome']][] = $valor;
            }
        }

        return null;
    }

    /**
     * Toca e CONFERE. Medido: Playback em arquivo ausente devolve
     * PLAYBACKSTATUS=FAILED e o dialplan CONTINUA — o Lead ouve silêncio e o
     * fluxo segue como se tudo estivesse bem. O log do PBX não é o log do
     * produto.
     *
     * @return bool false quando o fluxo deve terminar
     */
    private function tocar(string $arquivo, Resultado $r): bool
    {
        $this->agi->executar('Playback', $arquivo);
        $status = $this->agi->variavel('PLAYBACKSTATUS');

        if ($status !== 'SUCCESS') {
            $this->log->linha('audio', 'AUDIO_MISSING', ['arquivo' => $arquivo, 'status' => $status]);
            $r->desfecho = 'erro';
            $r->erro = 'AUDIO_MISSING';

            return false;
        }

        return true;
    }

    /**
     * 🔴 ÁUDIO DE CORTESIA: registra a falha e SEGUE, sem tocar no desfecho.
     *
     * A diferença com `tocar()` é de consequência, não de estilo. A abertura é CRÍTICA
     * — sem ela o AMD analisa ar morto — e por isso `tocar()` aborta a chamada. Já
     * "não entendi" e as confirmações são cortesia: perder uma delas piora a
     * experiência e NÃO invalida o que a pessoa fez.
     *
     * Antes as duas usavam `tocar()`, e o efeito foi medido em chamada real: o
     * `naoentendi` falhou, `tocar()` escreveu `desfecho='erro'` e `erro='AUDIO_MISSING'`,
     * o fluxo continuou e SOBRESCREVEU o desfecho com `invalido`. Resultado: a falha de
     * áudio desapareceu do banco e o registro ficou incoerente — `erro` preenchido com
     * desfecho de sucesso.
     *
     * Engolir diagnóstico é o que o ADR-0007 proíbe. Aqui ele não é engolido: vai para o
     * log com o arquivo e o status. O que não acontece é contaminar o desfecho de uma
     * chamada que, do ponto de vista da pessoa, funcionou.
     */
    private function tocarCortesia(?string $arquivo, Resultado $r): void
    {
        if ($arquivo === null || $arquivo === '') {
            return;
        }

        $this->agi->executar('Playback', $arquivo);
        $status = $this->agi->variavel('PLAYBACKSTATUS');

        if ($status !== 'SUCCESS') {
            $this->log->linha('audio_cortesia', 'AUDIO_MISSING', [
                'arquivo' => $arquivo,
                'status' => $status,
                'consequencia' => 'seguindo sem tocar; o desfecho não foi alterado',
            ]);
        }
    }
}
