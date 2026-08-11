<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Campanhas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\CriaAudio;
use Tests\TestCase;

/**
 * As três guardas de `iniciar` são a diferença entre "discou" e "discou e não fez
 * nada". Cada uma corresponde a um jeito de a Campanha parecer funcionar e não
 * produzir nada.
 */
final class IniciarCampanhaTest extends TestCase
{
    use CriaAudio;
    use RefreshDatabase;

    private int $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        // COM arquivo no disco: a guarda de ativação confere o arquivo, não a linha.
        $this->audio = $this->audioComArquivo();
    }

    protected function tearDown(): void
    {
        $this->limparAudios();
        parent::tearDown();
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

    // ---------------------------------------------- a chamada cabe na reconciliação?

    /**
     * 🔴 RECUSA CAMPANHA CUJA CHAMADA PODE PASSAR DA JANELA DA RECONCILIAÇÃO.
     *
     * `Fila::reconciliar` encerra Ligação aberta há mais de `limite_ligacao_seg` e devolve
     * o Lead à Fila — a única prova que ela tem de que o evento de AMI se perdeu é o
     * tempo. Chamada VIVA que passa do limite é declarada morta: o canal é dado como
     * livre, o Lead volta à Fila e é discado DE NOVO, para alguém que está na linha
     * naquele instante, no meio de digitar o CPF.
     *
     * Alcançável com valores que o formulário aceita — cinco campos, duas tentativas,
     * prompt de 20 s, espera de 20 s. Recadastramento comum, não caso adversarial.
     */
    #[Test]
    public function recusa_captura_que_pode_passar_do_limite_da_reconciliacao(): void
    {
        // 20 s de prompt: 160000 bytes de alaw. O áudio existe no disco, então a guarda
        // de arquivo passa e a única falta é a duração.
        $prompt = $this->audioComArquivo('Pergunta longa', 20);

        $id = $this->campanha(['tipo' => 'captura']);
        $this->lead($id);

        for ($i = 1; $i <= 5; $i++) {
            $definicao = (int) DB::table('campo_definicao')->insertGetId([
                'nome' => 'Campo ' . $i, 'tipo' => 'numero', 'tamanho' => 6,
            ]);
            DB::table('campanha_campo')->insert([
                'campanha_id' => $id, 'campo_definicao_id' => $definicao, 'ordem' => $i,
                'obrigatorio' => 1, 'tentativas' => 2, 'timeout_seg' => 20,
                'audio_prompt_id' => $prompt,
            ]);
        }

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('a chamada pode durar até')
            ->assertSee('discaria de novo para quem ainda está na linha');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /** Captura que cabe no limite entra no ar normalmente. */
    #[Test]
    public function aceita_captura_que_cabe_no_limite(): void
    {
        $prompt = $this->audioComArquivo('Pergunta', 4);

        $id = $this->campanha(['tipo' => 'captura']);
        $this->lead($id);

        for ($i = 1; $i <= 3; $i++) {
            $definicao = (int) DB::table('campo_definicao')->insertGetId([
                'nome' => 'Campo ' . $i, 'tipo' => 'numero', 'tamanho' => 6,
            ]);
            DB::table('campanha_campo')->insert([
                'campanha_id' => $id, 'campo_definicao_id' => $definicao, 'ordem' => $i,
                'obrigatorio' => 1, 'tentativas' => 2, 'timeout_seg' => 12,
                'audio_prompt_id' => $prompt,
            ]);
        }

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertDontSee('a chamada pode durar até');

        $this->assertSame(1, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * O mesmo para opt-in: áudio longo repetido em cada tentativa também estoura.
     *
     * `GET DATA` toca o arquivo e só então espera pelo dígito, então quem não digita ouve
     * a mensagem inteira de novo — o áudio multiplica pelas tentativas da URA.
     */
    #[Test]
    public function recusa_opt_in_com_audio_longo_repetido_muitas_vezes(): void
    {
        $longo = $this->audioComArquivo('Mensagem longa', 90);

        $id = $this->campanha(['audio_mensagem_id' => $longo, 'tentativas_ura' => 3]);
        $this->lead($id);

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('a chamada pode durar até');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * 🔴 "NÃO CONSIGO LER" NÃO É "NÃO EXISTE" — e aqui a confusão BLOQUEIA o operador.
     *
     * Medido nesta máquina: os áudios são `root:asterisk` modo 0640 e o php-fpm roda como
     * `prospecta`. Sem o grupo `asterisk` nos workers em execução, `is_file()` devolve
     * false para arquivo presente, e a ativação era recusada com "o arquivo não está no
     * disco" — acusando ausência de arquivo que estava lá, numa frase da qual o operador
     * não tem como chegar à causa.
     *
     * Pulado como root, que dispensa a conferência de modo, com a causa dita.
     */
    #[Test]
    public function diretorio_de_audio_fechado_nao_e_relatado_como_arquivo_ausente(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignora modo de arquivo: is_readable responde true para 0000');
        }

        $id = $this->campanha(['audio_mensagem_id' => $this->audio]);
        $this->lead($id);

        $dir = $this->diretorioDeAudio();
        chmod($dir, 0o000);

        try {
            Livewire::test(Campanhas::class)
                ->call('iniciar', $id)
                ->assertSee('AUDIO_SEM_ACESSO')
                ->assertDontSee('não está no disco');
        } finally {
            chmod($dir, 0o755);
        }

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * Diretório inexistente segue sendo relatado como arquivo ausente, não como permissão.
     *
     * Instância sem áudio instalado tem um problema — copiar arquivo — e mandar o operador
     * mexer em grupo de usuário o afastaria dele.
     */
    #[Test]
    public function diretorio_de_audio_inexistente_ainda_acusa_arquivo_ausente(): void
    {
        $id = $this->campanha(['audio_mensagem_id' => $this->audio]);
        $this->lead($id);

        config(['prospecta.audio_path' => '/tmp/prospecta-audio-inexistente-' . getmypid()]);

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('não está no disco')
            ->assertDontSee('AUDIO_SEM_ACESSO');
    }
}
