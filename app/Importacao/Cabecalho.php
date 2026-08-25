<?php

declare(strict_types=1);

namespace App\Importacao;

/**
 * A primeira linha do CSV é mesmo um cabeçalho, ou é dado?
 *
 * 🔴 EXISTE PORQUE DUAS CAMPANHAS NASCERAM QUEBRADAS, e ninguém percebeu por dias.
 *
 * O importador SEMPRE trata a primeira linha como cabeçalho, e não tem opção de
 * "arquivo sem cabeçalho". Quando o CSV vem sem ela, duas coisas acontecem juntas e
 * ambas em silêncio:
 *
 * · a primeira PESSOA da lista vira o nome das colunas, e é PERDIDA — ela nunca é
 *   discada, porque virou metadado;
 * · todo Lead do arquivo fica com colunas chamadas, por exemplo,
 *   `MILTON NATAL` e `247.942.998-93`.
 *
 * Medido em 25/08/2026: a Campanha 3 tinha 9.997 Leads assim e a Campanha 2 outros
 * 9.999. O sintoma só apareceu semanas depois, quando o Placeholder do template não
 * achou a coluna `nome` e o disparo falhou — um Lead por vez, com a Campanha no ar.
 *
 * 🔴 AVISO, NÃO RECUSA. Cabeçalho legítimo PODE ter nome estranho: uma planilha de
 * CRM exporta "1281" como código de campo, e recusar o arquivo impediria importação
 * válida. Quem sabe se aquilo é nome de coluna é quem exportou a planilha — a tela
 * mostra o que achou e deixa a decisão com ele.
 *
 * PURA por exigência do ADR-0006: sem arquivo, sem banco, sem framework. O teste
 * roda com uma lista de strings.
 */
final class Cabecalho
{
    /**
     * O que numa linha de cabeçalho parece dado em vez de nome de coluna.
     *
     * 🔴 O SINAL FORTE É CÉLULA NUMÉRICA. Cabeçalho de verdade quase nunca tem uma
     * coluna chamada `36787078153` ou `247.942.998-93`; linha de dados quase sempre
     * tem. Nome de pessoa — `ANA`, `MILTON NATAL` — NÃO é detectável: é
     * indistinguível de um cabeçalho legítimo como `NOME` ou `PRIME`, e chutar ali
     * geraria alarme falso em toda planilha.
     *
     * Nos dois casos medidos isso basta: `ANA, 1281, PRIME, 00200816, 36787078153`
     * tem três células numéricas, e `MILTON NATAL, 247.942.998-93` tem uma com cara
     * de CPF.
     *
     * @param list<string> $colunas
     * @return list<array{coluna:string, motivo:string}>
     */
    public function suspeitas(array $colunas): array
    {
        $achados = [];
        $vistos = [];

        foreach ($colunas as $bruto) {
            $c = trim($bruto);

            if ($c === '') {
                $achados[] = ['coluna' => '(vazia)', 'motivo' => 'sem nome'];
                continue;
            }

            // Repetido é sinal independente: planilha com duas colunas de mesmo nome
            // é rara; linha de dados com dois valores iguais — dois "PRIME", dois
            // zeros — é comum.
            $chave = mb_strtolower($c);
            if (isset($vistos[$chave])) {
                $achados[] = ['coluna' => $c, 'motivo' => 'repetido'];
                continue;
            }
            $vistos[$chave] = true;

            // Só dígitos depois de tirar a pontuação que CPF, CNPJ e telefone usam.
            $so = preg_replace('/[.\-\/() ]/', '', $c) ?? $c;
            if ($so === '' || preg_match('/^\d+$/', $so) !== 1) {
                continue;
            }

            $achados[] = [
                'coluna' => $c,
                /*
                 * 🔴 ONZE DÍGITOS É AMBÍGUO, e o rótulo diz isso em vez de chutar.
                 * Celular brasileiro com DDD tem exatamente onze, igual a CPF. Como o
                 * aviso serve para a pessoa OLHAR a planilha, chutar "parece CPF"
                 * sobre um telefone só atrapalharia a conferência dela.
                 */
                'motivo' => match (true) {
                    strlen($so) === 11 => 'parece CPF ou telefone',
                    strlen($so) === 14 => 'parece CNPJ',
                    strlen($so) >= 10 => 'parece telefone',
                    default => 'só dígitos',
                },
            ];
        }

        return $achados;
    }
}
