<?php

declare(strict_types=1);

namespace App\Audio;

use RuntimeException;

/**
 * Recusa de áudio, com CAUSA NOMEADA.
 *
 * O audios/README.md exige causa nomeada, e o motivo é o modo de falha medido:
 * `Playback` em arquivo ausente ou ilegível devolve `PLAYBACKSTATUS=FAILED` **e o
 * dialplan continua**. O Lead ouve silêncio, a chamada conta como atendida, e nada
 * indica que faltou áudio. Uma mensagem genérica de "erro no upload" reproduziria
 * esse silêncio na tela.
 */
final class AudioRecusado extends RuntimeException
{
    private function __construct(
        public readonly string $causa,
        string $mensagem,
    ) {
        parent::__construct($mensagem);
    }

    /** O `sox` não conseguiu ler a origem: formato desconhecido ou arquivo corrompido. */
    public static function ilegivel(string $detalhe): self
    {
        return new self(
            'AUDIO_ILEGIVEL',
            'Não consegui ler este arquivo de áudio. Envie WAV, MP3, OGG ou M4A. ' . $detalhe,
        );
    }

    public static function conversaoFalhou(string $detalhe): self
    {
        return new self('AUDIO_CONVERSAO_FALHOU', 'A conversão do áudio falhou. ' . $detalhe);
    }

    /**
     * Converteu, e o resultado não tem o formato que o PBX exige. É a recusa que
     * existe porque `sox` termina com sucesso e parâmetro errado.
     */
    public static function incompativel(int $bytes, int $esperado): self
    {
        return new self(
            'AUDIO_INCOMPATIBLE',
            sprintf(
                'O áudio convertido saiu com %d bytes onde 8 kHz mono exigiria cerca de %d. '
                . 'Isso indica taxa de amostragem ou número de canais errado, e na chamada sairia silêncio.',
                $bytes,
                $esperado,
            ),
        );
    }

    public static function vazio(): self
    {
        return new self('AUDIO_VAZIO', 'O áudio convertido ficou vazio.');
    }

    public static function longoDemais(float $segundos, float $limite): self
    {
        return new self(
            'AUDIO_LONGO',
            sprintf('O áudio tem %.1f s e o limite aqui é %.1f s.', $segundos, $limite),
        );
    }
}
