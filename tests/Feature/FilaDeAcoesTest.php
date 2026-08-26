<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Seguranca\Cofre;
use App\Worker\DecisaoDeEnvio;
use App\Worker\Envio;
use App\Worker\FilaDeAcoes;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A fila de Ações do worker: o caminho que gasta DINHEIRO do cliente.
 *
 * Cada Ação de `disparo` é uma mensagem paga de template. Errar aqui não dá erro — dá
 * mensagem duplicada para a mesma pessoa, ou opt-in que nunca vira mensagem. Era a
 * classe equivalente à `Fila` do dialer, e como ela, não tinha teste: só as puras
 * (`PoliticaDeEnvio`, `Whatsapp`) tinham.
 *
 * `DatabaseTruncation` porque `reivindicar` abre transação própria, e
 * `PDO::beginTransaction` dentro da transação do `RefreshDatabase` falha com "there is
 * already an active transaction". Testar com transação por fora é não testar a de dentro.
 */
final class FilaDeAcoesTest extends TestCase
{
    use DatabaseTruncation;

    private FilaDeAcoes $fila;

    protected function setUp(): void
    {
        parent::setUp();

        $chave = config('app.key');
        $this->fila = new FilaDeAcoes(
            DB::connection()->getPdo(),
            new Cofre(is_string($chave) ? $chave : ''),
        );
    }

    // -------------------------------------------------------------- reivindicação

    /**
     * 🔴 `tentativas` SOBE ANTES DO ENVIO.
     *
     * Se o envio der certo e a gravação do resultado falhar — processo morto no momento
     * errado —, a linha volta a ser `pendente` e a mensagem sai de novo. Incrementar
     * antes limita quantas duplicatas isso pode gerar ao teto de tentativas, em vez de
     * deixar um laço mandando mensagem paga para a mesma pessoa para sempre.
     */
    #[Test]
    public function reivindicar_incrementa_a_tentativa_antes_de_enviar(): void
    {
        $acao = $this->acao();

        $lote = $this->fila->reivindicar(10);

        $this->assertCount(1, $lote);
        $this->assertSame($acao, (int) $lote[0]['id']);
        $this->assertSame(1, (int) DB::table('acao')->where('id', $acao)->value('tentativas'));

        // E segue `pendente`: o estado terminal é do `aplicar`, depois do envio.
        $this->assertSame('pendente', DB::table('acao')->where('id', $acao)->value('status'));
    }

    /**
     * 🔴 SÓ `pendente`. Ação já enviada não é reivindicada de novo.
     *
     * Reenviar é mensagem paga em duplicata para quem já recebeu — o defeito mais caro
     * que esta fila pode ter, e o que menos parece defeito, porque tudo "funcionou".
     */
    #[Test]
    public function reivindicar_ignora_acao_ja_enviada(): void
    {
        $acao = $this->acao();
        DB::table('acao')->where('id', $acao)->update(['status' => 'enviado']);

        $this->assertSame([], $this->fila->reivindicar(10));
        $this->assertSame(0, (int) DB::table('acao')->where('id', $acao)->value('tentativas'));
    }

    /**
     * 🔴 UM CANAL POR VEZ, e o de outro canal NÃO gasta tentativa.
     *
     * Este teste nasceu quando o `sms` não tinha remetente: ele afirmava que um
     * Disparo de SMS ficava FORA da consulta, porque reivindicado ele gastaria uma
     * tentativa por volta e morreria por esgotamento — o opt-in que o cliente pagou
     * para conseguir, perdido por inanição, com o log dizendo "esgotou tentativas" e
     * apontando para o lugar errado.
     *
     * `Worker\Sms` existe agora, e o canal virou PARÂMETRO. A propriedade que
     * importava continua valendo e é o que se afirma aqui: reivindicar um canal não
     * toca no outro. Quem pede WhatsApp não gasta a tentativa do SMS.
     */
    #[Test]
    public function reivindicar_um_canal_nao_gasta_tentativa_do_outro(): void
    {
        $acao = $this->acao(canal: 'sms');

        $this->assertSame([], $this->fila->reivindicar(10, 'whatsapp'));
        $this->assertSame(0, (int) DB::table('acao')->where('id', $acao)->value('tentativas'));

        // E o MESMO registro é reivindicado quando se pede o canal dele.
        $this->assertCount(1, $this->fila->reivindicar(10, 'sms'));
        $this->assertSame(1, (int) DB::table('acao')->where('id', $acao)->value('tentativas'));
    }

