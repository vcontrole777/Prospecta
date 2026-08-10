<?php

declare(strict_types=1);

namespace Tests\Unit\Importacao;

use App\Importacao\Csv;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    /**
     * 🔴 O Excel em português exporta com `;`. Assumir `,` faz o arquivo inteiro
     * virar UMA coluna e nenhum telefone ser encontrado — o operador vê
     * "0 importados" num arquivo visivelmente cheio, e nada explica.
     */
    #[Test]
    public function detecta_ponto_e_virgula_do_excel_brasileiro(): void
    {
        $d = (new Csv())->detectar("Nome;Telefone;Cidade\nMaria;11987654321;Osasco\n");

        $this->assertSame(';', $d['delimitador']);
    }

    #[Test]
    public function detecta_virgula_tabulacao_e_barra(): void
    {
        $csv = new Csv();

        $this->assertSame(',', $csv->detectar("Nome,Telefone\nMaria,11987654321\n")['delimitador']);
        $this->assertSame("\t", $csv->detectar("Nome\tTelefone\nMaria\t11987654321\n")['delimitador']);
        $this->assertSame('|', $csv->detectar("Nome|Telefone\nMaria|11987654321\n")['delimitador']);
    }

    /**
     * 🔴 Codificação errada corrompe o NOME, e o nome vai para dentro do template
     * do WhatsApp. O Lead recebe mensagem com o próprio nome quebrado, que é pior
     * que não receber.
     */
    #[Test]
    public function detecta_cp1252_e_converte_o_acento(): void
    {
        $latin = mb_convert_encoding("Nome;Telefone\nJoão;11987654321\n", 'Windows-1252', 'UTF-8');

        $csv = new Csv();
        $d = $csv->detectar($latin);
        $this->assertSame('Windows-1252', $d['codificacao'], 'não é UTF-8 válido');

        $linhas = $this->ler($csv, $latin, $d['delimitador'], $d['codificacao']);

        $this->assertSame(['Nome', 'Telefone'], $linhas[0]);
        $this->assertSame('João', $linhas[1][0], 'o acento tem que sobreviver');
        $this->assertTrue(mb_check_encoding($linhas[1][0], 'UTF-8'));
    }

    #[Test]
    public function utf8_passa_intacto(): void
    {
        $csv = new Csv();
        $texto = "Nome;Telefone\nJoão;11987654321\n";

        $d = $csv->detectar($texto);
        $this->assertSame('UTF-8', $d['codificacao']);

        $linhas = $this->ler($csv, $texto, $d['delimitador'], $d['codificacao']);
        $this->assertSame('João', $linhas[1][0]);
    }

    /**
     * BOM deixado no lugar põe três bytes invisíveis na frente do primeiro nome de
     * coluna, que então nunca casa com o nome escolhido pelo operador. O defeito é
     * invisível na tela: a coluna PARECE chamar "Nome".
     */
    #[Test]
    public function remove_o_bom_do_primeiro_cabecalho(): void
    {
        $csv = new Csv();
        $texto = "\xEF\xBB\xBFNome;Telefone\nMaria;11987654321\n";

        $linhas = $this->ler($csv, $texto, ';', 'UTF-8');

        $this->assertSame('Nome', $linhas[0][0]);
        $this->assertNotSame("\xEF\xBB\xBFNome", $linhas[0][0]);
    }

    #[Test]
    public function respeita_campo_entre_aspas_com_o_delimitador_dentro(): void
    {
        $linhas = $this->ler(new Csv(), "Nome;Obs\n\"Silva; Maria\";ok\n", ';', 'UTF-8');

        $this->assertSame('Silva; Maria', $linhas[1][0]);
    }

    #[Test]
    public function pula_linha_vazia_sem_encerrar_a_leitura(): void
    {
        $linhas = $this->ler(new Csv(), "Nome;Tel\nMaria;1\n\nJoao;2\n", ';', 'UTF-8');

        // Três: cabeçalho e dois dados. A linha vazia não interrompe.
        $this->assertCount(3, $linhas);
        $this->assertSame('Joao', $linhas[2][0]);
    }

    #[Test]
    public function aceita_crlf_do_windows(): void
    {
        $linhas = $this->ler(new Csv(), "Nome;Tel\r\nMaria;11987654321\r\n", ';', 'UTF-8');

        $this->assertSame(['Nome', 'Tel'], $linhas[0]);
        $this->assertSame('11987654321', $linhas[1][1], 'sem \r pendurado no fim');
    }

    /**
     * A barra invertida NÃO é escape em CSV. Com o padrão do PHP, `C:\Users\maria`
     * consumiria a aspa seguinte e o estrago apareceria na coluna DEPOIS — que pode
     * ser a do telefone.
     */
    #[Test]
    public function barra_invertida_nao_e_escape(): void
    {
        $linhas = $this->ler(new Csv(), "Caminho;Tel\n\"C:\\Users\\maria\";11987654321\n", ';', 'UTF-8');

        $this->assertSame('C:\\Users\\maria', $linhas[1][0]);
        $this->assertSame('11987654321', $linhas[1][1], 'a coluna seguinte não foi contaminada');
    }

    /** @return list<list<string>> */
    private function ler(Csv $csv, string $conteudo, string $delimitador, string $codificacao): array
    {
        $fluxo = fopen('php://memory', 'r+');
        self::assertIsResource($fluxo);
        fwrite($fluxo, $conteudo);
        rewind($fluxo);

        $linhas = [];
        foreach ($csv->linhas($fluxo, $delimitador, $codificacao) as $l) {
            $linhas[] = $l;
        }
        fclose($fluxo);

        return $linhas;
    }
}
