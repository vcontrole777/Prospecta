<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Quanto tempo uma chamada pode durar, no PIOR caso. PURA.
 *
 * 🔴 EXISTE POR CAUSA DA RECONCILIAÇÃO.
 *
 * `Fila::reconciliar` encerra Ligação aberta há mais de `limite_ligacao_seg` e — desde
 * que passou a soltar o Lead — devolve o Lead à Fila. A varredura existe para o caso de
 * evento de AMI PERDIDO, e a única prova que ela tem de que o evento se perdeu é o
 * TEMPO. Se uma chamada VIVA passar do limite, a varredura a declara morta:
 *
 * · o canal é dado como livre e o dialer origina um a mais do que o contratado;
 * · o Lead volta para a Fila e é discado DE NOVO, para alguém que está na linha naquele
 *   instante, no meio de digitar o próprio CPF;
 * · a tentativa é gasta duas vezes pela mesma conversa.
 *
 * Quando o `fastagi` termina, `Banco::gravar` sobrescreve o desfecho — então o DADO se
 * conserta sozinho e a ligação duplicada não. É o pior formato de defeito: sem sintoma
 * no banco, com sintoma para a pessoa.
 *
 * Antes de a reconciliação soltar o Lead, o efeito era apenas um desfecho errado. Ou
 * seja: consertar um defeito subiu o preço de errar este número. É por isso que a conta
 * passa a ser feita, e não estimada.
 *
 * A conta é do PIOR caso, não da média, porque só ela serve de garantia — média
 * confortável não impede a chamada de seis minutos de existir. E erra para CIMA de
 * propósito: superestimar recusa uma configuração arriscada antes da hora, subestimar
 * deixa passar.
 *
 * O modelo segue `Fastagi\Fluxo` passo a passo. Se o fluxo mudar, esta conta mente.
 */
final class DuracaoMaxima
{
    /**
     * Folga para a análise do AMD e para o `WaitForSilence` que vem depois dela.
     *
     * O `app_amd` tem limite próprio — os argumentos padrão dão alguns segundos de
     * análise — e o `WaitForSilence(500, 1, 2)` espera no máximo 2 s. Sete segundos é
     * folga sobre os dois, nomeada em vez de omitida: de fora, viraria erro silencioso.
     */
    private const CLASSIFICACAO_SEG = 7.0;

    /**
     * O pior caso de um opt-in, em segundos.
     *
     * @param  int  $timeoutToqueSeg  Quanto deixamos chamando antes do NOSSO CANCEL. O
     *                                487 medido é o nosso cancelamento, não desistência
     *                                da operadora — então este número é decisão nossa.
     * @param  float  $aberturaSeg  Áudio de abertura: toca uma vez, antes do AMD.
     * @param  float  $mensagemSeg  Áudio da mensagem. 🔴 Toca em CADA tentativa: o
     *                              `GET DATA` recebe o arquivo e só espera depois de
     *                              tocá-lo, então quem erra ouve tudo de novo.
     * @param  int  $tentativasUra  Quantas vezes a URA repete a pergunta da tecla.
     * @param  float  $naoEntendiSeg  Áudio de "não entendi", entre tentativas — toca uma
     *                                vez menos que o número de tentativas.
     * @param  float  $confirmacaoSeg  Áudio de confirmação no fim, uma vez.
     */
    public function optIn(
        int $timeoutToqueSeg,
        float $aberturaSeg = 0.0,
        float $mensagemSeg = 0.0,
        int $tentativasUra = 1,
        float $naoEntendiSeg = 0.0,
        float $confirmacaoSeg = 0.0,
    ): int {
        $total = $this->antesDoDialogo($timeoutToqueSeg, $aberturaSeg)
            + $this->portao($mensagemSeg, $tentativasUra, $naoEntendiSeg, $confirmacaoSeg);

        return (int) ceil($total);
    }

