<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Seguranca\Cofre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WebhookWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'app-secret-da-meta';

    private const VERIFY = 'token-que-o-operador-escolheu';

    protected function setUp(): void
    {
        parent::setUp();

        $chave = config('app.key');
        $cofre = new Cofre(is_string($chave) ? $chave : '');

        $id = DB::table('integracao')->insertGetId([
            'tipo' => 'whatsapp', 'provedor' => 'meta', 'nome' => 'Conta', 'ativo' => 1,
            'credenciais' => $cofre->cifrarParaColuna([
                'token' => 'EAAG-x', 'phone_number_id' => '555000', 'versao_api' => 'v21.0',
                'app_secret' => self::SEGREDO, 'verify_token' => self::VERIFY,
            ]),
        ]);
        DB::table('configuracao')->where('id', 1)->update(['integracao_whatsapp_id' => $id]);
    }

    // ---- aperto de mão ---------------------------------------------------

    #[Test]
    public function devolve_o_desafio_no_aperto_de_mao(): void
    {
        $this->get('/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=' . self::VERIFY . '&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    #[Test]
    public function recusa_aperto_de_mao_com_token_errado_ou_modo_errado(): void
    {
        $this->get('/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=errado&hub_challenge=1')
            ->assertStatus(403);

        $this->get('/webhook/whatsapp?hub_mode=outro&hub_verify_token=' . self::VERIFY . '&hub_challenge=1')
            ->assertStatus(403);
    }

    // ---- recebimento -----------------------------------------------------

    /**
     * 🔴 A rota é PÚBLICA e o POST não tem token CSRF — quem chama é máquina.
     *
     * Sem a exclusão de CSVF em bootstrap/app.php, todo POST da Meta voltaria 419.
     * E 419 é não-200, então ela reentrega o MESMO evento em laço, indefinidamente,
     * enquanto o log enche de 419 que ninguém liga a CSRF. Este teste falha se a
     * exclusão sair.
     */
    #[Test]
    public function aceita_post_assinado_sem_sessao_nem_csrf(): void
    {
        $corpo = '{"object":"whatsapp_business_account","entry":[{"id":"1"}]}';

        $this->postJson('/webhook/whatsapp', [], $this->cabecalhos($corpo), )
            ->assertStatus(403);   // sem corpo, a assinatura não confere

        $this->enviar($corpo)->assertOk();

        $this->assertSame(1, DB::table('evento_whatsapp')->count());

        // 🔴 Comparação SEMÂNTICA, não byte a byte. A coluna `payload` é do tipo
        // `JSON` e o MySQL NORMALIZA o que entra — remove espaços e reordena chaves.
        // Então o que está guardado não são os bytes que a Meta assinou, e a
        // assinatura NÃO pode ser reverificada a partir da tabela. Para o
        // processamento isso é indiferente; para perícia, é uma limitação real, e
        // está registrada no comentário da migration.
        // `assertEquals` e não `assertSame`: o MySQL REORDENA as chaves do objeto, e
        // `assertSame` em array associativo é sensível à ordem. Confirmado aqui — é a
        // demonstração de que a normalização acontece de fato.
        $guardado = json_decode((string) DB::table('evento_whatsapp')->value('payload'), true);
        $this->assertEquals(json_decode($corpo, true), $guardado);

        $this->assertNull(DB::table('evento_whatsapp')->value('processado_em'), 'o worker consome depois');
    }

    /** Assinatura errada não grava NADA: a tabela de eventos não é lugar para JSON de desconhecido. */
    #[Test]
    public function recusa_assinatura_invalida_e_nao_grava(): void
    {
        $corpo = '{"entry":[]}';

        $this->call('POST', '/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $corpo, 'segredo-errado'),
        ], $corpo)->assertStatus(403);

        $this->assertSame(0, DB::table('evento_whatsapp')->count());
    }

    #[Test]
    public function recusa_quando_falta_o_cabecalho_de_assinatura(): void
    {
        $this->call('POST', '/webhook/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"entry":[]}')
            ->assertStatus(403);

        $this->assertSame(0, DB::table('evento_whatsapp')->count());
    }

    /**
     * Sem integração em vigor, o endpoint falha FECHADO. É o comportamento certo numa
     * rota pública: sem app secret não há como distinguir a Meta de qualquer um.
     */
    #[Test]
    public function sem_integracao_em_vigor_recusa(): void
    {
        DB::table('configuracao')->where('id', 1)->update(['integracao_whatsapp_id' => null]);

        $this->enviar('{"entry":[]}')->assertStatus(403);
        $this->assertSame(0, DB::table('evento_whatsapp')->count());
    }

    /** A Meta reentrega o mesmo corpo em não-200. A segunda vez é no-op. */
    #[Test]
    public function reentrega_do_mesmo_evento_grava_uma_vez(): void
    {
        $corpo = '{"entry":[{"id":"repetido"}]}';

        $this->enviar($corpo)->assertOk();
        $this->enviar($corpo)->assertOk();
        $this->enviar($corpo)->assertOk();

        $this->assertSame(1, DB::table('evento_whatsapp')->count());
    }

    #[Test]
    public function eventos_diferentes_geram_linhas_diferentes(): void
    {
        $this->enviar('{"entry":[{"id":"a"}]}')->assertOk();
        $this->enviar('{"entry":[{"id":"b"}]}')->assertOk();

        $this->assertSame(2, DB::table('evento_whatsapp')->count());
    }

    /**
     * Assinado e sem JSON válido: não é ataque, é a Meta mudando algo. Devolve 200,
     * porque reentregar não vai fazer o corpo virar JSON — reentrega em laço seria o
     * único efeito.
     */
    #[Test]
    public function corpo_assinado_sem_json_devolve_200_e_nao_grava(): void
    {
        $this->enviar('isto não é json')->assertOk();

        $this->assertSame(0, DB::table('evento_whatsapp')->count());
    }

    /** @return array<string,string> */
    private function cabecalhos(string $corpo): array
    {
        return ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $corpo, self::SEGREDO)];
    }

    private function enviar(string $corpo): \Illuminate\Testing\TestResponse
    {
        return $this->call('POST', '/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $corpo, self::SEGREDO),
        ], $corpo);
    }
}
