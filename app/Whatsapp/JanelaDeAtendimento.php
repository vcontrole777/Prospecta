<?php

declare(strict_types=1);

namespace App\Whatsapp;

use DateTimeImmutable;

/**
 * A janela de atendimento de 24 horas do WhatsApp. PURA.
 *
 * 🔴 É O MECANISMO ECONÔMICO DO CANAL, não um detalhe.
 *
 * Quando a pessoa manda qualquer mensagem — inclusive tocar um botão de resposta
 * rápida do template — abre-se uma janela de 24 h. DENTRO dela, texto livre é
 * ilimitado e não custa por mensagem. FORA, só template, e template é pago.
 *
 * Por isso o template do disparo é o pedágio de entrada e a conversa depois é livre. E
 * por isso a tela precisa mostrar quanto resta: responder às 23h55 é grátis e às 24h05
 * exige um template aprovado, que talvez não exista para o que se quer dizer.
 *
 * Contar isto errado não dá erro — dá mensagem recusada pela plataforma, ou custo que
 * ninguém previu.
 */
final class JanelaDeAtendimento
{
    public const DURACAO_HORAS = 24;

    private const DURACAO_SEG = self::DURACAO_HORAS * 3600;

    public function __construct(
        /** Quando a pessoa mandou a última mensagem. `null` = nunca mandou. */
        private readonly ?DateTimeImmutable $ultimaEntrada,
    ) {
    }

    public function aberta(DateTimeImmutable $agora): bool
    {
        return $this->segundosRestantes($agora) > 0;
    }

    public function segundosRestantes(DateTimeImmutable $agora): int
    {
        if ($this->ultimaEntrada === null) {
            return 0;
        }

        $decorrido = $agora->getTimestamp() - $this->ultimaEntrada->getTimestamp();

        // Entrada no FUTURO devolve a janela cheia em vez de número negativo virado.
        // Acontece com relógio dessincronizado entre o banco e a aplicação, e um
        // "-3600 segundos restantes" na tela seria pior que impreciso: leria como
        // fechada quando está aberta.
        if ($decorrido < 0) {
            return self::DURACAO_SEG;
        }

        return max(0, self::DURACAO_SEG - $decorrido);
    }

    /**
     * "3 h 12 min", ou "fechada". Para a tela.
     *
     * Sem segundos de propósito: o operador decide se responde agora, e um contador
     * descendo ao segundo dá urgência falsa numa janela de 24 h.
     */
    public function restante(DateTimeImmutable $agora): string
    {
        $s = $this->segundosRestantes($agora);

        if ($s <= 0) {
            return 'fechada';
        }

        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        if ($h > 0) {
            return sprintf('%d h %02d min', $h, $m);
        }

        // Abaixo de uma hora, mostra minuto — é quando a decisão fica urgente de fato.
        return sprintf('%d min', max(1, $m));
    }

    /** Está perto de fechar? Serve para a tela dar destaque. */
    public function acabando(DateTimeImmutable $agora, int $limiteHoras = 2): bool
    {
        $s = $this->segundosRestantes($agora);

        return $s > 0 && $s <= $limiteHoras * 3600;
    }
}
