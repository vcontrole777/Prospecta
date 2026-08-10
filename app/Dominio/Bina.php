<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Deriva a BINA de uma Ligação. PURA por exigência do ADR-0006.
 *
 * DOIS FATOS MEDIDOS EM CHAMADA REAL, 2026-08-09, que esta classe encarna:
 *
 *   1. A BINA vai SEM o `+`. Com o `+`, a rota prefixa `00` e o aparelho exibe
 *      `+005562984853523` — número sem sentido, que parece spam. E é pior que a
 *      BINA não funcionar, porque um número APARECE e nada no sistema indica
 *      erro. O formato canônico do `lead.telefone` (só dígitos) é o correto.
 *
 *   2. `from_user` no endpoint PJSIP SOBREPÕE o CallerID e torna toda regra
 *      daqui inócua, em silêncio. Nada nesta classe detecta isso — é
 *      configuração de tronco, e está no provisionamento com aviso.
 *
 * Os dígitos derivados são DETERMINÍSTICOS, não aleatórios: mesmo Lead, mesma
 * BINA em toda tentativa. Aleatório daria três números diferentes para quem foi
 * chamado três vezes, o que é mais estranho para o Lead, não menos — e
 * determinístico é testável.
 */
final class Bina
{
    /** Faixa de prefixo de fixo brasileiro plausível, confirmada contra a
     *  implementação de referência em AEL do MagnusBilling. Cobre fixos
     *  iniciando em 3 e 4. */
    private const FIXO_MIN = 3224;
    private const FIXO_MAX = 4711;

    /**
     * @param string $regra 'unica'|'lista'|'espelho'|'espelho_fixo'
     * @param string $telefone do Lead, formato canônico só dígitos (5511987654321)
     * @param string|null $binaCampanha campanha.bina — usada em 'unica' e como
     *        fallback de 'lista'
     * @param string|null $candidatoDaLista já sorteado pelo chamador via
     *        ORDER BY RAND() na tabela `bina`, filtrado pelo DDD do Lead. Fica
     *        fora desta classe justamente para ela seguir pura e testável.
     * @return string|null null significa "não pedir BINA nenhuma".
     *         🔴 O chamador OMITE o campo no Originate quando recebe null —
     *         nunca manda string vazia. Vazio pode sobrescrever o que a rota ia
     *         colocar, ou sair como chamada anônima, que tem atendimento perto
     *         de zero e causa invisível.
     */
    public function derivar(
        string $regra,
        string $telefone,
        ?string $binaCampanha = null,
        ?string $candidatoDaLista = null,
    ): ?string {
        $this->conferirTelefone($telefone);

        return match ($regra) {
            'unica' => $this->limpar($binaCampanha),

            // Fallback é campanha.bina, NÃO o espelho. Cair no espelho trocaria
            // em silêncio "números reais do operador, com retorno funcionando"
            // por "números de terceiros, com retorno quebrado" — mudança de
            // comportamento que o operador não pediu e não tem como perceber.
            // A implementação de referência em AEL cai no espelho; divergimos.
            'lista' => $this->limpar($candidatoDaLista) ?? $this->limpar($binaCampanha),

            'espelho' => $this->espelho($telefone),
            'espelho_fixo' => $this->espelhoFixo($telefone),
            default => throw ErroDeDominio::regraDesconhecida($regra),
        };
    }

    /**
     * Telefone do Lead com os 4 últimos dígitos trocados.
     *
     * "Tudo menos os 4 últimos" generaliza sem caso especial: vale para Lead
     * móvel (13 dígitos com país) e fixo (12).
     */
    private function espelho(string $telefone): string
    {
        $prefixo = substr($telefone, 0, -4);
        $originais = substr($telefone, -4);

        $quatro = $this->quatroDigitos($telefone, 0);

        // Se calhar de bater com os originais, o Lead veria o PRÓPRIO número
        // ligando para ele. É 1 em 10 mil e parece defeito.
        if ($quatro === $originais) {
            $quatro = $this->quatroDigitos($telefone, 1);
        }

        return $prefixo . $quatro;
    }

    /**
     * País + DDD do Lead (4 primeiros dígitos) mais um fixo de 8 dígitos
     * gerado. Total 12 dígitos, que é o comprimento de fixo com país.
     */
    private function espelhoFixo(string $telefone): string
    {
        $paisDdd = substr($telefone, 0, 4);

        $faixa = self::FIXO_MAX - self::FIXO_MIN + 1;
        $prefixo = self::FIXO_MIN + ($this->semente($telefone, 2) % $faixa);

        return $paisDdd . $prefixo . $this->quatroDigitos($telefone, 3);
    }

    /**
     * Quatro dígitos determinísticos, ZERADOS À ESQUERDA.
     *
     * A implementação de referência em AEL usa `RAND(8111, 9999)` e
     * `RAND(1111, 9999)` — pisos INCONSISTENTES entre macros, o que revela que o
     * piso existe só para garantir 4 dígitos, já que AEL não tem `sprintf`.
     * Zerando à esquerda recuperamos a faixa completa: 10 mil valores em vez de
     * 1.889.
     */
    private function quatroDigitos(string $telefone, int $variante): string
    {
        return str_pad((string) ($this->semente($telefone, $variante) % 10000), 4, '0', STR_PAD_LEFT);
    }

    private function semente(string $telefone, int $variante): int
    {
        return crc32($telefone . '#' . $variante);
    }

    private function conferirTelefone(string $telefone): void
    {
        // 12 dígitos = fixo com país, 13 = móvel com país.
        if (preg_match('/^[0-9]{12,13}$/', $telefone) !== 1) {
            throw ErroDeDominio::telefoneInvalido($telefone);
        }
    }

    /** Normaliza para só dígitos e devolve null quando não sobra nada. */
    private function limpar(?string $numero): ?string
    {
        if ($numero === null) {
            return null;
        }
        $digitos = preg_replace('/\D/', '', $numero) ?? '';

        return $digitos === '' ? null : $digitos;
    }
}