    #[Test]
    public function reivindicar_traz_o_telefone_e_o_template_da_campanha(): void
    {
        $this->acao(telefone: '5511987654321', template: 'boas_vindas');

        $lote = $this->fila->reivindicar(1);

        $this->assertSame('5511987654321', $lote[0]['telefone']);
        $this->assertSame('boas_vindas', $lote[0]['template_nome']);
    }

    #[Test]
    public function reivindicar_zero_nao_toca_no_banco(): void
    {
        $acao = $this->acao();

        $this->assertSame([], $this->fila->reivindicar(0));
        $this->assertSame(0, (int) DB::table('acao')->where('id', $acao)->value('tentativas'));
    }

    #[Test]
    public function reivindicar_respeita_o_limite(): void
    {
        $this->acao(telefone: '5511900000001');
        $this->acao(telefone: '5511900000002');
        $this->acao(telefone: '5511900000003');

        $this->assertCount(2, $this->fila->reivindicar(2));
    }

    // -------------------------------------------------------------------- aplicar

    /**
     * `processado_em` só em estado TERMINAL.
     *
     * Uma Ação que voltou para `pendente` não foi processada, e carimbá-la faria a tela
     * mentir sobre quando o envio aconteceu — e sobre se aconteceu.
     */
    #[Test]
    public function aplicar_pendente_nao_carimba_processado_em(): void
    {
        $acao = $this->acao();

        $this->fila->aplicar(
            $acao,
            new DecisaoDeEnvio('pendente', true, false, 'transitorio'),
            new Envio(http: 500, mensagem: 'erro do servidor'),
        );

        $l = (array) DB::table('acao')->where('id', $acao)->first();
        $this->assertSame('pendente', $l['status']);
        $this->assertNull($l['processado_em']);
        $this->assertStringContainsString('transitorio', (string) $l['resposta']);
    }

    #[Test]
    public function aplicar_terminal_carimba_e_grava_o_wamid(): void
    {
        $acao = $this->acao();

        $this->fila->aplicar(
            $acao,
            new DecisaoDeEnvio('enviado', false, false, 'aceito'),
            new Envio(http: 200, wamid: 'wamid.ABC123'),
        );

        $l = (array) DB::table('acao')->where('id', $acao)->first();
        $this->assertSame('enviado', $l['status']);
        $this->assertSame('wamid.ABC123', $l['wamid']);
        $this->assertNotNull($l['processado_em']);
    }

    /**
     * `wamid` já gravado NÃO é apagado por um envio posterior sem wamid.
     *
     * É o `COALESCE`. O wamid é a única chave que liga a Ação aos estados de entrega que
     * chegam pelo webhook — perdê-lo faria `entregue` e `lido` nunca encontrarem a linha,
     * e a mensagem ficaria "enviada" para sempre mesmo tendo sido lida.
     */
    #[Test]
    public function aplicar_sem_wamid_preserva_o_que_ja_estava(): void
    {
        $acao = $this->acao();
        DB::table('acao')->where('id', $acao)->update(['wamid' => 'wamid.ORIGINAL']);

        $this->fila->aplicar(
            $acao,
            new DecisaoDeEnvio('falhou', false, false, 'erro_permanente'),
            new Envio(http: 400, codigo: 131047),
        );

        $this->assertSame('wamid.ORIGINAL', DB::table('acao')->where('id', $acao)->value('wamid'));
    }

    /**
     * A resposta guarda o texto que a META escreveu para o usuário, e o `fbtrace_id`.
     *
     * O texto dela é melhor que a nossa paráfrase — evita a tela dizer "erro" quando a
     * plataforma já explicou o quê. E `fbtrace_id` é o que um ticket com a Meta exige:
     * sem ele guardado, o suporte não tem como olhar o caso.
     */
    #[Test]
    public function aplicar_guarda_a_explicacao_da_meta_e_o_fbtrace(): void
    {
        $acao = $this->acao();

        $this->fila->aplicar(
            $acao,
            new DecisaoDeEnvio('falhou', false, false, 'recusado'),
            new Envio(
                http: 400,
                codigo: 131049,
                mensagemDoUsuario: 'Não foi possível entregar esta mensagem agora.',
                fbtrace: 'AbCdEf123',
            ),
        );

        $resposta = (string) DB::table('acao')->where('id', $acao)->value('resposta');
        $this->assertStringContainsString('Não foi possível entregar', $resposta);
        $this->assertStringContainsString('codigo=131049', $resposta);
        $this->assertStringContainsString('fbtrace=AbCdEf123', $resposta);
    }

