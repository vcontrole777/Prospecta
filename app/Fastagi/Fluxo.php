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
        $this->agi->executar('AMD', ...$d->amd);
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

            return $r;
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

                return $r;
            }

            $r->teclaDigitada = $tecla;

            if ($d->teclaOptIn !== null && $tecla === $d->teclaOptIn) {
                $r->desfecho = 'opt_in';
                $this->tocarCortesia($d->audioConfirmaOptIn, $r);

                return $r;
            }

            if ($d->teclaOptOut !== null && $tecla === $d->teclaOptOut) {
                $r->desfecho = 'opt_out';
                $this->tocarCortesia($d->audioConfirmaOptOut, $r);

                return $r;
            }

            if ($tentativa < $d->tentativasUra) {
                $this->tocarCortesia($d->audioNaoEntendi, $r);
                continue;
            }

            $r->desfecho = 'invalido';

            return $r;
        }

        $r->desfecho = 'sem_resposta';

        return $r;
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

        $r->desfecho = $r->respostas === [] ? 'sem_resposta' : 'captura_concluida';

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
