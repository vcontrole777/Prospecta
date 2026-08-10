<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `prospecta:operador` É o caminho de cadastro do produto: não há tela de
 * registro nem redefinição por e-mail. Se este comando quebrar, ninguém entra na
 * instância — por isso ele tem teste, apesar de ser um comando de implantação.
 */
final class DefinirOperadorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cria_o_operador_e_a_senha_confere(): void
    {
        $this->artisan('prospecta:operador', [
            'email' => 'op@exemplo.com',
            '--nome' => 'Fulano',
            '--senha' => 'senha-bem-longa-1',
        ])->assertSuccessful();

        $u = User::query()->where('email', 'op@exemplo.com')->first();
        $this->assertNotNull($u);
        $this->assertSame('Fulano', $u->name);

        // A senha tem que estar em HASH utilizável, não em texto puro nem com
        // bcrypt aplicado duas vezes — que é o que aconteceria se o comando
        // chamasse Hash::make por cima do cast `hashed` do model.
        $this->assertNotSame('senha-bem-longa-1', $u->password);
        $this->assertTrue(Auth::attempt(['email' => 'op@exemplo.com', 'password' => 'senha-bem-longa-1']));
    }

    #[Test]
    public function redefine_a_senha_de_quem_ja_existe_sem_duplicar(): void
    {
        User::factory()->create(['email' => 'op@exemplo.com', 'password' => 'antiga-porem-longa']);

        $this->artisan('prospecta:operador', [
            'email' => 'op@exemplo.com',
            '--senha' => 'nova-senha-bem-longa',
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->count(), 'não pode criar uma segunda conta');
        $this->assertFalse(Auth::attempt(['email' => 'op@exemplo.com', 'password' => 'antiga-porem-longa']));
        $this->assertTrue(Auth::attempt(['email' => 'op@exemplo.com', 'password' => 'nova-senha-bem-longa']));
    }

    #[Test]
    public function recusa_senha_curta(): void
    {
        $this->artisan('prospecta:operador', [
            'email' => 'op@exemplo.com',
            '--senha' => 'curta1',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    #[Test]
    public function recusa_email_invalido(): void
    {
        $this->artisan('prospecta:operador', [
            'email' => 'nao-e-email',
            '--senha' => 'senha-bem-longa-1',
        ])->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    /**
     * Não bloqueia — rotação de acesso é legítima — mas avisa, porque num produto
     * de usuário único uma segunda conta é quase sempre resto de cadastro antigo,
     * com credencial viva que ninguém lembra.
     */
    #[Test]
    public function avisa_quando_ha_mais_de_uma_conta(): void
    {
        User::factory()->create(['email' => 'antigo@exemplo.com']);

        $this->artisan('prospecta:operador', [
            'email' => 'novo@exemplo.com',
            '--senha' => 'senha-bem-longa-1',
        ])
            ->expectsOutputToContain('usuário único')
            ->assertSuccessful();
    }
}
