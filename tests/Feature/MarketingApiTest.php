<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Configuracao;
use App\Models\User;
use App\Worker\FilaDeAcoes;
use App\Worker\Whatsapp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Worker\TransporteFalso;

/**
 * A Marketing Messages API da Meta — caminho otimizado para Disparo de marketing.
 *
 * 🔴 EXISTE POR UM PROBLEMA MEDIDO, não por novidade. Depois que o `(#132000)` foi
 * consertado, o que sobrou barrando entrega foi `In order to maintain a healthy
 * ecosystem engagement, the message failed to be delivered` — a Meta ACEITANDO a
 * mensagem e depois desistindo de entregar. A MM API é o caminho dela para isso.
 *
 * Não anula política nem qualidade do número, e a tela diz isso.
 */
final class MarketingApiTest extends TestCase
{
    use RefreshDatabase;

    private function whatsapp(TransporteFalso $t): Whatsapp
    {
        return new Whatsapp($t, 'tok', '123456', 'v21.0');
    }

    /**
     * 🔴 O CORPO É IDÊNTICO; muda só o recurso.
     *
     * É por isso que não há um segundo método de envio: duplicar a montagem dos
     * componentes para trocar uma palavra na URL criaria duas verdades sobre a forma
     * da mensagem, e elas divergiriam na primeira mudança.
     */
    #[Test]
    public function o_disparo_de_marketing_vai_para_o_recurso_marketing_messages(): void
    {
        $normal = new TransporteFalso(200, '{"messages":[{"id":"wamid.A"}]}');
        $this->whatsapp($normal)
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', ['Ana'], 'ligacao:1', null, [], false);

        $marketing = new TransporteFalso(200, '{"messages":[{"id":"wamid.B"}]}');
        $this->whatsapp($marketing)
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', ['Ana'], 'ligacao:1', null, [], true);

        $this->assertSame('https://graph.facebook.com/v21.0/123456/messages', $normal->url);
        $this->assertSame('https://graph.facebook.com/v21.0/123456/marketing_messages', $marketing->url);

        // O corpo tem que ser o MESMO nos dois. Se um dia divergir, é aqui que aparece.
        $this->assertSame($normal->corpo, $marketing->corpo);
    }

    /**
     * 🔴 NÃO MANDAMOS `message_activity_sharing`.
     *
     * Omitindo o campo, vale o que o operador configurar no painel da Meta — a
     * decisão de compartilhar evento de entrega e leitura com ela fica onde ele pode
     * mudá-la sozinho, em vez de fixa no nosso código.
     */
    #[Test]
    public function nao_decide_pelo_operador_o_compartilhamento_de_eventos(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');
        $this->whatsapp($t)
            ->enviarTemplate('5511999999999', 'tpl', 'pt_BR', [], null, null, [], true);

        $this->assertArrayNotHasKey('message_activity_sharing', $t->corpo);
    }

    /**
     * 🔴 TEXTO LIVRE NUNCA VAI PELA MARKETING API.
     *
     * Resposta do operador dentro da janela de 24 h não é marketing, e a MM API não
     * a aceita. `enviarTexto` nem tem como escolher — ele só conhece o `/messages`.
     */
    #[Test]
    public function a_resposta_do_operador_continua_no_endpoint_normal(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');
        $this->whatsapp($t)->enviarTexto('5511999999999', 'Oi', 'conversa:1');

        $this->assertSame('https://graph.facebook.com/v21.0/123456/messages', $t->url);
    }

    /** O botão de link também é conversa, não marketing. */
    #[Test]
    public function o_texto_com_botao_continua_no_endpoint_normal(): void
    {
        $t = new TransporteFalso(200, '{"messages":[{"id":"wamid.X"}]}');
        $this->whatsapp($t)
            ->enviarTextoComBotao('5511999999999', 'Oi', 'Ver', 'https://a.test', 'conversa:1');

        $this->assertSame('https://graph.facebook.com/v21.0/123456/messages', $t->url);
    }

    // ---- a chave ----------------------------------------------------------

    /**
     * 🔴 NASCE DESLIGADA, e não é conservadorismo vago: a MM API só aceita envio
     * depois que a WABA chega a `ONBOARDED`. Aceitar os Termos a deixa em
     * `PENDING_INTERNAL_SETUP` por um tempo, e ligar antes faz todo Disparo bater
     * num endpoint que recusa.
     */
    #[Test]
    public function a_chave_nasce_desligada(): void
    {
        $this->assertFalse((new FilaDeAcoes($this->pdo()))->marketingApiAtiva());
    }

    #[Test]
    public function o_worker_le_a_chave_do_banco(): void
    {
        DB::table('configuracao')->where('id', 1)->update(['marketing_api_ativa' => 1]);

        $this->assertTrue((new FilaDeAcoes($this->pdo()))->marketingApiAtiva());
    }

    #[Test]
    public function a_tela_liga_e_desliga(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Configuracao::class)
            ->assertSet('marketing_api_ativa', false)
            ->set('marketing_api_ativa', true)
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame(1, (int) DB::table('configuracao')->value('marketing_api_ativa'));

        Livewire::test(Configuracao::class)
            ->assertSet('marketing_api_ativa', true)
            ->set('marketing_api_ativa', false)
            ->call('salvar');

        $this->assertSame(0, (int) DB::table('configuracao')->value('marketing_api_ativa'));
    }

    /** A tela diz o que a chave NÃO resolve — senão ela vira promessa vaga. */
    #[Test]
    public function a_tela_nomeia_a_condicao_de_uso(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('configuracao'))
            ->assertOk()
            ->assertSee('ONBOARDED', escape: false)
            ->assertSee('healthy ecosystem engagement', escape: false);
    }

    private function pdo(): PDO
    {
        /** @var PDO $pdo */
        $pdo = DB::connection()->getPdo();

        return $pdo;
    }
}
