<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Cria ou redefine o operador do painel.
 *
 * Este comando É o caminho de cadastro do produto — não existe tela de registro
 * nem redefinição por e-mail (ver `App\Livewire\Entrar`). Roda no provisionamento
 * e roda de novo quando o cliente perde a senha.
 */
final class DefinirOperador extends Command
{
    /** @var string */
    protected $signature = 'prospecta:operador
                            {email : E-mail de acesso}
                            {--nome=Operador : Nome exibido no painel}
                            {--senha= : Senha; se omitida, é pedida sem eco}';

    /** @var string */
    protected $description = 'Cria ou redefine o operador do painel';

    private const MINIMO_SENHA = 12;

    public function handle(): int
    {
        $email = $this->texto($this->argument('email'));
        $nome = $this->texto($this->option('nome')) ?: 'Operador';
        $senha = $this->texto($this->option('senha'));

        // 🔴 `secret()` e não `ask()`: senha digitada com eco fica no histórico do
        // terminal e no scrollback de quem estiver olhando a implantação. E a
        // opção `--senha` existe para automação, ao custo de aparecer em `ps` e
        // no histórico do shell — por isso o modo interativo é o padrão.
        if ($senha === '') {
            $senha = $this->texto($this->secret('Senha'));
        }

        $validacao = Validator::make(
            ['email' => $email, 'nome' => $nome, 'senha' => $senha],
            [
                'email' => ['required', 'email'],
                'nome' => ['required', 'string', 'max:255'],
                // Piso de 12, e o motivo é honesto: o limite de tentativas do
                // formulário já torna força bruta online impraticável. Os 12
                // servem para o caso em que o hash escapa — backup, dump, cópia
                // do disco da VPS — onde o ataque passa a ser offline e o
                // comprimento é a única defesa que resta.
                'senha' => ['required', 'string', 'min:' . self::MINIMO_SENHA],
            ],
            [
                'senha.min' => 'A senha precisa de ao menos ' . self::MINIMO_SENHA . ' caracteres.',
            ],
        );

        if ($validacao->fails()) {
            foreach ($validacao->errors()->all() as $erro) {
                $this->components->error($erro);
            }

            return self::FAILURE;
        }

        $existia = User::query()->where('email', $email)->exists();

        // O cast `hashed` do model faz o hash; nunca passar Hash::make aqui, o que
        // aplicaria bcrypt duas vezes e produziria uma senha que não confere.
        User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $nome, 'password' => $senha],
        );

        $this->components->info(
            $existia
                ? "Senha redefinida para {$email}."
                : "Operador {$email} criado."
        );

        $total = User::query()->count();
        if ($total > 1) {
            // Não bloqueia: rotação de acesso é caso legítimo. Mas o produto é de
            // usuário único e mais de uma conta ativa é quase sempre resto de um
            // cadastro anterior, com credencial viva que ninguém lembra.
            $this->components->warn(
                "Existem {$total} contas neste painel. O produto é de usuário único — "
                . 'confira se alguma é resto de acesso antigo.'
            );
        }

        return self::SUCCESS;
    }

    private function texto(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }
}
