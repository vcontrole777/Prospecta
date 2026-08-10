<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Dígito verificador de CPF. PURA por exigência do ADR-0006.
 *
 * Roda em dois lugares: no painel, quando o operador configura um campo de
 * captura, e no `fastagi`, quando o Lead digita por DTMF. É UMA classe nos
 * dois.
 *
 * Por que validar ANTES de consultar a API, e não deixar a API recusar:
 *   - latência: o Lead está na linha esperando, e consulta que ia falhar não
 *     deveria custar o tempo dele
 *   - custo: consulta cobrada por chamada não deveria ser gasta em digitação
 *     errada
 *   - diagnóstico: "digitou errado" e "documento válido não encontrado" passam
 *     a ser distinguíveis, e são coisas diferentes para quem contratou
 *
 * Errar 11 dígitos num teclado de telefone, sem confirmação visual, é comum.
 */
final class Cpf
{
    /**
     * Aceita SÓ dígitos, que é o que o DTMF produz. Não limpa pontuação de
     * propósito: se veio pontuação, o dado não veio do teclado do telefone, e
     * mascarar isso esconderia um erro de caminho.
     */
    public function valido(string $digitos): bool
    {
        if (preg_match('/^[0-9]{11}$/', $digitos) !== 1) {
            return false;
        }

        // Sequências de dígito repetido passam pelo algoritmo mas não são CPF.
        // 00000000000 é o caso que mais aparece: é o que sai de um Lead que
        // apertou zero até o timeout.
        if (preg_match('/^(\d)\1{10}$/', $digitos) === 1) {
            return false;
        }

        return $this->digito($digitos, 9) === (int) $digitos[9]
            && $this->digito($digitos, 10) === (int) $digitos[10];
    }

    /**
     * Calcula o dígito da posição informada pelo módulo 11 padrão.
     */
    private function digito(string $digitos, int $posicao): int
    {
        $peso = $posicao + 1;
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += ((int) $digitos[$i]) * $peso--;
        }
        $resto = ($soma * 10) % 11;

        return $resto === 10 ? 0 : $resto;
    }
}
