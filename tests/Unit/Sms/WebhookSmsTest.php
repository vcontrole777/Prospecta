<?php

declare(strict_types=1);

namespace Tests\Unit\Sms;

use App\Sms\Assinatura;
use App\Sms\Leitura;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * O webhook do httpSMS: a assinatura que protege a rota pública, e a leitura do
 * CloudEvent.
 */
final class WebhookSmsTest extends TestCase
{
    private const CHAVE = 'chave-de-assinatura';

    private static function b64(string $bruto): string
    {
        return rtrim(strtr(base64_encode($bruto), '+/', '-_'), '=');
    }

    /**
     * Dois `@param` em LINHAS separadas: na mesma linha só o primeiro é lido, e o
     * segundo some sem aviso. Já custou um erro de tipo nesta suíte.
     *
     * @param array<string,mixed> $cabecalho
     * @param array<string,mixed> $corpo
     */
    private static function token(array $cabecalho, array $corpo, ?string $chave = self::CHAVE): string
    {
        $h = self::b64((string) json_encode($cabecalho));
        $c = self::b64((string) json_encode($corpo));

        if ($chave === null) {
            return "{$h}.{$c}.";   // sem assinatura
        }

        return "{$h}.{$c}." . self::b64(hash_hmac('sha256', "{$h}.{$c}", $chave, true));
    }

    // ---- assinatura -------------------------------------------------------

    #[Test]
    public function aceita_o_token_valido_com_e_sem_o_prefixo_bearer(): void
    {
        $a = new Assinatura();
        $t = self::token(['alg' => 'HS256', 'typ' => 'JWT'], ['iss' => 'httpsms']);

        $this->assertTrue($a->confere('Bearer ' . $t, self::CHAVE));
        $this->assertTrue($a->confere($t, self::CHAVE), 'sem o prefixo também, para teste com curl');
    }

    /**
     * 🔴 `alg: none` — O ATAQUE DE MANUAL.
     *
     * O token vem sem assinatura e uma implementação ingênua o aceita porque "não
     * havia o que conferir". Numa rota pública isso é qualquer um marcando as suas
     * Ações como entregues.
     */
    #[Test]
    public function recusa_alg_none(): void
    {
        $a = new Assinatura();

        $this->assertFalse($a->confere(self::token(['alg' => 'none'], ['x' => 1], null), self::CHAVE));
        $this->assertFalse($a->confere(self::token(['alg' => 'NONE'], ['x' => 1], null), self::CHAVE));
    }

    /**
     * 🔴 CONFUSÃO DE ALGORITMO.
     *
     * Mandar `RS256` para um verificador que usa a chave como segredo simétrico
     * transforma a chave PÚBLICA — que é pública — em segredo válido. Aceitar só o
     * que o httpSMS declara usar fecha isso.
     */
    #[Test]
    public function recusa_algoritmo_diferente_mesmo_com_assinatura_valida(): void
    {
        $a = new Assinatura();

        // Assinatura HMAC CORRETA, mas o cabeçalho declara outro algoritmo.
        $this->assertFalse($a->confere(self::token(['alg' => 'RS256'], ['x' => 1]), self::CHAVE));
        $this->assertFalse($a->confere(self::token(['alg' => 'HS512'], ['x' => 1]), self::CHAVE));
    }

    #[Test]
    public function recusa_assinatura_adulterada_e_chave_errada(): void
    {
        $a = new Assinatura();
        $t = self::token(['alg' => 'HS256'], ['x' => 1]);

        $this->assertFalse($a->confere($t, 'outra-chave'));
        $this->assertFalse($a->confere($t . 'x', self::CHAVE));
        $this->assertFalse($a->confere(self::token(['alg' => 'HS256'], ['x' => 1], 'chave-do-atacante'), self::CHAVE));
    }

    /** Corpo trocado depois de assinado quebra a conferência: o HMAC cobre os dois. */
    #[Test]
    public function recusa_corpo_trocado_apos_a_assinatura(): void
    {
        $t = self::token(['alg' => 'HS256'], ['sub' => 'certo']);
        [$h, , $s] = explode('.', $t);
        $forjado = $h . '.' . self::b64((string) json_encode(['sub' => 'forjado'])) . '.' . $s;

        $this->assertFalse((new Assinatura())->confere($forjado, self::CHAVE));
    }

    #[Test]
    public function recusa_lixo_e_chave_vazia(): void
    {
        $a = new Assinatura();

        $this->assertFalse($a->confere(null, self::CHAVE));
        $this->assertFalse($a->confere('', self::CHAVE));
        $this->assertFalse($a->confere('nao.e.jwt', self::CHAVE));
        $this->assertFalse($a->confere('so-uma-parte', self::CHAVE));
        // Chave vazia é integração sem `signing_key`: falha FECHADA.
        $this->assertFalse($a->confere(self::token(['alg' => 'HS256'], ['x' => 1]), ''));
    }

