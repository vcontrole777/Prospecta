<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CampanhaForm;
use App\Models\User;
use App\Worker\FilaDeAcoes;
use App\Worker\PoliticaDeEnvio;
use App\Worker\Sms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Worker\TransporteFalso;

/**
 * Envio de SMS pelo httpSMS — um celular Android com API.
 *
 * 🔴 O CANAL EXISTIA NO ESQUEMA SEM REMETENTE. `campanha.canal_opt_in` já aceitava
 * `sms`, `texto_sms` já era coluna, e `integracao.tipo` já tinha o valor — mas nada
 * enviava. A fila filtrava esses Disparos fora e um contador os tornava visíveis,
 * para uma Campanha de SMS não parecer simplesmente não fazer nada.
 */
final class SmsTest extends TestCase
{
    use RefreshDatabase;

    // ---- o envio ----------------------------------------------------------

    /** A forma que o httpSMS exige: `from`, `to`, `content`, e a chave no cabeçalho. */
    #[Test]
    public function monta_o_envio_do_httpsms(): void
    {
        $t = new TransporteFalso(200, '{"data":{"id":"msg-1"},"message":"ok","status":"success"}');

        $e = (new Sms($t, 'chave-secreta', '5511900000000'))
            ->enviar('5511987654321', 'Olá Maria', 'ligacao:42');

        $this->assertSame('https://api.httpsms.com/v1/messages/send', $t->url);

        // `x-api-key`, e NÃO `Authorization: Bearer` — mandar Bearer devolve 401 sem
        // dizer o que faltou.
        $this->assertSame('chave-secreta', $t->cabecalhos['x-api-key'] ?? null);
        $this->assertArrayNotHasKey('Authorization', $t->cabecalhos);

        $this->assertSame([
            'from' => '+5511900000000',
            'to' => '+5511987654321',
            'content' => 'Olá Maria',
            'request_id' => 'ligacao:42',
        ], $t->corpo);

        $this->assertTrue($e->aceito());
        $this->assertSame('msg-1', $e->wamid);
    }

    /**
     * 🔴 O `+` É NOSSO, e o produto guarda telefone SEM ele.
     *
     * `Dominio\Telefone` normaliza para só dígitos porque é o que o Asterisk quer no
     * `Originate`. O httpSMS recusa sem o `+`, e a recusa é um 422 sobre formato —
     * que não menciona telefone e manda quem investiga para o lado errado.
     */
    #[Test]
    public function acrescenta_o_mais_sem_duplicar(): void
    {
        $t = new TransporteFalso(200, '{"data":{"id":"x"}}');
        (new Sms($t, 'k', '+5511900000000'))->enviar('5511987654321', 'oi');

        $this->assertSame('+5511900000000', $t->corpo['from'] ?? null);
        $this->assertSame('+5511987654321', $t->corpo['to'] ?? null);
    }

    /**
     * 🔴 200 SEM `id` NÃO É ACEITO.
     *
     * Resposta sem identificador não permite casar o webhook de entrega depois.
     * Tratar como aceito criaria uma Ação `enviado` que nunca ganharia status —
     * indistinguível de uma que a operadora engoliu.
     */
    #[Test]
    public function resposta_sem_id_nao_conta_como_aceita(): void
    {
        $t = new TransporteFalso(200, '{"message":"ok","status":"success"}');
        $e = (new Sms($t, 'k', '5511900000000'))->enviar('5511987654321', 'oi');

        $this->assertFalse($e->aceito());
    }

    /**
     * A política de envio vale IGUAL, e é o que se ganha ao reusar `Envio`.
     *
     * O httpSMS também não deduplica — `request_id` serve para o cliente rastrear,
     * não para a plataforma reconhecer repetição —, então a premissa que governa a
     * `PoliticaDeEnvio` continua valendo: só retenta quando há razão POSITIVA para
     * crer que nada saiu.
     */
    #[Test]
    public function quatrocentos_nao_retenta_e_quinhentos_retenta(): void
    {
        $p = new PoliticaDeEnvio();

        $t422 = new TransporteFalso(422, '{"message":"validation error"}');
        $e422 = (new Sms($t422, 'k', '5511900000000'))->enviar('5511987654321', 'oi');
        $this->assertSame('falhou', $p->decidir($e422, 1, 3)->status);

        $t500 = new TransporteFalso(500, '{"message":"boom"}');
        $e500 = (new Sms($t500, 'k', '5511900000000'))->enviar('5511987654321', 'oi');
        $this->assertNotSame('falhou', $p->decidir($e500, 1, 3)->status, '5xx retenta');

        // Falha de conexão: o único caso em que se SABE que nada saiu.
        $tSem = new TransporteFalso(0, '', 'conexão recusada');
        $eSem = (new Sms($tSem, 'k', '5511900000000'))->enviar('5511987654321', 'oi');
        $this->assertTrue($eSem->semResposta);
    }

    // ---- a fila -----------------------------------------------------------

