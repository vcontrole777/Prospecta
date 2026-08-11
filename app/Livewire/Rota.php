<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Asterisk\AplicaRota;
use App\Dominio\Rota as RotaDeSaida;
use App\Seguranca\Cofre;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * A Rota: o caminho pelo qual as Ligações saem para a operadora.
 *
 * 🔴 ESTA TELA RECARREGA O ASTERISK. É o que a separa da Configuração.
 *
 * Salvar aqui grava `configuracao.rota_*`, reescreve o arquivo de pjsip e manda o Asterisk
 * recarregar. Horário de trabalho e retenção de gravação não fazem nada disso — juntar as
 * duas coisas numa tela faria um botão "Salvar" ter efeitos de naturezas muito diferentes.
 *
 * 🔴 A ROTA ERA A ÚNICA COISA QUE O OPERADOR NÃO PODIA MUDAR SOZINHO. Ela vivia no
 * `pjsip.conf` gerado pelo Ansible a partir de `host_vars/local.yml` — trocar de fornecedor
 * exigia editar arquivo na máquina e reexecutar o playbook.
 *
 * A senha vai CIFRADA no banco, pelo `Cofre`, e a tela mostra só os últimos quatro
 * dígitos. No arquivo do Asterisk ela vai em claro porque não há alternativa: o pjsip não
 * lê segredo cifrado. O arquivo fica 0640, legível só por `asterisk` e pelo grupo — a mesma
 * postura do `.env`.
 */
final class Rota extends Component
{
    public string $host = '';

    public string $usuario = '';

    /** Vazio significa "não mexer na que está gravada" — ver `salvar`. */
    public string $senha = '';

    public string $from_dominio = '';

    public string $from_usuario = '';

    public string $tech_prefix = '';

    /** Últimos 4 dígitos da senha gravada, para o operador reconhecer qual é. */
    public string $senhaMascarada = '';

    public function mount(): void
    {
        // Cinto de segurança, como na Configuração: a linha é semeada por migration, e sem
        // ela toda gravação aqui viraria `update` de zero linhas — em silêncio.
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);

        $linha = DB::table('configuracao')->where('id', 1)->first();
        $c = $linha === null ? [] : (array) $linha;

        $this->host = self::texto($c['rota_host'] ?? null);
        $this->usuario = self::texto($c['rota_usuario'] ?? null);
        $this->from_dominio = self::texto($c['rota_from_dominio'] ?? null);
        $this->from_usuario = self::texto($c['rota_from_usuario'] ?? null);
        $this->tech_prefix = self::texto($c['rota_tech_prefix'] ?? null);

