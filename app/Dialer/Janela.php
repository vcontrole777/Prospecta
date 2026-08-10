<?php

declare(strict_types=1);

namespace App\Dialer;

use DateTimeImmutable;

/**
 * Horário de Trabalho: a discadora pode originar AGORA? PURA.
 *
 * 🔴 As três colunas existiam no esquema e NADA as lia. O produto prometia horário
 * de trabalho e discava 24 horas, sete dias — inclusive 3 da manhã e domingo. É a
 * lacuna que causa dano ativo à reputação de quem usa, não apenas valor perdido.
 *
 * 🔴 FECHAR A JANELA NÃO DERRUBA CHAMADA EM VOO. A janela governa ORIGINAÇÃO. Uma
 * pessoa que atendeu às 17h59 termina a conversa dela; cortar no relógio seria
 * desligar na cara de quem estava falando, e ainda gastaria o Lead com um desfecho
 * que não é dele. O dialer para de discar e deixa as chamadas terminarem.
 */
final class Janela
{
    /**
     * @param string|null $inicio 'HH:MM' ou 'HH:MM:SS'; null significa sem restrição
     * @param string|null $fim idem
     * @param list<int>|null $diasSemana 1=segunda … 7=domingo (ISO-8601); null é todos
     */
    public function __construct(
        private readonly ?string $inicio = null,
        private readonly ?string $fim = null,
        private readonly ?array $diasSemana = null,
    ) {
    }

    /**
     * 🔴 SEM CONFIGURAÇÃO, LIBERA.
     *
     * A instalação que existe hoje tem as colunas nulas, e fechar por padrão faria a
     * discadora parar de discar no instante em que esta classe entrasse — sem
     * ninguém ter mudado nada, e sem sinal óbvio. Liberar por padrão mantém o
     * comportamento atual e faz a restrição existir só quando alguém a configura.
     */
    public function aberta(DateTimeImmutable $agora): bool
    {
        return $this->diaPermitido($agora) && $this->horaPermitida($agora);
    }

    private function diaPermitido(DateTimeImmutable $agora): bool
    {
        if ($this->diasSemana === null || $this->diasSemana === []) {
            return true;
        }

        // ISO-8601: 1 é segunda e 7 é domingo. `w` do PHP começa no domingo com 0, e
        // confundir os dois desloca a semana inteira em um dia — erro que passa por
        // seis dias e aparece no sétimo.
        return in_array((int) $agora->format('N'), $this->diasSemana, true);
    }

    private function horaPermitida(DateTimeImmutable $agora): bool
    {
        $i = $this->minutos($this->inicio);
        $f = $this->minutos($this->fim);

        if ($i === null || $f === null || $i === $f) {
            return true;
        }

        $m = (int) $agora->format('G') * 60 + (int) $agora->format('i');

        // Janela normal: 09:00 às 18:00.
        if ($i < $f) {
            return $m >= $i && $m < $f;
        }

        // 🔴 Janela que ATRAVESSA A MEIA-NOITE: 20:00 às 02:00. Sem este caso, a
        // comparação simples devolveria falso o tempo todo e a Campanha nunca
        // discaria — com a configuração parecendo correta na tela.
        return $m >= $i || $m < $f;
    }

    /** 'HH:MM[:SS]' em minutos desde a meia-noite. `null` quando não dá para ler. */
    private function minutos(?string $hora): ?int
    {
        if ($hora === null || $hora === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})/', $hora, $m) !== 1) {
            return null;
        }

        $h = (int) $m[1];
        $min = (int) $m[2];

        if ($h > 23 || $min > 59) {
            return null;
        }

        return $h * 60 + $min;
    }

    /** Descrição para o log e para a tela. */
    public function descricao(): string
    {
        if ($this->minutos($this->inicio) === null || $this->minutos($this->fim) === null) {
            return 'sem restrição de horário';
        }

        $dias = $this->diasSemana === null || $this->diasSemana === []
            ? 'todos os dias'
            : implode(', ', array_map(
                static fn (int $d): string => ['', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'][$d] ?? '?',
                $this->diasSemana,
            ));

        return sprintf('%s às %s, %s', substr((string) $this->inicio, 0, 5), substr((string) $this->fim, 0, 5), $dias);
    }
}
