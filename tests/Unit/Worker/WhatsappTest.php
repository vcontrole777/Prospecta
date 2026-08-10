<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use App\Worker\Whatsapp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WhatsappTest extends TestCase
{
    #[Test]
    public function monta_a_requisicao_de_template(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.XYZ"}]}');

        $e = (new Whatsapp($t, 'tok', '123456', 'v21.0'))
            ->enviarTemplate('5511999999999', 'boas_vindas', 'pt_BR', ['Maria', 'R$ 250'], 'ligacao:42');

        $this->assertSame('https://graph.facebook.com/v21.0/123456/messages', $t->url);
        $this->assertSame('Bearer tok', $t->cabecalhos['Authorization'] ?? null);

        $this->assertSame('whatsapp', $t->corpo['messaging_product'] ?? null);
        $this->assertSame('5511999999999', $t->corpo['to'] ?? null);
        $this->assertSame('template', $t->corpo['type'] ?? null);

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertSame('boas_vindas', $tpl['name']);
        $this->assertSame(['code' => 'pt_BR'], $tpl['language']);

        // Parâmetros POSICIONAIS, na ordem, cada um como {type:text,text:...}.
        $this->assertSame([[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'Maria'],
                ['type' => 'text', 'text' => 'R$ 250'],
            ],
        ]], $tpl['components']);

        // Correlação que volta no webhook — fecha o laço sem tabela de tradução.
        $this->assertSame('ligacao:42', $t->corpo['biz_opaque_callback_data'] ?? null);

        $this->assertTrue($e->aceito());
        $this->assertSame('wamid.XYZ', $e->wamid);
    }

    /**
     * 🔴 Nenhum campo de BOTÃO no envio.
     *
     * Resposta rápida é definida na CRIAÇÃO do template. É fácil errar porque
     * procurar "como mandar botões" leva a exemplos de mensagem interativa, que é
     * outro recurso — e cujo payload é recusado para template. Este teste falha se
     * alguém acrescentar `buttons` ou `interactive` aqui.
     */
    #[Test]
    public function nao_manda_botao_no_payload(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.1"}]}');
        (new Whatsapp($t, 'tok', '1'))->enviarTemplate('5511999999999', 'tpl', 'pt_BR', []);

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertArrayNotHasKey('buttons', $tpl);
        $this->assertArrayNotHasKey('interactive', $t->corpo);
        $this->assertArrayNotHasKey('components', $tpl, 'sem parâmetro, sem components');
    }

    #[Test]
    public function le_o_erro_da_graph_api_por_inteiro(): void
    {
        $corpo = json_encode([
            'error' => [
                'message' => 'Recipient limit reached',
                'code' => 131049,
                'is_transient' => true,
                'error_user_title' => 'Não entregue',
                'error_user_msg' => 'Esta pessoa já recebeu muitas mensagens hoje.',
                'fbtrace_id' => 'AbCdEf123',
            ],
        ]);
        $this->assertIsString($corpo);

        $e = (new Whatsapp(new TransporteFalso(400, $corpo), 'tok', '1'))
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', []);

        $this->assertFalse($e->aceito());
        $this->assertSame(131049, $e->codigo);
        $this->assertTrue($e->transitorio);
        // Texto que a Meta escreveu PARA o usuário: mostrar é melhor que parafrasear.
        $this->assertSame('Esta pessoa já recebeu muitas mensagens hoje.', $e->mensagemDoUsuario);
        // O que um ticket de suporte com a Meta exige.
        $this->assertSame('AbCdEf123', $e->fbtrace);
    }

    #[Test]
    public function falha_de_conexao_e_marcada_como_sem_resposta(): void
    {
        $e = (new Whatsapp(new TransporteFalso(0, '', 'Connection timed out'), 'tok', '1'))
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', []);

        $this->assertTrue($e->semResposta, 'é o único caso em que se SABE que nada saiu');
        $this->assertSame('Connection timed out', $e->mensagem);
    }

    #[Test]
    public function corpo_que_nao_e_json_nao_e_aceito(): void
    {
        $e = (new Whatsapp(new TransporteFalso(200, '<html>502 Bad Gateway</html>'), 'tok', '1'))
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', []);

        $this->assertFalse($e->aceito());
        $this->assertNull($e->wamid);
        $this->assertFalse($e->semResposta, 'houve resposta; ela é que não serve');
    }

    #[Test]
    public function a_versao_da_api_entra_na_url(): void
    {
        $t = new TransporteFalso(200, '{}');
        (new Whatsapp($t, 'tok', '999', 'v23.0'))->enviarTemplate('5511999999999', 'tpl', 'pt_BR', []);

        $this->assertSame('https://graph.facebook.com/v23.0/999/messages', $t->url);
    }
}
