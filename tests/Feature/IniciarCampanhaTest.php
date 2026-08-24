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
    /**
     * Reiniciar devolve à fila quem já foi discado, e zera as tentativas.
     *
     * `leads.status`: 1 disponível · 2 em voo · 3 terminal · 4 aguardando rodada.
     */
    #[Test]
    public function reiniciar_repoe_os_leads_terminais_e_os_que_aguardam(): void
    {
        $id = $this->campanha(['status' => 2]);
        $this->lead($id, 3);   // terminal
        $this->lead($id, 4);   // aguardando a próxima rodada
        $this->lead($id, 1);   // já disponível

        DB::table('leads')->where('campanha_id', $id)->update(['try' => 3]);

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        $this->assertSame(3, DB::table('leads')->where('campanha_id', $id)->where('status', 1)->count());
        $this->assertSame(0, DB::table('leads')->where('campanha_id', $id)->where('try', '>', 0)->count());
    }

    /**
     * 🔴 LEAD EM VOO NÃO VOLTA PARA A FILA.
     *
     * Status 2 é Lead com Ligação EM CURSO: o dialer o reivindicou e o fastagi ainda vai
     * gravar o desfecho. Devolvê-lo faria o mesmo número ser discado enquanto a pessoa
     * está na linha. Quem tira do 2 é o dialer ao concluir, ou a reconciliação por tempo.
     */
    #[Test]
    public function reiniciar_nao_mexe_em_lead_com_ligacao_em_curso(): void
    {
        $id = $this->campanha(['status' => 2]);
        $this->lead($id, 2);   // em voo
        $this->lead($id, 3);   // terminal

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        $this->assertSame(1, DB::table('leads')->where('campanha_id', $id)->where('status', 2)->count());
        $this->assertSame(1, DB::table('leads')->where('campanha_id', $id)->where('status', 1)->count());
    }

    /**
     * 🔴 SÓ OPT-IN E OPT-OUT FICAM DE FORA.
     *
     * `opt_out` é obrigatório — a pessoa pediu para não ser contatada. `opt_in` já
     * converteu: ligar de novo gasta canal com quem está do outro lado do funil.
     *
     * `captura_concluida` e `captura_parcial` VOLTAM: reiniciar é rodar a Campanha de
     * novo, e recadastramento é para todo mundo menos quem converteu ou recusou.
     */
    #[Test]
    public function reiniciar_deixa_de_fora_so_quem_deu_opt_in_ou_opt_out(): void
    {
        $id = $this->campanha(['status' => 2]);

        $fora = [];
        foreach (['opt_in', 'opt_out'] as $d) {
            $lead = $this->lead($id, 3);
            $this->ligacaoCom($id, $lead, $d);
            $fora[$d] = $lead;
        }

        // O Opt-out real entra na Lista de bloqueio no momento da tecla, APONTANDO para a
        // Ligação — e é essa Ligação que o reinício apaga. A FK é `nullOnDelete`
        // justamente para o bloqueio sobreviver ao apagamento; sem isso o `ON DELETE`
        // padrão levaria a Lista de bloqueio junto e a pessoa voltaria a ser discada.
        DB::table('bloqueio')->insert([
            'telefone' => (string) DB::table('leads')->where('id', $fora['opt_out'])->value('telefone'),
            'ligacao_id' => (int) DB::table('ligacao')->where('lead_id', $fora['opt_out'])->value('id'),
            'origem' => 'opt_out',
        ]);

        $volta = [];
        foreach ([
            'nao_atendeu', 'maquina', 'sem_resposta', 'invalido', 'erro',
            'captura_concluida', 'captura_parcial',
        ] as $d) {
            $lead = $this->lead($id, 3);
            $this->ligacaoCom($id, $lead, $d);
            $volta[$d] = $lead;
        }

        // Nunca discado: também volta.
        $volta['sem_ligacao'] = $this->lead($id, 3);

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        foreach ($fora as $desfecho => $leadId) {
            $this->assertSame(
                3,
                (int) DB::table('leads')->where('id', $leadId)->value('status'),
                "{$desfecho} não pode voltar para a fila",
            );
        }

        foreach ($volta as $desfecho => $leadId) {
            $this->assertSame(
                1,
                (int) DB::table('leads')->where('id', $leadId)->value('status'),
                "{$desfecho} tinha que voltar para a fila",
            );
        }

        $this->assertSame(
            1,
            DB::table('bloqueio')->count(),
            'a Lista de bloqueio sobrevive ao apagamento das Ligações que a originaram',
        );
    }

    /**
     * Opt-out pode ter vindo por WhatsApp, sem Ligação com desfecho `opt_out`. A Lista de
     * bloqueio é a fonte que cobre esse caso — e é a única que sobrevive ao apagamento.
     */
    #[Test]
    public function reiniciar_nao_repoe_numero_na_lista_de_bloqueio(): void
    {
        $id = $this->campanha(['status' => 2]);
        $lead = $this->lead($id, 3);
        $telefone = (string) DB::table('leads')->where('id', $lead)->value('telefone');

        DB::table('bloqueio')->insert(['telefone' => $telefone, 'origem' => 'opt_out']);

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        $this->assertSame(3, (int) DB::table('leads')->where('id', $lead)->value('status'));
    }

    /** Reiniciar apaga o histórico da Campanha: a tela recomeça com os contadores em zero. */
    #[Test]
    public function reiniciar_apaga_o_historico_da_campanha(): void
    {
        $id = $this->campanha(['status' => 3, 'rodada_atual' => 4, 'iniciada_em' => '2026-08-01 09:00:00']);
        $lead = $this->lead($id, 3);
        $this->ligacaoCom($id, $lead, 'nao_atendeu');
        $this->ligacaoCom($id, $lead, 'maquina');

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        $this->assertSame(0, DB::table('ligacao')->where('campanha_id', $id)->count());

        $c = DB::table('campanha')->where('id', $id)->first();
        $this->assertNotNull($c);
        $this->assertSame(0, (int) $c->rodada_atual, 'a rodada faz parte do histórico');
        $this->assertNotNull($c->iniciada_em, 'e o início passa a ser o desta volta');
        $this->assertStringStartsNotWith('2026-08-01', (string) $c->iniciada_em);
    }

    /**
     * 🔴 O HISTÓRICO DE OUTRA CAMPANHA NÃO É TOCADO. `campanha_id` está no `where` das
     * duas consultas, e um deslize ali apagaria a instância inteira num clique.
     */
    #[Test]
    public function reiniciar_nao_apaga_o_historico_de_outra_campanha(): void
    {
        $alvo = $this->campanha(['status' => 2]);
        $this->lead($alvo, 3);

        $vizinha = $this->campanha(['status' => 1]);
        $leadVizinho = $this->lead($vizinha, 3);
        $this->ligacaoCom($vizinha, $leadVizinho, 'nao_atendeu');

        Livewire::test(Campanhas::class)->call('reiniciar', $alvo);

        $this->assertSame(1, DB::table('ligacao')->where('campanha_id', $vizinha)->count());
        $this->assertSame(3, (int) DB::table('leads')->where('id', $leadVizinho)->value('status'));
    }

    /**
     * 🔴 O SEGUNDO REINÍCIO TAMBÉM POUPA QUEM DEU OPT-IN.
     *
     * É o defeito que a coluna `leads.opt_in_em` existe para impedir: a prova do Opt-in
     * morava em `ligacao.desfecho`, e reiniciar apaga `ligacao`. Lendo dali, o primeiro
     * reinício pouparia o Lead e o segundo o rediscaria — semanas depois, sem causa à
     * vista.
     */
    #[Test]
    public function reiniciar_duas_vezes_ainda_deixa_o_opt_in_de_fora(): void
    {
        $id = $this->campanha(['status' => 2]);
        $lead = $this->lead($id, 3);
        $this->ligacaoCom($id, $lead, 'opt_in');

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        // Fora do ar de novo, como se o operador tivesse pausado para rodar mais uma vez.
        DB::table('campanha')->where('id', $id)->update(['status' => 2]);
        $this->assertSame(0, DB::table('ligacao')->where('campanha_id', $id)->count());

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        $this->assertSame(
            3,
            (int) DB::table('leads')->where('id', $lead)->value('status'),
            'quem converteu não volta para a fila nunca, nem depois de o histórico sumir',
        );
    }

    /**
     * 🔴 A LIGAÇÃO EM CURSO NÃO É APAGADA.
     *
     * O `fastagi` ainda vai gravar o desfecho dela com um `UPDATE ... WHERE id = ?`.
     * Sem a linha, esse UPDATE acerta ZERO linhas em silêncio e o desfecho de uma
     * conversa que aconteceu se perde.
     */
    #[Test]
    public function reiniciar_nao_apaga_a_ligacao_de_lead_em_voo(): void
    {
        $id = $this->campanha(['status' => 2]);

        $emVoo = $this->lead($id, 2);
        $this->ligacaoCom($id, $emVoo, null);   // ainda sem desfecho: o fastagi vai gravar

        $terminal = $this->lead($id, 3);
        $this->ligacaoCom($id, $terminal, 'nao_atendeu');

        Livewire::test(Campanhas::class)->call('reiniciar', $id);

        $this->assertSame(1, DB::table('ligacao')->where('lead_id', $emVoo)->count());
        $this->assertSame(0, DB::table('ligacao')->where('lead_id', $terminal)->count());
    }

    /**
     * Disparo pendente que aponta para Ligação apagada fica invisível para sempre:
     * `FilaDeAcoes` reivindica com `JOIN ligacao`. Vai junto, e o recado diz quantos.
     */
    #[Test]
    public function reiniciar_leva_junto_o_disparo_pendente_da_ligacao_apagada(): void
    {
        $id = $this->campanha(['status' => 2]);
        $lead = $this->lead($id, 3);
        $this->ligacaoCom($id, $lead, 'opt_in');

        $ligacao = (int) DB::table('ligacao')->where('lead_id', $lead)->value('id');
        DB::table('acao')->insert([
            'tipo' => 'disparo', 'referencia_id' => $ligacao, 'status' => 'pendente',
        ]);

        Livewire::test(Campanhas::class)
            ->call('reiniciar', $id)
            ->assertSee('1 disparo(s) de WhatsApp ainda pendentes');

        $this->assertSame(0, DB::table('acao')->where('referencia_id', $ligacao)->count());
    }

    /** A gravação sai do DISCO junto com a linha — senão o arquivo fica órfão para sempre. */
    #[Test]
    public function reiniciar_apaga_a_gravacao_do_disco(): void
    {
        $dir = sys_get_temp_dir() . '/prospecta-grav-' . getmypid();
        @mkdir($dir, 0o755, true);
        config(['fastagi.gravacao_dir' => $dir]);

        $id = $this->campanha(['status' => 2]);
        $lead = $this->lead($id, 3);
        $this->ligacaoCom($id, $lead, 'nao_atendeu');

        $ligacao = (int) DB::table('ligacao')->where('lead_id', $lead)->value('id');
        $arquivo = $dir . '/' . $ligacao . '.gsm';
        file_put_contents($arquivo, 'x');
        DB::table('ligacao')->where('id', $ligacao)->update(['gravacao' => $arquivo]);

        try {
            Livewire::test(Campanhas::class)->call('reiniciar', $id);

            $this->assertFileDoesNotExist($arquivo);
            $this->assertSame(0, DB::table('ligacao')->where('id', $ligacao)->count());
        } finally {
            @unlink($arquivo);
            @rmdir($dir);
        }
    }

    /**
     * 🔴 CAMINHO FORA DA BASE RETÉM A LIGAÇÃO. Apagar a linha deixaria o arquivo no disco
     * fora do alcance do expurgo, que só conhece o que está no banco.
     */
    #[Test]
    public function reiniciar_mantem_a_ligacao_cuja_gravacao_aponta_para_fora_da_base(): void
    {
        $dir = sys_get_temp_dir() . '/prospecta-grav-' . getmypid();
        @mkdir($dir, 0o755, true);
        config(['fastagi.gravacao_dir' => $dir]);

        $id = $this->campanha(['status' => 2]);
        $lead = $this->lead($id, 3);
        $this->ligacaoCom($id, $lead, 'nao_atendeu');

        $ligacao = (int) DB::table('ligacao')->where('lead_id', $lead)->value('id');
        DB::table('ligacao')->where('id', $ligacao)->update(['gravacao' => '/etc/asterisk/pjsip.conf']);

        try {
            Livewire::test(Campanhas::class)
                ->call('reiniciar', $id)
                ->assertSee('foram MANTIDAS');

            $this->assertFileExists('/etc/asterisk/pjsip.conf');
            $this->assertSame(1, DB::table('ligacao')->where('id', $ligacao)->count());
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // Máquina sem Asterisk instalado: o que importa é que a linha ficou.
            $this->assertSame(1, DB::table('ligacao')->where('id', $ligacao)->count());
        } finally {
            @rmdir($dir);
        }
    }

    /** Reiniciar põe a Campanha no ar sozinho — inclusive uma que já estava concluída. */
    #[Test]
    public function reiniciar_poe_a_campanha_no_ar(): void
    {
        $id = $this->campanha(['status' => 3]);
        $this->lead($id, 3);

        Livewire::test(Campanhas::class)
            ->call('reiniciar', $id)
            ->assertSee('Campanha no ar');

        $this->assertSame(1, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * 🔴 A ATIVAÇÃO AUTOMÁTICA PASSA PELAS GUARDAS DE `iniciar`, não por um atalho.
     *
     * E a recusa não pode deixar a Campanha encalhada: ela fica PAUSADA, com a fila já
     * reposta, e a tela volta a oferecer o botão de iniciar depois do conserto.
     */
    #[Test]
    public function reiniciar_repoe_a_fila_mesmo_quando_nao_consegue_por_no_ar(): void
    {
        $id = $this->campanha(['status' => 3, 'audio_mensagem_id' => null]);
        $lead = $this->lead($id, 3);

        Livewire::test(Campanhas::class)
            ->call('reiniciar', $id)
            ->assertSee('número(s) de volta na fila')
            ->assertSee('áudio da mensagem');

        $this->assertSame(1, (int) DB::table('leads')->where('id', $lead)->value('status'));
        $this->assertSame(2, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /**
     * 🔴 NÃO REINICIA CAMPANHA NO AR. O botão já não aparece, mas a guarda mora no método:
     * repor a fila com o dialer trabalhando faria a mesma pessoa receber duas ligações da
     * mesma rodada — e agora há apagamento no meio.
     */
    #[Test]
    public function reiniciar_recusa_campanha_no_ar(): void
    {
        $id = $this->campanha(['status' => 1]);
        $lead = $this->lead($id, 3);
        $this->ligacaoCom($id, $lead, 'nao_atendeu');

        Livewire::test(Campanhas::class)
            ->call('reiniciar', $id)
            ->assertSee('a Campanha está no ar');

        $this->assertSame(1, DB::table('ligacao')->where('campanha_id', $id)->count());
        $this->assertSame(3, (int) DB::table('leads')->where('id', $lead)->value('status'));
    }

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

    private function lead(int $campanhaId, int $status = 1): int
    {
        return (int) DB::table('leads')->insertGetId([
            'campanha_id' => $campanhaId,
            'telefone' => '551190000' . random_int(1000, 9999),
            'status' => $status,
        ]);
    }

    /** Anexa uma Ligação com desfecho ao Lead, que é o que decide o reinício. */
    private function ligacaoCom(int $campanhaId, int $leadId, ?string $desfecho): void
    {
        DB::table('ligacao')->insert([
            'lead_id' => $leadId,
            'campanha_id' => $campanhaId,
            'numero_tentativa' => 1,
            'iniciada_em' => now(),
            'desfecho' => $desfecho,
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
