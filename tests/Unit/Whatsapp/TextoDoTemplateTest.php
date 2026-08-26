<?php

declare(strict_types=1);

namespace Tests\Unit\Whatsapp;

use App\Whatsapp\TextoDoTemplate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O texto que o Lead realmente recebeu.
 *
 * 🔴 A CAIXA DE ENTRADA MOSTRAVA `(template)` E MAIS NADA. Medido em 26/08/2026:
 * das 243 mensagens da base, 213 eram `saida/template` sem texto — o worker gravava
 * string vazia, porque tem os parâmetros mas nunca teve o corpo. O operador via que
 * ALGO foi enviado e não o quê.
 */
final class TextoDoTemplateTest extends TestCase
{
    /** O caso real da conta: `abordagem_liv_pts_2`, posicional com um parâmetro. */
    #[Test]
    public function monta_o_corpo_posicional(): void
    {
        $this->assertSame(
            'Olá, Karen! Há pontos disponíveis para consulta e resgate.',
            (new TextoDoTemplate())->montar(
                'Olá, {{1}}! Há pontos disponíveis para consulta e resgate.',
                ['Karen'],
            ),
        );
    }

    /** `abordagem_liv_pts` usa dois, e a ORDEM é a da lista. */
    #[Test]
    public function respeita_a_ordem_dos_parametros_posicionais(): void
    {
        $this->assertSame(
            'Olá, Ana! CPF 123.',
            (new TextoDoTemplate())->montar('Olá, {{1}}! CPF {{2}}.', ['Ana', '123']),
        );
    }

    /** Template NOMEADO: a chave é o nome, não a posição. */
    #[Test]
    public function monta_o_corpo_nomeado(): void
    {
        $this->assertSame(
            'Olá, Ana! Seu CPF é 123.',
            (new TextoDoTemplate())->montar(
                'Olá, {{nome}}! Seu CPF é {{documento}}.',
                ['Ana', '123'],
                ['nome', 'documento'],
            ),
        );
    }

    /** O mesmo parâmetro repetido no corpo é substituído em todas as ocorrências. */
    #[Test]
    public function substitui_todas_as_ocorrencias(): void
    {
        $this->assertSame(
            'Ana, confira. Até logo, Ana!',
            (new TextoDoTemplate())->montar('{{1}}, confira. Até logo, {{1}}!', ['Ana']),
        );
    }

    /** A Meta aceita espaço dentro das chaves; o corpo aprovado pode vir assim. */
    #[Test]
    public function tolera_espaco_dentro_das_chaves(): void
    {
        $this->assertSame(
            'Olá, Ana!',
            (new TextoDoTemplate())->montar('Olá, {{ 1 }}!', ['Ana']),
        );
    }

    /**
     * 🔴 VALOR COM `$` NÃO PODE VIRAR REFERÊNCIA DE CAPTURA.
     *
     * `preg_replace` lê `$0`, `\1` e afins no SUBSTITUTO como referência ao que casou
     * — um Lead chamado "R$ 1" viraria lixo, ou pior, o próprio texto do template. É
     * o tipo de defeito que só aparece com o dado de uma pessoa específica.
     */
    #[Test]
    public function valor_com_cifrao_e_barra_sai_literal(): void
    {
        $t = new TextoDoTemplate();

        $this->assertSame('Valor: R$ 1', $t->montar('Valor: {{1}}', ['R$ 1']));
        $this->assertSame('Nome: A\\B', $t->montar('Nome: {{1}}', ['A\\B']));
        $this->assertSame('X: $0', $t->montar('X: {{1}}', ['$0']));
    }

    /**
     * Sem corpo guardado — Campanha criada antes da coluna existir — sobra o que se
     * sabe. Os parâmetros dizem PARA QUEM a mensagem foi personalizada, que é o que
     * o operador procura ao abrir a conversa; melhor que vazio.
     */
    #[Test]
    public function sem_corpo_cai_nos_parametros(): void
    {
        $t = new TextoDoTemplate();

        $this->assertSame('Ana · 123', $t->montar('', ['Ana', '123']));
        $this->assertSame('', $t->montar('', []), 'sem corpo e sem parâmetro não há o que dizer');
        $this->assertSame('', $t->montar('   ', []));
    }

    /** Template sem parâmetro sai como está. */
    #[Test]
    public function corpo_sem_parametro_sai_intacto(): void
    {
        $this->assertSame(
            'Olá! Consulte seu saldo.',
            (new TextoDoTemplate())->montar('Olá! Consulte seu saldo.', []),
        );
    }

    /**
     * Parâmetro a MENOS que o corpo pede deixa a chave visível, e isso é melhor que
     * esconder: a mensagem que saiu tinha esse problema, e apagar a chave faria a
     * tela mentir sobre o que a pessoa recebeu.
     */
    #[Test]
    public function chave_sem_valor_permanece_visivel(): void
    {
        $this->assertSame(
            'Olá, Ana! CPF {{2}}.',
            (new TextoDoTemplate())->montar('Olá, {{1}}! CPF {{2}}.', ['Ana']),
        );
    }
}
