<?php

declare(strict_types=1);

namespace Tests\Unit\Dominio;

use App\Dominio\Rota;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RotaTest extends TestCase
{
    /**
     * 🔴 O PREFIXO TÉCNICO ENTRA NO CANAL, colado no número.
     *
     * Ele é SELEÇÃO DE CAPACIDADE, não roteamento: medido que na rota barata CallerID e
     * DTMF falham em silêncio — e o produto inteiro é "escute e digite uma tecla". Discar
     * sem o prefixo não dá erro; dá Campanha inteira sem resposta.
     */
    #[Test]
    public function o_canal_junta_prefixo_numero_e_nome_do_endpoint(): void
    {
        $t = new Rota(host: '200.1.2.3', techPrefix: '99901');

        $this->assertSame('PJSIP/999015511987654321@rota', $t->canal('PJSIP', '5511987654321'));
    }

    #[Test]
    public function sem_prefixo_o_canal_leva_so_o_numero(): void
    {
        $t = new Rota(host: '200.1.2.3');

        $this->assertSame('PJSIP/5511987654321@rota', $t->canal('PJSIP', '5511987654321'));
    }

    /**
     * 🔴 `from_user` SÓ SAI QUANDO PREENCHIDO, e o padrão é ausente.
     *
     * Ele SOBREPÕE o `CALLERID(num)` e torna toda Regra de BINA inócua: o painel mostra a
     * BINA escolhida e a rota manda outra coisa, sem erro em log nenhum. Medido em
     * 2026-08-09 — removendo-o, o From passou a levar a BINA e a rota aceitou.
     *
     * Se um dia ele passar a sair sempre, a consequência não é erro: é a Regra de BINA
     * inteira virando enfeite.
     */
    #[Test]
    public function from_user_fica_de_fora_quando_vazio(): void
    {
        $semele = new Rota(host: '200.1.2.3', usuario: 'u', senha: 's');
        $comele = new Rota(host: '200.1.2.3', usuario: 'u', senha: 's', fromUsuario: '551133334444');

        $this->assertStringNotContainsString('from_user', $semele->paraPjsip());
        $this->assertStringContainsString('from_user=551133334444', $comele->paraPjsip());
    }

    /**
     * O transporte NÃO é gerado aqui.
     *
     * `[transport-udp]` é da máquina, não da Rota, e vive no arquivo do Ansible.
     * Duplicá-lo faria o Asterisk ver duas seções de mesmo nome a cada recarga.
     */
    #[Test]
    public function nao_gera_o_transporte(): void
    {
        $t = new Rota(host: '200.1.2.3');

        $this->assertStringNotContainsString('[transport-udp]', $t->paraPjsip());
        $this->assertStringContainsString('transport=transport-udp', $t->paraPjsip());
    }

    /**
     * 🔴 SEM USUÁRIO NÃO SAI BLOCO DE AUTENTICAÇÃO.
     *
     * Rota autenticada por IP não tem credencial. Um `[rota-auth]` com `username=` vazio é
     * RECUSADO pelo Asterisk — medido nesta máquina:
     *
     *   res_pjsip/config_auth.c: rota-auth: No authentication username
     *   res_sorcery_config.c: Could not create an object of type 'auth'
     *
     * A chamada continua saindo, porque o IP autoriza. Então o efeito não é falha: é erro a
     * cada recarga e um `outbound_auth` apontando para objeto inexistente. Log cheio de erro
     * rotineiro é log que ninguém lê, e o erro de verdade se perde no meio.
     */
    #[Test]
    public function sem_usuario_nao_emite_bloco_de_autenticacao(): void
    {
        $conf = (new Rota(host: '200.1.2.3'))->paraPjsip();

        $this->assertStringNotContainsString('type=auth', $conf);
        $this->assertStringNotContainsString('outbound_auth', $conf);
        $this->assertStringNotContainsString('username=', $conf);

        // E o resto continua completo: o endpoint existe e o IP casa na entrada.
        $this->assertStringContainsString('type=endpoint', $conf);
        $this->assertStringContainsString('type=identify', $conf);
    }

    #[Test]
    public function gera_as_quatro_secoes_que_o_asterisk_precisa(): void
    {
        $conf = (new Rota('200.1.2.3', 'usr', 's3nh4', 'sip.forn.com'))->paraPjsip();

        $this->assertStringContainsString("type=endpoint", $conf);
        $this->assertStringContainsString("type=auth", $conf);
        $this->assertStringContainsString('outbound_auth=rota-auth', $conf);
        $this->assertStringContainsString("type=aor", $conf);
        $this->assertStringContainsString("type=identify", $conf);

        $this->assertStringContainsString('username=usr', $conf);
        $this->assertStringContainsString('password=s3nh4', $conf);
        $this->assertStringContainsString('contact=sip:200.1.2.3', $conf);
        $this->assertStringContainsString('match=200.1.2.3', $conf);
        $this->assertStringContainsString('from_domain=sip.forn.com', $conf);
    }

    /**
     * As três coisas que o produto exige do tronco e que não são opinião:
     * alaw (os áudios são alaw), DTMF fora de banda (DTMF é o produto), e o Asterisk no
     * caminho da mídia (sem isso não há AMD nem gravação).
     */
    #[Test]
    public function mantem_o_que_o_produto_exige_do_tronco(): void
    {
        $conf = (new Rota(host: '200.1.2.3'))->paraPjsip();

        $this->assertStringContainsString('allow=alaw,ulaw', $conf);
        $this->assertStringContainsString('dtmf_mode=rfc4733', $conf);
        $this->assertStringContainsString('direct_media=no', $conf);
    }

    /**
     * Sem host, gera comentário e NENHUM endpoint.
     *
     * Arquivo pela metade — endpoint sem aor, ou aor sem contato — faz o Asterisk recusar
     * a recarga inteira e derruba um tronco que estava funcionando. Vazio é recuperável;
     * quebrado, não.
     */
    #[Test]
    public function sem_host_nao_gera_endpoint_nenhum(): void
    {
        $t = new Rota();

        $this->assertFalse($t->configurado());
        $this->assertStringNotContainsString('type=endpoint', $t->paraPjsip());
        $this->assertStringContainsString('não configurada', $t->paraPjsip());
    }

    #[Test]
    public function host_em_branco_nao_conta_como_configurado(): void
    {
        $this->assertFalse((new Rota(host: '   '))->configurado());
        $this->assertTrue((new Rota(host: '200.1.2.3'))->configurado());
    }
}
