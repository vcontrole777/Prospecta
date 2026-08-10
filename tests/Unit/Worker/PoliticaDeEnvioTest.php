<?php

declare(strict_types=1);

namespace Tests\Unit\Worker;

use App\Worker\Envio;
use App\Worker\PoliticaDeEnvio;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoliticaDeEnvioTest extends TestCase
{
    private const MAX = 3;

    #[Test]
    public function aceito_com_wamid_e_terminal(): void
    {
        $d = $this->decidir(new Envio(http: 200, wamid: 'wamid.ABC'), 1);

        $this->assertSame('enviado', $d->status);
        $this->assertFalse($d->retentar);
        $this->assertFalse($d->pararCanal);
    }

    /**
     * 🔴 A REGRA QUE PROTEGE O BOLSO DO CLIENTE.
     *
     * A Cloud API não tem chave de idempotência — não existe campo que faça a Meta
     * reconhecer "esta é a mesma mensagem". Então 4xx sem `is_transient` NÃO é
     * retentado: a requisição foi recebida e recusada, e insistir só multiplica a
     * recusa. Retentar por reflexo aqui é o caminho para o Lead receber a mesma
     * mensagem várias vezes, cada uma cobrada.
     */
    #[Test]
    public function erro_4xx_definitivo_nao_retenta(): void
    {
        $d = $this->decidir(new Envio(http: 400, codigo: 132001, mensagem: 'template não existe'), 1);

        $this->assertSame('falhou', $d->status);
        $this->assertFalse($d->retentar, 'retentar aqui multiplicaria a recusa sem chance de sucesso');
    }

    /**
     * `is_transient` é a plataforma dizendo se vale retentar, e é regra melhor que
     * manter lista de códigos retentáveis — lista envelhece, e esta API depreciou
     * campo durante a especificação deste produto.
     */
    #[Test]
    public function is_transient_retenta_enquanto_houver_tentativa(): void
    {
        $e = new Envio(http: 400, codigo: 131000, transitorio: true);

        $d = $this->decidir($e, 1);
        $this->assertSame('pendente', $d->status);
        $this->assertTrue($d->retentar);

        // Na última, vira terminal em vez de ficar pendente para sempre.
        $d = $this->decidir($e, self::MAX);
        $this->assertSame('falhou', $d->status);
        $this->assertFalse($d->retentar);
        $this->assertSame('esgotou_tentativas', $d->motivo);
    }

    #[Test]
    public function falha_de_conexao_retenta_porque_nada_foi_enviado(): void
    {
        $d = $this->decidir(new Envio(http: 0, semResposta: true, mensagem: 'timeout'), 1);

        $this->assertSame('pendente', $d->status);
        $this->assertTrue($d->retentar);
        $this->assertSame('sem_resposta', $d->motivo);
    }

    #[Test]
    public function erro_5xx_retenta(): void
    {
        $d = $this->decidir(new Envio(http: 503), 1);

        $this->assertSame('pendente', $d->status);
        $this->assertTrue($d->retentar);
    }

    /**
     * `131049` não é falha técnica: é a Meta decidindo não entregar esta mensagem
     * de marketing agora, por limite do usuário ou qualidade. Status próprio porque
     * a tela precisa distinguir isso de erro — tratar como erro faria o operador
     * procurar defeito onde não há.
     */
    #[Test]
    public function limite_do_destinatario_tem_status_proprio_e_nao_retenta(): void
    {
        $d = $this->decidir(new Envio(http: 400, codigo: 131049), 1);

        $this->assertSame('limite_destinatario', $d->status);
        $this->assertFalse($d->retentar);
        $this->assertFalse($d->pararCanal, 'é decisão sobre ESTE destinatário, não sobre o canal');
    }

    /**
     * 🔴 Token inválido para o CANAL inteiro, não para esta Ação.
     *
     * Sem `pararCanal`, o worker percorreria a fila marcando toda Ação como falha
     * pelo mesmo motivo — destruindo a informação de quais ainda estavam pendentes,
     * que é justamente o que se precisa depois de trocar o token.
     */
    #[Test]
    public function erro_de_credencial_ou_permissao_para_o_canal(): void
    {
        foreach ([190, 200, 1349174] as $codigo) {
            $d = $this->decidir(new Envio(http: 401, codigo: $codigo), 1);

            $this->assertSame('pausado', $d->status, "codigo {$codigo}");
            $this->assertTrue($d->pararCanal, "codigo {$codigo}");
            $this->assertFalse($d->retentar, "codigo {$codigo}");
        }
    }

    /**
     * 2xx sem `wamid` é forma que não reconhecemos. Não retenta: se veio 2xx a
     * mensagem provavelmente saiu, e retentar duplicaria.
     */
    #[Test]
    public function resposta_2xx_sem_wamid_nao_retenta(): void
    {
        $d = $this->decidir(new Envio(http: 200), 1);

        $this->assertSame('falhou', $d->status);
        $this->assertFalse($d->retentar);
        $this->assertSame('resposta_nao_reconhecida', $d->motivo);
    }

    /**
     * Transitório vence a regra de "4xx não retenta": quem manda é a plataforma.
     */
    #[Test]
    public function transitorio_vence_o_4xx(): void
    {
        $definitivo = $this->decidir(new Envio(http: 429, codigo: 130429), 1);
        $transitorio = $this->decidir(new Envio(http: 429, codigo: 130429, transitorio: true), 1);

        $this->assertFalse($definitivo->retentar);
        $this->assertTrue($transitorio->retentar);
    }

    private function decidir(Envio $e, int $tentativas): \App\Worker\DecisaoDeEnvio
    {
        return (new PoliticaDeEnvio())->decidir($e, $tentativas, self::MAX);
    }
}
