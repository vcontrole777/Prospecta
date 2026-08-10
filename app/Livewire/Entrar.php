<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Entrada do painel.
 *
 * Produto de usuário único, uma VPS por cliente. Três ausências deliberadas:
 *
 * · SEM auto-registro. Rota de cadastro num painel exposto em domínio público
 *   deixa qualquer um criar operador — é o defeito, não a falta do recurso. O
 *   operador nasce por `php artisan prospecta:operador`, no provisionamento.
 *
 * · SEM redefinição por e-mail. Não há mail configurado, e com uma instância por
 *   cliente o fluxo de token por e-mail seria mais superfície de ataque e mais
 *   carga de suporte do que o problema que resolve. Quem esqueceu a senha pede ao
 *   fornecedor, que roda o mesmo comando.
 *
 * · SEM verificação de e-mail. Não há para onde verificar.
 */
#[Layout('layouts::entrada')]
final class Entrar extends Component
{
    public string $email = '';

    public string $senha = '';

    public bool $lembrar = false;

    public function render(): View
    {
        return view('livewire.entrar');
    }

    public function entrar(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'senha' => ['required', 'string'],
        ], attributes: ['email' => 'e-mail', 'senha' => 'senha']);

        $chave = $this->chaveDeLimite();

        // 🔴 Limite por tentativa. É a defesa que importa aqui: o painel fica em
        // domínio público, com um único usuário e um único e-mail válido, então
        // força bruta contra uma senha é o ataque óbvio e não há nada além disto
        // no caminho.
        if (RateLimiter::tooManyAttempts($chave, self::TENTATIVAS)) {
            $segundos = RateLimiter::availableIn($chave);

            throw ValidationException::withMessages([
                'email' => "Muitas tentativas. Aguarde {$segundos} segundos.",
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->senha], $this->lembrar)) {
            RateLimiter::hit($chave, self::JANELA_SEG);

            // 🔴 MESMA mensagem para e-mail inexistente e senha errada. Mensagens
            // distintas transformam o formulário num oráculo que confirma qual
            // e-mail é o do operador — e num produto de usuário único isso é
            // metade do segredo.
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        RateLimiter::clear($chave);

        // `Auth::attempt` já migra a sessão internamente. Explícito de propósito:
        // é a defesa contra fixação de sessão, e não deve depender de um detalhe
        // interno do guard continuar verdadeiro numa versão futura.
        session()->regenerate();

        $this->redirectIntended(default: route('campanhas'));
    }

    /**
     * Chave por e-mail E por IP. Só por e-mail, qualquer um trancaria o operador
     * de fora de propósito; só por IP, uma rede grande dividiria o orçamento de
     * tentativas entre pessoas sem relação.
     */
    private function chaveDeLimite(): string
    {
        return 'entrar|' . Str::lower($this->email) . '|' . (string) request()->ip();
    }

    private const TENTATIVAS = 5;

    private const JANELA_SEG = 60;
}
