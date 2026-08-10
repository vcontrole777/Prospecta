<?php

declare(strict_types=1);

namespace App\Audio;

/**
 * O formato de áudio do produto, e a invariante que o verifica. PURA.
 *
 * `alaw` a 8000 Hz, mono. Casar com o codec do tronco elimina transcodificação na
 * reprodução.
 *
 * 🔴 ARQUIVO RAW NÃO TEM CABEÇALHO, e daí sai tudo o que esta classe existe para
 * resolver.
 *
 * Não há nada dentro de um `.alaw` dizendo "8000 Hz". Consequências medidas nesta
 * máquina:
 *
 *   · `sox` NÃO tem handler para a extensão `.alaw` — precisa de `-t al` explícito.
 *     O comando que estava documentado no audios/README.md falhava com
 *     "no handler for file extension `alaw'".
 *   · `soxi` NÃO consegue ler o arquivo de jeito nenhum. Em `soxi`, `-t` significa
 *     "mostre o tipo", não "assuma este tipo" — então não há como informar o
 *     formato. A checagem `soxi -c -r` do provisionamento era impossível, não só
 *     com sintaxe errada.
 *
 * O que verifica de verdade é o TAMANHO. A 8000 Hz, mono, A-law, cada amostra é um
 * byte: **8000 bytes por segundo, exatos**. Medido: 2 s viraram 16000 bytes; a
 * mesma conversão sem `-r 8000 -c 1` produziu 176400 bytes, 11 vezes mais. O
 * tamanho delata o formato errado, que é justamente o modo de falha do qual o
 * README avisa — "sox termina com sucesso e parâmetro errado".
 *
 * E ganha um segundo uso que o `soxi` nunca teve: a `abertura` toca imediatamente
 * antes do `AMD()`, e áudio que invade a janela de análise faz o classificador
 * analisar o próprio som do sistema e devolver `HUMAN` para todo mundo. Como o
 * tamanho É a duração, verificar tamanho impõe aquele limite.
 */
final class Alaw
{
    public const TAXA_HZ = 8000;

    public const CANAIS = 1;

    /** A-law usa 1 byte por amostra, então bytes por segundo = taxa. */
    public const BYTES_POR_SEGUNDO = self::TAXA_HZ;

    /** Tipo do `sox` para A-law cru. NÃO é `alaw`; é `al`. */
    public const TIPO_SOX = 'al';

    public function duracaoSeg(int $bytes): float
    {
        return $bytes / self::BYTES_POR_SEGUNDO;
    }

    public function tamanhoEsperado(float $segundos): int
    {
        return (int) round($segundos * self::BYTES_POR_SEGUNDO);
    }

    /**
     * O arquivo convertido tem o tamanho que 8 kHz mono exige para a duração da
     * origem?
     *
     * A tolerância cobre arredondamento de quadro do reamostrador, que muda o
     * total em algumas dezenas de bytes. Ela NÃO precisa cobrir erro de formato:
     * taxa ou canal errado erram por fatores — 2×, 5,5×, 11× — não por bytes.
     */
    public function tamanhoConfere(int $bytes, float $segundosDaOrigem, float $tolerancia = 0.05): bool
    {
        $esperado = $this->tamanhoEsperado($segundosDaOrigem);
        if ($esperado <= 0) {
            return false;
        }

        return abs($bytes - $esperado) <= $esperado * $tolerancia;
    }

    /**
     * Argumentos do `sox` para produzir o formato. A ordem importa para o sox.
     *
     * @return list<string>
     */
    public function argumentosSox(string $origem, string $destino): array
    {
        return [
            'sox', $origem,
            '-r', (string) self::TAXA_HZ,
            '-c', (string) self::CANAIS,
            // 🔴 `-t al` é obrigatório: sem ele o sox recusa a extensão `.alaw`.
            '-t', self::TIPO_SOX,
            $destino,
        ];
    }
}
