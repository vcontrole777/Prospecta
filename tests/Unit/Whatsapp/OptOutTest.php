<?php

declare(strict_types=1);

namespace Tests\Unit\Whatsapp;

use App\Whatsapp\Entrada;
use App\Whatsapp\OptOut;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A assimetria manda: falso positivo bloqueia o Lead na lista GLOBAL, para sempre,
 * em toda Campanha. Falso negativo custa ele repetir.
 */
final class OptOutTest extends TestCase
{
    #[Test]
    #[DataProvider('pedidos')]
    public function reconhece_pedido_de_descadastro(string $texto): void
    {
        $this->assertTrue((new OptOut())->pediu(new Entrada('w', '5511900000001', 'text', $texto)), $texto);
    }

    /** @return iterable<string, array{string}> */
    public static function pedidos(): iterable
    {
        foreach ([
            'sair', 'SAIR', 'Parar', 'pare', 'para', 'chega',
            'cancelar', 'descadastrar', 'remover', 'me tira da lista',
            'não quero', 'nao quero mais', 'não me ligue mais', 'não perturbe',
            'pode parar de me ligar', 'pare de me ligar',
            'stop', 'unsubscribe',
            // Pontuação e espaço não podem transformar cortesia em pegadinha.
            'PARAR!', '  sair  ', 'cancelar.', 'não quero!!!',
        ] as $t) {
            yield $t => [$t];
        }
    }

    /**
     * 🔴 OS FALSOS POSITIVOS QUE UM `str_contains` CRIARIA. Cada um destes é um Lead
     * bloqueado para sempre, em toda Campanha, sem ninguém saber por quê.
     */
    #[Test]
    #[DataProvider('naoPedidos')]
    public function nao_confunde_conversa_normal_com_descadastro(string $texto): void
    {
        $this->assertFalse((new OptOut())->pediu(new Entrada('w', '5511900000001', 'text', $texto)), $texto);
    }

    /** @return iterable<string, array{string}> */
    public static function naoPedidos(): iterable
    {
        foreach ([
            'vou parar no mercado antes',
            'pode me ligar para explicar',
            'quanto sai a parcela',
            'preciso cancelar meu outro contrato, esse eu quero',
            'me liga que eu quero saber mais',
            'para que horas você liga',
            'não quero perder essa chance',
            'chega amanhã?',
            'onde fica a loja',
            '',
        ] as $t) {
            yield ($t === '' ? '(vazio)' : $t) => [$t];
        }
    }

    /** Botão é sinal FORTE: o operador escolheu o payload ao criar o template. */
    #[Test]
    public function reconhece_botao_de_descadastro_por_prefixo(): void
    {
        $o = new OptOut();

        foreach (['opt_out', 'optout', 'opt_out_2', 'sair', 'cancelar_agora', 'nao_quero'] as $p) {
            $this->assertTrue($o->pediu(new Entrada('w', '5511900000001', 'button', 'Qualquer texto', $p)), $p);
        }

        foreach (['aceito', 'quero_sim', 'saber_mais'] as $p) {
            $this->assertFalse($o->pediu(new Entrada('w', '5511900000001', 'button', 'Quero sim', $p)), $p);
        }
    }

    /**
     * 🔴 O RÓTULO DO BOTÃO TAMBÉM CONTA, e isso é decisão, não acidente.
     *
     * Um botão com payload `aceito` e rótulo "Sair" é botão mal nomeado pelo
     * operador. O payload é o id interno dele; o RÓTULO é o que a pessoa leu e tocou.
     * Diante da ambiguidade, honrar o que a pessoa viu é a leitura segura para ela —
     * a confusão é de quem escreveu o template.
     *
     * Descobri isto porque o teste falhou esperando o contrário, e o comportamento
     * estava mais certo que a minha expectativa.
     */
    #[Test]
    public function o_rotulo_visivel_do_botao_vale_mesmo_com_payload_de_aceite(): void
    {
        $o = new OptOut();

        $this->assertTrue(
            $o->pediu(new Entrada('w', '5511900000001', 'button', 'Sair', 'aceito')),
            'a pessoa tocou num botão escrito "Sair"',
        );
    }
}
