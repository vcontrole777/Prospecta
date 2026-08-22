<?php

declare(strict_types=1);

namespace App\Suporte;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Converte um instante GRAVADO para o instante EXIBIDO.
 *
 * 🔴 O BANCO GUARDA EM UTC, E ISSO NÃO MUDA. É a escolha certa e a que o código já
 * assume: `Conversas::momento()` interpreta as colunas em `app.timezone`, e a janela de
 * 24 h é calculada em cima disso. Mexer no fuso de armazenamento — pela máquina, pelo
 * MySQL ou pelo `app.timezone` — reinterpretaria TODA linha já gravada, deslocando o
 * histórico em três horas e quebrando a conta da janela em silêncio.
 *
 * O que estava errado era só a EXIBIÇÃO: três telas imprimiam a string crua da coluna, e
 * o operador via 19:45 às 16:45. Este é o único lugar que traduz, e traduz num sentido só.
 *
 * O fuso de exibição vem de `APP_TIMEZONE`, a MESMA variável que `config/dialer.php` já
 * lê com o mesmo padrão — o Horário de Trabalho e a tela precisam falar do mesmo relógio,
 * senão o operador vê uma Campanha "fora de horário" num horário que a tela diz ser
 * comercial.
 */
final class Instante
{
    /**
     * Formata para exibição. String vazia quando não há instante — a tela mostra o
     * traço dela, e não uma data de 1970.
     */
    public static function local(mixed $gravado, string $formato = 'd/m/Y H:i'): string
    {
        if (! is_string($gravado) || trim($gravado) === '') {
            return '';
        }

        try {
            $armazenamento = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
            $exibicao = new DateTimeZone(self::fuso());

            return (new DateTimeImmutable($gravado, $armazenamento))
                ->setTimezone($exibicao)
                ->format($formato);
        } catch (Throwable) {
            // Coluna com conteúdo inesperado não pode derrubar a tela inteira. Devolver
            // o valor cru é pior que nada? Não: é o que estava lá, e o operador ao menos
            // vê que existe algo estranho em vez de uma célula vazia mentindo.
            return $gravado;
        }
    }

    /**
     * O fuso em que o operador pensa.
     *
     * 🔴 `config()`, NUNCA `env()` direto. Com `config:cache` ativo o `env()` fora de
     * arquivo de configuração devolve null — é a armadilha que o cabeçalho de
     * `config/prospecta.php` descreve, e cair nela faria a tela voltar para UTC só em
     * produção, que é onde ninguém testa.
     */
    public static function fuso(): string
    {
        return self::texto(config('prospecta.fuso')) ?: 'America/Sao_Paulo';
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }
}