        // 🔴 A SENHA NÃO VOLTA PARA A TELA, nem cifrada. Preencher o campo com o valor real
        // o poria no HTML e no estado do Livewire, que trafega em cada interação. O
        // operador reconhece pela máscara; para trocar, digita a nova.
        $this->senhaMascarada = $this->mascararSenhaGravada();
    }

    public function render(): View
    {
        return view('livewire.rota', [
            'configurada' => $this->rotaAtual()->configurado(),
        ]);
    }

    public function salvar(): void
    {
        $this->validate([
            // `host` aceita IP ou nome. Sem espaço e sem quebra de linha: o valor vai para
            // um arquivo `.conf` que não tem sintaxe de aspas, então quebra de linha aqui
            // partiria o arquivo e o Asterisk recusaria a recarga INTEIRA — derrubando uma
            // rota que estava funcionando.
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'usuario' => ['nullable', 'string', 'max:255', 'regex:/^[^\s]+$/'],
            'senha' => ['nullable', 'string', 'max:255', 'regex:/^[^\s]+$/'],
            'from_dominio' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._:\-]*$/'],
            'from_usuario' => ['nullable', 'string', 'max:255', 'regex:/^[^\s]*$/'],
            'tech_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[0-9]*$/'],
        ], [
            'host.required' => 'Sem host não há para onde discar.',
            'host.regex' => 'Host é IP ou nome, sem espaço — 200.1.2.3 ou sip.fornecedor.com.',
            'usuario.regex' => 'O usuário não pode ter espaço.',
            'senha.regex' => 'A senha não pode ter espaço.',
            'from_dominio.regex' => 'O domínio é IP ou nome, sem espaço.',
            'from_usuario.regex' => 'O from_user não pode ter espaço.',
            'tech_prefix.regex' => 'O prefixo técnico é só dígitos.',
        ], [
            'host' => 'host',
            'tech_prefix' => 'prefixo técnico',
            'from_dominio' => 'from_domain',
            'from_usuario' => 'from_user',
        ]);

        $campos = [
            'rota_host' => trim($this->host),
            'rota_usuario' => trim($this->usuario) === '' ? null : trim($this->usuario),
            'rota_from_dominio' => trim($this->from_dominio) === '' ? null : trim($this->from_dominio),
            'rota_from_usuario' => trim($this->from_usuario) === '' ? null : trim($this->from_usuario),
            'rota_tech_prefix' => trim($this->tech_prefix) === '' ? null : trim($this->tech_prefix),
        ];

        // Campo em branco PRESERVA a senha gravada, e não a apaga. O operador que vem
        // trocar só o prefixo não deveria perder a credencial por não redigitá-la — e
        // apagar a senha derrubaria a rota inteira no próximo `Reload`.
        if (trim($this->senha) !== '') {
            $campos['rota_senha'] = $this->cofre()->cifrarParaColuna(['senha' => trim($this->senha)]);
        }

        DB::table('configuracao')->where('id', 1)->update($campos);

        $this->senha = '';
        $this->senhaMascarada = $this->mascararSenhaGravada();

        // Aplica na mesma ação. Dois botões fariam o operador salvar, sair da tela, e a
        // rota nova nunca entrar em vigor — divergência entre o que a tela mostra e o que
        // disca, que é o defeito exato que esta tela existe para acabar.
        //
        // A falha do aplicar NÃO desfaz o salvar: o valor no banco está certo, e o que
        // faltou é permissão ou o Asterisk. Desfazer obrigaria a redigitar tudo por um
        // problema que não é do formulário.
        $r = $this->aplicador()->aplicar($this->rotaAtual());

        session()->flash($r['ok'] ? 'recado' : 'recusa', $r['ok']
            ? 'Rota salva e aplicada. ' . $r['mensagem']
            : 'Rota SALVA no banco, mas não entrou em vigor: ' . $r['mensagem']);
    }

    /** Para quando o operador consertou a permissão e quer aplicar o que já está salvo. */
    public function aplicar(): void
    {
        $rota = $this->rotaAtual();

        if (! $rota->configurado()) {
            session()->flash('recusa', 'Preencha o host antes de aplicar.');

            return;
        }

        $r = $this->aplicador()->aplicar($rota);

        session()->flash($r['ok'] ? 'recado' : 'recusa', $r['mensagem']);
    }

    private function aplicador(): AplicaRota
    {
        $ami = config('dialer.ami');
        $ami = is_array($ami) ? $ami : [];

        return new AplicaRota(
            arquivo: self::texto(config('prospecta.pjsip_rota')),
            amiHost: self::texto($ami['host'] ?? null) ?: '127.0.0.1',
            amiPorta: is_numeric($ami['porta'] ?? null) ? (int) $ami['porta'] : 5038,
            amiUsuario: self::texto($ami['usuario'] ?? null),
            amiSegredo: self::texto($ami['segredo'] ?? null),
        );
    }

    /** A Rota como o resto do sistema a vê, já decifrada. */
    private function rotaAtual(): RotaDeSaida
    {
        $linha = DB::table('configuracao')->where('id', 1)->first();
        $c = $linha === null ? [] : (array) $linha;

        return new RotaDeSaida(
            host: self::texto($c['rota_host'] ?? null),
            usuario: self::texto($c['rota_usuario'] ?? null),
            senha: $this->senhaGravada(),
            fromDominio: self::texto($c['rota_from_dominio'] ?? null),
            fromUsuario: self::texto($c['rota_from_usuario'] ?? null),
            techPrefix: self::texto($c['rota_tech_prefix'] ?? null),
        );
    }

    private function senhaGravada(): string
    {
        $coluna = self::texto(DB::table('configuracao')->where('id', 1)->value('rota_senha'));
        if ($coluna === '') {
            return '';
        }

        $dados = $this->cofre()->decifrarDaColuna($coluna);

        return is_string($dados['senha'] ?? null) ? $dados['senha'] : '';
    }

    private function mascararSenhaGravada(): string
    {
        $senha = $this->senhaGravada();

        return $senha === '' ? '' : $this->cofre()->mascarar($senha);
    }

    private function cofre(): Cofre
    {
        $chave = config('app.key');

        return new Cofre(is_string($chave) ? $chave : '');
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
