<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use App\Worker\Whatsapp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O componente `header` do template — a falta dele é o `(#132000)` medido em
 * 25/08/2026, que deixou dois templates aprovados inenviáveis.
 */
final class WhatsappCabecalhoTest extends TestCase
{
    private const URL = 'https://exemplo.test/imagem/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function espiao(): TransporteFalso
    {
        // O dublê que já existe. Inventar outro criaria duas verdades sobre como o
        // transporte se comporta, e elas divergiriam na primeira mudança.
        return new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');
    }

    /**
     * 🔴 O CASO QUE FALHAVA EM PRODUÇÃO.
     *
     * `abordagem_liv_pts_2` declara `HEADER format=IMAGE` e corpo `Olá, {{1}}!`. Sem o
     * componente de cabeçalho a Meta recusa TUDO com "Number of parameters does not
     * match the expected number of params" — mensagem que fala de parâmetros e não
     * menciona imagem, o que manda quem investiga para o lado errado.
     *
     * A ordem é conferida junto: cabeçalho antes do corpo, como o template aparece no
     * gerenciador da Meta. A Meta aceita qualquer ordem; divergir só atrapalha quem
     * for comparar um envio recusado com o que ela mostra.
     */
    #[Test]
    public function template_com_imagem_manda_header_e_body(): void
    {
        $t = $this->espiao();
        (new Whatsapp($t, 'tok', '123', 'v21.0'))->enviarTemplate(
            '5511999999999',
            'abordagem_liv_pts_2',
            'pt_BR',
            ['Maria'],
            null,
            self::URL,
        );

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);

        $this->assertSame([
            [
                'type' => 'header',
                'parameters' => [['type' => 'image', 'image' => ['link' => self::URL]]],
            ],
            [
                'type' => 'body',
                'parameters' => [['type' => 'text', 'text' => 'Maria']],
            ],
        ], $tpl['components']);
    }

    /**
     * 🔴 CABEÇALHO A MAIS É O MESMO ERRO AO CONTRÁRIO.
     *
     * Template sem `HEADER` que receba um componente de cabeçalho é recusado pela Meta
     * exatamente como o caso de cima. Por isso o envio só o emite quando recebe a URL,
     * e quem decide isso é o formato declarado pelo template — lido em
     * `TemplatesAprovados::cabecalho`, não escolhido pelo operador.
     */
    #[Test]
    public function template_sem_imagem_nao_manda_header(): void
    {
        $t = $this->espiao();
        (new Whatsapp($t, 'tok', '123', 'v21.0'))
            ->enviarTemplate('5511999999999', 'abordagem_liv_link', 'pt_BR', [], null, null);

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertArrayNotHasKey(
            'components',
            $tpl,
            'sem parâmetro e sem imagem, o template vai sem componente nenhum',
        );
    }

    /** Só imagem, sem parâmetro de corpo: manda apenas o `header`. */
    #[Test]
    public function so_imagem_manda_so_o_header(): void
    {
        $t = $this->espiao();
        (new Whatsapp($t, 'tok', '123', 'v21.0'))
            ->enviarTemplate('5511999999999', 'x', 'pt_BR', [], null, self::URL);

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertSame([[
            'type' => 'header',
            'parameters' => [['type' => 'image', 'image' => ['link' => self::URL]]],
        ]], $tpl['components']);
    }

    /**
     * URL vazia conta como ausente.
     *
     * Não é zelo: `Worker\FilaDeAcoes` faz `LEFT JOIN` na galeria, então Campanha sem
     * imagem traz o token nulo, e a montagem no `bin/worker.php` produz string vazia
     * quando a base de URL não está configurada. Cabeçalho com `link` em branco seria
     * recusado pela Meta como mídia inválida — o mesmo sintoma, causa diferente.
     */
    #[Test]
    public function url_vazia_nao_vira_cabecalho(): void
    {
        $t = $this->espiao();
        (new Whatsapp($t, 'tok', '123', 'v21.0'))
            ->enviarTemplate('5511999999999', 'x', 'pt_BR', ['a'], null, '');

        $tpl = $t->corpo['template'];
        $this->assertIsArray($tpl);
        $this->assertSame([[
            'type' => 'body',
            'parameters' => [['type' => 'text', 'text' => 'a']],
        ]], $tpl['components']);
    }
}
