<?php

declare(strict_types=1);

namespace Tests\Unit\Seguranca;

use App\Seguranca\Cofre;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CofreTest extends TestCase
{
    private const CHAVE = 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=';   // 32 bytes

    private const OUTRA = 'base64:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXphYmNkZWY=';

    #[Test]
    public function ida_e_volta(): void
    {
        $c = new Cofre(self::CHAVE);
        $claro = '{"token":"EAAG...","phone_number_id":"555000"}';

        $cifrado = $c->cifrar($claro);

        $this->assertNotSame($claro, $cifrado);
        $this->assertStringNotContainsString('EAAG', $cifrado, 'o texto claro não pode aparecer');
        $this->assertSame($claro, $c->decifrar($cifrado));
    }

    /** IV aleatório: o mesmo texto cifra diferente a cada vez. */
    #[Test]
    public function o_mesmo_texto_cifra_diferente(): void
    {
        $c = new Cofre(self::CHAVE);

        $this->assertNotSame($c->cifrar('igual'), $c->cifrar('igual'));
        $this->assertSame('igual', $c->decifrar($c->cifrar('igual')));
    }

    /**
     * 🔴 A razão de existir o MAC. Sem ele, quem tem escrita no banco altera bytes
     * do texto cifrado e muda o claro de forma controlada. Numa coluna que guarda
     * token de ENVIO DE MENSAGEM isso é pior que vazamento: é mensagem saindo no
     * nome da empresa com destino escolhido por outro.
     */
    #[Test]
    public function recusa_texto_cifrado_adulterado(): void
    {
        $c = new Cofre(self::CHAVE);
        $cifrado = $c->cifrar('segredo do cliente');

        foreach (['valor', 'iv', 'mac'] as $campo) {
            $p = json_decode((string) base64_decode($cifrado, true), true);
            $this->assertIsArray($p);

            $original = $p[$campo];
            $this->assertIsString($original);

            // Vira o primeiro byte do campo.
            $bytes = $campo === 'mac' ? $original : (string) base64_decode($original, true);
            $bytes[0] = $bytes[0] === 'a' ? 'b' : 'a';
            $p[$campo] = $campo === 'mac' ? $bytes : base64_encode($bytes);

            $adulterado = base64_encode((string) json_encode($p));

            try {
                $c->decifrar($adulterado);
                $this->fail("adulteração em '{$campo}' passou");
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString('segredo', $e->getMessage());
            }
        }
    }

    /** Trocar a APP_KEY torna a credencial ilegível, e a mensagem tem que dizer isso. */
    #[Test]
    public function outra_chave_nao_abre_e_a_mensagem_explica(): void
    {
        $cifrado = (new Cofre(self::CHAVE))->cifrar('segredo');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/APP_KEY.*recadastre/is');

        (new Cofre(self::OUTRA))->decifrar($cifrado);
    }

    #[Test]
    public function recusa_chave_curta_na_construcao(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/32 bytes/');

        new Cofre('curta');
    }

    #[Test]
    public function recusa_pacote_malformado(): void
    {
        $c = new Cofre(self::CHAVE);

        foreach ([
            'não é base64 nem json' => '@@@@',
            'base64 que não é json' => base64_encode('nada disso'),
            'json sem os campos' => base64_encode((string) json_encode(['v' => 1])),
            'iv de tamanho errado' => base64_encode((string) json_encode([
                'v' => 1, 'iv' => base64_encode('curto'), 'valor' => base64_encode('x'), 'mac' => 'y',
            ])),
            'vazio' => '',
        ] as $rot => $ruim) {
            try {
                $c->decifrar($ruim);
                $this->fail("passou: {$rot}");
            } catch (RuntimeException $e) {
                // A recusa tem que DIZER algo: exceção com mensagem vazia deixaria o
                // operador sem pista nenhuma sobre o que está errado no cadastro.
                $this->assertNotSame('', $e->getMessage(), $rot);
            }
        }
    }

    #[Test]
    public function aceita_chave_sem_o_prefixo_base64(): void
    {
        $c = new Cofre(str_repeat('k', 32));

        $this->assertSame('ok', $c->decifrar($c->cifrar('ok')));
    }

    /**
     * Mostra os ÚLTIMOS caracteres, não os primeiros: prefixo de token identifica
     * tipo e conta, e é a parte com valor para quem olha a tela por cima do ombro.
     */
    #[Test]
    public function mascara_preservando_o_fim(): void
    {
        $c = new Cofre(self::CHAVE);

        // 18 caracteres menos os 4 visíveis = 14 marcadores.
        $this->assertSame(str_repeat('•', 14) . '3456', $c->mascarar('EAAG12345678123456', 4));
        $this->assertStringNotContainsString('EAAG', $c->mascarar('EAAG12345678123456'));
        $this->assertSame('•••', $c->mascarar('abc'), 'curto demais: esconde tudo');
        $this->assertSame('', $c->mascarar(''));
    }

    /** Texto longo e binário passam intactos — credencial pode ter qualquer coisa. */
    #[Test]
    public function preserva_conteudo_longo_e_com_acento(): void
    {
        $c = new Cofre(self::CHAVE);
        $claro = json_encode(['nota' => 'ção áéí — ' . str_repeat('x', 5000)]);
        $this->assertIsString($claro);

        $this->assertSame($claro, $c->decifrar($c->cifrar($claro)));
    }
}
