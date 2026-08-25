<?php

declare(strict_types=1);

namespace Tests\Unit\Importacao;

use App\Importacao\Cabecalho;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Os dois casos abaixo são REAIS: as linhas que viraram cabeçalho nas Campanhas 2 e
 * 3, descobertas em 25/08/2026 semanas depois da importação.
 */
final class CabecalhoTest extends TestCase
{
    /**
     * @param list<string> $colunas
     * @return list<string>
     */
    private function motivos(array $colunas): array
    {
        return array_map(
            static fn (array $s): string => $s['coluna'] . ': ' . $s['motivo'],
            (new Cabecalho())->suspeitas($colunas),
        );
    }

    /**
     * 🔴 A CAMPANHA 3. Duas colunas, e a segunda é um CPF formatado.
     *
     * 9.997 Leads nasceram com essas colunas, e a primeira pessoa do arquivo — a que
     * virou o cabeçalho — nunca foi discada.
     */
    #[Test]
    public function pega_o_cpf_formatado_que_virou_nome_de_coluna(): void
    {
        $this->assertSame(
            ['247.942.998-93: parece CPF ou telefone'],
            $this->motivos(['MILTON NATAL', '247.942.998-93']),
        );
    }

    /**
     * 🔴 A CAMPANHA 2. Cinco colunas, três numéricas.
     *
     * `ANA` e `PRIME` NÃO são detectados, e isso é limite conhecido: nome de pessoa é
     * indistinguível de cabeçalho legítimo — `PRIME` bem que podia ser uma coluna
     * chamada Prime. Basta uma célula numérica para o aviso aparecer, e aqui há três.
     */
    #[Test]
    public function pega_as_celulas_numericas_da_linha_de_dados(): void
    {
        $this->assertSame(
            ['1281: só dígitos', '00200816: só dígitos', '36787078153: parece CPF ou telefone'],
            $this->motivos(['ANA', '1281', 'PRIME', '00200816', '36787078153']),
        );
    }

    /** Cabeçalho normal não gera alarme — é o caso da maioria das planilhas. */
    #[Test]
    public function cabecalho_de_verdade_passa_limpo(): void
    {
        $this->assertSame([], $this->motivos(['nome', 'cpf', 'telefone', 'agencia']));
        $this->assertSame([], $this->motivos(['Nome do Cliente', 'CPF/CNPJ', 'Telefone 1']));
    }

    /**
     * 🔴 COLUNA COM NOME ESTRANHO MAS NÃO NUMÉRICA PASSA, de propósito.
     *
     * CRM exporta "Campo 1", "Coluna B". Alarmar nisso geraria aviso em planilha boa,
     * e aviso que aparece sempre deixa de ser lido.
     */
    #[Test]
    public function nome_generico_de_crm_nao_alarma(): void
    {
        $this->assertSame([], $this->motivos(['Campo 1', 'Coluna B', 'Field_3']));
    }

    #[Test]
    public function classifica_pelo_formato(): void
    {
        $this->assertSame(['11122233344: parece CPF ou telefone'], $this->motivos(['11122233344']));
        $this->assertSame(['11222333000144: parece CNPJ'], $this->motivos(['11222333000144']));
        $this->assertSame(['5511987654321: parece telefone'], $this->motivos(['5511987654321']));
        $this->assertSame(['1281: só dígitos'], $this->motivos(['1281']));
    }

    /**
     * Telefone com pontuação também é pego: a máscara é tirada antes de olhar.
     *
     * E o rótulo sai como "CPF ou telefone" de propósito — celular com DDD tem onze
     * dígitos, igual a CPF, e chutar um dos dois atrapalharia quem for conferir.
     */
    #[Test]
    public function tira_a_pontuacao_antes_de_decidir(): void
    {
        $this->assertSame(['(11) 98765-4321: parece CPF ou telefone'], $this->motivos(['(11) 98765-4321']));
    }

    /**
     * Vazio e repetido são sinais próprios.
     *
     * Planilha com duas colunas de mesmo nome é rara; linha de dados com dois valores
     * iguais — dois "PRIME", dois zeros — é comum.
     */
    #[Test]
    public function coluna_vazia_e_repetida_tambem_avisam(): void
    {
        $this->assertSame(
            ['(vazia): sem nome', 'nome: repetido'],
            $this->motivos(['nome', '', 'nome']),
        );
    }

    /** Repetição não distingue maiúscula: "Nome" e "nome" são a mesma coluna. */
    #[Test]
    public function repeticao_ignora_caixa(): void
    {
        $this->assertSame(['NOME: repetido'], $this->motivos(['nome', 'NOME']));
    }
}