    // --------------------------------------------------------------- esgotamento

    #[Test]
    public function marcar_esgotadas_falha_so_quem_estourou_o_teto(): void
    {
        $noTeto = $this->acao(telefone: '5511900000001');
        $abaixo = $this->acao(telefone: '5511900000002');
        DB::table('acao')->where('id', $noTeto)->update(['tentativas' => 3]);
        DB::table('acao')->where('id', $abaixo)->update(['tentativas' => 2]);

        $this->assertSame(1, $this->fila->marcarEsgotadas(3));

        $l = (array) DB::table('acao')->where('id', $noTeto)->first();
        $this->assertSame('falhou', $l['status']);
        $this->assertStringContainsString('esgotou_tentativas', (string) $l['resposta']);
        $this->assertNotNull($l['processado_em']);

        $this->assertSame('pendente', DB::table('acao')->where('id', $abaixo)->value('status'));
    }

    /**
     * Não mexe em quem já terminou.
     *
     * Uma Ação `enviado` com tentativas no teto é sucesso na primeira e re-registro
     * depois; marcá-la `falhou` transformaria mensagem entregue em falha na tela.
     */
    #[Test]
    public function marcar_esgotadas_nao_mexe_em_acao_terminal(): void
    {
        $acao = $this->acao();
        DB::table('acao')->where('id', $acao)->update(['status' => 'enviado', 'tentativas' => 9]);

        $this->assertSame(0, $this->fila->marcarEsgotadas(3));
        $this->assertSame('enviado', DB::table('acao')->where('id', $acao)->value('status'));
    }

    // --------------------------------------------------------------- credenciais

    /**
     * 🔴 A INTEGRAÇÃO EM VIGOR VEM DE `configuracao`, não de "a primeira ativa".
     *
     * Pode haver mais de uma conta cadastrada — a de teste e a de produção. Qual está em
     * uso é escolha do operador, não ordem de inserção. Pegar a primeira ativa mandaria
     * as mensagens pela conta errada, com o número errado como remetente, e tudo
     * pareceria funcionar.
     */
    #[Test]
    public function credenciais_vem_da_integracao_escolhida_e_nao_da_primeira(): void
    {
        $cofre = new Cofre((string) config('app.key'));

        $primeira = (int) DB::table('integracao')->insertGetId([
            'tipo' => 'whatsapp', 'provedor' => 'meta', 'nome' => 'Teste', 'ativo' => 1,
            'credenciais' => $cofre->cifrarParaColuna([
                'token' => 'TOKEN-DE-TESTE', 'phone_number_id' => '111', 'versao_api' => 'v21.0',
            ]),
        ]);
        $escolhida = (int) DB::table('integracao')->insertGetId([
            'tipo' => 'whatsapp', 'provedor' => 'meta', 'nome' => 'Produção', 'ativo' => 1,
            'credenciais' => $cofre->cifrarParaColuna([
                'token' => 'TOKEN-DE-PRODUCAO', 'phone_number_id' => '999', 'versao_api' => 'v21.0',
            ]),
        ]);
        $this->assertLessThan($escolhida, $primeira);

        DB::table('configuracao')->where('id', 1)->update(['integracao_whatsapp_id' => $escolhida]);

        $c = $this->fila->credenciaisWhatsapp();

        $this->assertNotNull($c);
        $this->assertSame('TOKEN-DE-PRODUCAO', $c['token']);
        $this->assertSame('999', $c['numero_id']);
    }

    #[Test]
    public function sem_integracao_escolhida_nao_ha_credencial(): void
    {
        $this->assertNull($this->fila->credenciaisWhatsapp());
    }

    // ---------------------------------------------------------------- auxiliares

    private function acao(
        string $telefone = '5511900000001',
        string $canal = 'whatsapp',
        string $template = 'tpl',
    ): int {
        $campanha = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C ' . $telefone, 'tipo' => 'opt_in', 'status' => 1,
            'canal_opt_in' => $canal, 'template_nome' => $template,
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanha, 'telefone' => $telefone, 'status' => 3,
        ]);
        $ligacao = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $campanha,
            'numero_tentativa' => 1, 'iniciada_em' => now(), 'desfecho' => 'opt_in',
        ]);

        return (int) DB::table('acao')->insertGetId([
            'tipo' => 'disparo', 'referencia_id' => $ligacao, 'status' => 'pendente',
        ]);
    }
}
