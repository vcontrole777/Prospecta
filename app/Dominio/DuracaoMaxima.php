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
        $tentativas = max(1, $tentativasUra);

        $total = $this->antesDoDialogo($timeoutToqueSeg, $aberturaSeg);

        // `lerDigitos($audioMensagem, 1, 8000)`: toca a mensagem e espera 8 s por dígito.
        $total += $tentativas * (self::naoNegativo($mensagemSeg) + self::ESPERA_TECLA_SEG);

        // O "não entendi" toca ENTRE tentativas, então uma vez menos.
        $total += ($tentativas - 1) * self::naoNegativo($naoEntendiSeg);

        $total += self::naoNegativo($confirmacaoSeg);

        return (int) ceil($total);
    }

    /**
     * O pior caso de uma captura, em segundos.
     *
     * A captura NÃO toca o áudio de mensagem — `Fluxo::captura` pergunta campo a campo,
     * com o prompt de cada um. Somar a mensagem aqui inflaria a conta e recusaria
     * Campanha que caberia no limite.
     *
     * @param  list<array{tentativas:int, timeoutSeg:int, promptSeg:float}>  $campos
     */
    public function captura(int $timeoutToqueSeg, float $aberturaSeg = 0.0, array $campos = []): int
    {
        $total = $this->antesDoDialogo($timeoutToqueSeg, $aberturaSeg);

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