    /** `exp` só é conferido quando VEM — o httpSMS não documenta as claims. */
    #[Test]
    public function exp_vencida_e_recusada_e_ausente_e_aceita(): void
    {
        $a = new Assinatura();

        $this->assertFalse($a->confere(self::token(['alg' => 'HS256'], ['exp' => 1000]), self::CHAVE, 2000));
        $this->assertTrue($a->confere(self::token(['alg' => 'HS256'], ['exp' => 3000]), self::CHAVE, 2000));
        $this->assertTrue($a->confere(self::token(['alg' => 'HS256'], []), self::CHAVE, 2000));
    }

    // ---- leitura ----------------------------------------------------------

    /**
     * 🔴 O NOME DO CAMPO DO ID MUDA ENTRE EVENTOS.
     *
     * Quadros REAIS da documentação do httpSMS. `sent`, `delivered` e `failed` usam
     * `data.id`; `expired` usa `data.message_id`. Ler só um deles perde metade — e a
     * metade perdida inclui a EXPIRAÇÃO, que é justamente o caso em que a mensagem
     * não saiu.
     */
    #[Test]
    public function le_o_id_nos_dois_nomes_que_a_plataforma_usa(): void
    {
        $l = new Leitura();

        $entregue = $l->status([
            'type' => 'message.phone.delivered',
            'data' => ['id' => '5be8f09e-7007-4fe9-86b6-591d63fd38ad', 'contact' => '+18005550100'],
        ]);
        $this->assertNotNull($entregue);
        $this->assertSame('5be8f09e-7007-4fe9-86b6-591d63fd38ad', $entregue->id);
        $this->assertSame('entregue', $entregue->status);

        $expirado = $l->status([
            'type' => 'message.send.expired',
            'data' => ['message_id' => 'ff313c14-17a3-4f74-bcb2-ca77213a64af', 'send_attempt_count' => 2],
        ]);
        $this->assertNotNull($expirado, 'expirado usa `message_id`, não `id`');
        $this->assertSame('ff313c14-17a3-4f74-bcb2-ca77213a64af', $expirado->id);
        $this->assertSame('falhou', $expirado->status);
    }

    #[Test]
    public function traz_a_causa_da_falha_que_o_httpsms_escreveu(): void
    {
        $s = (new Leitura())->status([
            'type' => 'message.send.failed',
            'data' => ['id' => 'x', 'error_message' => 'MOBILE_APP_INACTIVE', 'request_id' => 'ligacao:42'],
        ]);

        $this->assertNotNull($s);
        $this->assertSame('falhou', $s->status);
        // É o que diz ao operador que o problema é o APARELHO, não o número do Lead.
        $this->assertSame('MOBILE_APP_INACTIVE', $s->erro);
        $this->assertSame('ligacao:42', $s->requestId);
    }

    /**
     * 🔴 SMS DE ENTRADA NÃO VIRA STATUS, de propósito.
     *
     * `message.phone.received` é alguém respondendo, e a caixa de entrada do produto
     * é modelada em cima do WhatsApp — janela de 24 h, `conversa.telefone` único.
     * Encaixar à força criaria Conversa que mente sobre as próprias regras.
     */
    #[Test]
    public function evento_de_entrada_e_de_aparelho_nao_viram_status(): void
    {
        $l = new Leitura();

        $this->assertNull($l->status(['type' => 'message.phone.received', 'data' => ['message_id' => 'x']]));
        $this->assertNull($l->status(['type' => 'phone.heartbeat.offline', 'data' => []]));
        $this->assertNull($l->status(['type' => 'coisa.nova.da.plataforma', 'data' => ['id' => 'x']]));
        $this->assertNull($l->status([]));
    }

    /** Evento sem id não vira status: não haveria a que aplicar. */
    #[Test]
    public function evento_sem_id_nao_vira_status(): void
    {
        $this->assertNull((new Leitura())->status([
            'type' => 'message.phone.sent',
            'data' => ['contact' => '+18005550100'],
        ]));
    }

    /** O aparelho caindo é o REMETENTE morrendo — sem isso a Campanha para em silêncio. */
    #[Test]
    public function le_o_heartbeat_do_aparelho(): void
    {
        $l = new Leitura();

        $this->assertFalse($l->heartbeat(['type' => 'phone.heartbeat.offline']));
        $this->assertTrue($l->heartbeat(['type' => 'phone.heartbeat.online']));
        $this->assertNull($l->heartbeat(['type' => 'message.phone.sent']));
    }

    /** O `id` do CloudEvent é o que dá idempotência contra a reentrega. */
    #[Test]
    public function le_o_identificador_do_cloudevent(): void
    {
        $l = new Leitura();

        $this->assertSame('f4b86c6d', $l->identificador(['id' => 'f4b86c6d', 'type' => 'x']));
        $this->assertNull($l->identificador(['type' => 'x']));
        $this->assertNull($l->identificador(['id' => '   ']));
    }
}
