<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Audios;
use App\Livewire\Campanhas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Roda contra o `sox` de verdade: é a conversão que importa, e simulá-la testaria
 * o simulador. O diretório de áudio é redirecionado para um temporário.
 */
final class AudiosTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->dir = sys_get_temp_dir() . '/prospecta-audio-' . getmypid();
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0o770, true);
        }
        config(['prospecta.audio_path' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    #[Test]
    public function converte_e_grava_com_a_duracao_derivada_do_tamanho(): void
    {
        Livewire::test(Audios::class)
            ->set('nome', 'Mensagem principal')
            ->set('arquivo', $this->wav(segundos: 3.0, taxa: 44100, canais: 2))
            ->call('salvar')
            ->assertHasNoErrors();

        $a = DB::table('audio')->first();
        $this->assertNotNull($a);
        $this->assertSame('Mensagem principal', $a->nome);
        $this->assertSame(1, (int) $a->ativo);
        $this->assertSame(3, (int) $a->duracao_seg);

        // 🔴 Nome derivado do ID, nunca do nome digitado: fecha o contexto "caminho
        // de arquivo" do ADR-0003 por construção.
        $this->assertSame($a->id . '.alaw', $a->arquivo);

        // A prova de que o formato saiu certo: 8000 bytes por segundo, exatos.
        $caminho = $this->dir . '/' . $a->arquivo;
        $this->assertFileExists($caminho);
        $this->assertSame(24000, filesize($caminho), '3 s a 8 kHz mono A-law');
    }

    #[Test]
    public function recusa_arquivo_que_o_sox_nao_le_e_nao_deixa_linha_orfa(): void
    {
        $falso = UploadedFile::fake()->createWithContent('audio.wav', 'isto nao e audio');

        Livewire::test(Audios::class)
            ->set('nome', 'Ruim')
            ->set('arquivo', $falso)
            ->call('salvar')
            ->assertHasErrors('arquivo');

        // A linha nasce inativa e é removida na recusa: nunca esteve disponível, então
        // apagar não deixa Campanha apontando para nada.
        $this->assertSame(0, DB::table('audio')->count());
    }

    #[Test]
    public function recusa_audio_mais_longo_que_o_limite(): void
    {
        config(['prospecta.audio_limite_seg' => 1.0]);

        Livewire::test(Audios::class)
            ->set('nome', 'Longo')
            ->set('arquivo', $this->wav(segundos: 3.0))
            ->call('salvar')
            ->assertHasErrors('arquivo');

        $this->assertSame(0, DB::table('audio')->count());
    }

    /** Desativa, nunca apaga: Campanha antiga continua apontando para o que tocou. */
    #[Test]
    public function desativar_mantem_a_linha_e_o_arquivo(): void
    {
        Livewire::test(Audios::class)
            ->set('nome', 'Aviso')
            ->set('arquivo', $this->wav(segundos: 1.0))
            ->call('salvar');

        $id = (int) DB::table('audio')->value('id');
        $caminho = $this->dir . '/' . $id . '.alaw';

        Livewire::test(Audios::class)->call('desativar', $id);

        $this->assertSame(1, DB::table('audio')->count(), 'a linha permanece');
        $this->assertFileExists($caminho, 'o arquivo permanece');
        $this->assertSame(0, (int) DB::table('audio')->value('ativo'));

        Livewire::test(Audios::class)->call('reativar', $id);
        $this->assertSame(1, (int) DB::table('audio')->value('ativo'));
    }

    #[Test]
    public function avisa_quando_o_audio_desativado_esta_em_uso(): void
    {
        Livewire::test(Audios::class)
            ->set('nome', 'Em uso')
            ->set('arquivo', $this->wav(segundos: 1.0))
            ->call('salvar');

        $id = (int) DB::table('audio')->value('id');
        DB::table('campanha')->insert([
            'tipo' => 'opt_in', 'nome' => 'C', 'status' => 1, 'audio_mensagem_id' => $id,
        ]);

        Livewire::test(Audios::class)
            ->call('desativar', $id)
            ->assertSee('ainda o referenciam');
    }

    /**
     * 🔴 A guarda de ativação: a linha no banco NÃO é prova de que o arquivo existe.
     * O README exige verificação na ativação, com causa nomeada, porque entre
     * cadastrar e iniciar o arquivo pode desaparecer.
     */
    #[Test]
    public function iniciar_recusa_quando_o_arquivo_sumiu_do_disco(): void
    {
        Livewire::test(Audios::class)
            ->set('nome', 'Vai sumir')
            ->set('arquivo', $this->wav(segundos: 1.0))
            ->call('salvar');

        $id = (int) DB::table('audio')->value('id');
        $campanha = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'C', 'status' => 0,
            'canal_opt_in' => 'whatsapp', 'template_nome' => 'tpl',
            'audio_mensagem_id' => $id,
        ]);
        DB::table('leads')->insert(['campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 1]);

        // Com o arquivo no lugar, inicia.
        Livewire::test(Campanhas::class)->call('iniciar', $campanha);
        $this->assertSame(1, (int) DB::table('campanha')->where('id', $campanha)->value('status'));

        // Alguém remove o arquivo por fora e a Campanha é pausada e reiniciada.
        DB::table('campanha')->where('id', $campanha)->update(['status' => 2]);
        unlink($this->dir . '/' . $id . '.alaw');

        Livewire::test(Campanhas::class)
            ->call('iniciar', $campanha)
            ->assertSee('AUDIO_MISSING');

        $this->assertSame(2, (int) DB::table('campanha')->where('id', $campanha)->value('status'));
    }

    /**
     * O arquivo existe e o tamanho não corresponde à duração gravada — arquivo
     * truncado, ou trocado por outro em formato diferente.
     */
    #[Test]
    public function iniciar_recusa_arquivo_com_tamanho_incompativel(): void
    {
        Livewire::test(Audios::class)
            ->set('nome', 'Vai truncar')
            ->set('arquivo', $this->wav(segundos: 3.0))
            ->call('salvar');

        $id = (int) DB::table('audio')->value('id');
        $campanha = DB::table('campanha')->insertGetId([
            'tipo' => 'opt_in', 'nome' => 'C', 'status' => 0,
            'canal_opt_in' => 'whatsapp', 'template_nome' => 'tpl',
            'audio_mensagem_id' => $id,
        ]);
        DB::table('leads')->insert(['campanha_id' => $campanha, 'telefone' => '5511987654321', 'status' => 1]);

        // Trunca para 1 s, mantendo `duracao_seg = 3` no banco.
        file_put_contents($this->dir . '/' . $id . '.alaw', str_repeat("\x55", 8000));

        Livewire::test(Campanhas::class)
            ->call('iniciar', $campanha)
            ->assertSee('AUDIO_INCOMPATIBLE');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $campanha)->value('status'));
    }

    #[Test]
    public function a_pagina_renderiza_inteira(): void
    {
        $this->get(route('audios'))
            ->assertOk()
            ->assertSee('Áudios')
            ->assertSee('Nenhum áudio cadastrado', escape: false)
            ->assertDontSee('Unclosed');
    }

    private function wav(float $segundos, int $taxa = 22050, int $canais = 1): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'src') . '.wav';
        exec(sprintf(
            'sox -n -r %d -c %d %s synth %s sine 440 2>/dev/null',
            $taxa,
            $canais,
            escapeshellarg($tmp),
            escapeshellarg((string) $segundos),
        ));
        self::assertFileExists($tmp, 'o sox precisa estar instalado para este teste');

        // `UploadedFile::fake()->createWithContent` e não `new UploadedFile`: o
        // Livewire acessa propriedades que só o arquivo falso do framework tem, e um
        // objeto construído à mão falha com "Undefined property ...::$name".
        $conteudo = (string) file_get_contents($tmp);
        unlink($tmp);

        return UploadedFile::fake()->createWithContent('audio.wav', $conteudo);
    }
}