    /**
     * O pior caso de uma captura, em segundos.
     *
     * 🔴 A CAPTURA TAMBÉM PASSA PELO PORTÃO, e esta conta NEGAVA isso.
     *
     * O texto que estava aqui dizia que "a captura NÃO toca o áudio de mensagem" e que
     * somá-lo inflaria a conta. Era verdade e deixou de ser: `Fluxo::captura` passou a
     * chamar `portao()` antes das perguntas — Mensagem, tecla de Opt-in, e só então os
     * campos —, porque pedir CPF a quem não ouviu a oferta é pedir documento sem
     * autorização. A conta não acompanhou.
     *
     * O efeito era subestimar TODA Campanha de captura pelo custo do portão: a mensagem
     * multiplicada pelas tentativas da URA, mais o "não entendi" entre elas, mais a
     * confirmação. Com mensagem de 14 s e duas tentativas na URA, são 47 s que não
     * apareciam — e o preço de subestimar é exatamente o que o cabeçalho desta classe
     * descreve: a reconciliação declara morta uma chamada VIVA e disca de novo para quem
     * está na linha digitando o próprio CPF.
     *
     * É a demonstração do aviso que já estava escrito: "o modelo segue `Fastagi\Fluxo`
     * passo a passo; se o fluxo mudar, esta conta mente".
     *
     * @param  list<array{tentativas:int, timeoutSeg:int, promptSeg:float}>  $campos
     * @param  float  $confirmaCapturaSeg  O "recebi, obrigado" do fim. SOMA com
     *                                     `$confirmacaoSeg` em vez de substituí-la: na
     *                                     mesma chamada tocam as duas — a do portão
     *                                     quando a tecla é apertada, esta quando os dados
     *                                     chegam. Contar só a maior subestimaria o caso
     *                                     que de fato acontece no caminho de sucesso.
     */
    public function captura(
        int $timeoutToqueSeg,
        float $aberturaSeg = 0.0,
        array $campos = [],
        float $mensagemSeg = 0.0,
        int $tentativasUra = 1,
        float $naoEntendiSeg = 0.0,
        float $confirmacaoSeg = 0.0,
        float $confirmaCapturaSeg = 0.0,
    ): int {
        $total = $this->antesDoDialogo($timeoutToqueSeg, $aberturaSeg)
            + $this->portao($mensagemSeg, $tentativasUra, $naoEntendiSeg, $confirmacaoSeg);

        foreach ($campos as $campo) {
            // 🔴 O PROMPT ENTRA EM CADA TENTATIVA, pelo mesmo motivo da mensagem no
            // opt-in. Contá-lo uma vez só subestimaria cada campo pelo fator de
            // tentativas — e é exatamente a pessoa que erra que estica a chamada.
            $total += max(1, $campo['tentativas'])
                * (self::naoNegativo($campo['promptSeg']) + (float) max(0, $campo['timeoutSeg']));
        }

        return (int) ceil($total);
    }

    /**
     * O portão: Mensagem, tecla e confirmação. COMUM aos dois tipos, como no `Fluxo`.
     *
     * Extraído em vez de copiado pela mesma razão que `Fluxo::portao` foi: as três regras
     * sutis — a mensagem tocando em CADA tentativa, o "não entendi" tocando uma vez menos,
     * a confirmação uma vez só — já estavam certas de um lado. Duplicá-las significaria
     * consertar duas vezes, e este arquivo já provou que a segunda cópia fica para trás.
     */
    private function portao(
        float $mensagemSeg,
        int $tentativasUra,
        float $naoEntendiSeg,
        float $confirmacaoSeg,
    ): float {
        $tentativas = max(1, $tentativasUra);

        // `lerDigitos($audioMensagem, 1, 8000)`: toca a mensagem e espera 8 s por dígito.
        $total = $tentativas * (self::naoNegativo($mensagemSeg) + self::ESPERA_TECLA_SEG);

        // O "não entendi" toca ENTRE tentativas, então uma vez menos.
        $total += ($tentativas - 1) * self::naoNegativo($naoEntendiSeg);

        return $total + self::naoNegativo($confirmacaoSeg);
    }

    /**
     * Toque, abertura e classificação — o trecho comum aos dois tipos.
     *
     * O TOQUE CONTA. A Ligação é criada ANTES de originar e `iniciada_em` é daquele
     * instante, então a janela da reconciliação começa a correr no toque, não no
     * atendimento. Esquecer isso subestima o total em quase meio minuto.
     */
    private function antesDoDialogo(int $timeoutToqueSeg, float $aberturaSeg): float
    {
        return (float) max(0, $timeoutToqueSeg)
            + self::naoNegativo($aberturaSeg)
            + self::CLASSIFICACAO_SEG;
    }

    /** Espera por dígito na pergunta da tecla: `lerDigitos(..., 1, 8000)` no `Fluxo`. */
    private const ESPERA_TECLA_SEG = 8.0;

    private static function naoNegativo(float $v): float
    {
        return $v > 0.0 ? $v : 0.0;
    }
}
