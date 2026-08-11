<?php

declare(strict_types=1);

namespace Tests\Unit\Whatsapp;

use App\Whatsapp\Descoberta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Worker\TransporteFalso;

/**
 * A descoberta do Phone Number ID. Sem rede: o transporte é injetado.
 *
 * O que ela existe para evitar: um campo de 15 dígitos copiado à mão de outro painel. Erra
 * sem avisar, e o erro aparece só na primeira mensagem que não sai.
 */
final class DescobertaTest extends TestCase
{
    #[Test]
    public function acha_o_numero_a_partir_do_token(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [
            $this->ok(['data' => [
                'is_valid' => true,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['1111']],
                ],
            ]]),
            $this->ok(['data' => [
                ['id' => '555000111', 'display_phone_number' => '+55 11 98765-4321', 'verified_name' => 'Minha Empresa'],
            ]]),
        ];

        $r = (new Descoberta($t))->numeros('EAAG-token');

        $this->assertTrue($r['ok'], (string) $r['erro']);
        $this->assertSame('555000111', $r['numeros'][0]['id']);
        $this->assertSame('+55 11 98765-4321', $r['numeros'][0]['numero']);
        $this->assertSame('Minha Empresa', $r['numeros'][0]['nome']);
    }

    /** Duas contas, dois números: o operador escolhe de uma lista com o número VISÍVEL. */
    #[Test]
    public function junta_os_numeros_de_todas_as_contas(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [
            $this->ok(['data' => [
                'is_valid' => true,
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['1111', '2222']],
                ],
            ]]),
            $this->ok(['data' => [['id' => 'a', 'display_phone_number' => '+55 11 1']]]),
            $this->ok(['data' => [['id' => 'b', 'display_phone_number' => '+55 21 2']]]),
        ];

        $r = (new Descoberta($t))->numeros('tok');

        $this->assertSame(['a', 'b'], array_column($r['numeros'], 'id'));
    }

    /**
     * 🔴 `is_valid: false` VEM COM HTTP 200.
     *
     * É a resposta para token expirado ou revogado. Tratar 200 como sucesso deixaria o
     * operador com "nenhum número encontrado" quando a causa é token morto — e ele iria
     * procurar no lugar errado.
     */
    #[Test]
    public function token_expirado_e_dito_como_token_expirado(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [$this->ok(['data' => ['is_valid' => false]])];

        $r = (new Descoberta($t))->numeros('tok-morto');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('expirou', (string) $r['erro']);
    }

    /** Token sem a permissão certa: a causa é dita com o nome do escopo que falta. */
    #[Test]
    public function token_sem_escopo_de_whatsapp_diz_qual_permissao_falta(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [$this->ok(['data' => [
            'is_valid' => true,
            'granular_scopes' => [['scope' => 'pages_messaging', 'target_ids' => ['9']]],
        ]])];

        $r = (new Descoberta($t))->numeros('tok');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('whatsapp_business_messaging', (string) $r['erro']);
    }

    /** A mensagem que a META escreveu vem primeiro, e o fbtrace vai junto. */
    #[Test]
    public function repassa_a_explicacao_da_meta_com_o_fbtrace(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [[
            'http' => 400,
            'corpo' => (string) json_encode(['error' => [
                'message' => 'Invalid OAuth access token',
                'error_user_msg' => 'Sua sessão expirou.',
                'fbtrace_id' => 'AbC123',
            ]]),
            'erro' => null,
        ]];

        $r = (new Descoberta($t))->numeros('tok');

        $this->assertStringContainsString('Sua sessão expirou.', (string) $r['erro']);
        $this->assertStringContainsString('fbtrace=AbC123', (string) $r['erro']);
    }

    /**
     * Uma conta falhando não derruba a outra.
     *
     * Token com duas contas e permissão só numa é caso real; abortar na primeira faria o
     * operador não conseguir usar a que funciona.
     */
    #[Test]
    public function conta_que_falha_nao_impede_a_que_funciona(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [
            $this->ok(['data' => [
                'is_valid' => true,
                'granular_scopes' => [['scope' => 'whatsapp_business_messaging', 'target_ids' => ['ruim', 'boa']]],
            ]]),
            ['http' => 403, 'corpo' => (string) json_encode(['error' => ['message' => 'sem permissão']]), 'erro' => null],
            $this->ok(['data' => [['id' => 'ok-id', 'display_phone_number' => '+55 11 9']]]),
        ];

        $r = (new Descoberta($t))->numeros('tok');

        $this->assertTrue($r['ok'], (string) $r['erro']);
        $this->assertSame(['ok-id'], array_column($r['numeros'], 'id'));
    }

    /** Falha de rede não vira "nenhum número": vira o motivo, para a tela cair no manual. */
    #[Test]
    public function falha_de_rede_e_relatada_como_falha_de_rede(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [['http' => 0, 'corpo' => '', 'erro' => 'timeout']];

        $r = (new Descoberta($t))->numeros('tok');

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('timeout', (string) $r['erro']);
    }

    #[Test]
    public function token_vazio_nao_gera_chamada(): void
    {
        $t = new TransporteFalso();

        $r = (new Descoberta($t))->numeros('   ');

        $this->assertFalse($r['ok']);
        $this->assertSame([], $t->urlsGet, 'não deve consultar a Meta sem token');
    }

    /**
     * O token NÃO vai na URL sem escapar.
     *
     * Token da Meta pode conter caracteres que quebrariam a query string, e um token
     * truncado devolveria "inválido" para um token que é válido.
     */
    #[Test]
    public function o_token_vai_escapado_na_url(): void
    {
        $t = new TransporteFalso();
        $t->respostasGet = [$this->ok(['data' => ['is_valid' => false]])];

        (new Descoberta($t))->numeros('tok com espaço&x=1');

        $this->assertStringContainsString('tok+com+espa%C3%A7o%26x%3D1', $t->urlsGet[0]);
    }

    /**
     * @param  array<string,mixed>  $corpo
     * @return array{http:int, corpo:string, erro:?string}
     */
    private function ok(array $corpo): array
    {
        return ['http' => 200, 'corpo' => (string) json_encode($corpo), 'erro' => null];
    }
}
