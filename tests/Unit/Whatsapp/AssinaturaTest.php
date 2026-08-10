<?php

declare(strict_types=1);

namespace Tests\Unit\Whatsapp;

use App\Whatsapp\Assinatura;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * É a única coisa entre o endpoint e a internet aberta. Sem ela, qualquer um posta
 * JSON e — pelo worker — marca Ação como entregue, cria Conversa e insere na lista
 * de bloqueio.
 */
final class AssinaturaTest extends TestCase
{
    private const SEGREDO = 'app-secret-da-meta';

    #[Test]
    public function aceita_assinatura_correta_com_e_sem_prefixo(): void
    {
        $a = new Assinatura();
        $corpo = '{"entry":[{"id":"1"}]}';
        $hex = hash_hmac('sha256', $corpo, self::SEGREDO);

        $this->assertTrue($a->confere($corpo, 'sha256=' . $hex, self::SEGREDO), 'como a Meta manda');
        $this->assertTrue($a->confere($corpo, $hex, self::SEGREDO), 'sem prefixo, para teste com curl');
    }

    /**
     * 🔴 O CORPO É OS BYTES CRUS. A Meta assina o que enviou, e JSON reserializado
     * muda espaçamento e escape — o HMAC falharia para 100% dos pedidos legítimos, e
     * o sintoma mandaria trocar um app secret que estava certo.
     */
    #[Test]
    public function json_reserializado_nao_confere(): void
    {
        $a = new Assinatura();
        $cru = '{"a":1,"b":"x"}';
        $hex = hash_hmac('sha256', $cru, self::SEGREDO);

        $reserializado = json_encode(json_decode($cru, true), JSON_PRETTY_PRINT);
        $this->assertIsString($reserializado);
        $this->assertNotSame($cru, $reserializado);

        $this->assertTrue($a->confere($cru, $hex, self::SEGREDO));
        $this->assertFalse($a->confere($reserializado, $hex, self::SEGREDO));
    }

    #[Test]
    public function recusa_o_que_tem_que_recusar(): void
    {
        $a = new Assinatura();
        $corpo = '{"x":1}';
        $hex = hash_hmac('sha256', $corpo, self::SEGREDO);

        $this->assertFalse($a->confere($corpo, null, self::SEGREDO), 'sem cabeçalho');
        $this->assertFalse($a->confere($corpo, 'sha256=', self::SEGREDO), 'cabeçalho vazio');
        $this->assertFalse($a->confere($corpo, $hex, ''), 'sem app secret — falha FECHADO');
        $this->assertFalse($a->confere($corpo, $hex, 'outro-segredo'), 'segredo errado');
        $this->assertFalse($a->confere($corpo . ' ', $hex, self::SEGREDO), 'um byte a mais no corpo');
        $this->assertFalse($a->confere($corpo, substr($hex, 0, -1), self::SEGREDO), 'assinatura truncada');
        $this->assertFalse($a->confere($corpo, strtoupper($hex), self::SEGREDO), 'hex em caixa alta não é o mesmo');
    }

    /**
     * O identificador vem do CORPO, não de campo do payload: o ADR-0002 manda não
     * interpretar nada na entrada, e a Meta reentrega o mesmo corpo — então o resumo
     * do corpo é exatamente a chave de reentrega.
     */
    #[Test]
    public function o_identificador_e_estavel_e_distingue(): void
    {
        $a = new Assinatura();

        $this->assertSame($a->identificador('{"x":1}'), $a->identificador('{"x":1}'));
        $this->assertNotSame($a->identificador('{"x":1}'), $a->identificador('{"x":2}'));
        $this->assertSame(64, strlen($a->identificador('{"x":1}')), 'cabe no varchar(255)');
    }
}
