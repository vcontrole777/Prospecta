<?php

declare(strict_types=1);

namespace Tests\Unit\Whatsapp;

use App\Whatsapp\Leitura;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeituraTest extends TestCase
{
    #[Test]
    public function le_status_de_entrega_com_correlacao(): void
    {
        $l = Leitura::de($this->payload([
            'statuses' => [[
                'id' => 'wamid.AAA',
                'status' => 'delivered',
                'recipient_id' => '5511987654321',
                'biz_opaque_callback_data' => 'ligacao:42',
            ]],
        ]));

        $this->assertCount(1, $l->status);
        $this->assertSame('wamid.AAA', $l->status[0]->wamid);
        $this->assertSame('delivered', $l->status[0]->estado);
        // O campo que fecha o laço sem tabela de tradução.
        $this->assertSame('ligacao:42', $l->status[0]->correlacao);
    }

    /** `played` é o quinto estado. Código que assume quatro o trata como desconhecido. */
    #[Test]
    public function le_os_cinco_estados(): void
    {
        foreach (['sent', 'delivered', 'read', 'played', 'failed'] as $e) {
            $l = Leitura::de($this->payload(['statuses' => [['id' => 'w', 'status' => $e]]]));
            $this->assertSame($e, $l->status[0]->estado ?? null, $e);
        }
    }

    #[Test]
    public function le_o_erro_do_status_com_o_texto_para_o_usuario(): void
    {
        $l = Leitura::de($this->payload([
            'statuses' => [[
                'id' => 'wamid.X', 'status' => 'failed',
                'errors' => [[
                    'code' => 131049,
                    'title' => 'Não entregue',
                    'error_data' => ['details' => 'Esta pessoa já recebeu muitas mensagens hoje.'],
                ]],
            ]],
        ]));

        $this->assertSame(131049, $l->status[0]->erroCodigo);
        // `details` primeiro: é o texto que a Meta escreveu PARA o usuário final, e
        // mostrar o dela é melhor que parafrasear.
        $this->assertSame('Esta pessoa já recebeu muitas mensagens hoje.', $l->status[0]->erroTexto);
    }

    #[Test]
    public function le_mensagem_de_texto(): void
    {
        $l = Leitura::de($this->payload([
            'messages' => [[
                'id' => 'wamid.M1', 'from' => '5511987654321',
                'type' => 'text', 'text' => ['body' => 'Quero saber mais'],
            ]],
        ]));

        $this->assertCount(1, $l->entradas);
        $this->assertSame('5511987654321', $l->entradas[0]->de);
        $this->assertSame('Quero saber mais', $l->entradas[0]->texto);
        $this->assertNull($l->entradas[0]->botao);
    }

    /**
     * 🔴 Botão tem DUAS formas no payload, em caminhos diferentes. Tratar só uma faz
     * metade dos toques parecer mensagem sem texto — e é o toque no botão que abre a
     * janela de 24 h.
     */
    #[Test]
    public function le_as_duas_formas_de_botao(): void
    {
        $template = Leitura::de($this->payload([
            'messages' => [[
                'id' => 'w1', 'from' => '5511900000001', 'type' => 'button',
                'button' => ['text' => 'Quero sim', 'payload' => 'aceito'],
            ]],
        ]));
        $this->assertSame('Quero sim', $template->entradas[0]->texto);
        $this->assertSame('aceito', $template->entradas[0]->botao);

        $interativo = Leitura::de($this->payload([
            'messages' => [[
                'id' => 'w2', 'from' => '5511900000002', 'type' => 'interactive',
                'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'opt_out', 'title' => 'Sair']],
            ]],
        ]));
        $this->assertSame('Sair', $interativo->entradas[0]->texto);
        $this->assertSame('opt_out', $interativo->entradas[0]->botao);
    }

    /**
     * A documentação da Meta é a que envelhece mais rápido do projeto. Payload com
     * campo ausente, forma inesperada ou tipo novo não pode derrubar o worker.
     */
    #[Test]
    public function sobrevive_a_payload_incompleto_ou_estranho(): void
    {
        // Payloads completos, cada um quebrado num nível diferente do aninhamento.
        $completos = [
            'vazio' => [],
            'entry não é lista' => ['entry' => 'x'],
            'changes ausente' => ['entry' => [['id' => '1']]],
            'value ausente' => ['entry' => [['changes' => [['field' => 'messages']]]]],
            'item da lista não é array' => ['entry' => [['changes' => [[
                'field' => 'messages', 'value' => ['messages' => ['x']],
            ]]]]],
        ];

        foreach ($completos as $rot => $p) {
            $l = Leitura::de($p);
            $this->assertSame([], $l->status, $rot);
            $this->assertSame([], $l->entradas, $rot);
        }

        // Estes já vêm embrulhados na forma boa; o defeito está no item.
        $itens = [
            'status sem id' => ['statuses' => [['status' => 'read']]],
            'status sem estado' => ['statuses' => [['id' => 'w']]],
            'mensagem sem from' => ['messages' => [['id' => 'w', 'type' => 'text']]],
            'mensagem sem id' => ['messages' => [['from' => '5511900000001', 'type' => 'text']]],
        ];

        foreach ($itens as $rot => $valor) {
            $l = Leitura::de($this->payload($valor));
            $this->assertSame([], $l->status, $rot);
            $this->assertSame([], $l->entradas, $rot);
        }
    }

    /** Campo que não é `messages` é registrado, não descartado em silêncio. */
    #[Test]
    public function registra_campo_desconhecido_para_o_log(): void
    {
        $l = Leitura::de([
            'entry' => [['changes' => [
                ['field' => 'calls', 'value' => []],
                ['field' => 'account_update', 'value' => []],
                ['field' => 'calls', 'value' => []],
            ]]],
        ]);

        $this->assertSame(['calls', 'account_update'], $l->camposIgnorados, 'sem repetir');
        $this->assertSame([], $l->entradas);
    }

    #[Test]
    public function le_varios_eventos_no_mesmo_payload(): void
    {
        $l = Leitura::de($this->payload([
            'statuses' => [['id' => 'w1', 'status' => 'sent'], ['id' => 'w2', 'status' => 'read']],
            'messages' => [['id' => 'w3', 'from' => '5511900000001', 'type' => 'text', 'text' => ['body' => 'oi']]],
        ]));

        $this->assertCount(2, $l->status);
        $this->assertCount(1, $l->entradas);
    }

    /**
     * @param array<string,mixed> $valor
     * @return array<string,mixed>
     */
    private function payload(array $valor): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [['field' => 'messages', 'value' => array_merge(
                    ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '555000']],
                    $valor,
                )]],
            ]],
        ];
    }
}