    /** @return array{campanha:int, acao:int} */
    private function campanhaComDisparo(string $canal, ?string $textoSms): array
    {
        $c = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 1, 'criada_em' => now(),
            'canal_opt_in' => $canal, 'texto_sms' => $textoSms,
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $c, 'telefone' => '5511987654321',
            'dados_extras' => json_encode(['nome' => 'Maria']), 'status' => 2, 'criado_em' => now(),
        ]);
        $lig = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $c, 'numero_tentativa' => 1, 'iniciada_em' => now(),
        ]);
        $acao = (int) DB::table('acao')->insertGetId([
            'tipo' => 'disparo', 'referencia_id' => $lig, 'status' => 'pendente',
            'tentativas' => 0, 'criado_em' => now(),
        ]);

        return ['campanha' => $c, 'acao' => $acao];
    }

    /**
     * 🔴 O CONTADOR DE "CANAL SEM REMETENTE" PASSOU A IGNORAR O SMS.
     *
     * Ele existia justamente porque o SMS não tinha como sair. Agora tem, e contá-lo
     * mandaria quem lesse o log procurar um problema que não existe. A guarda
     * continua para o dia em que alguém acrescentar um canal ao enum sem escrever o
     * remetente.
     */
    #[Test]
    public function sms_deixou_de_contar_como_canal_sem_remetente(): void
    {
        $this->campanhaComDisparo('sms', 'Olá');

        $this->assertSame(0, (new FilaDeAcoes($this->pdo()))->contarAguardandoCanal());
    }

    /** Sem credencial cadastrada não há o que mandar, e a fila não é reivindicada. */
    #[Test]
    public function sem_integracao_de_sms_as_credenciais_sao_nulas(): void
    {
        $this->assertNull((new FilaDeAcoes($this->pdo()))->credenciaisSms());
    }

    /** Credencial pela metade também é nula: o httpSMS recusaria com 422 sobre formato. */
    #[Test]
    public function credencial_de_sms_incompleta_e_recusada(): void
    {
        $id = (int) DB::table('integracao')->insertGetId([
            'tipo' => 'sms', 'provedor' => 'httpsms', 'nome' => 'Celular',
            'credenciais' => json_encode(['api_key' => 'k']),   // sem `remetente`
            'ativo' => 1, 'criado_em' => now(),
        ]);
        DB::table('configuracao')->where('id', 1)->update(['integracao_sms_id' => $id]);

        $this->assertNull((new FilaDeAcoes($this->pdo()))->credenciaisSms());
    }

    #[Test]
    public function credencial_de_sms_completa_e_lida(): void
    {
        $id = (int) DB::table('integracao')->insertGetId([
            'tipo' => 'sms', 'provedor' => 'httpsms', 'nome' => 'Celular',
            'credenciais' => json_encode(['api_key' => 'k', 'remetente' => '5511900000000']),
            'ativo' => 1, 'criado_em' => now(),
        ]);
        DB::table('configuracao')->where('id', 1)->update(['integracao_sms_id' => $id]);

        $this->assertSame(
            ['api_key' => 'k', 'remetente' => '5511900000000'],
            (new FilaDeAcoes($this->pdo()))->credenciaisSms(),
        );
    }

    // ---- a tela -----------------------------------------------------------

    /**
     * 🔴 O CAMPO ENTROU QUANDO GANHOU LEITOR.
     *
     * O docblock do formulário lista `texto_sms` entre as ausências MEDIDAS — coluna
     * que nenhum processo lia. `Worker\Sms` a lê agora.
     */
    #[Test]
    public function a_tela_cobra_o_texto_no_canal_sms(): void
    {
        $this->actingAs(User::factory()->create());
        $audio = (int) DB::table('audio')->insertGetId([
            'nome' => 'M', 'arquivo' => 'm.alaw', 'ativo' => 1,
        ]);

        Livewire::test(CampanhaForm::class)
            ->set('nome', 'C')
            ->set('audio_mensagem_id', $audio)
            ->set('canal_opt_in', 'sms')
            ->call('salvar')
            ->assertHasErrors('texto_sms');

        Livewire::test(CampanhaForm::class)
            ->set('nome', 'C')
            ->set('audio_mensagem_id', $audio)
            ->set('canal_opt_in', 'sms')
            ->set('texto_sms', 'Olá {nome}, confira.')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame('Olá {nome}, confira.', DB::table('campanha')->value('texto_sms'));
    }

    /** E não cobra no WhatsApp — exigir sempre recusaria Campanha bem configurada. */
    #[Test]
    public function a_tela_nao_cobra_o_texto_no_canal_whatsapp(): void
    {
        $this->actingAs(User::factory()->create());
        $audio = (int) DB::table('audio')->insertGetId([
            'nome' => 'M', 'arquivo' => 'm.alaw', 'ativo' => 1,
        ]);

        Livewire::test(CampanhaForm::class)
            ->set('nome', 'C')
            ->set('audio_mensagem_id', $audio)
            ->set('canal_opt_in', 'whatsapp')
            ->set('template_nome', 'tpl')
            ->call('salvar')
            ->assertHasNoErrors();
    }

    private function pdo(): PDO
    {
        /** @var PDO $pdo */
        $pdo = DB::connection()->getPdo();

        return $pdo;
    }
}
