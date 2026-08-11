<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\CampanhaForm;
use App\Livewire\Campanhas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\CriaAudio;
use Tests\TestCase;

final class CampanhaCapturaTest extends TestCase
{
    use CriaAudio;
    use RefreshDatabase;

    private int $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->prompt = $this->audioComArquivo('Pergunta o CPF', 2);
    }

    protected function tearDown(): void
    {
        $this->limparAudios();
        parent::tearDown();
    }

    #[Test]
    public function cria_campanha_de_captura_com_campos_na_ordem(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Recadastramento')
            ->set('tipo', 'captura')
            ->set('campos', [
                $this->campo('CPF', 'cpf', 11),
                $this->campo('Contrato', 'numero', 6),
            ])
            ->call('salvar')
            ->assertHasNoErrors();

        $campanha = DB::table('campanha')->first();
        $this->assertNotNull($campanha);
        $this->assertSame('captura', $campanha->tipo);

        $campos = DB::select(
            'SELECT cd.nome, cd.tipo, cd.tamanho, cc.ordem, cc.obrigatorio, cc.tentativas, cc.timeout_seg
               FROM campanha_campo cc JOIN campo_definicao cd ON cd.id = cc.campo_definicao_id
              WHERE cc.campanha_id = ? ORDER BY cc.ordem',
            [$campanha->id],
        );

        $this->assertCount(2, $campos);
        // A ordem da pergunta é a ordem da lista, começando em 1 e sem lacuna.
        $this->assertSame(['CPF', 'Contrato'], array_map(fn ($c) => $c->nome, $campos));
        $this->assertSame([1, 2], array_map(fn ($c) => (int) $c->ordem, $campos));
        $this->assertSame(11, (int) $campos[0]->tamanho);
        $this->assertSame(6, (int) $campos[1]->tamanho);
    }

    /**
     * 🔴 CPF é 11 por definição: `Cpf::valido` exige exatamente 11 dígitos, e campo `cpf`
     * com outro tamanho NUNCA validaria — a pessoa digitaria, erraria todas as tentativas,
     * e o campo sairia como não coletado sem explicação nenhuma.
     */
    #[Test]
    public function cpf_e_forcado_para_onze_digitos(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Com CPF torto')
            ->set('tipo', 'captura')
            ->set('campos', [$this->campo('CPF', 'cpf', 5)])
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame(11, (int) DB::table('campo_definicao')->value('tamanho'));
    }

    /** A definição é GLOBAL: duas Campanhas que pedem o mesmo campo compartilham a linha. */
    #[Test]
    public function reaproveita_a_definicao_entre_campanhas(): void
    {
        foreach (['Primeira', 'Segunda'] as $nome) {
            Livewire::test(CampanhaForm::class)
                ->set('nome', $nome)
                ->set('tipo', 'captura')
                ->set('campos', [$this->campo('CPF', 'cpf', 11)])
                ->call('salvar')
                ->assertHasNoErrors();
        }

        $this->assertSame(2, DB::table('campanha')->count());
        $this->assertSame(2, DB::table('campanha_campo')->count());
        $this->assertSame(1, DB::table('campo_definicao')->count(), 'a definição é compartilhada');
    }

    /**
     * 🔴 Prompt obrigatório. Sem ele o `GET DATA` tocava um caminho fabricado e a pessoa
     * ouvia SILÊNCIO até o timeout, "errava" por não digitar, e o campo era descartado.
     */
    #[Test]
    public function exige_o_audio_que_faz_a_pergunta(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Sem prompt')
            ->set('tipo', 'captura')
            ->set('campos', [array_merge($this->campo('CPF', 'cpf', 11), ['audio_prompt_id' => null])])
            ->call('salvar')
            ->assertHasErrors('campos.0.audio_prompt_id');

        $this->assertSame(0, DB::table('campanha')->count());
    }

    #[Test]
    public function captura_sem_campo_e_recusada(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Vazia')
            ->set('tipo', 'captura')
            ->set('campos', [])
            ->call('salvar')
            ->assertHasErrors('campos');
    }

    /** Trocar o tipo para opt-in apaga os campos: eles não fazem sentido lá. */
    #[Test]
    public function trocar_para_opt_in_remove_os_campos(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Vira opt-in')
            ->set('tipo', 'captura')
            ->set('campos', [$this->campo('CPF', 'cpf', 11)])
            ->call('salvar');

        $id = (int) DB::table('campanha')->value('id');
        $this->assertSame(1, DB::table('campanha_campo')->count());

        Livewire::test(CampanhaForm::class, ['id' => $id])
            ->set('tipo', 'opt_in')
            ->set('audio_mensagem_id', $this->prompt)
            ->set('template_nome', 'tpl')
            ->call('salvar')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('campanha_campo')->count());
    }

    #[Test]
    public function editar_carrega_os_campos_na_ordem_gravada(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Editar')
            ->set('tipo', 'captura')
            ->set('campos', [$this->campo('CPF', 'cpf', 11), $this->campo('Contrato', 'numero', 6)])
            ->call('salvar');

        $id = (int) DB::table('campanha')->value('id');

        $c = Livewire::test(CampanhaForm::class, ['id' => $id])->assertSet('tipo', 'captura');
        $campos = $c->get('campos');

        $this->assertCount(2, $campos);
        $this->assertSame('CPF', $campos[0]['nome']);
        $this->assertSame('Contrato', $campos[1]['nome']);
    }

    /** Remover o do meio reindexa: `ordem` não pode ter lacuna. */
    #[Test]
    public function remover_campo_do_meio_reindexa_a_ordem(): void
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Três campos')
            ->set('tipo', 'captura')
            ->set('campos', [
                $this->campo('A', 'numero', 3),
                $this->campo('B', 'numero', 4),
                $this->campo('C', 'numero', 5),
            ])
            ->call('removerCampo', 1)
            ->call('salvar')
            ->assertHasNoErrors();

        $campos = DB::select(
            'SELECT cd.nome, cc.ordem FROM campanha_campo cc
               JOIN campo_definicao cd ON cd.id = cc.campo_definicao_id ORDER BY cc.ordem'
        );

        $this->assertSame(['A', 'C'], array_map(fn ($c) => $c->nome, $campos));
        $this->assertSame([1, 2], array_map(fn ($c) => (int) $c->ordem, $campos), 'sem lacuna');
    }

    // ---- a guarda de iniciar --------------------------------------------

    /** Captura NÃO usa áudio de mensagem: exigi-lo recusaria Campanha bem configurada. */
    #[Test]
    public function captura_inicia_sem_audio_de_mensagem(): void
    {
        $id = $this->campanhaDeCaptura();
        DB::table('leads')->insert(['campanha_id' => $id, 'telefone' => '5511987654321', 'status' => 1]);

        Livewire::test(Campanhas::class)->call('iniciar', $id);

        $this->assertSame(1, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    #[Test]
    public function captura_sem_campo_nao_vai_ao_ar(): void
    {
        $id = $this->campanhaDeCaptura();
        DB::table('campanha_campo')->where('campanha_id', $id)->delete();
        DB::table('leads')->insert(['campanha_id' => $id, 'telefone' => '5511987654321', 'status' => 1]);

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('nenhum campo a coletar', escape: false);

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    /** Prompt cadastrado mas ausente do disco: mesma classe do áudio de mensagem. */
    #[Test]
    public function captura_com_prompt_fora_do_disco_nao_vai_ao_ar(): void
    {
        $id = $this->campanhaDeCaptura();
        DB::table('leads')->insert(['campanha_id' => $id, 'telefone' => '5511987654321', 'status' => 1]);

        unlink($this->diretorioDeAudio() . '/' . DB::table('audio')->where('id', $this->prompt)->value('arquivo'));

        Livewire::test(Campanhas::class)
            ->call('iniciar', $id)
            ->assertSee('AUDIO_MISSING');

        $this->assertSame(0, (int) DB::table('campanha')->where('id', $id)->value('status'));
    }

    // ---- auxiliares ------------------------------------------------------

    /** @return array{nome:string, tipo:string, tamanho:int, audio_prompt_id:?int, obrigatorio:bool, tentativas:int, timeout_seg:int} */
    private function campo(string $nome, string $tipo, int $tamanho): array
    {
        return [
            'nome' => $nome,
            'tipo' => $tipo,
            'tamanho' => $tamanho,
            'audio_prompt_id' => $this->prompt,
            'obrigatorio' => true,
            'tentativas' => 2,
            'timeout_seg' => 15,
        ];
    }

    private function campanhaDeCaptura(): int
    {
        Livewire::test(CampanhaForm::class)
            ->set('nome', 'Captura')
            ->set('tipo', 'captura')
            ->set('campos', [$this->campo('CPF', 'cpf', 11)])
            ->call('salvar')
            ->assertHasNoErrors();

        return (int) DB::table('campanha')->value('id');
    }

}
