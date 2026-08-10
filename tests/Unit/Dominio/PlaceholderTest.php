<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\ErroDeDominio;
use App\Dominio\Placeholder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Estende TestCase do PHPUnit, NÃO o TestCase do Laravel: a classe é pura por
 * exigência do ADR-0006, e um teste que sobe o framework esconderia uma
 * dependência acidental dele.
 */
final class PlaceholderTest extends TestCase
{
    private Placeholder $p;

    protected function setUp(): void
    {
        $this->p = new Placeholder();
    }

    #[Test]
    public function substitui_coluna_citada_no_texto(): void
    {
        $r = $this->p->resolverTexto(
            'Olá, {Nome do Lead}, a {Empresa} tem uma proposta.',
            ['Nome do Lead' => 'João', 'Empresa' => 'Acme'],
        );

        $this->assertSame('Olá, João, a Acme tem uma proposta.', $r);
    }

    #[Test]
    public function coluna_ausente_falha_com_causa_nomeada(): void
    {
        try {
            $this->p->resolverTexto('Olá, {Empresa}', ['Nome do Lead' => 'João']);
            $this->fail('devia ter falhado');
        } catch (ErroDeDominio $e) {
            $this->assertSame('COLUNA_AUSENTE', $e->causa);
        }
    }

    #[Test]
    public function valor_vazio_falha_em_vez_de_produzir_frase_quebrada(): void
    {
        // "Olá, , a  tem uma proposta" tocado para um Lead real é pior que não
        // ligar. E como a mesma classe roda na importação, o operador descobre
        // no upload.
        foreach (['', '   '] as $vazio) {
            try {
                $this->p->resolverTexto('Olá, {Empresa}', ['Empresa' => $vazio]);
                $this->fail('devia ter falhado');
            } catch (ErroDeDominio $e) {
                $this->assertSame('VALOR_VAZIO', $e->causa);
            }
        }
    }

    #[Test]
    public function lista_as_colunas_que_o_texto_exige_sem_repetir(): void
    {
        $this->assertSame(
            ['Nome do Lead', 'Empresa'],
            $this->p->colunasCitadas('{Nome do Lead}, {Empresa} e {Nome do Lead}'),
        );
    }

    #[Test]
    public function parametros_de_template_saem_na_ordem_numerica(): void
    {
        // Mapa fora de ordem de propósito: a saída tem que ser 1,2,3.
        $r = $this->p->parametrosTemplate(
            ['2' => 'Empresa', '1' => 'Nome', '3' => 'Cidade'],
            ['Nome' => 'João', 'Empresa' => 'Acme', 'Cidade' => 'Goiânia'],
        );

        $this->assertSame(['João', 'Acme', 'Goiânia'], $r);
    }

    #[Test]
    public function mapa_com_lacuna_falha_porque_a_meta_exige_sequencia(): void
    {
        try {
            $this->p->parametrosTemplate(['1' => 'Nome', '3' => 'Cidade'], ['Nome' => 'a', 'Cidade' => 'b']);
            $this->fail('devia ter falhado');
        } catch (ErroDeDominio $e) {
            $this->assertSame('MAPA_NAO_SEQUENCIAL', $e->causa);
        }
    }

    #[Test]
    public function mapa_que_nao_comeca_em_um_falha(): void
    {
        try {
            $this->p->parametrosTemplate(['2' => 'Nome'], ['Nome' => 'a']);
            $this->fail('devia ter falhado');
        } catch (ErroDeDominio $e) {
            $this->assertSame('MAPA_NAO_SEQUENCIAL', $e->causa);
        }
    }

    #[Test]
    public function valor_com_quebra_de_linha_ou_espaco_longo_nao_serve_de_parametro(): void
    {
        // Falhamos aqui em vez de sanitizar: alterar o dado do Lead em silêncio
        // produz mensagem errada, que é pior que mensagem não enviada.
        foreach (["Acme\nLtda", "Acme\tLtda", 'Acme     Ltda'] as $ruim) {
            try {
                $this->p->parametrosTemplate(['1' => 'Empresa'], ['Empresa' => $ruim]);
                $this->fail('devia ter falhado para ' . json_encode($ruim));
            } catch (ErroDeDominio $e) {
                $this->assertSame('VALOR_INVALIDO_TEMPLATE', $e->causa);
            }
        }
    }
}
