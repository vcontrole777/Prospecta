<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As três guardas de `iniciar` são a diferença entre "discou" e "discou e não fez
 * nada". Cada uma corresponde a um jeito de a Campanha parecer funcionar e não
 * produzir nada.
 */
final class IniciarCampanhaTest extends TestCase
{
    use RefreshDatabase;

    private int $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->audio = (int) DB::table('audio')->insertGetId([
            'nome' => 'Mensagem', 'arquivo' => 'msg.alaw', 'ativo' => 1,
        ]);
    }

    /**
     * Sem Lead, o dialer não acha nada e marca a Campanha como concluída na MESMA
     * volta. O operador clicaria em iniciar e veria "concluída" um segundo depois —
     * parece defeito, e a causa verdadeira não aparece em lugar nenhum.
     */
    #[Test]
    public function recusa_sem_lead_disponivel(): void
    {
        $id = $this->campanha();

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            // Afirma o que o operador VÊ na resposta, não `session('recusa')`: o
            // flash é lido pela view no MESMO ciclo, e uma leitura de sessão do
            // lado do teste devolve null sem que isso diga nada sobre a tela.
            ->assertSee('nenhum Lead disponível');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * Sem áudio, a chamada sai muda. Medido: `Playback` em arquivo ausente devolve
     * PLAYBACKSTATUS=FAILED e o dialplan CONTINUA — o Lead ouve silêncio e o fluxo
     * segue como se nada tivesse acontecido.
     */
    #[Test]
    public function recusa_sem_audio_de_mensagem(): void
    {
        $id = $this->campanha(['audio_mensagem_id' => null]);
        $this->lead($id);

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('áudio da mensagem');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * Sem template, o Lead aperta 1, a Ação é gravada e o worker a recusa com
     * `campanha_sem_template_ou_lead_sem_telefone`. O opt-in que o cliente pagou
     * para conseguir morre DEPOIS de dar certo, que é o pior momento.
     */
    #[Test]
    public function recusa_whatsapp_sem_template(): void
    {
        $id = $this->campanha(['template_nome' => null]);
        $this->lead($id);

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('nome do template ausente');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    #[Test]
    public function inicia_quando_esta_tudo_no_lugar(): void
    {
        $id = $this->campanha();
        $this->lead($id);

        Livewire::test(Campanhas::class)->call('iniciar', $id);

        $c = DB::table('campanha')->where('id', $id)->first();
        $this->assertNotNull($c);
        $this->assertSame(1, (int) $c->status);
        $this->assertNotNull($c->iniciada_em, 'a primeira ativação registra quando começou');
    }

    /** Lead aguardando a próxima rodada conta: o dialer reabre a rodada sozinho. */
    #[Test]
    public function lead_aguardando_rodada_conta_como_disponivel(): void
    {
        $id = $this->campanha();
        $this->lead($id, status: 4);

        Livewire::test(Campanhas::class)->call('iniciar', $id);

        $this->assertSame(1, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    #[Test]
    public function retomar_nao_reescreve_quando_a_campanha_comecou(): void
    {
        $id = $this->campanha(['status' => 2, 'iniciada_em' => '2026-08-01 09:00:00']);
        $this->lead($id);

        Livewire::test(Campanhas::class)->call('iniciar', $id);

        $c = DB::table('campanha')->where('id', $id)->first();
        $this->assertNotNull($c);
        $this->assertSame(1, (int) $c->status);
        $this->assertStringStartsWith('2026-08-01 09:00:00', (string) $c->iniciada_em);
    }

    #[Test]
    public function pausar_tira_do_ar(): void
    {
        $id = $this->campanha(['status' => 1]);

        Livewire::test(Campanhas::class)->call('pausar', $id);

        $this->assertSame(2, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * Campanha concluída não volta a ser pausada: isso a ressuscitaria como
     * pausável, e o dialer voltaria a avaliá-la sem motivo.
     */
    #[Test]
    public function pausar_nao_mexe_em_campanha_concluida(): void
    {
        $id = $this->campanha(['status' => 3]);

        Livewire::test(Campanhas::class)->call('pausar', $id);

        $this->assertSame(3, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /** @param array<string,mixed> $atributos */
    private function campanha(array $atributos = []): int
    {
        return (int) DB::table('campanha')->insertGetId(array_merge([
            'tipo' => 'opt_in',
            'nome' => 'Campanha',
            'status' => 0,
            'canal_opt_in' => 'whatsapp',
            'template_nome' => 'boas_vindas',
            'audio_mensagem_id' => $this->audio,
        ], $atributos));
    }

    private function lead(int $campanhaId, int $status = 1): void
    {
        DB::table('leads')->insert([
            'campanha_id' => $campanhaId,
            'telefone' => '551190000' . random_int(1000, 9999),
            'status' => $status,
        ]);
    }
}
