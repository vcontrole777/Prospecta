<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Entrar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EntrarTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-12';

    /**
     * 🔴 Trava do achado do Laravel 13: acesso não autenticado tem que REDIRECIONAR.
     *
     * A convenção antiga de o middleware `auth` procurar uma rota chamada `login`
     * não existe mais. Sem `redirectGuestsTo` em bootstrap/app.php o handler
     * devolve `204 No Content` com status 401 — o operador veria página em branco
     * e nada no log explicaria. Este teste falha se alguém remover a linha.
     */
    #[Test]
    public function visitante_no_painel_vai_para_o_formulario_e_nao_recebe_401(): void
    {
        $r = $this->get('/');

        $r->assertRedirect('/entrar');
        $this->assertNotSame(401, $r->getStatusCode());
    }

    #[Test]
    public function o_formulario_abre_para_visitante(): void
    {
        $this->get('/entrar')
            ->assertOk()
            ->assertSee('acesso ao painel')
            // Diz onde a senha se redefine, porque não há fluxo por e-mail.
            ->assertSee('redefinida pelo fornecedor', escape: false);
    }

    #[Test]
    public function credencial_correta_entra_e_vai_para_o_painel(): void
    {
        $u = $this->operador();

        Livewire::test(Entrar::class)
            ->set('email', $u->email)
            ->set('senha', self::SENHA)
            ->call('entrar')
            ->assertHasNoErrors()
            ->assertRedirect(route('campanhas'));

        $this->assertTrue(Auth::check());
        $this->assertSame($u->id, Auth::id());
    }

    #[Test]
    public function senha_errada_nao_entra(): void
    {
        $u = $this->operador();

        Livewire::test(Entrar::class)
            ->set('email', $u->email)
            ->set('senha', 'errada-mas-longa-o-bastante')
            ->call('entrar')
            ->assertHasErrors('email');

        $this->assertFalse(Auth::check());
    }

    /**
     * 🔴 O formulário NÃO pode ser oráculo de e-mail.
     *
     * Num produto de usuário único existe um e-mail válido só. Se a mensagem
     * distinguir "não existe" de "senha errada", o formulário confirma qual é —
     * e aí metade do segredo está entregue, sem nenhuma senha ter vazado.
     */
    #[Test]
    public function email_inexistente_e_senha_errada_dao_a_mesma_mensagem(): void
    {
        $u = $this->operador();

        $comSenhaErrada = Livewire::test(Entrar::class)
            ->set('email', $u->email)
            ->set('senha', 'errada-mas-longa-o-bastante')
            ->call('entrar')
            ->errors()->get('email');

        $comEmailInexistente = Livewire::test(Entrar::class)
            ->set('email', 'ninguem@exemplo.com')
            ->set('senha', 'errada-mas-longa-o-bastante')
            ->call('entrar')
            ->errors()->get('email');

        $this->assertSame($comSenhaErrada, $comEmailInexistente);
        $this->assertSame(['Credenciais inválidas.'], $comSenhaErrada);
    }

    #[Test]
    public function tranca_depois_de_cinco_tentativas(): void
    {
        $u = $this->operador();

        for ($i = 1; $i <= 5; $i++) {
            Livewire::test(Entrar::class)
                ->set('email', $u->email)
                ->set('senha', 'errada-mas-longa-o-bastante')
                ->call('entrar')
                ->assertHasErrors('email');
        }

        // Sexta tentativa: mesmo COM a senha certa, não entra.
        $erro = Livewire::test(Entrar::class)
            ->set('email', $u->email)
            ->set('senha', self::SENHA)
            ->call('entrar')
            ->assertHasErrors('email')
            ->errors()->get('email');

        $this->assertStringContainsString('Muitas tentativas', $erro[0]);
        $this->assertFalse(Auth::check(), 'o limite tem que valer mesmo com a senha correta');
    }

    #[Test]
    public function quem_ja_entrou_nao_ve_o_formulario(): void
    {
        $this->actingAs($this->operador())
            ->get('/entrar')
            ->assertRedirect('/');
    }

    #[Test]
    public function a_saida_e_por_post_e_encerra_a_sessao(): void
    {
        $this->actingAs($this->operador());

        $this->post('/sair')->assertRedirect(route('login'));
        $this->assertFalse(Auth::check());
    }

    /**
     * Saída por GET não pode existir: qualquer `<img src="/sair">` numa página de
     * terceiro derrubaria a sessão do operador no meio de uma campanha.
     */
    #[Test]
    public function saida_por_get_nao_existe(): void
    {
        $this->actingAs($this->operador())
            ->get('/sair')
            ->assertStatus(405);
    }

    private function operador(): User
    {
        return User::factory()->create(['password' => self::SENHA]);
    }
}
